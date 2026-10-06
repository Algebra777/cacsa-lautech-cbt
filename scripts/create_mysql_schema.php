<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$pdo = mysqlMigrationPdo($root);
$schema = file_get_contents($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'mysql_schema.sql');
if ($schema === false) throw new RuntimeException('MySQL schema file could not be read.');

$statements = preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [];
$created = 0;
foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement === '' || str_starts_with($statement, '--')) {
        $statement = preg_replace('/^--[^\r\n]*(?:\r?\n|$)/m', '', $statement) ?? '';
        $statement = trim($statement);
    }
    if ($statement === '') continue;
    $pdo->exec($statement);
    if (stripos($statement, 'CREATE TABLE') === 0) $created++;
}

$seed = $pdo->prepare('INSERT INTO institutions (id, name, slug, active, created_at) VALUES (:id, :name, :slug, :active, :created_at) ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), active = VALUES(active)');
$seed->execute(['id' => 1, 'name' => 'CACSA LAUTECH', 'slug' => 'cacsa-lautech', 'active' => 1, 'created_at' => date('Y-m-d H:i:s.u')]);
$brandingSeed = $pdo->prepare('INSERT IGNORE INTO institution_branding (institution_id,display_name,portal_title,logo_path,favicon_path,accent_source_color,primary_color,accent_color,nav_label,assessment_label,footer_primary,footer_secondary,footer_legal,result_sheet_title,newsletter_sender_name,support_email,updated_at) VALUES (1,:display_name,:portal_title,:logo_path,:favicon_path,:accent_source_color,:primary_color,:accent_color,:nav_label,:assessment_label,:footer_primary,:footer_secondary,:footer_legal,:result_sheet_title,:newsletter_sender_name,:support_email,UTC_TIMESTAMP(6))');
$brandingSeed->execute([
    'display_name' => 'CACSA LAUTECH', 'portal_title' => 'CACSA LAUTECH CBT', 'logo_path' => 'CACSA%20Logo.jpeg', 'favicon_path' => 'favicon.php',
    'accent_source_color' => '#16774d', 'primary_color' => '#16774d', 'accent_color' => '#105839', 'nav_label' => 'CACSA LAUTECH CBT', 'assessment_label' => 'Assessment centre',
    'footer_primary' => 'LAUTECH Academic Directorate Certified Node', 'footer_secondary' => 'Assessment timing supplied by the CBT service',
    'footer_legal' => '© {year} CACSA LAUTECH. All rights reserved.', 'result_sheet_title' => 'CACSA LAUTECH CBT',
    'newsletter_sender_name' => 'CACSA LAUTECH', 'support_email' => 'cacsalautech001@gmail.com',
]);

$tables = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchColumn();
echo json_encode(['createStatements' => $created, 'tablesPresent' => (int)$tables, 'institution' => 'CACSA LAUTECH', 'charset' => $pdo->query("SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.schemata WHERE schema_name = DATABASE()")->fetchColumn(), 'collation' => $pdo->query("SELECT DEFAULT_COLLATION_NAME FROM information_schema.schemata WHERE schema_name = DATABASE()")->fetchColumn()], JSON_UNESCAPED_SLASHES) . PHP_EOL;
