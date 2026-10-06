<?php
declare(strict_types=1);

/**
 * Deployment-only maintenance switch. The API checks this file before opening
 * its legacy store, so it can be used safely during a storage cutover.
 * Usage: php scripts/set_maintenance_mode.php on|off
 */

$root = dirname(__DIR__);
$flag = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '.maintenance.json';
$command = strtolower(trim((string)($argv[1] ?? '')));

if (!in_array($command, ['on', 'off'], true)) {
    fwrite(STDERR, "Usage: php scripts/set_maintenance_mode.php on|off\n");
    exit(64);
}

if ($command === 'on') {
    $payload = [
        'enabled' => true,
        'message' => 'Brief maintenance is in progress. Please check back shortly.',
        'enabledAt' => gmdate('c'),
        'reason' => 'mysql_storage_cutover',
    ];
    if (file_put_contents($flag, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not enable maintenance mode.');
    }
    echo json_encode(['maintenance' => 'enabled', 'flag' => basename($flag)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

if (is_file($flag) && !unlink($flag)) throw new RuntimeException('Could not disable maintenance mode.');
echo json_encode(['maintenance' => 'disabled'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
