<?php
declare(strict_types=1);

/* Guarded cleanup for a disposable Phase 2 provisioning regression tenant. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

[$script, $slug, $confirmation] = array_pad($argv, 3, '');
if (!preg_match('/^phase2-provisioning-test-[0-9]{14}$/', $slug) || !hash_equals('DELETE-' . $slug, $confirmation)) {
    throw new RuntimeException('Cleanup is restricted to one exact disposable phase2-provisioning-test slug and matching DELETE confirmation.');
}
$root = dirname(__DIR__); $pdo = mysqlMigrationPdo($root);
$institutionQuery = $pdo->prepare('SELECT id,name,slug FROM institutions WHERE slug=:slug AND deleted_at IS NULL LIMIT 1');
$institutionQuery->execute(['slug' => $slug]); $institution = $institutionQuery->fetch();
if (!$institution || (int)$institution['id'] < 2 || (string)$institution['name'] !== 'Phase 2 Provisioning Test') throw new RuntimeException('The guarded disposable institution was not found.');
$institutionId = (int)$institution['id'];
function provisioningCleanupHash(PDO $pdo, string $table): string {
    $statement = $pdo->query("SELECT institution_id,id FROM {$table} WHERE institution_id=1 ORDER BY id");
    return hash('sha256', json_encode($statement->fetchAll(), JSON_UNESCAPED_SLASHES));
}
$cacsaBefore = []; foreach (['students','courses','questions','component_submissions','audit_events'] as $table) $cacsaBefore[$table] = provisioningCleanupHash($pdo, $table);
$directory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Could not create the guarded cleanup safety-backup directory.');
$tables = ['institution_branding','institution_assets','storage_migrations','academic_sessions','academic_semesters','courses','course_components','students','questions','question_options','question_publish_targets','assessment_passwords','assessment_login_tokens','assessment_attempts','attempt_question_snapshots','attempt_answers','attempt_flags','attempt_integrity_events','component_submissions','calculated_results','calculated_result_items','roles','role_permissions','admin_users','admin_sessions','admin_profile_overrides','admin_user_activity','pending_admin_requests','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts','exam_flags','newsletter_subscribers','newsletters','newsletter_deliveries','pdf_import_jobs','algebra_requests','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records'];
$snapshot = ['kind'=>'guarded-phase2-provisioning-test-cleanup','reason'=>'Failed disposable regression cleanup; no production tenant targeted.','institution'=>$institution,'createdAt'=>gmdate('c'),'records'=>[]];
foreach ($tables as $table) { $statement = $pdo->prepare("SELECT * FROM {$table} WHERE institution_id=:institution_id"); $statement->execute(['institution_id'=>$institutionId]); $snapshot['records'][$table] = $statement->fetchAll(); }
$filename = 'guarded-phase2-provisioning-test-safety-' . gmdate('Ymd-His') . '.json';
$backupPath = $directory . DIRECTORY_SEPARATOR . $filename;
if (file_put_contents($backupPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Could not write the cleanup safety backup.');
$logoPath = (string)($snapshot['records']['institution_branding'][0]['logo_path'] ?? '');
$logo = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoPath);
if ($logoPath !== '' && is_file($logo)) @copy($logo, $directory . DIRECTORY_SEPARATOR . 'logo-' . basename($logo));
$pdo->beginTransaction();
try {
    $detatchAudit = $pdo->prepare("UPDATE platform_audit_events SET target_institution_id=NULL,target_type='deleted_test_institution',metadata_json=JSON_SET(COALESCE(metadata_json,JSON_OBJECT()), '$.cleanupReason','Failed disposable provisioning regression cleanup', '$.safetyBackup',:backup) WHERE target_institution_id=:institution_id");
    $detatchAudit->execute(['backup'=>$filename,'institution_id'=>$institutionId]);
    foreach (['calculated_result_items','calculated_results','newsletter_deliveries','attempt_answers','attempt_flags','attempt_question_snapshots','attempt_integrity_events','component_submissions','exam_flags','assessment_attempts','assessment_login_tokens','assessment_passwords','question_publish_targets','question_options','questions','role_permissions','admin_sessions','admin_profile_overrides','admin_user_activity','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','pending_admin_requests','audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts','newsletter_subscribers','newsletters','pdf_import_jobs','algebra_requests','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records','institution_assets','students','course_components','courses','academic_semesters','academic_sessions','admin_users','roles','storage_migrations','institution_branding'] as $table) {
        $delete = $pdo->prepare("DELETE FROM {$table} WHERE institution_id=:institution_id"); $delete->execute(['institution_id'=>$institutionId]);
    }
    $deleteInstitution = $pdo->prepare('DELETE FROM institutions WHERE id=:institution_id AND slug=:slug'); $deleteInstitution->execute(['institution_id'=>$institutionId,'slug'=>$slug]);
    if ($deleteInstitution->rowCount() !== 1) throw new RuntimeException('Disposable institution deletion did not apply.');
    $audit = $pdo->prepare("INSERT INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,ip_address,user_agent,before_after_json,metadata_json) VALUES (:id,UTC_TIMESTAMP(6),'platform-superadmin','institution_test_cleanup_completed',NULL,'deleted_test_institution',:target_id,'success','phase2-provisioning-cleanup','127.0.0.1','guarded cleanup',JSON_OBJECT(),JSON_OBJECT('reason','Failed disposable provisioning regression cleanup','safetyBackup',:backup))");
    $audit->execute(['id'=>bin2hex(random_bytes(12)),'target_id'=>(string)$institutionId,'backup'=>$filename]);
    $pdo->commit();
} catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
if (str_starts_with(basename($logo), 'phase2-provisioning-test-') && is_file($logo)) @unlink($logo);
$cacsaAfter = []; foreach (array_keys($cacsaBefore) as $table) $cacsaAfter[$table] = provisioningCleanupHash($pdo, $table);
foreach ($cacsaBefore as $table=>$hash) if (!hash_equals($hash, $cacsaAfter[$table])) throw new RuntimeException("CACSA {$table} changed during disposable cleanup.");
echo json_encode(['pass'=>true,'institutionId'=>$institutionId,'safetyBackup'=>'database/backups/institution-'.$institutionId.'/'.$filename,'cacsaHashes'=>'unchanged'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
