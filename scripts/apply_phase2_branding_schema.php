<?php
declare(strict_types=1);

/**
 * Phase 2 / Step 3 structural migration.
 *
 * It creates the per-institution branding record and seeds only CACSA's
 * current, already-live appearance. It neither provisions another tenant nor
 * changes tenant academic data.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$pdo = mysqlMigrationPdo($root);

try {
    // DDL is intentionally separate from its idempotent seed transaction.
    $pdo->exec("CREATE TABLE IF NOT EXISTS institution_branding (
        institution_id INT UNSIGNED NOT NULL,
        display_name VARCHAR(190) NOT NULL,
        portal_title VARCHAR(190) NOT NULL,
        logo_path VARCHAR(500) NOT NULL,
        favicon_path VARCHAR(500) NOT NULL,
        accent_source_color CHAR(7) NOT NULL DEFAULT '#16774d',
        primary_color CHAR(7) NOT NULL,
        accent_color CHAR(7) NOT NULL,
        nav_label VARCHAR(190) NOT NULL,
        assessment_label VARCHAR(190) NOT NULL,
        footer_primary VARCHAR(255) NOT NULL,
        footer_secondary VARCHAR(255) NOT NULL,
        footer_legal VARCHAR(255) NOT NULL,
        result_sheet_title VARCHAR(190) NOT NULL,
        newsletter_sender_name VARCHAR(190) NOT NULL,
        support_email VARCHAR(254) NULL,
        updated_at DATETIME(6) NULL,
        PRIMARY KEY (institution_id),
        CONSTRAINT fk_institution_branding_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->beginTransaction();
    // INSERT IGNORE deliberately preserves a later administrator-configured
    // brand if this migration is run again.
    $seed = $pdo->prepare('INSERT IGNORE INTO institution_branding
        (institution_id,display_name,portal_title,logo_path,favicon_path,accent_source_color,primary_color,accent_color,nav_label,assessment_label,footer_primary,footer_secondary,footer_legal,result_sheet_title,newsletter_sender_name,support_email,updated_at)
        VALUES (1,:display_name,:portal_title,:logo_path,:favicon_path,:accent_source_color,:primary_color,:accent_color,:nav_label,:assessment_label,:footer_primary,:footer_secondary,:footer_legal,:result_sheet_title,:newsletter_sender_name,:support_email,UTC_TIMESTAMP(6))');
    $seed->execute([
        'display_name' => 'CACSA LAUTECH',
        'portal_title' => 'CACSA LAUTECH CBT',
        'logo_path' => 'CACSA%20Logo.jpeg',
        'favicon_path' => 'favicon.php',
        'accent_source_color' => '#16774d',
        'primary_color' => '#16774d',
        'accent_color' => '#105839',
        'nav_label' => 'CACSA LAUTECH CBT',
        'assessment_label' => 'Assessment centre',
        'footer_primary' => 'LAUTECH Academic Directorate Certified Node',
        'footer_secondary' => 'Assessment timing supplied by the CBT service',
        'footer_legal' => '© {year} CACSA LAUTECH. All rights reserved.',
        'result_sheet_title' => 'CACSA LAUTECH CBT',
        'newsletter_sender_name' => 'CACSA LAUTECH',
        'support_email' => 'cacsalautech001@gmail.com',
    ]);

    $audit = $pdo->prepare('INSERT IGNORE INTO platform_audit_events
        (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,metadata_json)
        VALUES (:id,UTC_TIMESTAMP(6),:actor_id,:action_type,1,:target_type,:target_id,:outcome,:correlation_id,:metadata_json)');
    $audit->execute([
        'id' => 'phase2-step3-branding-cacsa-v1', 'actor_id' => 'system',
        'action_type' => 'institution_branding_seeded', 'target_type' => 'institution',
        'target_id' => '1', 'outcome' => 'success', 'correlation_id' => 'phase2-step3',
        'metadata_json' => json_encode(['seedOnly' => true, 'tenantAcademicDataChanged' => false], JSON_UNESCAPED_SLASHES),
    ]);
    $pdo->commit();
    echo json_encode(['ok' => true, 'table' => 'institution_branding', 'seededInstitutionId' => 1], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Phase 2 Step 3 failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
