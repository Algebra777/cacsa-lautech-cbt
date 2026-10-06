<?php
declare(strict_types=1);

/*
 * Phase-1 storage-migration safety utility.
 * It intentionally reads cbt-data.json without loading api.php, so the legacy
 * source remains byte-for-byte untouched. Its backup payload follows the
 * existing backup/restore contract, including the exclusion of active tokens
 * and sign-in secrets.
 */

$root = dirname(__DIR__);
$source = $root . DIRECTORY_SEPARATOR . 'cbt-data.json';
$backupDirectory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups';

if (!is_file($source)) throw new RuntimeException('Legacy cbt-data.json was not found.');
$raw = file_get_contents($source);
if ($raw === false) throw new RuntimeException('Legacy cbt-data.json could not be read.');
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data)) throw new RuntimeException('Legacy cbt-data.json does not contain a JSON object.');

$stripSecrets = static function (array &$value) use (&$stripSecrets): void {
    foreach ($value as $key => &$item) {
        if (in_array((string)$key, ['passwordHash', 'password', 'twoFactor', 'twoFactorPending', 'admin2faChallenges', 'emergencyRecoveryCodes'], true)) {
            unset($value[$key]);
            continue;
        }
        if (is_array($item)) $stripSecrets($item);
    }
    unset($item);
};

$payload = $data;
$payload['_backup'] = ['format' => 'cacsa-cbt-backup', 'version' => 1, 'createdAt' => date('c')];
$stripSecrets($payload);
unset($payload['adminSessions'], $payload['loginTokens'], $payload['adminPasswordResets'], $payload['rateLimits']);

if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Backup directory could not be created.');
}
$base = 'cacsa-cbt-backup_' . date('Y-m-d_His');
$filename = $base . '.json';
for ($counter = 1; file_exists($backupDirectory . DIRECTORY_SEPARATOR . $filename); $counter++) {
    $filename = $base . '-' . $counter . '.json';
}
$path = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
if (file_put_contents($path, $json, LOCK_EX) === false) throw new RuntimeException('Backup file could not be written.');

echo json_encode([
    'filename' => $filename,
    'size' => filesize($path),
    'sourceSha256' => hash('sha256', $raw),
    'backupSha256' => hash_file('sha256', $path),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
