<?php
class BinaryLaneBackup {
    private $apiToken;
    private $baseUrl = 'https://api.binarylane.com.au/v2';
    private $backupDir;
    private $retentionDays = 14; // 2 weeks retention
    private $discordWebhook = 'DISCORD WEBHOOK'; // Set Discord webhook URL here
    private $enableDiscord = false; // Enable/Disable Discord notifications

    public function __construct($apiToken, $backupDir) {
        $this->apiToken = $apiToken;
        $this->backupDir = rtrim($backupDir, '/');
        
        // Create backup directory if it doesn't exist
        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    private function sendDiscordAlert($message) {
        if (!$this->enableDiscord || empty($this->discordWebhook)) {
            return;
        }

        $payload = json_encode(['content' => $message]);
        $ch = curl_init($this->discordWebhook);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function apiRequest($endpoint, $method = 'GET', $data = null) {
        $ch = curl_init("{$this->baseUrl}/{$endpoint}");
        
        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json'
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers
        ]);

        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            $this->sendDiscordAlert("API request failed with code $httpCode: $response");
            throw new Exception("API request failed with code $httpCode: $response");
        }

        return json_decode($response, true);
    }

    public function processServerBackups() {
        try {
            $servers = $this->apiRequest('servers')['servers'];
            
            if (!empty($servers)) {
                foreach ($servers as $server) {
                    $serverId = $server['id'];
                    $serverName = $server['name'];

                    try {
                        echo "Starting backup for $serverName (ID: $serverId)\n";
                        $backupAction = $this->apiRequest("servers/$serverId/actions", 'POST', [
                            'type' => 'take_backup',
                            'backup_type' => 'temporary',
                            'replacement_strategy' => 'oldest'
                        ]);

                        $actionId = $backupAction['action']['id'];
                        $this->waitForAction($actionId);

                        $backups = $this->apiRequest("servers/$serverId/backups")['backups'];
                        if (empty($backups)) {
                            throw new Exception("No backups found for server $serverName");
                        }

                        $latestBackup = end($backups);
                        $downloadUrl = $this->apiRequest("images/{$latestBackup['id']}/download")['link']['disks'][0]['compressed_url'];
                        $backupFile = $this->downloadBackup($downloadUrl, $serverName);

                        $this->checkBackupIntegrity($backupFile, $serverName);
                        $this->rotateBackups($serverName);

                        echo "\nSuccessfully processed backup for $serverName\n";
                    } catch (Exception $e) {
                        echo "Error processing backup for $serverName: " . $e->getMessage() . "\n";
                        $this->sendDiscordAlert("Error processing backup for $serverName: " . $e->getMessage());
                    }
                }
            } else {
                echo "No servers found\n";
                $this->sendDiscordAlert("No servers found to process backups.");
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            $this->sendDiscordAlert("Error: " . $e->getMessage());
        }
    }

        private function waitForAction($actionId, $timeout = 3600) {
        $start = time();
        while (true) {
            $action = $this->apiRequest("actions/$actionId")['action'];

            if ($action['status'] === 'completed') {
                return true;
            }

            if ($action['status'] === 'errored') {
                throw new Exception("Action failed: " . ($action['result_data'] ?? 'Unknown error'));
            }

            if (time() - $start > $timeout) {
                throw new Exception("Action timed out after {$timeout} seconds");
            }

            sleep(30); // Wait 30 seconds before checking again
        }
    }


    private function checkBackupIntegrity($filePath, $serverName) {
        $fileSize = filesize($filePath);
        if ($fileSize < 100 * 1024 * 1024) { // 100 MB in bytes
            $message = "Backup for $serverName is corrupted (size: " . number_format($fileSize / 1024 / 1024, 2) . " MB)";
            echo "$message\n";
            $this->sendDiscordAlert($message);
        }
    }

    private function downloadBackup($url, $serverName) {
        $date = date('Y-m-d');
        $targetDir = "{$this->backupDir}/$serverName";
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $targetFile = "$targetDir/backup-$date.tar.gz";
        $fp = fopen($targetFile, 'w+');
        $ch = curl_init($url);

        $progress = function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) {
            if ($downloadSize > 0) {
                $progress = ($downloaded / $downloadSize) * 100;
                echo "\rDownloading: " . number_format($progress, 2) . "%";
            }
        };

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => $progress,
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode >= 400) {
            unlink($targetFile);
            throw new Exception("Failed to download backup: HTTP $httpCode");
        }

        return $targetFile;
    }

    private function rotateBackups($serverName) {
        $targetDir = "{$this->backupDir}/$serverName";
        if (!file_exists($targetDir)) {
            return;
        }

        $files = glob("$targetDir/backup-*.tar.gz");
        foreach ($files as $file) {
            $fileDate = strtotime(basename($file, '.tar.gz'));
            if ($fileDate && (time() - $fileDate) > ($this->retentionDays * 86400)) {
                unlink($file);
            }
        }
    }
}

try {
    $apiToken = 'API_KEY';
    $backupDir = '/backup';
    
    $backup = new BinaryLaneBackup($apiToken, $backupDir);
    $backup->processServerBackups();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
