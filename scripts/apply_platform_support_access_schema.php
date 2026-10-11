<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$pdo = mysqlMigrationPdo($root);
$schema = file_get_contents($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'mysql_schema.sql');
if ($schema === false) throw new RuntimeException('The MySQL schema file could not be read.');
if (!preg_match('/CREATE TABLE IF NOT EXISTS platform_support_access_sessions \([\s\S]*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/', $schema, $match)) {
    throw new RuntimeException('Platform support-access schema definition is missing.');
}

try {
    $pdo->exec($match[0]);
    $tables = $pdo->query("SHOW TABLES LIKE 'platform_support_access_sessions'")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['ok' => $tables === ['platform_support_access_sessions'], 'table' => 'platform_support_access_sessions'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Platform support-access schema failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
