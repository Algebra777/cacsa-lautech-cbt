<?php
declare(strict_types=1);

/*
 * Phase 2 provisioning prerequisite. Safe to run repeatedly: it only adds
 * the tenant-admin first-login flag when the existing Phase 1 schema lacks it.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$pdo = mysqlMigrationPdo($root);
$exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'must_change_password'");
$exists->execute();
if ((int)$exists->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE admin_users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER verified');
    echo "added\n";
} else {
    echo "already-present\n";
}
