<?php
declare(strict_types=1);

/*
 * Final Phase 2 verification for a tenant created through the browser UI.
 * It creates only short-lived verifier sessions/users, writes one backup in
 * the disposable tenant to prove directory scoping, and removes its fixtures
 * in finally. It never writes CACSA academic, audit, settings, or backup data.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$slug = $argv[1] ?? '';
if ($slug !== 'phase2-final-ui-test') throw new RuntimeException('This final verifier is restricted to phase2-final-ui-test.');
$pdo = mysqlMigrationPdo($root);
$lookup = $pdo->prepare('SELECT id,name FROM institutions WHERE slug = :slug LIMIT 1');
$lookup->execute(['slug' => $slug]); $tenant = $lookup->fetch();
if (!$tenant || (int)$tenant['id'] < 2) throw new RuntimeException('The final disposable UI tenant was not found.');
$tenantId = (int)$tenant['id'];
$tenantUserId = 'phase2-final-verifier-' . bin2hex(random_bytes(5));
$tenantToken = bin2hex(random_bytes(32)); $cacsaToken = bin2hex(random_bytes(32));

function finalHttp(string $url, string $token = '', string $method = 'GET', ?array $json = null, ?array $multipart = null): array {
    $csrf = '';
    if ($method !== 'GET') {
        $csrfResponse = finalHttp($url . (str_contains($url, '?') ? '&' : '?') . 'action=auth-csrf', $token);
        if (($csrfResponse['status'] ?? 0) !== 200 || empty($csrfResponse['body']['csrfToken'])) throw new RuntimeException('Could not obtain a CSRF token.');
        $csrf = (string)$csrfResponse['body']['csrfToken'];
    }
    $curl = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== '') $headers[] = 'Cookie: CBT_ADMIN_SESSION=' . rawurlencode($token) . ($csrf !== '' ? '; CBT_CSRF=' . rawurlencode($csrf) : '');
    if ($csrf !== '') $headers[] = 'X-CSRF-Token: ' . $csrf;
    if ($json !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
    if ($multipart !== null) { $multipart['CBT_CSRF'] = $csrf; curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart); }
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 25]);
    $raw = curl_exec($curl); if ($raw === false) throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $headerBytes = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE); curl_close($curl);
    return ['status' => $status, 'body' => json_decode(substr($raw, $headerBytes), true) ?: []];
}
function finalExpect(array $response, int|array $status, string $label): void {
    $allowed = is_array($status) ? $status : [$status];
    if (!in_array($response['status'], $allowed, true)) throw new RuntimeException("{$label}: expected " . implode('/', $allowed) . ', received ' . $response['status'] . ' ' . json_encode($response['body'], JSON_UNESCAPED_SLASHES));
}

try {
    $cacsaAdmin = $pdo->query("SELECT id,email,name FROM admin_users WHERE institution_id = 1 AND role_id = 'admin' AND active = 1 LIMIT 1")->fetch();
    if (!$cacsaAdmin) throw new RuntimeException('CACSA Admin fixture is unavailable.');
    $now = gmdate('Y-m-d H:i:s.u');
    $permissions = json_encode(['overview','students','exams','questions','results','audit','newsletter','settings','roles']);
    $addUser = $pdo->prepare("INSERT INTO admin_users (institution_id,id,role_id,name,email,password_hash,active,verified,must_change_password,created_at,approved_at,approved_by) VALUES (:institution_id,:id,'admin','Final isolation verifier',:email,:password_hash,1,1,0,:created_at,:approved_at,'phase2-final-test')");
    $addUser->execute(['institution_id' => $tenantId, 'id' => $tenantUserId, 'email' => $tenantUserId . '@example.invalid', 'password_hash' => password_hash(bin2hex(random_bytes(18)), PASSWORD_DEFAULT), 'created_at' => $now, 'approved_at' => $now]);
    $addSession = $pdo->prepare("INSERT INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at) VALUES (:institution_id,:token_hash,:user_id,:email,:name,'admin',:permissions,:correlation,:created_at,:last_seen_at,'2099-12-31 23:59:59.000000')");
    $addSession->execute(['institution_id' => $tenantId, 'token_hash' => hash('sha256', $tenantToken), 'user_id' => $tenantUserId, 'email' => $tenantUserId . '@example.invalid', 'name' => 'Final isolation verifier', 'permissions' => $permissions, 'correlation' => 'phase2-final-isolation', 'created_at' => $now, 'last_seen_at' => $now]);
    $addSession->execute(['institution_id' => 1, 'token_hash' => hash('sha256', $cacsaToken), 'user_id' => $cacsaAdmin['id'], 'email' => $cacsaAdmin['email'], 'name' => $cacsaAdmin['name'], 'permissions' => $permissions, 'correlation' => 'phase2-final-isolation', 'created_at' => $now, 'last_seen_at' => $now]);

    $ids = [];
    foreach (['students','courses','questions','component_submissions'] as $table) $ids[$table] = (string)$pdo->query("SELECT id FROM {$table} WHERE institution_id = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $resultId = (string)$pdo->query('SELECT id FROM component_submissions WHERE institution_id = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $tenantBase = 'http://localhost/BEREVION/i/' . rawurlencode($slug) . '/api.php?action=';
    $cacsaBase = 'http://localhost/BEREVION/i/cacsa-lautech/api.php?action=';
    $checks = [];

    // Public tenant resolution must be path-based: no caller-supplied institution ID is accepted.
    $tenantBrand = finalHttp($tenantBase . 'branding'); finalExpect($tenantBrand, 200, 'tenant branding');
    $cacsaBrand = finalHttp($cacsaBase . 'branding'); finalExpect($cacsaBrand, 200, 'CACSA branding');
    if (($tenantBrand['body']['branding']['displayName'] ?? '') !== $tenant['name'] || ($cacsaBrand['body']['branding']['displayName'] ?? '') === $tenant['name']) throw new RuntimeException('Branding was not tenant-resolved.');
    $checks['branding-path-resolution'] = 'isolated';

    // Components are deliberately returned inside course/exam responses; they
    // do not expose a standalone GET endpoint.
    foreach (['dashboard','students','courses','exams','questions','results','audit-monitor','audit-events','audit-archives','settings','backups','roles','admin-users'] as $endpoint) {
        $response = finalHttp($tenantBase . $endpoint, $tenantToken); finalExpect($response, 200, "tenant {$endpoint}");
        $serialized = json_encode($response['body'], JSON_UNESCAPED_SLASHES);
        foreach ($ids as $foreignId) if ($foreignId !== '' && str_contains((string)$serialized, $foreignId)) throw new RuntimeException("{$endpoint} exposed a CACSA identifier.");
        $checks['tenant-' . $endpoint] = 'isolated';
    }

    // All platform-only routes/tables are structurally unreachable to tenant sessions.
    foreach (['platform-institutions','platform-admin-users','platform-admin-sessions','platform-audit-events'] as $endpoint) {
        finalExpect(finalHttp($tenantBase . $endpoint, $tenantToken), 404, "tenant {$endpoint}");
        $checks[$endpoint] = 'blocked-404';
    }

    foreach ([['students',$ids['students']],['courses',$ids['courses']],['questions',$ids['questions']]] as [$endpoint, $foreignId]) {
        if ($foreignId === '') continue;
        finalExpect(finalHttp($tenantBase . $endpoint . '&id=' . rawurlencode($foreignId), $tenantToken, 'DELETE'), 404, "tenant cross-id {$endpoint}");
        $checks[$endpoint . '-foreign-write'] = 'blocked-404';
    }
    if ($resultId !== '') {
        finalExpect(finalHttp($tenantBase . 'result-review&id=' . rawurlencode($resultId), $tenantToken), 404, 'tenant foreign result review');
        finalExpect(finalHttp($tenantBase . 'component-submission-delete', $tenantToken, 'POST', ['resultId' => $resultId, 'reason' => 'isolation verification only', 'confirmation' => 'not-used']), 404, 'tenant foreign submission delete');
        $checks['result-submission-foreign-read-write'] = 'blocked-404';
    }
    finalExpect(finalHttp($tenantBase . 'calculate-student-result', $tenantToken, 'POST', ['studentId' => $ids['students'], 'sessionId' => 'foreign', 'semesterId' => 'foreign']), [404,422], 'tenant foreign result calculation');
    $checks['calculate-result-foreign-write'] = 'blocked';
    finalExpect(finalHttp($cacsaBase . 'admin-users&id=' . rawurlencode($tenantUserId), $cacsaToken, 'DELETE'), 404, 'CACSA foreign admin delete');
    $checks['cacsa-to-tenant-admin-write'] = 'blocked-404';

    // Produce an actual tenant backup, then prove it is invisible across both directions.
    $madeBackup = finalHttp($tenantBase . 'backups', $tenantToken, 'POST'); finalExpect($madeBackup, 200, 'tenant backup creation');
    $tenantBackup = (string)($madeBackup['body']['backup']['filename'] ?? ''); if ($tenantBackup === '') throw new RuntimeException('Tenant backup creation returned no filename.');
    $cacsaBackups = finalHttp($cacsaBase . 'backups', $cacsaToken); finalExpect($cacsaBackups, 200, 'CACSA backups');
    if (str_contains(json_encode($cacsaBackups['body'], JSON_UNESCAPED_SLASHES), $tenantBackup)) throw new RuntimeException('CACSA enumerated a tenant backup.');
    $cacsaBackup = glob($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'cacsa-cbt-backup_*.json')[0] ?? null;
    if ($cacsaBackup === null) throw new RuntimeException('No CACSA backup fixture is available for cross-restore verification.');
    $foreignBackup = basename($cacsaBackup);
    finalExpect(finalHttp($tenantBase . 'backups&download=' . rawurlencode($foreignBackup), $tenantToken), 404, 'tenant foreign backup download');
    finalExpect(finalHttp($cacsaBase . 'backups&download=' . rawurlencode($tenantBackup), $cacsaToken), 404, 'CACSA tenant backup download');
    $foreignFile = new CURLFile($cacsaBackup, 'application/json', $foreignBackup);
    finalExpect(finalHttp($tenantBase . 'backup-restore-validate', $tenantToken, 'POST', null, ['backup' => $foreignFile]), 403, 'tenant foreign backup validation');
    finalExpect(finalHttp($tenantBase . 'backup-restore', $tenantToken, 'POST', null, ['backup' => $foreignFile, 'confirmation' => 'RESTORE']), 403, 'tenant foreign backup restore');
    $checks['backup-download-cross-tenant'] = 'blocked-404';
    $checks['backup-restore-cross-tenant'] = 'blocked-403';
    $checks['tenant-backup-directory'] = 'isolated';

    echo json_encode(['pass' => true, 'institutionId' => $tenantId, 'checks' => $checks], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $deleteSession = $pdo->prepare('DELETE FROM admin_sessions WHERE token_hash = :hash');
    foreach ([$tenantToken, $cacsaToken] as $token) $deleteSession->execute(['hash' => hash('sha256', $token)]);
    $deleteUser = $pdo->prepare('DELETE FROM admin_users WHERE institution_id = :institution_id AND id = :id');
    $deleteUser->execute(['institution_id' => $tenantId, 'id' => $tenantUserId]);
}
