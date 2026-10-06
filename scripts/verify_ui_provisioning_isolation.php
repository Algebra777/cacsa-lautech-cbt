<?php
declare(strict_types=1);
/* Re-check tenant isolation for an institution created through the Super Admin UI. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$slug = $argv[1] ?? '';
if (!preg_match('/^[a-z0-9][a-z0-9-]{0,118}$/', $slug)) throw new RuntimeException('Pass a valid institution slug.');
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$lookup = $pdo->prepare('SELECT id FROM institutions WHERE slug = :slug LIMIT 1'); $lookup->execute(['slug' => $slug]); $tenantId = (int)$lookup->fetchColumn();
if ($tenantId < 2) throw new RuntimeException('The disposable UI tenant was not found.');
$tenantUserId = 'verify-ui-isolation-' . bin2hex(random_bytes(5)); $tenantToken = bin2hex(random_bytes(32)); $cacsaToken = bin2hex(random_bytes(32));

function uiIsoHttp(string $url, string $token, string $method = 'GET', ?array $body = null): array {
    $csrf = '';
    if ($method !== 'GET') { $csrfResult = uiIsoHttp($url . (str_contains($url, '?') ? '&' : '?') . 'action=auth-csrf', $token); $csrf = (string)($csrfResult['body']['csrfToken'] ?? ''); if ($csrf === '') throw new RuntimeException('CSRF token unavailable.'); }
    $headers = ['Accept: application/json', 'Cookie: CBT_ADMIN_SESSION=' . $token . ($csrf !== '' ? '; CBT_CSRF=' . $csrf : '')];
    if ($csrf !== '') $headers[] = 'X-CSRF-Token: ' . $csrf;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES), 'ignore_errors' => true, 'timeout' => 20]]);
    $raw = file_get_contents($url, false, $context); $line = $http_response_header[0] ?? ''; preg_match('/\s(\d{3})\s/', $line, $match);
    return ['status' => (int)($match[1] ?? 0), 'body' => json_decode((string)$raw, true) ?: []];
}

try {
    $cacsaAdmin = $pdo->query("SELECT id,email,name FROM admin_users WHERE institution_id = 1 AND role_id = 'admin' AND active = 1 LIMIT 1")->fetch();
    if (!$cacsaAdmin) throw new RuntimeException('CACSA Admin fixture is unavailable.');
    $now = gmdate('Y-m-d H:i:s.u'); $permissions = json_encode(['overview','students','exams','questions','results','audit','newsletter','settings','roles']);
    $addUser = $pdo->prepare("INSERT INTO admin_users (institution_id,id,role_id,name,email,password_hash,active,verified,must_change_password,created_at,approved_at,approved_by) VALUES (:institution_id,:id,'admin','Isolation verifier',:email,:password_hash,1,1,0,:created_at,:approved_at,'phase2-test')");
    $addUser->execute(['institution_id' => $tenantId, 'id' => $tenantUserId, 'email' => $tenantUserId . '@example.invalid', 'password_hash' => password_hash(bin2hex(random_bytes(18)), PASSWORD_DEFAULT), 'created_at' => $now, 'approved_at' => $now]);
    $addSession = $pdo->prepare('INSERT INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at) VALUES (:institution_id,:token_hash,:user_id,:email,:name,\'admin\',:permissions,:correlation,:created_at,:last_seen_at,\'2099-12-31 23:59:59.000000\')');
    $addSession->execute(['institution_id' => $tenantId, 'token_hash' => hash('sha256', $tenantToken), 'user_id' => $tenantUserId, 'email' => $tenantUserId . '@example.invalid', 'name' => 'Isolation verifier', 'permissions' => $permissions, 'correlation' => 'ui-provisioning-isolation', 'created_at' => $now, 'last_seen_at' => $now]);
    $addSession->execute(['institution_id' => 1, 'token_hash' => hash('sha256', $cacsaToken), 'user_id' => $cacsaAdmin['id'], 'email' => $cacsaAdmin['email'], 'name' => $cacsaAdmin['name'], 'permissions' => $permissions, 'correlation' => 'ui-provisioning-isolation', 'created_at' => $now, 'last_seen_at' => $now]);
    $cacsaIds = [];
    foreach (['students','courses','questions'] as $table) $cacsaIds[$table] = (string)$pdo->query("SELECT id FROM {$table} WHERE institution_id=1 ORDER BY id LIMIT 1")->fetchColumn();
    $baseTenant = 'http://localhost/BEREVION/i/' . rawurlencode($slug) . '/api.php?action='; $baseCacsa = 'http://localhost/BEREVION/i/cacsa-lautech/api.php?action=';
    $checks = [];
    foreach (['students','courses','questions','results','audit-events','settings','backups'] as $endpoint) {
        $response = uiIsoHttp($baseTenant . $endpoint, $tenantToken); if ($response['status'] !== 200) throw new RuntimeException("UI tenant {$endpoint} request failed.");
        $serialized = json_encode($response['body'], JSON_UNESCAPED_SLASHES); foreach ($cacsaIds as $foreignId) if ($foreignId !== '' && str_contains((string)$serialized, $foreignId)) throw new RuntimeException("UI tenant {$endpoint} exposed CACSA data.");
        $checks['ui-tenant-' . $endpoint] = 'isolated';
    }
    foreach ([['students',$cacsaIds['students']],['courses',$cacsaIds['courses']],['questions',$cacsaIds['questions']]] as [$endpoint,$foreignId]) {
        if ($foreignId === '') continue; $response = uiIsoHttp($baseTenant . $endpoint . '&id=' . rawurlencode($foreignId), $tenantToken, 'DELETE');
        if ($response['status'] !== 404) throw new RuntimeException("UI tenant {$endpoint} cross-tenant delete was not blocked."); $checks['ui-tenant-' . $endpoint . '-write'] = 'blocked-404';
    }
    $foreignAdmin = uiIsoHttp($baseCacsa . 'admin-users&id=' . rawurlencode($tenantUserId), $cacsaToken, 'DELETE');
    if ($foreignAdmin['status'] !== 404) throw new RuntimeException('CACSA Admin could target the UI tenant Admin.'); $checks['cacsa-to-ui-admin-write'] = 'blocked-404';
    $platform = uiIsoHttp($baseTenant . 'platform-institutions', $tenantToken);
    if ($platform['status'] !== 404) throw new RuntimeException('UI tenant Admin reached platform provisioning.'); $checks['ui-tenant-platform-provisioning'] = 'blocked-404';
    $cacsaSettings = uiIsoHttp($baseCacsa . 'settings', $cacsaToken);
    if ($cacsaSettings['status'] !== 200 || str_contains(json_encode($cacsaSettings['body'], JSON_UNESCAPED_SLASHES), 'test-temp-1')) throw new RuntimeException('CACSA settings exposed UI tenant configuration.'); $checks['cacsa-settings'] = 'isolated';
    echo json_encode(['pass' => true, 'checks' => $checks], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $del = $pdo->prepare('DELETE FROM admin_sessions WHERE token_hash = :hash'); foreach ([$tenantToken, $cacsaToken] as $token) $del->execute(['hash' => hash('sha256', $token)]);
    if ($tenantUserId !== '') { $delUser = $pdo->prepare('DELETE FROM admin_users WHERE institution_id = :institution_id AND id = :id'); $delUser->execute(['institution_id' => $tenantId, 'id' => $tenantUserId]); }
}
