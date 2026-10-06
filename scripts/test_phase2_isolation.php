<?php
declare(strict_types=1);

/**
 * Phase 2 / Step 2 automated isolation test.
 *
 * It creates a disposable institution, authenticates its Admin through the
 * real HTTP API route, attempts cross-tenant reads and ID-targeted writes,
 * verifies CACSA hashes remain unchanged, then makes a test-only safety export
 * before deleting the disposable institution in FK-safe order.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$pdo = mysqlMigrationPdo($root);
$slug = 'phase2-isolation-test-' . gmdate('Ymdhis');
$testStudentId = 'phase2-test-student';
$testAdminId = 'phase2-test-admin';
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$now = gmdate('Y-m-d H:i:s.u');
$expires = gmdate('Y-m-d H:i:s.u', time() + 3600);
$testInstitutionId = 0;

function phase2Hash(PDO $pdo, string $table): string {
    $statement = $pdo->query("SELECT institution_id, id FROM {$table} WHERE institution_id = 1 ORDER BY id");
    return hash('sha256', json_encode($statement->fetchAll(), JSON_UNESCAPED_SLASHES));
}
function phase2Http(string $url, string $token, string $method = 'GET', ?array $body = null): array {
    $csrf = '';
    if ($method !== 'GET') {
        $csrfResponse = phase2Http($url . (str_contains($url, '?') ? '&' : '?') . 'action=auth-csrf', $token);
        if (($csrfResponse['status'] ?? 0) !== 200 || empty($csrfResponse['body']['csrfToken'])) throw new RuntimeException('Could not obtain a scoped CSRF token.');
        $csrf = (string)$csrfResponse['body']['csrfToken'];
    }
    $headers = [
        'Cookie: CBT_ADMIN_SESSION=' . $token . ($csrf !== '' ? '; CBT_CSRF=' . $csrf : ''),
        'Accept: application/json',
    ];
    if ($csrf !== '') $headers[] = 'X-CSRF-Token: ' . $csrf;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES), 'ignore_errors' => true, 'timeout' => 20]]);
    $raw = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    return ['status' => (int)($match[1] ?? 0), 'body' => json_decode((string)$raw, true) ?: []];
}
function phase2Cleanup(PDO $pdo, int $institutionId, string $root, string $slug): void {
    if ($institutionId < 2) return;
    $directory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Could not create the test-only safety-backup directory.');
    $snapshot = ['kind' => 'phase2-isolation-test-safety-backup', 'institutionId' => $institutionId, 'slug' => $slug, 'createdAt' => gmdate('c')];
    foreach (['students','courses','questions','audit_events','admin_users'] as $table) {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE institution_id = :institution_id");
        $stmt->execute(['institution_id' => $institutionId]);
        $snapshot[$table] = $stmt->fetchAll();
    }
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'phase2-isolation-test-safety.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    $pdo->beginTransaction();
    try {
        foreach ([
            'calculated_result_items','calculated_results','newsletter_deliveries','attempt_answers','attempt_flags',
            'attempt_question_snapshots','attempt_integrity_events','component_submissions','exam_flags','assessment_attempts',
            'assessment_login_tokens','assessment_passwords','question_publish_targets','question_options','questions',
            'role_permissions','admin_sessions','admin_profile_overrides','admin_user_activity','admin_email_verifications',
            'admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','pending_admin_requests',
            'audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts',
            'newsletter_subscribers','newsletters','grading_scale_bands','integrity_policies','institution_settings',
            'dashboard_hidden_outcomes','backup_records','students','course_components','courses','academic_semesters',
            'academic_sessions','admin_users','roles','storage_migrations'
        ] as $table) {
            $delete = $pdo->prepare("DELETE FROM {$table} WHERE institution_id = :institution_id");
            $delete->execute(['institution_id' => $institutionId]);
        }
        $deleteInstitution = $pdo->prepare('DELETE FROM institutions WHERE id = :id AND slug = :slug');
        $deleteInstitution->execute(['id' => $institutionId, 'slug' => $slug]);
        if ($deleteInstitution->rowCount() !== 1) throw new RuntimeException('The disposable institution cleanup target was not found.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

$hashesBefore = [];
foreach (['students','courses','questions','component_submissions','audit_events'] as $table) $hashesBefore[$table] = phase2Hash($pdo, $table);
$results = [];
try {
    $pdo->beginTransaction();
    $insertInstitution = $pdo->prepare('INSERT INTO institutions (name,slug,active,created_at) VALUES (:name,:slug,1,:created_at)');
    $insertInstitution->execute(['name' => 'Phase 2 Isolation Test', 'slug' => $slug, 'created_at' => $now]);
    $testInstitutionId = (int)$pdo->lastInsertId();
    $insertRole = $pdo->prepare('INSERT INTO roles (institution_id,id,name,description,max_users,system_locked) VALUES (:institution_id,:id,:name,:description,1,1)');
    $insertRole->execute(['institution_id' => $testInstitutionId, 'id' => 'admin', 'name' => 'Admin', 'description' => 'Isolation test administrator']);
    $insertPermission = $pdo->prepare('INSERT INTO role_permissions (institution_id,role_id,permission) VALUES (:institution_id,:role_id,:permission)');
    foreach (['overview','students','exams','questions','results','audit','newsletter','settings','roles'] as $permission) $insertPermission->execute(['institution_id' => $testInstitutionId, 'role_id' => 'admin', 'permission' => $permission]);
    $insertAdmin = $pdo->prepare('INSERT INTO admin_users (institution_id,id,role_id,name,email,password_hash,active,verified,created_at) VALUES (:institution_id,:id,:role_id,:name,:email,:hash,1,1,:created_at)');
    $insertAdmin->execute(['institution_id' => $testInstitutionId, 'id' => $testAdminId, 'role_id' => 'admin', 'name' => 'Isolation Test Admin', 'email' => $slug . '@example.invalid', 'hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'created_at' => $now]);
    $insertSession = $pdo->prepare('INSERT INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at) VALUES (:institution_id,:token_hash,:user_id,:email,:name,:role_id,:permissions,:correlation,:created_at,:last_seen_at,:expires_at)');
    $insertSession->execute(['institution_id' => $testInstitutionId, 'token_hash' => $tokenHash, 'user_id' => $testAdminId, 'email' => $slug . '@example.invalid', 'name' => 'Isolation Test Admin', 'role_id' => 'admin', 'permissions' => json_encode(['overview','students','exams','questions','results','audit','newsletter','settings','roles']), 'correlation' => 'phase2-isolation', 'created_at' => $now, 'last_seen_at' => $now, 'expires_at' => $expires]);
    $insertStudent = $pdo->prepare('INSERT INTO students (institution_id,id,full_name,matric_number,email,department,active,created_at) VALUES (:institution_id,:id,:name,:matric,:email,:department,1,:created_at)');
    $insertStudent->execute(['institution_id' => $testInstitutionId, 'id' => $testStudentId, 'name' => 'Isolation Test Student', 'matric' => 'P2-ONLY-001', 'email' => 'phase2-student@example.invalid', 'department' => 'Isolation Testing', 'created_at' => $now]);
    $pdo->commit();

    $cacsaStudent = $pdo->query('SELECT id FROM students WHERE institution_id = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $cacsaCourse = $pdo->query('SELECT id FROM courses WHERE institution_id = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $cacsaQuestion = $pdo->query('SELECT id FROM questions WHERE institution_id = 1 ORDER BY id LIMIT 1')->fetchColumn();
    if (!$cacsaStudent || !$cacsaCourse || !$cacsaQuestion) throw new RuntimeException('CACSA fixture IDs were unavailable for the isolation test.');
    $base = 'http://localhost/BEREVION/i/' . rawurlencode($slug) . '/api.php?action=';
    foreach (['students','courses','questions','results','audit-events','settings','backups'] as $endpoint) {
        $response = phase2Http($base . $endpoint, $token);
        if ($response['status'] !== 200) throw new RuntimeException("{$endpoint} did not return 200 for the test institution.");
        $encoded = json_encode($response['body'], JSON_UNESCAPED_SLASHES);
        foreach ([$cacsaStudent, $cacsaCourse, $cacsaQuestion] as $foreignId) if (str_contains((string)$encoded, (string)$foreignId)) throw new RuntimeException("{$endpoint} exposed a CACSA identifier.");
        $results[$endpoint] = 'isolated';
    }
    $platformEndpoint = phase2Http($base . 'platform-institutions', $token);
    if ($platformEndpoint['status'] !== 404) throw new RuntimeException('A tenant Admin reached the platform institution-provisioning endpoint.');
    $results['platform-institution-provisioning'] = 'blocked-404';
    $cacsaBackup = glob($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'cacsa-cbt-*.json')[0] ?? null;
    if ($cacsaBackup !== null) {
        $response = phase2Http($base . 'backups&download=' . rawurlencode(basename($cacsaBackup)), $token);
        if ($response['status'] !== 404) throw new RuntimeException('The test institution could access a CACSA backup filename.');
        $results['backup-download-id-manipulation'] = 'blocked-404';
    }
    foreach ([
        ['students', (string)$cacsaStudent], ['courses', (string)$cacsaCourse], ['questions', (string)$cacsaQuestion]
    ] as [$endpoint, $foreignId]) {
        $response = phase2Http($base . $endpoint . '&id=' . rawurlencode($foreignId), $token, 'DELETE');
        if ($response['status'] !== 404) throw new RuntimeException("{$endpoint} accepted a cross-institution ID-targeted delete.");
        $results[$endpoint . '-id-manipulation'] = 'blocked-404';
    }
    $calculation = phase2Http($base . 'calculate-student-result', $token, 'POST', ['studentId' => $cacsaStudent, 'sessionId' => 'foreign', 'semesterId' => 'foreign']);
    if (!in_array($calculation['status'], [404, 422], true)) throw new RuntimeException('Result calculation did not reject the foreign student ID.');
    $results['calculate-student-result-id-manipulation'] = 'blocked-' . $calculation['status'];
    foreach ($hashesBefore as $table => $hash) if (!hash_equals($hash, phase2Hash($pdo, $table))) throw new RuntimeException("CACSA {$table} changed during the isolation test.");
    $results['cacsa-data-hashes'] = 'unchanged';
    echo json_encode(['pass' => true, 'testInstitutionId' => $testInstitutionId, 'checks' => $results, 'cleanup' => 'pending-finally'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    phase2Cleanup($pdo, $testInstitutionId, $root, $slug);
    if ($testInstitutionId > 0) fwrite(STDERR, "isolation-test cleanup: completed\n");
}
