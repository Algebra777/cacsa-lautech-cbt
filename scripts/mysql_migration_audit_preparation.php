<?php
declare(strict_types=1);

/* Records the pre-MySQL audit event without mutating the legacy JSON source. */

$root = dirname(__DIR__);
$backupDirectory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups';
$backupFilename = $argv[1] ?? '';
if (!preg_match('/^cacsa-cbt-backup_\d{4}-\d{2}-\d{2}_\d{6}(?:-\d+)?\.json$/', $backupFilename)) {
    throw new InvalidArgumentException('Pass the exact safety-backup filename.');
}
$backupPath = $backupDirectory . DIRECTORY_SEPARATOR . $backupFilename;
$sourcePath = $root . DIRECTORY_SEPARATOR . 'cbt-data.json';
if (!is_file($backupPath) || !is_file($sourcePath)) throw new RuntimeException('The source JSON or safety backup is missing.');

$record = [
    'format' => 'cacsa-cbt-storage-migration-audit-preparation',
    'version' => 1,
    'timestamp' => date('c'),
    'actorType' => 'system',
    'actorId' => 'storage-migration-cli',
    'actionType' => 'mysql_storage_migration_prepared',
    'targetType' => 'storage',
    'targetId' => 'cacsa-lautech',
    'outcome' => 'success',
    'metadata' => [
        'legacySource' => 'cbt-data.json',
        'legacySourceSha256' => hash_file('sha256', $sourcePath),
        'safetyBackup' => $backupFilename,
        'safetyBackupSha256' => hash_file('sha256', $backupPath),
        'legacySourcePreserved' => true,
    ],
];
$base = 'cacsa-mysql-migration-preparation_' . date('Y-m-d_His');
$filename = $base . '.json';
for ($counter = 1; file_exists($backupDirectory . DIRECTORY_SEPARATOR . $filename); $counter++) {
    $filename = $base . '-' . $counter . '.json';
}
$path = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
if (file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
    throw new RuntimeException('Migration audit-preparation record could not be written.');
}
echo json_encode(['filename' => $filename, 'record' => $record], JSON_UNESCAPED_SLASHES) . PHP_EOL;
