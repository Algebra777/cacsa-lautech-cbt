<?php
declare(strict_types=1);

/**
 * Regression check for the support-access boundary. It exercises CACSA's
 * real tenant route with disposable sessions only; no account or academic
 * record is changed. A tenant session must win over a same-name platform
 * cookie, while a platform-only session remains subject to break-glass.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__); $pdo = mysqlMigrationPdo($root);
$tenantToken = bin2hex(random_bytes(32)); $platformToken = bin2hex(random_bytes(32)); $grantId = bin2hex(random_bytes(16));
$tenantHash = hash('sha256', $tenantToken); $platformHash = hash('sha256', $platformToken); $inserted = false;
function supportBoundaryRequest(string $cookie): array {
    $context = stream_context_create(['http'=>['method'=>'GET','header'=>"Accept: application/json\r\nCookie: {$cookie}",'ignore_errors'=>true,'timeout'=>20]]);
    $raw = file_get_contents('http://localhost/BEREVION/i/cacsa-lautech/api.php?action=dashboard', false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return ['status'=>(int)($match[1] ?? 0),'body'=>json_decode((string)$raw, true) ?: []];
}
try {
    $tenant = $pdo->query("SELECT id FROM institutions WHERE slug='cacsa-lautech' AND active=1 AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    $admin = $pdo->query("SELECT id,email,name FROM admin_users WHERE institution_id=1 AND role_id='admin' AND active=1 LIMIT 1")->fetch();
    $platform = $pdo->query("SELECT id,email,name FROM platform_admin_users WHERE active=1 LIMIT 1")->fetch();
    if (!$tenant || !$admin || !$platform) throw new RuntimeException('CACSA tenant Admin or platform Super Admin fixture is unavailable.');
    $permissions = json_encode(['overview','students','exams','questions','results','audit','newsletter','messages','settings','roles'], JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at) VALUES (1,:token_hash,:user_id,:email,:name,'admin',:permissions,'support-boundary-test',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'2099-12-31 23:59:59.000000')")->execute(['token_hash'=>$tenantHash,'user_id'=>$admin['id'],'email'=>$admin['email'],'name'=>$admin['name'],'permissions'=>$permissions]);
    $pdo->prepare("INSERT INTO platform_admin_sessions (token_hash,user_id,email,name,correlation_id,created_at,last_seen_at,expires_at) VALUES (:token_hash,:user_id,:email,:name,'support-boundary-test',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'2099-12-31 23:59:59.000000')")->execute(['token_hash'=>$platformHash,'user_id'=>$platform['id'],'email'=>$platform['email'],'name'=>$platform['name']]);
    $inserted = true;

    // Platform token is deliberately first, mirroring the worst duplicate
    // cookie parsing order. The tenant session must still win on /i/cacsa.
    $tenantWithPlatform = supportBoundaryRequest('CBT_ADMIN_SESSION='.$platformToken.'; CBT_ADMIN_SESSION='.$tenantToken);
    if ($tenantWithPlatform['status'] !== 200) throw new RuntimeException('CACSA tenant Admin was blocked when a platform cookie was also present: '.json_encode($tenantWithPlatform));
    $platformDenied = supportBoundaryRequest('CBT_ADMIN_SESSION='.$platformToken);
    if ($platformDenied['status'] !== 403 || !str_contains((string)($platformDenied['body']['error'] ?? ''), 'Tenant support access is required')) throw new RuntimeException('Platform session reached CACSA without a support grant: '.json_encode($platformDenied));
    $pdo->prepare("INSERT INTO platform_support_access_sessions (id,institution_id,platform_admin_id,platform_session_token_hash,reason,created_at,expires_at) VALUES (:id,1,:platform_admin_id,:token_hash,'Regression verification of tenant session precedence',UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE))")->execute(['id'=>$grantId,'platform_admin_id'=>$platform['id'],'token_hash'=>$platformHash]);
    $platformGranted = supportBoundaryRequest('CBT_ADMIN_SESSION='.$platformToken);
    if ($platformGranted['status'] !== 200) throw new RuntimeException('Platform session was not admitted after a tenant-specific support grant: '.json_encode($platformGranted));
    echo json_encode(['pass'=>true,'cacsaTenantAdminWithPlatformCookie'=>'200','platformWithoutGrant'=>'403','platformWithCACSAGrant'=>'200'], JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    if ($inserted) {
        $pdo->prepare('DELETE FROM platform_support_access_sessions WHERE id=:id')->execute(['id'=>$grantId]);
        $pdo->prepare('DELETE FROM platform_admin_sessions WHERE token_hash=:token_hash')->execute(['token_hash'=>$platformHash]);
        $pdo->prepare('DELETE FROM admin_sessions WHERE institution_id=1 AND token_hash=:token_hash')->execute(['token_hash'=>$tenantHash]);
    }
}
