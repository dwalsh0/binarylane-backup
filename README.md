# Binarylane Backup  
A simple PHP script to download Binarylane server backups and store them locally.  

## Features  

### Discord Integration  
Get notified about backup or API failures via Discord webhook alerts.  

### Integrity Check  
Automatically verifies that each backup is over 100MB, ensuring the entire VM is successfully backed up.  

### Backup Rotation  
Manage backup retention by setting the number of backups to keep. Older images are automatically deleted based on your configuration.  

## Configuration  

Update the following variables in the script:  

```php
$apiToken = 'API_KEY'; // Replace with your Binarylane API Key  
$backupDir = '/backup'; // Set the destination folder for backups  
private $retentionDays = 14; // Retain backups for 14 days (adjust as needed)  
private $discordWebhook = 'DISCORD_WEBHOOK'; // Add your Discord webhook URL  
private $enableDiscord = false; // Enable/disable Discord notifications  
```

### Scheduling
Set up a cron job to run the script daily or at an interval that suits your backup needs.

## Important Notes

- Avoid overloading the Binarylane API by downloading server images excessively. Running once per day would be acceptable and pick a time that would be the least impactful.
- This script processes one backup at a time and includes options to introduce additional delays to minimise infrastructure impact.
- Only the primary VM disk gets downloaded, if you have multiple disks this won't work for you.
- 
Feel free to suggest improvements or report issues!
