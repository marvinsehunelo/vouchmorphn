<?php
/**
 * deploy_update.php
 * Handles system updates: backups, versioning, and logging
 */

$rootDir = __DIR__ . "/../"; // PrestagedSWAP root
$backupDir = $rootDir . 'APP_LAYER/logs/backups/';
if (!file_exists($backupDir)) mkdir($backupDir, 0777, true);

// Step 1: Backup DB
$backupFile = $backupDir . 'db_backup_' . date('Y-m-d_H-i-s') . '.sql';
$dbName = "prestagedSWAP";
$dbUser = "root";
$dbPass = "YourPass"; // replace with your DB password

exec("mysqldump -u {$dbUser} -p{$dbPass} {$dbName} > {$backupFile}", $output, $return);
if ($return === 0) {
    file_put_contents($rootDir . 'APP_LAYER/logs/system.log', "[".date('Y-m-d H:i:s')."] Database backup successful: $backupFile\n", FILE_APPEND);
} else {
    file_put_contents($rootDir . 'APP_LAYER/logs/system.log', "[".date('Y-m-d H:i:s')."] Database backup FAILED\n", FILE_APPEND);
    exit("Database backup failed.\n");
}

// Step 2: Deploy new files from UPDATES folder
$updateFolder = $rootDir . 'UPDATES/';
if (!file_exists($updateFolder)) {
    exit("No updates folder found. Place update files in /UPDATES\n");
}

exec("cp -r {$updateFolder}* {$rootDir}", $output, $return);
if ($return === 0) {
    file_put_contents($rootDir . 'APP_LAYER/logs/system.log', "[".date('Y-m-d H:i:s')."] System update applied from UPDATES folder\n", FILE_APPEND);
} else {
    file_put_contents($rootDir . 'APP_LAYER/logs/system.log', "[".date('Y-m-d H:i:s')."] System update FAILED\n", FILE_APPEND);
    exit("Update failed.\n");
}

// Step 3: Update version
$versionFile = $rootDir . 'MANAGEMENT/version.txt';
$currentVersion = 'v' . date('Ymd_His');
file_put_contents($versionFile, $currentVersion);

echo "Update applied successfully. Version: $currentVersion\n";

