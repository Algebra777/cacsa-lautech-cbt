<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$pdo = mysqlMigrationPdo(dirname(__DIR__));
$exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name');
$exists->execute(['table_name' => 'assessment_login_tokens', 'column_name' => 'password_id']);
if (!(int)$exists->fetchColumn()) {
    $pdo->exec('ALTER TABLE assessment_login_tokens ADD COLUMN password_id VARCHAR(128) NULL AFTER component_id');
}
echo "MySQL runtime schema is current.\n";
