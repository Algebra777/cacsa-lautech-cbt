<?php
declare(strict_types=1);

/* Guarded, one-purpose removal of the final Phase 2 UI verification tenant. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

[$script, $slug, $confirmation] = array_pad($argv, 3, '');
if ($slug !== 'phase2-final-ui-test' || $confirmation !== 'DELETE-phase2-final-ui-test') throw new RuntimeException('This cleanup is restricted to phase2-final-ui-test with its exact confirmation.');
$reason = 'Disposable final Phase 2 UI provisioning and isolation verification completed; removal authorized by platform Super Admin.';
$root = dirname(__DIR__); $pdo = mysqlMigrationPdo($root);
$institution = $pdo->prepare('SELECT id,name,slug FROM institutions WHERE slug = :slug LIMIT 1'); $institution->execute(['slug' => $slug]); $institution = $institution->fetch();
if (!$institution || (int)$institution['id'] < 2) throw new RuntimeException('Final disposable institution was not found.');
$institutionId = (int)$institution['id'];
$directory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Could not create the cleanup safety-backup directory.');
$tables = ['institution_branding','storage_migrations','academic_sessions','academic_semesters','courses','course_components','students','questions','question_options','question_publish_targets','assessment_passwords','assessment_login_tokens','assessment_attempts','attempt_question_snapshots','attempt_answers','attempt_flags','attempt_integrity_events','component_submissions','calculated_results','calculated_result_items','roles','role_permissions','admin_users','admin_sessions','admin_profile_overrides','admin_user_activity','pending_admin_requests','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts','exam_flags','newsletter_subscribers','newsletters','newsletter_deliveries','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records'];
$snapshot = ['kind' => 'guarded-phase2-final-ui-test-cleanup', 'reason' => $reason, 'institution' => $institution, 'createdAt' => gmdate('c'), 'records' => []];
foreach ($tables as $table) { $statement = $pdo->prepare("SELECT * FROM {$table} WHERE institution_id = :institution_id"); $statement->execute(['institution_id' => $institutionId]); $snapshot['records'][$table] = $statement->fetchAll(); }
$filename = 'guarded-phase2-final-ui-test-safety-' . gmdate('Ymd-His') . '.json';
$path = $directory . DIRECTORY_SEPARATOR . $filename;
if (file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Could not write the safety backup.');
$logoPath = (string)($snapshot['records']['institution_branding'][0]['logo_path'] ?? '');
$logo = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoPath);
if ($logoPath !== '' && is_file($logo)) @copy($logo, $directory . DIRECTORY_SEPARATOR . 'logo-' . basename($logo));

$pdo->beginTransaction();
try {
    $log = $pdo->prepare("INSERT INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,ip_address,user_agent,before_after_json,metadata_json) VALUES (:id,UTC_TIMESTAMP(6),'platform-superadmin','institution_test_cleanup_started',:institution_id,'institution',:target_id,'success','phase2-final-ui-cleanup','127.0.0.1','guarded cleanup',JSON_OBJECT(),JSON_OBJECT('reason',:reason,'safetyBackup',:backup))");
    $log->execute(['id' => bin2hex(random_bytes(12)), 'institution_id' => $institutionId, 'target_id' => (string)$institutionId, 'reason' => $reason, 'backup' => $filename]);
    foreach (['calculated_result_items','calculated_results','newsletter_deliveries','attempt_answers','attempt_flags','attempt_question_snapshots','attempt_integrity_events','component_submissions','exam_flags','assessment_attempts','assessment_login_tokens','assessment_passwords','question_publish_targets','question_options','questions','role_permissions','admin_sessions','admin_profile_overrides','admin_user_activity','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','pending_admin_requests','audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts','newsletter_subscribers','newsletters','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records','institution_assets','students','course_components','courses','academic_semesters','academic_sessions','admin_users','roles','storage_migrations','institution_branding'] as $table) {
        $delete = $pdo->prepare("DELETE FROM {$table} WHERE institution_id = :institution_id"); $delete->execute(['institution_id' => $institutionId]);
    }
    $deleteInstitution = $pdo->prepare('DELETE FROM institutions WHERE id = :institution_id AND slug = :slug'); $deleteInstitution->execute(['institution_id' => $institutionId, 'slug' => $slug]);
    if ($deleteInstitution->rowCount() !== 1) throw new RuntimeException('Institution deletion was not applied.');
    $log = $pdo->prepare("INSERT INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,ip_address,user_agent,before_after_json,metadata_json) VALUES (:id,UTC_TIMESTAMP(6),'platform-superadmin','institution_test_cleanup_completed',NULL,'deleted_test_institution',:target_id,'success','phase2-final-ui-cleanup','127.0.0.1','guarded cleanup',JSON_OBJECT(),JSON_OBJECT('reason',:reason,'safetyBackup',:backup))");
    $log->execute(['id' => bin2hex(random_bytes(12)), 'target_id' => (string)$institutionId, 'reason' => $reason, 'backup' => $filename]);
    $pdo->commit();
} catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
if (str_starts_with(basename($logo), 'phase2-final-ui-test-') && is_file($logo)) @unlink($logo);
$remaining = $pdo->prepare('SELECT COUNT(*) FROM institutions WHERE id = :institution_id'); $remaining->execute(['institution_id' => $institutionId]);
$audit = $pdo->prepare("SELECT COUNT(*) FROM platform_audit_events WHERE action_type = 'institution_test_cleanup_completed' AND target_id = :target_id AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.safetyBackup')) = :backup"); $audit->execute(['target_id' => (string)$institutionId, 'backup' => $filename]);
echo json_encode(['pass' => (int)$remaining->fetchColumn() === 0 && (int)$audit->fetchColumn() === 1, 'institutionId' => $institutionId, 'safetyBackup' => 'database/backups/institution-' . $institutionId . '/' . $filename, 'reasonLogged' => true, 'platformAuditLogged' => true, 'logoRemoved' => !is_file($logo)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
