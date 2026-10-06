<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$pdo->exec('CREATE TABLE IF NOT EXISTS platform_rate_limit_records (legacy_key CHAR(64) NOT NULL, count_value INT UNSIGNED NOT NULL, reset_at DATETIME(6) NOT NULL, payload_json JSON NOT NULL, PRIMARY KEY (legacy_key), KEY ix_platform_rate_reset (reset_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
echo json_encode(['pass' => true, 'table' => 'platform_rate_limit_records']) . PHP_EOL;
