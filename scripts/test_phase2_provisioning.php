<?php
declare(strict_types=1);

/*
 * Phase 2 / Step 4 endpoint smoke test.
 *
 * It uses a disposable platform-session record only to exercise the same HTTP
 * endpoint that the Super Admin screen calls. No platform password is read or
 * changed. The tenant itself is created by multipart POST, signed in through
 * its tenant route, forced to change its initial password, then removed with a
 * test-only safety export. Platform audit history is retained: its FK target
 * is nulled before the disposable institution is removed.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$pdo = mysqlMigrationPdo($root);
$slug = 'phase2-provisioning-test-' . gmdate('Ymdhis');
$platformToken = bin2hex(random_bytes(32));
$platformTokenHash = hash('sha256', $platformToken);
$platform = $pdo->query("SELECT id,email,name FROM platform_admin_users WHERE id = 'platform-superadmin' AND active = 1 LIMIT 1")->fetch();
if (!$platform) throw new RuntimeException('Active platform Super Admin account is unavailable for the Step 4 smoke test.');
$createdInstitutionId = 0;
$tenantTokenHash = '';

function provisioningHttp(string $url, string $method = 'GET', array $cookies = [], ?array $json = null, ?array $multipart = null): array {
    $curl = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($cookies) $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($name, $value): string => $name . '=' . rawurlencode($value), array_keys($cookies), $cookies));
    if ($method !== 'GET' && !empty($cookies['CBT_CSRF'])) $headers[] = 'X-CSRF-Token: ' . $cookies['CBT_CSRF'];
    if ($json !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
    if ($multipart !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 25]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $headerBytes = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE); curl_close($curl);
    $responseBody = substr($raw, $headerBytes);
    return ['status' => $status, 'body' => json_decode($responseBody, true) ?: [], 'raw' => $responseBody];
}
function provisioningCsrf(string $base, array $cookies): array {
    $response = provisioningHttp($base . 'auth-csrf', 'GET', $cookies);
    if ($response['status'] !== 200 || empty($response['body']['csrfToken'])) throw new RuntimeException('Could not establish a CSRF token for provisioning test.');
    return $cookies + ['CBT_CSRF' => (string)$response['body']['csrfToken']];
}
function provisioningDelete(PDO $pdo, int $institutionId, string $slug, string $root): void {
    if ($institutionId < 2 || !str_starts_with($slug, 'phase2-provisioning-test-')) throw new RuntimeException('Refusing an unsafe test cleanup target.');
    $dir = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Could not create the provisioning-test safety-backup directory.');
    $snapshot = ['kind' => 'phase2-provisioning-test-safety-backup', 'reason' => 'Disposable Step 4 provisioning verification cleanup', 'institutionId' => $institutionId, 'slug' => $slug, 'createdAt' => gmdate('c')];
    foreach (['institutions','institution_branding','roles','admin_users','academic_sessions','grading_scale_bands','integrity_policies','institution_settings'] as $table) {
        $where = $table === 'institutions' ? 'id = :institution_id' : 'institution_id = :institution_id';
        $statement = $pdo->prepare("SELECT * FROM {$table} WHERE {$where}"); $statement->execute(['institution_id' => $institutionId]); $snapshot[$table] = $statement->fetchAll();
    }
    $backupFile = 'phase2-provisioning-test-safety-' . gmdate('Ymd-His') . '.json';
    file_put_contents($dir . DIRECTORY_SEPARATOR . $backupFile, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    $pdo->beginTransaction();
    try {
        // Retain the platform audit history while removing its restrictive FK.
        $audit = $pdo->prepare("UPDATE platform_audit_events SET target_institution_id = NULL, target_type = 'deleted_test_institution', metadata_json = JSON_SET(COALESCE(metadata_json, JSON_OBJECT()), '$.cleanupReason', 'Disposable Step 4 provisioning verification cleanup', '$.safetyBackup', :backup) WHERE target_institution_id = :institution_id");
        $audit->execute(['backup' => $backupFile, 'institution_id' => $institutionId]);
        foreach (['calculated_result_items','calculated_results','newsletter_deliveries','attempt_answers','attempt_flags','attempt_question_snapshots','attempt_integrity_events','component_submissions','exam_flags','assessment_attempts','assessment_login_tokens','assessment_passwords','question_publish_targets','question_options','questions','role_permissions','admin_sessions','admin_profile_overrides','admin_user_activity','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','pending_admin_requests','audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts','newsletter_subscribers','newsletters','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records','students','course_components','courses','academic_semesters','academic_sessions','admin_users','roles','storage_migrations','institution_branding'] as $table) {
            $delete = $pdo->prepare("DELETE FROM {$table} WHERE institution_id = :institution_id"); $delete->execute(['institution_id' => $institutionId]);
        }
        $delete = $pdo->prepare('DELETE FROM institutions WHERE id = :institution_id AND slug = :slug'); $delete->execute(['institution_id' => $institutionId, 'slug' => $slug]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('Disposable provisioning institution was not removed.');
        $log = $pdo->prepare("INSERT INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,ip_address,user_agent,before_after_json,metadata_json) VALUES (:id,UTC_TIMESTAMP(6),'phase2-test','institution_test_cleanup',NULL,'deleted_test_institution',:target_id,'success','phase2-provisioning-test','127.0.0.1','test runner',JSON_OBJECT(),JSON_OBJECT('reason','Disposable Step 4 provisioning verification cleanup','safetyBackup',:backup))");
        $log->execute(['id' => bin2hex(random_bytes(12)), 'target_id' => (string)$institutionId, 'backup' => $backupFile]);
        $pdo->commit();
        foreach ((array)($snapshot['institution_branding'] ?? []) as $brand) {
            $path = (string)($brand['logo_path'] ?? ''); $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (basename($file) !== '' && str_starts_with(basename($file), 'phase2-provisioning-test-') && is_file($file)) @unlink($file);
        }
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

try {
    $now = gmdate('Y-m-d H:i:s.u');
    $insert = $pdo->prepare('INSERT INTO platform_admin_sessions (token_hash,user_id,email,name,correlation_id,created_at,last_seen_at,expires_at) VALUES (:token_hash,:user_id,:email,:name,:correlation_id,:created_at,:last_seen_at,:expires_at)');
    $insert->execute(['token_hash' => $platformTokenHash, 'user_id' => $platform['id'], 'email' => $platform['email'], 'name' => $platform['name'], 'correlation_id' => 'phase2-provisioning-test', 'created_at' => $now, 'last_seen_at' => $now, 'expires_at' => '2099-12-31 23:59:59.000000']);
    $base = 'http://localhost/BEREVION/api.php?action=';
    $platformCookies = provisioningCsrf($base, ['CBT_ADMIN_SESSION' => $platformToken]);
    $logo = $root . DIRECTORY_SEPARATOR . 'CACSA Logo.jpeg';
    $response = provisioningHttp($base . 'platform-institutions', 'POST', $platformCookies, null, ['name' => 'Phase 2 Provisioning Test', 'adminEmail' => $slug . '@example.invalid', 'slug' => $slug, 'logo' => new CURLFile($logo, 'image/jpeg', 'test-logo.jpg'), 'CBT_CSRF' => $platformCookies['CBT_CSRF']]);
    if ($response['status'] !== 201) throw new RuntimeException('Provisioning endpoint failed: ' . json_encode($response['body'], JSON_UNESCAPED_SLASHES));
    $createdInstitutionId = (int)($response['body']['institution']['id'] ?? 0); $temporaryPassword = (string)($response['body']['initialAdmin']['temporaryPassword'] ?? '');
    if ($createdInstitutionId < 2 || $temporaryPassword === '') throw new RuntimeException('Provisioning endpoint did not return the required initial Admin credentials.');
    $checks = [];
    foreach (['institution_branding' => 1, 'roles' => 3, 'grading_scale_bands' => 6, 'integrity_policies' => 6, 'institution_settings' => 5, 'academic_sessions' => 1, 'admin_users' => 1] as $table => $expected) {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE institution_id = :institution_id"); $statement->execute(['institution_id' => $createdInstitutionId]);
        if ((int)$statement->fetchColumn() !== $expected) throw new RuntimeException("{$table} seed count did not match.");
        $checks[$table] = 'seeded';
    }
    $forced = $pdo->prepare('SELECT must_change_password FROM admin_users WHERE institution_id = :institution_id AND role_id = \'admin\''); $forced->execute(['institution_id' => $createdInstitutionId]);
    if ((int)$forced->fetchColumn() !== 1) throw new RuntimeException('Initial Admin was not marked for forced password change.'); $checks['initial-admin'] = 'forced-password-change';
    $tenantBase = 'http://localhost/BEREVION/i/' . rawurlencode($slug) . '/api.php?action=';
    $tenantPortal = provisioningHttp('http://localhost/BEREVION/i/' . rawurlencode($slug) . '/');
    if ($tenantPortal['status'] !== 200 || !str_contains((string)$tenantPortal['raw'], '<div id="app"')) throw new RuntimeException('New tenant public portal root was not routed to the application shell.');
    $brand = provisioningHttp($tenantBase . 'branding');
    if ($brand['status'] !== 200 || ($brand['body']['branding']['displayName'] ?? '') !== 'Phase 2 Provisioning Test') throw new RuntimeException('New tenant branding was not served from the provisioned record.'); $checks['branding'] = 'served'; $checks['tenant-public-portal-root'] = 'served';
    // Use the same multipart endpoint as the Super Admin editor. Pure red is
    // intentionally inaccessible on the dark background, so this verifies
    // server-side acknowledgement plus the stored light/dark derived values.
    $brandingPayload = [
        'operation' => 'update-branding', 'institutionId' => (string)$createdInstitutionId, 'name' => 'Phase 2 Provisioning Test', 'displayName' => 'Phase 2 Branding Review',
        'portalTitle' => 'Phase 2 Branding Review CBT', 'navLabel' => 'Review CBT', 'assessmentLabel' => 'Assessment centre', 'resultSheetTitle' => 'Review CBT',
        'newsletterSenderName' => 'Phase 2 Review', 'supportEmail' => $slug . '@example.invalid', 'footerPrimary' => 'Review footer one', 'footerSecondary' => 'Review footer two', 'footerLegal' => '© {year} Phase 2 Review.', 'accentColor' => '#ff0000', 'CBT_CSRF' => $platformCookies['CBT_CSRF']
    ];
    $unacknowledged = provisioningHttp($base . 'platform-institutions', 'POST', $platformCookies, null, $brandingPayload);
    if ($unacknowledged['status'] !== 422) throw new RuntimeException('Branding editor accepted an unacknowledged inaccessible source accent.');
    $brandingPayload['accentAcknowledged'] = '1';
    $brandingUpdate = provisioningHttp($base . 'platform-institutions', 'POST', $platformCookies, null, $brandingPayload);
    if ($brandingUpdate['status'] !== 200 || ($brandingUpdate['body']['accent']['source'] ?? '') !== '#ff0000' || ($brandingUpdate['body']['accent']['darkContrast'] ?? 0) < 4.5) throw new RuntimeException('Branding editor did not save the acknowledged accessible accent profile.');
    $brandAfter = provisioningHttp($tenantBase . 'branding');
    if ($brandAfter['status'] !== 200 || ($brandAfter['body']['branding']['displayName'] ?? '') !== 'Phase 2 Branding Review' || ($brandAfter['body']['branding']['accentSourceColor'] ?? '') !== '#ff0000' || ($brandAfter['body']['branding']['primaryColor'] ?? '') === '#ff0000') throw new RuntimeException('Tenant branding response did not return the adjusted branding values.');
    $audit = $pdo->prepare("SELECT before_after_json FROM platform_audit_events WHERE target_institution_id = :institution_id AND action_type = 'institution_branding_updated' ORDER BY timestamp_at DESC LIMIT 1"); $audit->execute(['institution_id' => $createdInstitutionId]);
    $beforeAfter = json_decode((string)$audit->fetchColumn(), true);
    if (($beforeAfter['before']['display_name'] ?? '') !== 'Phase 2 Provisioning Test' || ($beforeAfter['after']['display_name'] ?? '') !== 'Phase 2 Branding Review') throw new RuntimeException('Branding editor audit event does not contain the required before/after summary.');
    $checks['branding-editor'] = 'blocked-unacknowledged-adjustment-then-saved-derived-accent-with-audit';
    $countBeforeSuspend = [];
    foreach (['students','courses','questions','component_submissions','audit_events','backup_records'] as $table) { $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE institution_id = :institution_id"); $count->execute(['institution_id' => $createdInstitutionId]); $countBeforeSuspend[$table] = (int)$count->fetchColumn(); }
    $suspend = provisioningHttp($base . 'platform-institutions', 'POST', $platformCookies, null, ['operation' => 'set-status', 'institutionId' => (string)$createdInstitutionId, 'active' => '0', 'CBT_CSRF' => $platformCookies['CBT_CSRF']]);
    if ($suspend['status'] !== 200 || ($suspend['body']['item']['active'] ?? true) !== false) throw new RuntimeException('Suspend operation did not mark the disposable institution inactive.');
    $suspendedStudent = provisioningHttp($tenantBase . 'exams&active=true');
    $suspendedAdmin = provisioningHttp($tenantBase . 'admin-login', 'POST', [], ['email' => $slug . '@example.invalid', 'password' => 'ignored']);
    if ($suspendedStudent['status'] !== 403 || empty($suspendedStudent['body']['institutionSuspended']) || $suspendedAdmin['status'] !== 403 || empty($suspendedAdmin['body']['institutionSuspended'])) throw new RuntimeException('Suspension did not block both student and administrator access with the suspended-institution response.');
    foreach ($countBeforeSuspend as $table => $expected) { $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE institution_id = :institution_id"); $count->execute(['institution_id' => $createdInstitutionId]); if ((int)$count->fetchColumn() !== $expected) throw new RuntimeException("Suspend operation altered {$table} data."); }
    $reactivate = provisioningHttp($base . 'platform-institutions', 'POST', $platformCookies, null, ['operation' => 'set-status', 'institutionId' => (string)$createdInstitutionId, 'active' => '1', 'CBT_CSRF' => $platformCookies['CBT_CSRF']]);
    if ($reactivate['status'] !== 200 || ($reactivate['body']['item']['active'] ?? false) !== true || provisioningHttp($tenantBase . 'exams&active=true')['status'] !== 200) throw new RuntimeException('Reactivate operation did not restore tenant access.');
    $statusAudit = $pdo->prepare("SELECT COUNT(*) FROM platform_audit_events WHERE target_institution_id = :institution_id AND action_type IN ('institution_suspended','institution_reactivated')"); $statusAudit->execute(['institution_id' => $createdInstitutionId]);
    if ((int)$statusAudit->fetchColumn() !== 2) throw new RuntimeException('Suspend/reactivate audit events were not recorded.');
    $checks['suspend-reactivate'] = 'student-and-admin-blocked-data-unchanged-reactivated-and-audited';
    $loginCookies = provisioningCsrf($tenantBase, []);
    $login = provisioningHttp($tenantBase . 'admin-login', 'POST', $loginCookies, ['email' => $slug . '@example.invalid', 'password' => $temporaryPassword]);
    if ($login['status'] !== 200 || empty($login['body']['user']['mustChangePassword'])) throw new RuntimeException('New Admin did not enter the forced-password-change flow.');
    // The cookie is set by the real login response; for a deterministic CLI
    // check we retrieve the persisted token hash and use a fresh test token
    // only after verifying that the actual login route accepted the password.
    $tenantToken = bin2hex(random_bytes(32)); $tenantTokenHash = hash('sha256', $tenantToken);
    $admin = $pdo->prepare('SELECT id,email,name FROM admin_users WHERE institution_id = :institution_id AND role_id = \'admin\''); $admin->execute(['institution_id' => $createdInstitutionId]); $adminRow = $admin->fetch();
    $session = $pdo->prepare('INSERT INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at) VALUES (:institution_id,:token_hash,:user_id,:email,:name,\'admin\',:permissions,:correlation,:created_at,:last_seen_at,:expires_at)');
    $session->execute(['institution_id' => $createdInstitutionId, 'token_hash' => $tenantTokenHash, 'user_id' => $adminRow['id'], 'email' => $adminRow['email'], 'name' => $adminRow['name'], 'permissions' => json_encode(['overview','students','exams','questions','results','audit','newsletter','settings','roles']), 'correlation' => 'phase2-provisioning-test-tenant', 'created_at' => $now, 'last_seen_at' => $now, 'expires_at' => '2099-12-31 23:59:59.000000']);
    $tenantCookies = provisioningCsrf($tenantBase, ['CBT_ADMIN_SESSION' => $tenantToken]);
    $change = provisioningHttp($tenantBase . 'admin-account', 'POST', $tenantCookies, ['operation' => 'change-password', 'currentPassword' => $temporaryPassword, 'newPassword' => 'Phase2-Verified-Password!42']);
    if ($change['status'] !== 200) throw new RuntimeException('Initial Admin password change failed.');
    foreach (['dashboard','students','exams','questions','results','settings','audit-monitor'] as $endpoint) {
        $page = provisioningHttp($tenantBase . $endpoint, 'GET', ['CBT_ADMIN_SESSION' => $tenantToken]);
        if ($page['status'] !== 200) throw new RuntimeException("New Admin menu endpoint {$endpoint} failed with {$page['status']}.");
    }
    $checks['tenant-admin-login'] = 'accepted'; $checks['tenant-menu-endpoints'] = 'all-200';
    $softDeleteCounts = [];
    foreach (['students','courses','questions','assessment_attempts','component_submissions','audit_events'] as $table) { $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE institution_id = :institution_id"); $count->execute(['institution_id' => $createdInstitutionId]); $softDeleteCounts[$table] = (int)$count->fetchColumn(); }
    $badDelete = provisioningHttp($base . 'platform-institutions', 'POST', $platformCookies, null, ['operation'=>'soft-delete','institutionId'=>(string)$createdInstitutionId,'reason'=>'Disposable verification of guarded soft deletion.','confirmation'=>'wrong-confirmation','CBT_CSRF'=>$platformCookies['CBT_CSRF']]);
    if ($badDelete['status'] !== 422) throw new RuntimeException('Soft delete accepted an incorrect typed confirmation.');
    $softDelete = provisioningHttp($base . 'platform-institutions', 'POST', $platformCookies, null, ['operation'=>'soft-delete','institutionId'=>(string)$createdInstitutionId,'reason'=>'Disposable verification of guarded soft deletion.','confirmation'=>'Phase 2 Provisioning Test','CBT_CSRF'=>$platformCookies['CBT_CSRF']]);
    $backupFile = (string)($softDelete['body']['item']['safetyBackup'] ?? '');
    if ($softDelete['status'] !== 200 || $backupFile === '' || !is_file($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $createdInstitutionId . DIRECTORY_SEPARATOR . $backupFile)) throw new RuntimeException('Soft delete did not create its required safety backup.');
    $listAfterDelete = provisioningHttp($base . 'platform-institutions', 'GET', $platformCookies);
    if ($listAfterDelete['status'] !== 200 || array_filter((array)($listAfterDelete['body']['items'] ?? []), static fn(array $item): bool => (int)($item['id'] ?? 0) === $createdInstitutionId)) throw new RuntimeException('Soft-deleted institution remained in normal platform views.');
    if (provisioningHttp($tenantBase . 'exams&active=true')['status'] !== 404) throw new RuntimeException('Soft-deleted institution portal remained accessible.');
    foreach ($softDeleteCounts as $table => $expected) { $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE institution_id = :institution_id"); $count->execute(['institution_id' => $createdInstitutionId]); if ((int)$count->fetchColumn() !== $expected) throw new RuntimeException("Soft delete altered {$table} records."); }
    $deleted = $pdo->prepare('SELECT active,deleted_at,retention_until FROM institutions WHERE id = :institution_id'); $deleted->execute(['institution_id'=>$createdInstitutionId]); $deletedRow = $deleted->fetch();
    if (!$deletedRow || (int)$deletedRow['active'] !== 0 || !$deletedRow['deleted_at'] || !$deletedRow['retention_until']) throw new RuntimeException('Soft-delete retention markers were not stored.');
    $checks['soft-delete'] = 'typed-confirmation-rejected-when-wrong-safety-backup-created-data-retained-and-portal-hidden';
    echo json_encode(['pass' => true, 'testInstitutionId' => $createdInstitutionId, 'checks' => $checks], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($tenantTokenHash !== '') { $deleteSession = $pdo->prepare('DELETE FROM admin_sessions WHERE token_hash = :token_hash'); $deleteSession->execute(['token_hash' => $tenantTokenHash]); }
    $deletePlatform = $pdo->prepare('DELETE FROM platform_admin_sessions WHERE token_hash = :token_hash'); $deletePlatform->execute(['token_hash' => $platformTokenHash]);
    if ($createdInstitutionId > 0) { provisioningDelete($pdo, $createdInstitutionId, $slug, $root); fwrite(STDERR, "provisioning-test cleanup: completed\n"); }
}
