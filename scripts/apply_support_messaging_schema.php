<?php
declare(strict_types=1);

/* Idempotent Phase 2 support-messaging schema and permission migration. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$pdo = mysqlMigrationPdo($root);
$schema = file_get_contents($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'mysql_schema.sql');
if ($schema === false) throw new RuntimeException('The MySQL schema file could not be read.');

foreach (['support_threads', 'support_messages'] as $table) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . ' \([\s\S]*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/', $schema, $match)) {
        throw new RuntimeException("Support schema definition for {$table} is missing.");
    }
    $pdo->exec($match[0]);
}

// Existing administrators receive the same ordinary communications access as
// newsletter access.  Newly provisioned roles receive it from DEFAULT_ROLES.
$pdo->exec("INSERT IGNORE INTO role_permissions (institution_id,role_id,permission) SELECT institution_id,id,'messages' FROM roles");

$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('support_threads','support_messages') ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$permissions = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission='messages'")->fetchColumn();
echo json_encode(['tables'=>$tables,'rolePermissionRows'=>$permissions,'charset'=>'utf8mb4','foreignKeyDeleteRule'=>'RESTRICT'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
