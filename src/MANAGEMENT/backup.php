<?php
// MANAGEMENT/backup.php
declare(strict_types=1);

$config = require __DIR__ . '/backup.php'; // Load your config

$backupRoot = __DIR__ . '/../../BACKUPS/' . date('Y-m-d_H-i-s');
@mkdir($backupRoot, 0777, true);

function backupDatabase(array $dbConfig, string $backupDir): void {
    $host = $dbConfig['host'] ?? 'localhost';
    $name = $dbConfig['name'] ?? '';
    $user = $dbConfig['user'] ?? '';
    $pass = $dbConfig['pass'] ?? '';

    if (!$name) return;

    $dumpFile = $backupDir . '/' . $name . '.sql';
    $command = sprintf(
        'mysqldump -h%s -u%s -p\'%s\' %s > %s',
        escapeshellarg($host),
        escapeshellarg($user),
        $pass,
        escapeshellarg($name),
        escapeshellarg($dumpFile)
    );
    exec($command, $output, $returnVar);

    if ($returnVar !== 0) {
        echo "Error backing up {$name}\n";
    } else {
        echo "Backup completed for {$name} at $dumpFile\n";
    }
}

// --- Backup all databases from config ---
foreach ($config['db'] as $dbName => $dbConfig) {
    backupDatabase($dbConfig, $backupRoot);
}

// --- Backup logs and configs ---
$pathsToBackup = [
    __DIR__ . '/../../APP_LAYER/logs',
    __DIR__ . '/../../CORE_CONFIG/env',
    __DIR__ . '/../../DATA_PERSISTENCE_LAYER/config'
];

foreach ($pathsToBackup as $path) {
    $dest = $backupRoot . '/' . basename($path);
    exec("cp -r " . escapeshellarg($path) . " " . escapeshellarg($dest));
}

// --- Log backup ---
$logFile = __DIR__ . '/../../APP_LAYER/logs/backup.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Backup created at $backupRoot\n", FILE_APPEND);

echo "All backups completed successfully.\n";

