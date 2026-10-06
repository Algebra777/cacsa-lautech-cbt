<?php
declare(strict_types=1);

/**
 * Additive, idempotent platform-branding migration for the Berevion rename.
 * It never reads or writes tenant branding, academic, or identity rows.
 *
 * Usage: php scripts/apply_platform_branding_schema.php
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$pdo = mysqlMigrationPdo($root);
try {
    // MySQL DDL commits implicitly, so create the additive table first and
    // transact the seed/audit pair separately.
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_branding (
      id TINYINT UNSIGNED NOT NULL,
      display_name VARCHAR(190) NOT NULL,
      tagline VARCHAR(255) NOT NULL,
      logo_path VARCHAR(500) NOT NULL,
      favicon_path VARCHAR(500) NOT NULL,
      accent_source_color CHAR(7) NOT NULL,
      primary_color CHAR(7) NOT NULL,
      accent_color CHAR(7) NOT NULL,
      navy_color CHAR(7) NOT NULL DEFAULT '#00205D',
      mid_blue_color CHAR(7) NOT NULL DEFAULT '#024DB2',
      bright_blue_color CHAR(7) NOT NULL DEFAULT '#0094FE',
      updated_at DATETIME(6) NOT NULL,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['navy_color' => "CHAR(7) NOT NULL DEFAULT '#00205D'", 'mid_blue_color' => "CHAR(7) NOT NULL DEFAULT '#024DB2'", 'bright_blue_color' => "CHAR(7) NOT NULL DEFAULT '#0094FE'"] as $column => $definition) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
        $exists->execute(['table_name' => 'platform_branding', 'column_name' => $column]);
        if (!(int)$exists->fetchColumn()) $pdo->exec("ALTER TABLE platform_branding ADD COLUMN {$column} {$definition} AFTER accent_color");
    }
    $pdo->beginTransaction();
    $statement = $pdo->prepare('INSERT INTO platform_branding (id,display_name,tagline,logo_path,favicon_path,accent_source_color,primary_color,accent_color,navy_color,mid_blue_color,bright_blue_color,updated_at) VALUES (1,:display_name,:tagline,:logo_path,:favicon_path,:accent_source_color,:primary_color,:accent_color,:navy_color,:mid_blue_color,:bright_blue_color,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),tagline=VALUES(tagline),logo_path=VALUES(logo_path),favicon_path=VALUES(favicon_path),accent_source_color=VALUES(accent_source_color),primary_color=VALUES(primary_color),accent_color=VALUES(accent_color),navy_color=VALUES(navy_color),mid_blue_color=VALUES(mid_blue_color),bright_blue_color=VALUES(bright_blue_color),updated_at=UTC_TIMESTAMP(6)');
    $statement->execute([
        'display_name'=>'Berevion', 'tagline'=>'Examine. Verify. Excel.',
        'logo_path'=>'uploads/platform-branding/berevion-logo.png', 'favicon_path'=>'uploads/platform-branding/berevion-logo.png',
        'accent_source_color'=>'#14D2BA', 'primary_color'=>'#0D8475', 'accent_color'=>'#14D2BA',
        'navy_color'=>'#00205D', 'mid_blue_color'=>'#024DB2', 'bright_blue_color'=>'#0094FE'
    ]);
    $audit = $pdo->prepare('INSERT INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,ip_address,user_agent,before_after_json,metadata_json) VALUES (:id,UTC_TIMESTAMP(6),:actor_id,:action_type,NULL,:target_type,:target_id,:outcome,:correlation_id,NULL,NULL,NULL,:metadata_json)');
    $audit->execute(['id'=>'platform-branding-' . bin2hex(random_bytes(12)), 'actor_id'=>'system', 'action_type'=>'platform_branding_initialized', 'target_type'=>'platform_branding', 'target_id'=>'1', 'outcome'=>'success', 'correlation_id'=>'berevion-root-rename', 'metadata_json'=>json_encode(['name'=>'Berevion','tagline'=>'Examine. Verify. Excel.'], JSON_THROW_ON_ERROR)]);
    $pdo->commit();
    echo json_encode(['ok'=>true,'table'=>'platform_branding','brand'=>'Berevion'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1);
}
