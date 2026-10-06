<?php
declare(strict_types=1);

/* Retain platform audit events when a deliberately guarded test tenant is removed. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$check = $pdo->prepare("SELECT DELETE_RULE FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_platform_audit_institution' LIMIT 1");
$check->execute(); $rule = strtoupper((string)$check->fetchColumn());
if ($rule !== 'SET NULL') {
    if ($rule !== '') $pdo->exec('ALTER TABLE platform_audit_events DROP FOREIGN KEY fk_platform_audit_institution');
    $pdo->exec('ALTER TABLE platform_audit_events ADD CONSTRAINT fk_platform_audit_institution FOREIGN KEY (target_institution_id) REFERENCES institutions (id) ON DELETE SET NULL ON UPDATE RESTRICT');
    echo "updated\n";
} else echo "already-set-null\n";
