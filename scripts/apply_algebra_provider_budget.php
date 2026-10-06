<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$pdo->exec("CREATE TABLE IF NOT EXISTS algebra_provider_budget (budget_key VARCHAR(80) NOT NULL PRIMARY KEY, quota_day DATE NOT NULL, attempt_count INT UNSIGNED NOT NULL DEFAULT 0, blocked_until DATETIME(6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "algebra_provider_budget ready\n";
