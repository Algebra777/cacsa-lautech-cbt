<?php
declare(strict_types=1);

/**
 * Captures a deterministic, full tenant-data fingerprint for CACSA (institution 1).
 * This stores hashes only, never academic or account values.  It is deliberately
 * restricted to the Phase 2 verification tenant and cannot target another tenant.
 *
 * Usage:
 *   php scripts/phase2_cacsa_integrity_baseline.php capture
 *   php scripts/phase2_cacsa_integrity_baseline.php verify path/to/baseline.json
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$pdo = mysqlMigrationPdo($root);
$tables = [
    'institution_branding','storage_migrations','academic_sessions','academic_semesters','courses','course_components',
    'students','questions','question_options','question_publish_targets','assessment_passwords','assessment_login_tokens',
    'assessment_attempts','attempt_question_snapshots','attempt_answers','attempt_flags','attempt_integrity_events',
    'component_submissions','calculated_results','calculated_result_items','roles','role_permissions','admin_users',
    'admin_sessions','admin_profile_overrides','admin_user_activity','pending_admin_requests','admin_email_verifications',
    'admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','audit_events','audit_archives',
    'rate_limit_records','exam_login_failures','exam_login_ip_attempts','exam_flags','newsletter_subscribers','newsletters',
    'newsletter_deliveries','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records',
];

function phase2Fingerprint(PDO $pdo, array $tables): array {
    $result = [];
    $institution = $pdo->query('SELECT * FROM institutions WHERE id = 1 LIMIT 1')->fetchAll();
    $result['institutions'] = hash('sha256', json_encode($institution, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    foreach ($tables as $table) {
        // Not every normalized table has a surrogate `id`; use its declared
        // primary-key order so two equivalent states always hash identically.
        $keys = $pdo->query("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'")->fetchAll();
        usort($keys, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        $order = $keys === [] ? '1' : implode(',', array_map(static fn(array $key): string => '`' . str_replace('`', '``', (string)$key['Column_name']) . '`', $keys));
        $statement = $pdo->prepare("SELECT * FROM {$table} WHERE institution_id = 1 ORDER BY {$order}");
        $statement->execute();
        $rows = $statement->fetchAll();
        $result[$table] = [
            'count' => count($rows),
            'sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }
    return $result;
}

$mode = $argv[1] ?? '';
if ($mode === 'capture') {
    $fingerprint = phase2Fingerprint($pdo, $tables);
    $directory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-1';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Unable to create the integrity-baseline directory.');
    $path = $directory . DIRECTORY_SEPARATOR . 'phase2-final-cacsa-baseline-' . gmdate('Ymd-His') . '.json';
    $payload = ['kind' => 'phase2-cacsa-integrity-hash-baseline', 'institutionId' => 1, 'capturedAt' => gmdate('c'), 'fingerprint' => $fingerprint];
    if (file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Unable to write the integrity baseline.');
    echo json_encode(['pass' => true, 'baseline' => str_replace($root . DIRECTORY_SEPARATOR, '', $path), 'tables' => count($tables) + 1, 'fingerprint' => $fingerprint], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

if ($mode === 'verify') {
    $path = $argv[2] ?? '';
    if ($path === '' || !is_file($path)) throw new RuntimeException('Pass the baseline file path produced by capture.');
    $baseline = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($baseline['institutionId'] ?? null) !== 1 || !is_array($baseline['fingerprint'] ?? null)) throw new RuntimeException('This is not a CACSA Phase 2 integrity baseline.');
    $current = phase2Fingerprint($pdo, $tables);
    $changed = [];
    foreach ($baseline['fingerprint'] as $name => $value) if (($current[$name] ?? null) !== $value) $changed[] = $name;
    echo json_encode(['pass' => $changed === [], 'baseline' => $path, 'changed' => $changed, 'tables' => count($tables) + 1], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($changed === [] ? 0 : 1);
}

throw new RuntimeException('Use capture or verify.');
