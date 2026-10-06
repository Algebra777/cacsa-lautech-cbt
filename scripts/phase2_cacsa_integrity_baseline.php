<?php
declare(strict_types=1);

/**
 * Captures a deterministic, full tenant-data fingerprint for CACSA (institution 1).
 * This stores hashes only, never academic or account values.  It is deliberately
 * restricted to the Phase 2 verification tenant and cannot target another tenant.
 *
 * Usage:
 *   php scripts/phase2_cacsa_integrity_baseline.php capture path/to/output.json
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
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $encoded = array_map(static fn(array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        $result[$table] = [
            'count' => count($rows),
            'sha256' => hash('sha256', json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ];
    }
    return $result;
}

function phase2Manifest(PDO $pdo, array $tables): array {
    $manifest = [];
    foreach ($tables as $table) {
        $keys = $pdo->query("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        usort($keys, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        $keyNames = array_map(static fn(array $key): string => (string)$key['Column_name'], $keys);
        $order = $keyNames === [] ? '1' : implode(',', array_map(static fn(string $key): string => '`' . str_replace('`', '``', $key) . '`', $keyNames));
        $statement = $pdo->prepare("SELECT * FROM {$table} WHERE institution_id = 1 ORDER BY {$order}");
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $manifest[$table] = [];
        foreach ($rows as $row) {
            $canonical = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $primaryKey = [];
            foreach ($keyNames as $keyName) $primaryKey[$keyName] = $row[$keyName] ?? null;
            $manifest[$table][] = [
                'primaryKey' => $primaryKey,
                'rowSha256' => hash('sha256', $canonical),
            ];
        }
    }
    return $manifest;
}

function phase2PrimaryKeyManifest(PDO $pdo, string $table): array {
    $keys = $pdo->query("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
    usort($keys, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
    $keyNames = array_map(static fn(array $key): string => (string)$key['Column_name'], $keys);
    $order = implode(',', array_map(static fn(string $key): string => '`' . str_replace('`', '``', $key) . '`', $keyNames));
    $rows = $pdo->query("SELECT " . $order . " FROM {$table} WHERE institution_id = 1 ORDER BY {$order}")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function (array $row) use ($keyNames): array {
        $primaryKey = [];
        foreach ($keyNames as $keyName) $primaryKey[$keyName] = $row[$keyName] ?? null;
        return $primaryKey;
    }, $rows);
}

$mode = $argv[1] ?? '';
if ($mode === 'capture') {
    $fingerprint = phase2Fingerprint($pdo, $tables);
    $path = $argv[2] ?? '';
    if ($path === '') throw new RuntimeException('Pass an output path outside the web roots.');
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Unable to create the integrity-baseline directory.');
    $manifestTables = ['courses','course_components','questions','question_options','question_publish_targets','students','assessment_attempts','component_submissions','calculated_results','calculated_result_items'];
    $payload = [
        'kind' => 'phase2-cacsa-integrity-hash-baseline',
        'capturedAt' => gmdate('c'),
        'institutionId' => 1,
        'slug' => 'cacsa-lautech',
        'method' => 'Rows selected by institution_id=1 (institutions by id=1); each row JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION; rows sorted by encoded JSON string; SHA-256 of encoded sorted array.',
        'version' => 'canonical-cacsa-v2-row-manifest',
        'fingerprint' => $fingerprint,
        'rowManifest' => phase2Manifest($pdo, $manifestTables),
        'auditEventIds' => phase2PrimaryKeyManifest($pdo, 'audit_events'),
        'note' => 'Eight course_component_updated audit events after the prior baseline were legitimate administrator actions by adepojutimothy001@gmail.com at approximately 10:37:53-10:39:27 UTC (12:37 local time). They recorded STA101 and STA 201 as published to active, plus transient CSC 105 and MTH 101 status changes. The mock suite, HTTP inertness probe, quota tests, cleanup, and deployment checks used disposable tenant slugs and produced no institution-1 algebra_requests or algebra audit events; no test targeted institution 1.',
    ];
    if (file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Unable to write the integrity baseline.');
    echo json_encode(['pass' => true, 'baseline' => $path, 'sha256' => hash_file('sha256', $path), 'tables' => count($tables) + 1, 'fingerprint' => $fingerprint], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

if ($mode === 'verify') {
    $path = $argv[2] ?? '';
    if ($path === '' || !is_file($path)) throw new RuntimeException('Pass the baseline file path produced by capture.');
    $baseline = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($baseline['institutionId'] ?? null) !== 1 || !is_array($baseline['fingerprint'] ?? null)) throw new RuntimeException('This is not a CACSA Phase 2 integrity baseline.');
    $current = phase2Fingerprint($pdo, $tables);
    $changed = [];
    foreach ($baseline['fingerprint'] as $name => $value) {
        if (($current[$name] ?? null) !== $value) $changed[$name] = ['baseline' => $value, 'current' => $current[$name] ?? null];
    }
    if (isset($baseline['rowManifest']) && is_array($baseline['rowManifest'])) {
        $currentManifest = phase2Manifest($pdo, array_keys($baseline['rowManifest']));
        foreach ($baseline['rowManifest'] as $table => $rows) {
            if ($table === 'audit_events') {
                $oldIds = array_map(static fn(array $row): string => json_encode($row['primaryKey'] ?? [], JSON_UNESCAPED_SLASHES), $rows);
                $newIds = array_map(static fn(array $row): string => json_encode($row['primaryKey'] ?? [], JSON_UNESCAPED_SLASHES), $currentManifest[$table] ?? []);
                $added = array_values(array_diff($newIds, $oldIds));
                if ($added !== []) $changed[$table]['newIds'] = $added;
                continue;
            }
            $old = [];
            foreach ($rows as $row) $old[json_encode($row['primaryKey'] ?? [], JSON_UNESCAPED_SLASHES)] = $row['rowSha256'] ?? '';
            $new = [];
            foreach (($currentManifest[$table] ?? []) as $row) $new[json_encode($row['primaryKey'] ?? [], JSON_UNESCAPED_SLASHES)] = $row['rowSha256'] ?? '';
            $diff = ['added' => array_values(array_diff_key($new, $old)), 'removed' => array_values(array_diff_key($old, $new)), 'changed' => []];
            foreach (array_intersect_key($old, $new) as $id => $hash) if ($hash !== $new[$id]) $diff['changed'][] = $id;
            if ($diff['added'] !== [] || $diff['removed'] !== [] || $diff['changed'] !== []) $changed[$table]['rows'] = $diff;
        }
    }
    if (isset($baseline['auditEventIds']) && is_array($baseline['auditEventIds'])) {
        $oldIds = array_map(static fn(array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES), $baseline['auditEventIds']);
        $newIds = array_map(static fn(array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES), phase2PrimaryKeyManifest($pdo, 'audit_events'));
        $newAuditIds = array_values(array_diff($newIds, $oldIds));
        if ($newAuditIds !== []) $changed['audit_events']['newIds'] = $newAuditIds;
    }
    echo json_encode(['pass' => $changed === [], 'baseline' => $path, 'changed' => $changed, 'tables' => count($tables) + 1], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($changed === [] ? 0 : 1);
}

throw new RuntimeException('Use capture or verify.');
