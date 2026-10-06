<?php
declare(strict_types=1);

/** Guarded cleanup for the Stage 1 disposable performance tenant only. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$slug = $argv[1] ?? '';
$reason = 'Stage 1 targeted-transaction and load-test verification completed; disposable tenant removal authorized.';
if (!is_string($slug) || !preg_match('/^perf-stage1-[a-z0-9-]+$/', $slug)) {
    fwrite(STDERR, "Refusing: cleanup accepts only a perf-stage1-* disposable slug.\n"); exit(2);
}
$root = dirname(__DIR__); $pdo = mysqlMigrationPdo($root);
$find = $pdo->prepare('SELECT id,name,slug FROM institutions WHERE slug = :slug AND id <> 1 AND deleted_at IS NULL');
$find->execute(['slug' => $slug]); $institution = $find->fetch(PDO::FETCH_ASSOC);
if (!$institution) { fwrite(STDERR, "Refusing: active disposable tenant was not found.\n"); exit(2); }
$institutionId = (int)$institution['id'];

$tables = [
    'academic_sessions','academic_semesters','courses','course_components','students','questions','question_options','question_publish_targets',
    'assessment_passwords','assessment_login_tokens','assessment_attempts','attempt_question_snapshots','attempt_answers','attempt_flags','attempt_integrity_events',
    'component_submissions','calculated_results','calculated_result_items','roles','role_permissions','admin_users','admin_sessions','admin_profile_overrides',
    'admin_user_activity','pending_admin_requests','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes',
    'audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts','exam_flags','newsletter_subscribers','newsletters',
    'newsletter_deliveries','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records','storage_migrations','institution_branding',
];
$snapshot = ['kind' => 'stage1-performance-test-safety-backup', 'reason' => $reason, 'createdAt' => gmdate('c'), 'institution' => $institution, 'tables' => []];
foreach ($tables as $table) {
    $select = $pdo->prepare("SELECT * FROM {$table} WHERE institution_id = :institution_id");
    $select->execute(['institution_id' => $institutionId]); $snapshot['tables'][$table] = $select->fetchAll(PDO::FETCH_ASSOC);
}
$directory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Could not create the disposable-test safety-backup directory.');
$filename = 'stage1-performance-test-safety_' . gmdate('Y-m-d_His') . '.json';
$path = $directory . DIRECTORY_SEPARATOR . $filename;
if (file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Could not write the safety backup.');

$pdo->beginTransaction();
try {
    $audit = $pdo->prepare('INSERT INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,before_after_json,metadata_json) VALUES (:id,UTC_TIMESTAMP(6),:actor,:action,:institution_id,:target_type,:target_id,:outcome,:correlation,:before_after,:metadata)');
    $audit->execute(['id' => 'stage1-cleanup-' . bin2hex(random_bytes(8)), 'actor' => 'stage1-performance-verifier', 'action' => 'disposable_institution_cleanup', 'institution_id' => $institutionId, 'target_type' => 'institution', 'target_id' => $slug, 'outcome' => 'success', 'correlation' => 'stage1-' . $slug, 'before_after' => json_encode(['before' => ['institutionId' => $institutionId, 'slug' => $slug], 'after' => ['deleted' => true]], JSON_THROW_ON_ERROR), 'metadata' => json_encode(['reason' => $reason, 'safetyBackupFilename' => $filename], JSON_THROW_ON_ERROR)]);
    foreach ([
        'calculated_result_items','calculated_results','newsletter_deliveries','attempt_answers','attempt_flags','attempt_question_snapshots','attempt_integrity_events',
        'component_submissions','exam_flags','assessment_attempts','assessment_login_tokens','assessment_passwords','question_publish_targets','question_options',
        'questions','role_permissions','admin_sessions','admin_profile_overrides','admin_user_activity','admin_email_verifications','admin_password_resets',
        'admin_two_factor_challenges','emergency_recovery_codes','pending_admin_requests','audit_events','audit_archives','rate_limit_records',
        'exam_login_failures','exam_login_ip_attempts','newsletter_subscribers','newsletters','grading_scale_bands','integrity_policies','institution_settings',
        'dashboard_hidden_outcomes','backup_records','storage_migrations','admin_users','course_components','courses','academic_semesters','academic_sessions','students',
        'roles','institution_branding',
    ] as $table) {
        $delete = $pdo->prepare("DELETE FROM {$table} WHERE institution_id = :institution_id"); $delete->execute(['institution_id' => $institutionId]);
    }
    $deleteInstitution = $pdo->prepare('DELETE FROM institutions WHERE id = :id AND slug = :slug AND id <> 1');
    $deleteInstitution->execute(['id' => $institutionId, 'slug' => $slug]);
    if ($deleteInstitution->rowCount() !== 1) throw new RuntimeException('Disposable tenant was not deleted; transaction will roll back.');
    $pdo->commit();
} catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
echo json_encode(['pass' => true, 'slug' => $slug, 'institutionId' => $institutionId, 'safetyBackup' => str_replace($root . DIRECTORY_SEPARATOR, '', $path), 'reason' => $reason], JSON_UNESCAPED_SLASHES) . PHP_EOL;
