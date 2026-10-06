<?php
declare(strict_types=1);

/* One-purpose recovery of the guarded Phase 2 final UI test tenant only. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__); $slug = 'phase2-final-ui-test'; $institutionId = 21;
$backup = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-21' . DIRECTORY_SEPARATOR . 'guarded-phase2-final-ui-test-safety-20261003-213226.json';
if (!is_file($backup)) throw new RuntimeException('The guarded final-tenant safety archive is unavailable.');
$snapshot = json_decode((string)file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR);
if (($snapshot['institution']['id'] ?? null) !== $institutionId || ($snapshot['institution']['slug'] ?? null) !== $slug || !is_array($snapshot['records'] ?? null)) throw new RuntimeException('The safety archive does not match the approved final test tenant.');
$pdo = mysqlMigrationPdo($root);
$exists = $pdo->prepare('SELECT COUNT(*) FROM institutions WHERE id = :id OR slug = :slug'); $exists->execute(['id' => $institutionId, 'slug' => $slug]);
if ((int)$exists->fetchColumn() !== 0) throw new RuntimeException('The final test tenant already exists; refusing to overwrite it.');
$tables = ['storage_migrations','academic_sessions','academic_semesters','roles','students','courses','course_components','questions','question_options','question_publish_targets','assessment_passwords','assessment_login_tokens','assessment_attempts','attempt_question_snapshots','attempt_answers','attempt_flags','attempt_integrity_events','component_submissions','calculated_results','calculated_result_items','role_permissions','admin_users','admin_sessions','admin_profile_overrides','admin_user_activity','pending_admin_requests','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts','exam_flags','newsletter_subscribers','newsletters','newsletter_deliveries','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records','institution_branding'];
$pdo->beginTransaction();
try {
    $insertInstitution = $pdo->prepare('INSERT INTO institutions (id,name,slug,active,created_at) VALUES (:id,:name,:slug,1,UTC_TIMESTAMP(6))');
    $insertInstitution->execute(['id' => $institutionId, 'name' => (string)$snapshot['institution']['name'], 'slug' => $slug]);
    foreach ($tables as $table) foreach ((array)($snapshot['records'][$table] ?? []) as $row) {
        if (!is_array($row) || !$row) continue;
        $columns = array_keys($row); $quoted = array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', $columns);
        $placeholders = []; $parameters = [];
        foreach ($columns as $index => $column) { $key = ':v' . $index; $placeholders[] = $key; $parameters[$key] = $row[$column]; }
        $statement = $pdo->prepare("INSERT INTO {$table} (" . implode(',', $quoted) . ') VALUES (' . implode(',', $placeholders) . ')');
        $statement->execute($parameters);
    }
    $audit = $pdo->prepare("INSERT INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,ip_address,user_agent,before_after_json,metadata_json) VALUES (:id,UTC_TIMESTAMP(6),'platform-superadmin','institution_test_recovered',:institution_id,'institution',:target_id,'success','phase2-final-ui-recovery','127.0.0.1','guarded recovery',JSON_OBJECT(),JSON_OBJECT('sourceBackup',:backup,'reason','Public tenant-routing verification resumed after premature test cleanup'))");
    $audit->execute(['id' => bin2hex(random_bytes(12)), 'institution_id' => $institutionId, 'target_id' => (string)$institutionId, 'backup' => basename($backup)]);
    $pdo->commit();
} catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
$brand = (array)($snapshot['records']['institution_branding'][0] ?? []); $logoPath = (string)($brand['logo_path'] ?? '');
$logoBackup = dirname($backup) . DIRECTORY_SEPARATOR . 'logo-' . basename($logoPath); $logoTarget = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoPath);
if ($logoPath !== '' && is_file($logoBackup) && !is_file($logoTarget)) @copy($logoBackup, $logoTarget);
echo json_encode(['pass' => true, 'institutionId' => $institutionId, 'slug' => $slug, 'brandingRecovered' => $logoPath !== '', 'logoRecovered' => $logoPath === '' || is_file($logoTarget), 'sourceBackup' => 'database/backups/institution-21/' . basename($backup)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
