<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$exists = $pdo->query("SHOW COLUMNS FROM institution_branding LIKE 'accent_source_color'")->fetch();
if (!$exists) $pdo->exec("ALTER TABLE institution_branding ADD COLUMN accent_source_color CHAR(7) NOT NULL DEFAULT '#16774d' AFTER favicon_path");
$pdo->exec("UPDATE institution_branding SET accent_source_color = primary_color WHERE accent_source_color IS NULL OR accent_source_color = ''");
echo json_encode(['pass' => true, 'column' => 'accent_source_color']) . PHP_EOL;
