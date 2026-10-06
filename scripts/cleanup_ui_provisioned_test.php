<?php
declare(strict_types=1);
/* Guarded cleanup for a Super-Admin-created disposable Phase 2 tenant. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

[$script, $slug, $confirmation] = array_pad($argv, 3, '');
if ($slug !== 'test-temp-1' || $confirmation !== 'DELETE-test-temp-1') throw new RuntimeException('This cleanup is restricted to the verified disposable tenant test-temp-1 with its exact confirmation.');
$reason = 'Disposable Step 4 UI provisioning verification completed; removal authorized by platform Super Admin.';
$root = dirname(__DIR__); $pdo = mysqlMigrationPdo($root);
function cleanupHash(PDO $pdo, string $table): string { $statement = $pdo->query("SELECT institution_id,id FROM {$table} WHERE institution_id = 1 ORDER BY id"); return hash('sha256', json_encode($statement->fetchAll(), JSON_UNESCAPED_SLASHES)); }
$cacsaBefore = []; foreach (['students','courses','questions','component_submissions','audit_events'] as $table) $cacsaBefore[$table] = cleanupHash($pdo, $table);
$institution = $pdo->prepare('SELECT id,name,slug FROM institutions WHERE slug = :slug LIMIT 1'); $institution->execute(['slug' => $slug]); $institution = $institution->fetch();
if (!$institution || (int)$institution['id'] < 2) throw new RuntimeException('Disposable institution was not found.');
$institutionId = (int)$institution['id']; $backupDirectory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) throw new RuntimeException('Could not create the cleanup safety-backup directory.');
$tables = ['institution_branding','storage_migrations','academic_sessions','academic_semesters','courses','course_components','students','questions','question_options','question_publish_targets','assessment_passwords','assessment_login_tokens','assessment_attempts','attempt_question_snapshots','attempt_answers','attempt_flags','attempt_integrity_events','component_submissions','calculated_results','calculated_result_items','roles','role_permissions','admin_users','admin_sessions','admin_profile_overrides','admin_user_activity','pending_admin_requests','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts','exam_flags','newsletter_subscribers','newsletters','newsletter_deliveries','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records'];
$snapshot = ['kind' => 'guarded-ui-test-institution-cleanup', 'reason' => $reason, 'institution' => $institution, 'createdAt' => gmdate('c'), 'records' => []];
foreach ($tables as $table) { $statement = $pdo->prepare("SELECT * FROM {$table} WHERE institution_id = :institution_id"); $statement->execute(['institution_id' => $institutionId]); $snapshot['records'][$table] = $statement->fetchAll(); }
$filename = 'guarded-test-temp-1-safety-' . gmdate('Ymd-His') . '.json'; $backupPath = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
if (file_put_contents($backupPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Could not write the safety backup.');
$logoPath = (string)($snapshot['records']['institution_branding'][0]['logo_path'] ?? ''); $logoSource = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoPath);
if ($logoPath !== '' && is_file($logoSource)) @copy($logoSource, $backupDirectory . DIRECTORY_SEPARATOR . 'logo-' . basename($logoSource));
$pdo->beginTransaction();
try {
    $audit = $pdo->prepare("INSERT INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,ip_address,user_agent,before_after_json,metadata_json) VALUES (:id,UTC_TIMESTAMP(6),'platform-superadmin','institution_test_cleanup_started',:institution_id,'institution',:target_id,'success','ui-test-cleanup','127.0.0.1','guarded cleanup',JSON_OBJECT(),JSON_OBJECT('reason',:reason,'safetyBackup',:backup))");
    $audit->execute(['id' => bin2hex(random_bytes(12)), 'institution_id' => $institutionId, 'target_id' => (string)$institutionId, 'reason' => $reason, 'backup' => $filename]);
    foreach (['calculated_result_items','calculated_results','newsletter_deliveries','attempt_answers','attempt_flags','attempt_question_snapshots','attempt_integrity_events','component_submissions','exam_flags','assessment_attempts','assessment_login_tokens','assessment_passwords','question_publish_targets','question_options','questions','role_permissions','admin_sessions','admin_profile_overrides','admin_user_activity','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','pending_admin_requests','audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts','newsletter_subscribers','newsletters','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records','institution_assets','students','course_components','courses','academic_semesters','academic_sessions','admin_users','roles','storage_migrations','institution_branding'] as $table) {
        $delete = $pdo->prepare("DELETE FROM {$table} WHERE institution_id = :institution_id"); $delete->execute(['institution_id' => $institutionId]);
    }
    $deleteInstitution = $pdo->prepare('DELETE FROM institutions WHERE id = :institution_id AND slug = :slug'); $deleteInstitution->execute(['institution_id' => $institutionId, 'slug' => $slug]);
    if ($deleteInstitution->rowCount() !== 1) throw new RuntimeException('Institution deletion was not applied.');
    $audit = $pdo->prepare("INSERT INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,ip_address,user_agent,before_after_json,metadata_json) VALUES (:id,UTC_TIMESTAMP(6),'platform-superadmin','institution_test_cleanup_completed',NULL,'deleted_test_institution',:target_id,'success','ui-test-cleanup','127.0.0.1','guarded cleanup',JSON_OBJECT(),JSON_OBJECT('reason',:reason,'safetyBackup',:backup))");
    $audit->execute(['id' => bin2hex(random_bytes(12)), 'target_id' => (string)$institutionId, 'reason' => $reason, 'backup' => $filename]);
    $pdo->commit();
} catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
if (str_starts_with(basename($logoSource), 'test-temp-1-') && is_file($logoSource)) @unlink($logoSource);
$cacsaAfter = []; foreach (array_keys($cacsaBefore) as $table) $cacsaAfter[$table] = cleanupHash($pdo, $table);
foreach ($cacsaBefore as $table => $hash) if (!hash_equals($hash, $cacsaAfter[$table])) throw new RuntimeException("CACSA {$table} changed during test cleanup.");
$exists = $pdo->prepare('SELECT COUNT(*) FROM institutions WHERE id = :institution_id'); $exists->execute(['institution_id' => $institutionId]);
$auditCheck = $pdo->prepare("SELECT COUNT(*) FROM platform_audit_events WHERE action_type = 'institution_test_cleanup_completed' AND target_id = :target_id AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.safetyBackup')) = :backup"); $auditCheck->execute(['target_id' => (string)$institutionId, 'backup' => $filename]);
echo json_encode(['pass' => (int)$exists->fetchColumn() === 0 && (int)$auditCheck->fetchColumn() === 1, 'institutionId' => $institutionId, 'safetyBackup' => 'database/backups/institution-' . $institutionId . '/' . $filename, 'reasonLogged' => true, 'platformAuditLogged' => true, 'cacsaHashes' => 'unchanged', 'logoRemoved' => !is_file($logoSource)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
