<?php
declare(strict_types=1);

/*
 * MySQL compatibility repository for the existing API handlers. It presents
 * the handlers' established array model while every read is reconstructed
 * from institution-scoped normalized tables. Writes are a single transaction
 * that refreshes those same normalized rows; cbt-data.json is never read or
 * written once MYSQL_STORAGE_ACTIVE is enabled.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'mysql_connection.php';

const MYSQL_LEGACY_INSTITUTION_SLUG = 'cacsa-lautech';
final class InstitutionSuspendedException extends RuntimeException {}
final class InstitutionUnavailableException extends RuntimeException {}

function mysqlAppEnvironment(): array {
    // Apache on Windows may execute concurrent requests in different worker
    // threads. PHP's process environment is not a reliable configuration
    // boundary there: one thread can observe a value written by putenv() while
    // another observes it as unset. Read the server-only .env file directly
    // for each request instead, so authentication can never fall back from
    // MySQL to the retired JSON session store intermittently.
    static $environment = null;
    if (!is_array($environment)) $environment = mysqlMigrationEnv(__DIR__);
    return $environment;
}
function mysqlStorageEnabled(): bool { return (string)(mysqlAppEnvironment()['MYSQL_STORAGE_ACTIVE'] ?? '') === '1'; }
function mysqlAppPdo(): PDO {
    // One PDO connection per PHP request.  Previously the rate-limit check,
    // full compatibility loader and shutdown writer each opened their own
    // connection, which exhausted MySQL's 151-connection development limit
    // long before Apache's 150-worker limit was reached.
    static $pdo = null;
    return $pdo ??= mysqlMigrationPdo(__DIR__);
}
/**
 * Resolve the tenant from the server-visible URL, never from a request body,
 * query parameter, or client-supplied header.  The legacy root URL remains
 * CACSA for existing bookmarks until the branded routes are introduced.
 */
function mysqlRequestInstitution(): array {
    static $institution = null;
    if (is_array($institution)) return $institution;
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $slug = MYSQL_LEGACY_INSTITUTION_SLUG;
    if (preg_match('~(?:^|/)i/([a-z0-9][a-z0-9-]{0,118})(?:/|$)~i', $path, $matches)) $slug = strtolower($matches[1]);
    $statement = mysqlAppPdo()->prepare('SELECT id, name, slug, active, deleted_at FROM institutions WHERE slug = :slug LIMIT 1');
    $statement->execute(['slug' => $slug]);
    $row = $statement->fetch();
    if (!$row || $row['deleted_at'] !== null) throw new InstitutionUnavailableException('The requested institution is unavailable.');
    if (!(int)$row['active']) throw new InstitutionSuspendedException('This institution’s access has been suspended — contact the platform administrator.');
    $institution = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'slug' => (string)$row['slug']];
    return $institution;
}
function mysqlCurrentInstitutionId(): int { return (int)mysqlRequestInstitution()['id']; }
/**
 * Branding is tenant-owned data but deliberately read through this dedicated
 * helper rather than the legacy-array loader. That prevents a normal data
 * persistence pass from accidentally replacing a future institution's brand.
 */
function mysqlInstitutionBranding(): ?array {
    $statement = mysqlAppPdo()->prepare('SELECT display_name,portal_title,logo_path,favicon_path,accent_source_color,primary_color,accent_color,nav_label,assessment_label,footer_primary,footer_secondary,footer_legal,result_sheet_title,newsletter_sender_name,support_email FROM institution_branding WHERE institution_id = :institution_id LIMIT 1');
    $statement->execute(['institution_id' => mysqlCurrentInstitutionId()]);
    $row = $statement->fetch();
    if (!$row) {
        $institution = mysqlRequestInstitution();
        $seed = (int)$institution['id'];
        $palette = [
            ['#2563eb', '#1d4ed8'], ['#7c3aed', '#6d28d9'], ['#db2777', '#be185d'],
            ['#ea580c', '#c2410c'], ['#0891b2', '#0e7490'], ['#4f46e5', '#4338ca'],
            ['#16a34a', '#15803d'], ['#ca8a04', '#a16207'], ['#dc2626', '#b91c1c'],
        ];
        [$primary, $accent] = $palette[$seed % count($palette)];
        $name = (string)$institution['name'];
        return [
            'displayName' => $name, 'portalTitle' => $name, 'logoPath' => 'uploads/platform-branding/berevion-logo.png',
            'faviconPath' => 'uploads/platform-branding/berevion-logo.png', 'accentSourceColor' => $primary,
            'primaryColor' => $primary, 'accentColor' => $accent, 'navLabel' => $name,
            'assessmentLabel' => 'Assessment centre', 'footerPrimary' => $name . ' assessment platform',
            'footerSecondary' => 'Examine. Verify. Excel.', 'footerLegal' => '© {year} ' . $name . '. All rights reserved.',
            'resultSheetTitle' => $name, 'newsletterSenderName' => $name, 'supportEmail' => '',
        ];
    }
    return [
        'displayName' => (string)$row['display_name'],
        'portalTitle' => (string)$row['portal_title'],
        'logoPath' => (string)$row['logo_path'],
        'faviconPath' => (string)$row['favicon_path'],
        'accentSourceColor' => (string)($row['accent_source_color'] ?? $row['primary_color']), 'primaryColor' => (string)$row['primary_color'],
        'accentColor' => (string)$row['accent_color'],
        'navLabel' => (string)$row['nav_label'],
        'assessmentLabel' => (string)$row['assessment_label'],
        'footerPrimary' => (string)$row['footer_primary'],
        'footerSecondary' => (string)$row['footer_secondary'],
        'footerLegal' => (string)$row['footer_legal'],
        'resultSheetTitle' => (string)$row['result_sheet_title'],
        'newsletterSenderName' => (string)$row['newsletter_sender_name'],
        'supportEmail' => (string)($row['support_email'] ?? ''),
    ];
}
/** Platform branding is not tenant-scoped. It is safe to read before tenant
 * resolution so the Berevion root can remain a genuine platform surface. */
function mysqlPlatformBranding(): ?array {
    $statement = mysqlAppPdo()->prepare('SELECT display_name,tagline,logo_path,favicon_path,accent_source_color,primary_color,accent_color,navy_color,mid_blue_color,bright_blue_color FROM platform_branding WHERE id = 1 LIMIT 1');
    $statement->execute(); $row = $statement->fetch();
    if (!$row) return null;
    return [
        'displayName' => (string)$row['display_name'], 'portalTitle' => (string)$row['display_name'], 'tagline' => (string)$row['tagline'],
        'logoPath' => (string)$row['logo_path'], 'faviconPath' => (string)$row['favicon_path'],
        'accentSourceColor' => (string)$row['accent_source_color'], 'primaryColor' => (string)$row['primary_color'], 'accentColor' => (string)$row['accent_color'],
        'navyColor' => (string)($row['navy_color'] ?? '#00205D'), 'midBlueColor' => (string)($row['mid_blue_color'] ?? '#024DB2'), 'brightBlueColor' => (string)($row['bright_blue_color'] ?? '#0094FE'),
        'navLabel' => (string)$row['display_name'], 'assessmentLabel' => (string)$row['tagline'],
        'footerPrimary' => 'Berevion assessment platform', 'footerSecondary' => (string)$row['tagline'],
        'footerLegal' => '© {year} Berevion. All rights reserved.', 'resultSheetTitle' => (string)$row['display_name'],
        'newsletterSenderName' => (string)$row['display_name'], 'supportEmail' => ''
    ];
}
function mysqlPlatformAdminAccount(): ?array {
    $statement = mysqlAppPdo()->prepare('SELECT id,name,email,phone_number,password_hash,active,verified,must_change_password,profile_json,created_at,updated_at FROM platform_admin_users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => 'platform-superadmin']);
    $row = $statement->fetch();
    if (!$row) return null;
    $profile = mysqlJson($row['profile_json'] ?? null, []);
    if (!is_array($profile)) $profile = [];
    $account = [
        'id' => (string)$row['id'], 'name' => (string)$row['name'], 'email' => (string)$row['email'],
        'phoneNumber' => (string)($row['phone_number'] ?? ''), 'passwordHash' => (string)($row['password_hash'] ?? ''),
        'roleId' => 'platform_super_admin', 'active' => mysqlBool($row['active']), 'verified' => mysqlBool($row['verified']),
        'mustChangePassword' => mysqlBool($row['must_change_password']), 'scope' => 'platform', 'systemLocked' => true,
    ];
    foreach (['twoFactor', 'twoFactorPending'] as $key) if (isset($profile[$key]) && is_array($profile[$key])) $account[$key] = $profile[$key];
    return $account;
}
function mysqlPlatformSession(string $tokenHash): ?array {
    $statement = mysqlAppPdo()->prepare('SELECT token_hash,user_id,email,name,correlation_id,created_at,last_seen_at,expires_at FROM platform_admin_sessions WHERE token_hash = :token_hash LIMIT 1');
    $statement->execute(['token_hash' => $tokenHash]);
    $row = $statement->fetch();
    if (!$row) return null;
    return ['tokenHash' => (string)$row['token_hash'], 'userId' => (string)$row['user_id'], 'email' => (string)$row['email'], 'name' => (string)$row['name'], 'roleId' => 'platform_super_admin', 'permissions' => ['overview','students','exams','questions','results','audit','newsletter','settings','roles'], 'scope' => 'platform', 'correlationId' => $row['correlation_id'], 'createdAt' => mysqlIso($row['created_at']), 'lastSeenAt' => mysqlIso($row['last_seen_at']), 'expiresAt' => mysqlIso($row['expires_at'])];
}
/** A narrow tenant-session lookup for read-heavy admin endpoints. */
function mysqlTenantSession(string $tokenHash): ?array {
    $statement = mysqlAppPdo()->prepare('SELECT s.token_hash,s.user_id,s.email,s.name,s.role_id,s.permissions_json,s.correlation_id,s.created_at,s.last_seen_at,s.expires_at,u.active,u.must_change_password FROM admin_sessions s INNER JOIN admin_users u ON u.institution_id=s.institution_id AND u.id=s.user_id WHERE s.institution_id=:institution_id AND s.token_hash=:token_hash LIMIT 1');
    $statement->execute(['institution_id'=>mysqlCurrentInstitutionId(),'token_hash'=>$tokenHash]); $row=$statement->fetch();
    if (!$row || !mysqlBool($row['active'])) return null;
    return ['tokenHash'=>(string)$row['token_hash'],'userId'=>(string)$row['user_id'],'email'=>(string)$row['email'],'name'=>(string)$row['name'],'roleId'=>(string)$row['role_id'],'permissions'=>(array)mysqlJson($row['permissions_json'],[]),'correlationId'=>(string)($row['correlation_id']??''),'createdAt'=>mysqlIso($row['created_at']),'lastSeenAt'=>mysqlIso($row['last_seen_at']),'expiresAt'=>mysqlIso($row['expires_at']),'mustChangePassword'=>mysqlBool($row['must_change_password']),'scope'=>'institution'];
}
function mysqlUpdatePlatformSession(string $tokenHash, string $lastSeenAt, string $expiresAt): void {
    $statement = mysqlAppPdo()->prepare('UPDATE platform_admin_sessions SET last_seen_at = :last_seen_at, expires_at = :expires_at WHERE token_hash = :token_hash');
    $statement->execute(['token_hash' => $tokenHash, 'last_seen_at' => (new DateTimeImmutable($lastSeenAt))->format('Y-m-d H:i:s.u'), 'expires_at' => (new DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s.u')]);
}
/** Update an institution session in-place. Read-only admin navigation must
 * never invoke the legacy compatibility synchronizer or rewrite academic rows. */
function mysqlUpdateTenantSession(string $tokenHash, string $lastSeenAt, string $expiresAt): void {
    $statement = mysqlAppPdo()->prepare('UPDATE admin_sessions SET last_seen_at = :last_seen_at, expires_at = :expires_at WHERE institution_id = :institution_id AND token_hash = :token_hash');
    $statement->execute(['institution_id' => mysqlCurrentInstitutionId(), 'token_hash' => $tokenHash, 'last_seen_at' => (new DateTimeImmutable($lastSeenAt))->format('Y-m-d H:i:s.u'), 'expires_at' => (new DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s.u')]);
}
function mysqlRetryableTransactionFailure(Throwable $error): bool {
    $code = (string)$error->getCode();
    $message = strtolower($error->getMessage());
    return $code === '40001' || str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout');
}
/** Atomic tenant-scoped rate-limit storage, used instead of a full data-model
 * rewrite for requests whose only state change is rate-limit accounting. */
function mysqlConsumeRateLimit(string $key, int $maximum, int $windowSeconds): bool {
    $institutionId = mysqlCurrentInstitutionId();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $pdo = mysqlAppPdo(); $now = time(); $pdo->beginTransaction();
        try {
            $select = $pdo->prepare('SELECT count_value,reset_at,payload_json FROM rate_limit_records WHERE institution_id = :institution_id AND legacy_key = :legacy_key FOR UPDATE');
            $select->execute(['institution_id' => $institutionId, 'legacy_key' => $key]); $row = $select->fetch();
            $payload = $row ? mysqlJson($row['payload_json'], []) : [];
            $resetAt = isset($payload['resetAt']) ? (int)$payload['resetAt'] : (isset($row['reset_at']) ? strtotime((string)$row['reset_at']) : 0);
            $count = $row ? (int)$row['count_value'] : 0;
            if ($resetAt <= $now) { $count = 0; $resetAt = $now + $windowSeconds; }
            if ($count >= $maximum) { $pdo->commit(); return false; }
            $count++; $payload = ['count' => $count, 'resetAt' => $resetAt];
            $write = $pdo->prepare('INSERT INTO rate_limit_records (institution_id,legacy_key,scope,subject_hash,count_value,reset_at,payload_json) VALUES (:institution_id,:legacy_key,NULL,NULL,:count_value,:reset_at,:payload_json) ON DUPLICATE KEY UPDATE count_value=VALUES(count_value),reset_at=VALUES(reset_at),payload_json=VALUES(payload_json)');
            $write->execute(['institution_id' => $institutionId, 'legacy_key' => $key, 'count_value' => $count, 'reset_at' => gmdate('Y-m-d H:i:s', $resetAt) . '.000000', 'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            $pdo->commit(); return true;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($attempt < 4 && mysqlRetryableTransactionFailure($error)) { usleep((int)(2000 * (1 << $attempt) + random_int(0, 2000))); continue; }
            throw $error;
        }
    }
    throw new RuntimeException('Rate-limit transaction retry budget exhausted.');
}
function mysqlConsumePlatformRateLimit(string $key, int $maximum, int $windowSeconds): bool {
    $pdo = mysqlAppPdo(); $now = time();
    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT count_value,reset_at,payload_json FROM platform_rate_limit_records WHERE legacy_key = :legacy_key FOR UPDATE');
        $select->execute(['legacy_key' => $key]); $row = $select->fetch();
        $payload = $row ? mysqlJson($row['payload_json'], []) : [];
        $resetAt = isset($payload['resetAt']) ? (int)$payload['resetAt'] : (isset($row['reset_at']) ? strtotime((string)$row['reset_at']) : 0);
        $count = $row ? (int)$row['count_value'] : 0;
        if ($resetAt <= $now) { $count = 0; $resetAt = $now + $windowSeconds; }
        if ($count >= $maximum) { $pdo->commit(); return false; }
        $count++; $payload = ['count' => $count, 'resetAt' => $resetAt];
        $write = $pdo->prepare('INSERT INTO platform_rate_limit_records (legacy_key,count_value,reset_at,payload_json) VALUES (:legacy_key,:count_value,:reset_at,:payload_json) ON DUPLICATE KEY UPDATE count_value=VALUES(count_value),reset_at=VALUES(reset_at),payload_json=VALUES(payload_json)');
        $write->execute(['legacy_key' => $key, 'count_value' => $count, 'reset_at' => gmdate('Y-m-d H:i:s', $resetAt) . '.000000', 'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $pdo->commit(); return true;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
function mysqlClearRateLimit(string $key): void {
    $statement = mysqlAppPdo()->prepare('DELETE FROM rate_limit_records WHERE institution_id = :institution_id AND legacy_key = :legacy_key');
    $statement->execute(['institution_id' => mysqlCurrentInstitutionId(), 'legacy_key' => $key]);
}
function mysqlDeletePlatformSession(string $tokenHash): void {
    $statement = mysqlAppPdo()->prepare('DELETE FROM platform_admin_sessions WHERE token_hash = :token_hash');
    $statement->execute(['token_hash' => $tokenHash]);
}
function mysqlPersistPlatformAdmin(array $account): void {
    $profile = [];
    foreach (['twoFactor', 'twoFactorPending'] as $key) if (isset($account[$key]) && is_array($account[$key])) $profile[$key] = $account[$key];
    $statement = mysqlAppPdo()->prepare('UPDATE platform_admin_users SET name = :name, phone_number = :phone_number, password_hash = :password_hash, must_change_password = :must_change_password, profile_json = :profile_json, updated_at = UTC_TIMESTAMP(6) WHERE id = :id');
    $statement->execute(['id' => 'platform-superadmin', 'name' => (string)$account['name'], 'phone_number' => (string)($account['phoneNumber'] ?? ''), 'password_hash' => (string)$account['passwordHash'], 'must_change_password' => !empty($account['mustChangePassword']) ? 1 : 0, 'profile_json' => json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
}
function mysqlCreatePlatformSession(array $account, string $tokenHash, string $correlationId, string $createdAt, string $expiresAt): void {
    $statement = mysqlAppPdo()->prepare('INSERT INTO platform_admin_sessions (token_hash,user_id,email,name,correlation_id,created_at,last_seen_at,expires_at) VALUES (:token_hash,:user_id,:email,:name,:correlation_id,:created_at,:last_seen_at,:expires_at)');
    $time = (new DateTimeImmutable($createdAt))->format('Y-m-d H:i:s.u');
    $statement->execute(['token_hash' => $tokenHash, 'user_id' => (string)$account['id'], 'email' => (string)$account['email'], 'name' => (string)$account['name'], 'correlation_id' => $correlationId, 'created_at' => $time, 'last_seen_at' => $time, 'expires_at' => (new DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s.u')]);
}
function mysqlPlatformTwoFactorChallenge(string $tokenHash): ?array {
    $statement = mysqlAppPdo()->prepare('SELECT token_hash,user_id,email,password_rate_limit_key,attempts,expires_at FROM platform_admin_two_factor_challenges WHERE token_hash = :token_hash LIMIT 1');
    $statement->execute(['token_hash' => $tokenHash]);
    $row = $statement->fetch();
    if (!$row) return null;
    return ['tokenHash' => (string)$row['token_hash'], 'userId' => (string)$row['user_id'], 'email' => (string)$row['email'], 'passwordRateLimitKey' => $row['password_rate_limit_key'], 'attempts' => (int)$row['attempts'], 'expiresAt' => mysqlIso($row['expires_at']), 'scope' => 'platform'];
}
function mysqlCreatePlatformTwoFactorChallenge(array $account, string $tokenHash, string $rateLimitKey, string $expiresAt): void {
    $remove = mysqlAppPdo()->prepare('DELETE FROM platform_admin_two_factor_challenges WHERE user_id = :user_id');
    $remove->execute(['user_id' => (string)$account['id']]);
    $insert = mysqlAppPdo()->prepare('INSERT INTO platform_admin_two_factor_challenges (token_hash,user_id,email,password_rate_limit_key,attempts,expires_at) VALUES (:token_hash,:user_id,:email,:rate_limit_key,0,:expires_at)');
    $insert->execute(['token_hash' => $tokenHash, 'user_id' => (string)$account['id'], 'email' => (string)$account['email'], 'rate_limit_key' => $rateLimitKey, 'expires_at' => (new DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s.u')]);
}
function mysqlUpdatePlatformTwoFactorChallenge(array $challenge): void {
    $statement = mysqlAppPdo()->prepare('UPDATE platform_admin_two_factor_challenges SET attempts = :attempts WHERE token_hash = :token_hash');
    $statement->execute(['attempts' => (int)$challenge['attempts'], 'token_hash' => (string)$challenge['tokenHash']]);
}
function mysqlDeletePlatformTwoFactorChallenge(string $tokenHash): void {
    $statement = mysqlAppPdo()->prepare('DELETE FROM platform_admin_two_factor_challenges WHERE token_hash = :token_hash');
    $statement->execute(['token_hash' => $tokenHash]);
}
function mysqlInsertPlatformAudit(PDO $pdo, string $actorId, string $action, ?int $institutionId, string $targetType, string $targetId, string $outcome, string $correlationId, array $metadata = [], array $beforeAfter = []): void {
    $statement = $pdo->prepare('INSERT INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,ip_address,user_agent,before_after_json,metadata_json) VALUES (:id,UTC_TIMESTAMP(6),:actor_id,:action_type,:institution_id,:target_type,:target_id,:outcome,:correlation_id,:ip_address,:user_agent,:before_after_json,:metadata_json)');
    $statement->execute(['id' => bin2hex(random_bytes(12)), 'actor_id' => $actorId, 'action_type' => $action, 'institution_id' => $institutionId, 'target_type' => $targetType, 'target_id' => $targetId, 'outcome' => $outcome, 'correlation_id' => $correlationId, 'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 4000), 'before_after_json' => json_encode($beforeAfter, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
}

/** Storage keys are database values, never paths supplied by a browser. */
function mysqlInstitutionAssetStorageKey(string $storageKey): ?string {
    $storageKey = str_replace('\\', '/', ltrim($storageKey, '/'));
    if (str_contains($storageKey, '..')) return null;
    return (str_starts_with($storageKey, 'uploads/institution-logos/') || str_starts_with($storageKey, 'uploads/pdf-imports/')) ? $storageKey : null;
}
function mysqlInstitutionLogoStorageKey(string $storageKey): ?string { $key = mysqlInstitutionAssetStorageKey($storageKey); return $key !== null && str_starts_with($key, 'uploads/institution-logos/') ? $key : null; }
function mysqlStorageAbsolutePath(string $storageKey): ?string {
    $key = mysqlInstitutionAssetStorageKey($storageKey);
    if ($key === null) return null;
    $subdirectory = str_starts_with($key, 'uploads/pdf-imports/') ? 'pdf-imports' : 'institution-logos';
    $root = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $subdirectory);
    if ($root === false) return null;
    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    $parent = realpath(dirname($path));
    if ($parent === false || !str_starts_with($parent . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) return null;
    return $path;
}
function mysqlRegisterInstitutionAsset(PDO $pdo, int $institutionId, string $assetType, string $storageKey, ?string $createdBy = null, array $metadata = []): void {
    $storageKey = mysqlInstitutionAssetStorageKey($storageKey) ?? throw new InvalidArgumentException('Unsupported institution asset key.');
    $path = mysqlStorageAbsolutePath($storageKey);
    if ($path === null || !is_file($path)) throw new RuntimeException('The uploaded institution asset could not be found.');
    $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null;
    $statement = $pdo->prepare("INSERT INTO institution_assets (institution_id,asset_type,storage_key,sha256,size_bytes,mime_type,asset_state,created_by,created_at,referenced_at,metadata_json) VALUES (:institution_id,:asset_type,:storage_key,:sha256,:size_bytes,:mime_type,'active',:created_by,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),:metadata_json) ON DUPLICATE KEY UPDATE asset_type=VALUES(asset_type),sha256=VALUES(sha256),size_bytes=VALUES(size_bytes),mime_type=VALUES(mime_type),asset_state='active',created_by=COALESCE(VALUES(created_by),created_by),referenced_at=UTC_TIMESTAMP(6),unreferenced_at=NULL,expires_at=NULL,metadata_json=VALUES(metadata_json)");
    $statement->execute(['institution_id' => $institutionId, 'asset_type' => $assetType, 'storage_key' => $storageKey, 'sha256' => hash_file('sha256', $path), 'size_bytes' => (int)filesize($path), 'mime_type' => $mime, 'created_by' => $createdBy, 'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
}
function mysqlMarkInstitutionAssetUnreferenced(PDO $pdo, int $institutionId, string $storageKey, int $retentionHours = 168): void {
    $storageKey = mysqlInstitutionAssetStorageKey($storageKey);
    if ($storageKey === null) return;
    $retentionHours = max(1, min(24 * 365, $retentionHours));
    $statement = $pdo->prepare("UPDATE institution_assets SET asset_state='unreferenced',unreferenced_at=UTC_TIMESTAMP(6),expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL {$retentionHours} HOUR) WHERE institution_id=:institution_id AND storage_key=:storage_key AND asset_state='active'");
    $statement->execute(['institution_id' => $institutionId, 'storage_key' => $storageKey]);
}
function mysqlRegisterBackupRecord(int $institutionId, array $backup, string $backupType, ?string $createdBy = null, array $metadata = []): void {
    $filename = basename((string)($backup['filename'] ?? ''));
    if ($filename === '') throw new InvalidArgumentException('Backup filename is required.');
    $statement = mysqlAppPdo()->prepare('INSERT INTO backup_records (institution_id,filename,backup_type,created_at,created_by,size_bytes,checksum_sha256,metadata_json) VALUES (:institution_id,:filename,:backup_type,UTC_TIMESTAMP(6),:created_by,:size_bytes,:checksum_sha256,:metadata_json) ON DUPLICATE KEY UPDATE backup_type=VALUES(backup_type),created_by=COALESCE(VALUES(created_by),created_by),size_bytes=VALUES(size_bytes),checksum_sha256=VALUES(checksum_sha256),metadata_json=VALUES(metadata_json)');
    $statement->execute(['institution_id' => $institutionId, 'filename' => $filename, 'backup_type' => $backupType, 'created_by' => $createdBy, 'size_bytes' => (int)($backup['size'] ?? 0), 'checksum_sha256' => (string)($backup['checksum'] ?? ''), 'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
}
function mysqlDeleteBackupRecord(int $institutionId, string $filename): void {
    $statement = mysqlAppPdo()->prepare('DELETE FROM backup_records WHERE institution_id=:institution_id AND filename=:filename');
    $statement->execute(['institution_id' => $institutionId, 'filename' => basename($filename)]);
}
function mysqlBackupRecordMap(int $institutionId): array {
    $statement = mysqlAppPdo()->prepare('SELECT filename,backup_type,size_bytes,checksum_sha256,metadata_json FROM backup_records WHERE institution_id=:institution_id');
    $statement->execute(['institution_id' => $institutionId]); $records = [];
    foreach ($statement->fetchAll() as $row) $records[(string)$row['filename']] = ['type' => (string)$row['backup_type'], 'size' => $row['size_bytes'] === null ? null : (int)$row['size_bytes'], 'checksum' => $row['checksum_sha256'], 'metadata' => mysqlJson($row['metadata_json'] ?? null, [])];
    return $records;
}
/** Registers legacy logo files referenced by branding before any cleanup runs. */
function mysqlReconcileInstitutionAssets(PDO $pdo, int $institutionId, bool $dryRun = true): array {
    $brands = $pdo->prepare('SELECT logo_path FROM institution_branding WHERE institution_id=:institution_id');
    $brands->execute(['institution_id' => $institutionId]); $registered = 0; $missing = 0;
    foreach ($brands->fetchAll() as $brand) {
        $key = mysqlInstitutionLogoStorageKey((string)$brand['logo_path']); if ($key === null) continue;
        $path = mysqlStorageAbsolutePath($key);
        if ($path === null || !is_file($path)) { $missing++; continue; }
        if (!$dryRun) mysqlRegisterInstitutionAsset($pdo, $institutionId, 'institution_logo', $key, null, ['reconciled' => true]);
        $registered++;
    }
    return ['referencedLogoFiles' => $registered, 'missingReferencedLogoFiles' => $missing];
}
/** Only assets already marked unreferenced are eligible; active branding is never glob-deleted. */
function mysqlPruneUnreferencedInstitutionAssets(PDO $pdo, int $institutionId, bool $dryRun = true): array {
    $statement = $pdo->prepare("SELECT id,storage_key FROM institution_assets WHERE institution_id=:institution_id AND asset_state='unreferenced' AND expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP(6)");
    $statement->execute(['institution_id' => $institutionId]); $candidates = $statement->fetchAll(); $removed = [];
    foreach ($candidates as $candidate) {
        $path = mysqlStorageAbsolutePath((string)$candidate['storage_key']);
        if ($path === null) continue;
        if (!$dryRun && is_file($path) && !unlink($path)) continue;
        if (!$dryRun) $pdo->prepare("UPDATE institution_assets SET asset_state='deleted',expires_at=UTC_TIMESTAMP(6) WHERE id=:id AND institution_id=:institution_id")->execute(['id' => $candidate['id'], 'institution_id' => $institutionId]);
        $removed[] = (string)$candidate['storage_key'];
    }
    return ['candidates' => count($candidates), 'removed' => $removed];
}
/** Routine backup pruning is file-count plus monthly-history retention. Safety
 * snapshots deliberately do not match this filename pattern. */
function mysqlPruneRoutineBackupFiles(PDO $pdo, int $institutionId, string $directory, int $recentCount, bool $dryRun = true): array {
    $items = [];
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*-backup_*.json') ?: [] as $path) if (is_file($path) && preg_match('/^(?:berevion|cacsa-cbt)-backup_\d{4}-\d{2}-\d{2}_\d{6}(?:-\d+)?\.json$/', basename($path))) $items[] = ['path' => $path, 'filename' => basename($path), 'createdAt' => (int)filemtime($path)];
    usort($items, fn(array $a, array $b): int => $b['createdAt'] <=> $a['createdAt']);
    $cutoff = strtotime('-12 months'); $keep = []; $months = []; $removed = [];
    foreach ($items as $offset => $item) {
        if ($offset < max(1, $recentCount)) { $keep[$item['filename']] = true; continue; }
        if ($item['createdAt'] < $cutoff) continue;
        $month = gmdate('Y-m', $item['createdAt']);
        if (!isset($months[$month])) { $months[$month] = true; $keep[$item['filename']] = true; }
    }
    foreach ($items as $item) {
        if (isset($keep[$item['filename']])) continue;
        if (!$dryRun && !unlink($item['path'])) continue;
        if (!$dryRun) $pdo->prepare('DELETE FROM backup_records WHERE institution_id=:institution_id AND filename=:filename')->execute(['institution_id' => $institutionId, 'filename' => $item['filename']]);
        $removed[] = $item['filename'];
    }
    return ['routineCandidates' => count($items), 'removed' => $removed, 'retained' => count($keep)];
}
function mysqlPlatformAudit(string $actorId, string $action, ?int $institutionId, string $targetType, string $targetId, string $outcome, string $correlationId, array $metadata = [], array $beforeAfter = []): void {
    mysqlInsertPlatformAudit(mysqlAppPdo(), $actorId, $action, $institutionId, $targetType, $targetId, $outcome, $correlationId, $metadata, $beforeAfter);
}
/** Platform-only provisioner. Tenant data is created in one transaction so an
 * incomplete institution can never become available to a browser request. */
function mysqlProvisionInstitution(array $input): array {
    $pdo = mysqlAppPdo();
    $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $pdo->beginTransaction();
    try {
        $insertInstitution = $pdo->prepare('INSERT INTO institutions (name,slug,active,created_at) VALUES (:name,:slug,1,:created_at)');
        $insertInstitution->execute(['name' => $input['name'], 'slug' => $input['slug'], 'created_at' => $createdAt]);
        $institutionId = (int)$pdo->lastInsertId();
        $brand = $input['branding'];
        $pdo->prepare('INSERT INTO institution_branding (institution_id,display_name,portal_title,logo_path,favicon_path,accent_source_color,primary_color,accent_color,nav_label,assessment_label,footer_primary,footer_secondary,footer_legal,result_sheet_title,newsletter_sender_name,support_email,updated_at) VALUES (:institution_id,:display_name,:portal_title,:logo_path,:favicon_path,:accent_source_color,:primary_color,:accent_color,:nav_label,:assessment_label,:footer_primary,:footer_secondary,:footer_legal,:result_sheet_title,:newsletter_sender_name,:support_email,:updated_at)')->execute([
            'institution_id' => $institutionId, 'display_name' => $brand['displayName'], 'portal_title' => $brand['portalTitle'], 'logo_path' => $brand['logoPath'], 'favicon_path' => $brand['faviconPath'], 'accent_source_color' => $brand['accentSourceColor'] ?? $brand['primaryColor'], 'primary_color' => $brand['primaryColor'], 'accent_color' => $brand['accentColor'], 'nav_label' => $brand['navLabel'], 'assessment_label' => $brand['assessmentLabel'], 'footer_primary' => $brand['footerPrimary'], 'footer_secondary' => $brand['footerSecondary'], 'footer_legal' => $brand['footerLegal'], 'result_sheet_title' => $brand['resultSheetTitle'], 'newsletter_sender_name' => $brand['newsletterSenderName'], 'support_email' => $brand['supportEmail'] ?: null, 'updated_at' => $createdAt
        ]);
        if (mysqlInstitutionLogoStorageKey((string)$brand['logoPath']) !== null) mysqlRegisterInstitutionAsset($pdo, $institutionId, 'institution_logo', (string)$brand['logoPath'], (string)$input['actorEmail'], ['source' => 'institution_provisioning']);
        $insertRole = $pdo->prepare('INSERT INTO roles (institution_id,id,name,description,max_users,system_locked) VALUES (:institution_id,:id,:name,:description,:max_users,:system_locked)');
        $insertPermission = $pdo->prepare('INSERT INTO role_permissions (institution_id,role_id,permission) VALUES (:institution_id,:role_id,:permission)');
        foreach ($input['roles'] as $role) {
            $insertRole->execute(['institution_id' => $institutionId, 'id' => $role['id'], 'name' => $role['name'], 'description' => $role['description'], 'max_users' => $role['maxUsers'], 'system_locked' => !empty($role['systemLocked']) ? 1 : 0]);
            foreach ($role['permissions'] as $permission) $insertPermission->execute(['institution_id' => $institutionId, 'role_id' => $role['id'], 'permission' => $permission]);
        }
        $pdo->prepare('INSERT INTO admin_users (institution_id,id,role_id,name,email,password_hash,active,verified,must_change_password,created_at,approved_at,approved_by) VALUES (:institution_id,:id,\'admin\',:name,:email,:password_hash,1,1,1,:created_at,:approved_at,:approved_by)')->execute([
            'institution_id' => $institutionId, 'id' => $input['adminId'], 'name' => $input['adminName'], 'email' => $input['adminEmail'], 'password_hash' => $input['adminPasswordHash'], 'created_at' => $createdAt, 'approved_at' => $createdAt, 'approved_by' => $input['actorEmail']
        ]);
        $insertBand = $pdo->prepare('INSERT INTO grading_scale_bands (institution_id,ordinal,min_score,max_score,grade,grade_point) VALUES (:institution_id,:ordinal,:min_score,:max_score,:grade,:grade_point)');
        foreach ($input['gradingScale'] as $ordinal => $band) $insertBand->execute(['institution_id' => $institutionId, 'ordinal' => $ordinal + 1, 'min_score' => $band['minScore'], 'max_score' => $band['maxScore'], 'grade' => $band['grade'], 'grade_point' => $band['gradePoint']]);
        $insertPolicy = $pdo->prepare('INSERT INTO integrity_policies (institution_id,event_type,mode,lock_after) VALUES (:institution_id,:event_type,:mode,:lock_after)');
        foreach ($input['integrityPolicy'] as $event => $policy) $insertPolicy->execute(['institution_id' => $institutionId, 'event_type' => $event, 'mode' => $policy['mode'], 'lock_after' => $policy['lockAfter']]);
        $insertSetting = $pdo->prepare('INSERT INTO institution_settings (institution_id,setting_key,setting_json) VALUES (:institution_id,:setting_key,:setting_json)');
        foreach ($input['settings'] as $key => $value) $insertSetting->execute(['institution_id' => $institutionId, 'setting_key' => $key, 'setting_json' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $pdo->prepare('INSERT INTO academic_sessions (institution_id,id,label,is_active,created_at) VALUES (:institution_id,:id,:label,1,:created_at)')->execute(['institution_id' => $institutionId, 'id' => 'initial-session', 'label' => 'Set academic session in Settings', 'created_at' => $createdAt]);
        mysqlInsertPlatformAudit($pdo, $input['actorEmail'], 'institution_created', $institutionId, 'institution', (string)$institutionId, 'success', $input['correlationId'], ['name' => $input['name'], 'slug' => $input['slug'], 'initialAdminEmail' => $input['adminEmail'], 'forcedPasswordChange' => true]);
        $pdo->commit();
        return ['id' => $institutionId, 'name' => $input['name'], 'slug' => $input['slug'], 'adminEmail' => $input['adminEmail'], 'adminName' => $input['adminName'], 'logoPath' => $brand['logoPath'], 'createdAt' => $createdAt];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
function mysqlPlatformInstitutionRows(?int $institutionId = null): array {
    $sql = 'SELECT i.id,i.name,i.slug,i.active,i.created_at,b.logo_path,b.display_name,b.portal_title,b.favicon_path,b.accent_source_color,b.primary_color,b.accent_color,b.nav_label,b.assessment_label,b.footer_primary,b.footer_secondary,b.footer_legal,b.result_sheet_title,b.newsletter_sender_name,b.support_email,u.email AS admin_email FROM institutions i LEFT JOIN institution_branding b ON b.institution_id=i.id LEFT JOIN admin_users u ON u.institution_id=i.id AND u.role_id=\'admin\'';
    $sql .= ' WHERE i.deleted_at IS NULL';
    if ($institutionId !== null) $sql .= ' AND i.id = :institution_id';
    $sql .= ' ORDER BY i.created_at DESC';
    $statement = mysqlAppPdo()->prepare($sql);
    $statement->execute($institutionId === null ? [] : ['institution_id' => $institutionId]);
    $items = [];
    foreach ($statement->fetchAll() as $row) $items[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'slug' => (string)$row['slug'], 'active' => mysqlBool($row['active']), 'createdAt' => mysqlIso($row['created_at']), 'displayName' => (string)($row['display_name'] ?? $row['name']), 'logoPath' => (string)($row['logo_path'] ?? ''), 'adminEmail' => (string)($row['admin_email'] ?? ''), 'branding' => ['displayName' => (string)($row['display_name'] ?? $row['name']), 'portalTitle' => (string)($row['portal_title'] ?? ''), 'logoPath' => (string)($row['logo_path'] ?? ''), 'faviconPath' => (string)($row['favicon_path'] ?? ''), 'accentSourceColor' => (string)($row['accent_source_color'] ?? $row['primary_color'] ?? '#16774d'), 'primaryColor' => (string)($row['primary_color'] ?? '#16774d'), 'accentColor' => (string)($row['accent_color'] ?? '#105839'), 'navLabel' => (string)($row['nav_label'] ?? ''), 'assessmentLabel' => (string)($row['assessment_label'] ?? ''), 'footerPrimary' => (string)($row['footer_primary'] ?? ''), 'footerSecondary' => (string)($row['footer_secondary'] ?? ''), 'footerLegal' => (string)($row['footer_legal'] ?? ''), 'resultSheetTitle' => (string)($row['result_sheet_title'] ?? ''), 'newsletterSenderName' => (string)($row['newsletter_sender_name'] ?? ''), 'supportEmail' => (string)($row['support_email'] ?? '')]];
    return $items;
}
function mysqlPlatformInstitutions(): array { return mysqlPlatformInstitutionRows(); }
function mysqlPlatformInstitution(int $institutionId): ?array { return mysqlPlatformInstitutionRows($institutionId)[0] ?? null; }
/** Access suspension is intentionally a single flag change: no tenant data,
 * sessions, backups, or academic history is deleted or rewritten. */
function mysqlSetInstitutionActive(int $institutionId, bool $active, string $actorEmail, string $correlationId): ?array {
    $pdo = mysqlAppPdo(); $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT id,name,slug,active FROM institutions WHERE id = :institution_id FOR UPDATE');
        $select->execute(['institution_id' => $institutionId]); $before = $select->fetch();
        if (!$before) { $pdo->rollBack(); return null; }
        if (mysqlBool($before['active']) !== $active) {
            $pdo->prepare('UPDATE institutions SET active = :active WHERE id = :institution_id')->execute(['institution_id' => $institutionId, 'active' => $active ? 1 : 0]);
            mysqlInsertPlatformAudit($pdo, $actorEmail, $active ? 'institution_reactivated' : 'institution_suspended', $institutionId, 'institution', (string)$institutionId, 'success', $correlationId, ['slug' => (string)$before['slug'], 'dataDeleted' => false], ['before' => ['active' => mysqlBool($before['active'])], 'after' => ['active' => $active]]);
        }
        $pdo->commit(); return mysqlPlatformInstitution($institutionId);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
function mysqlInstitutionSafetyBackup(PDO $pdo, int $institutionId, string $slug, string $reason, string $actorEmail): array {
    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Could not create the institution safety-backup directory.');
    $tables = ['institution_branding','institution_assets','storage_migrations','academic_sessions','academic_semesters','roles','role_permissions','students','courses','course_components','questions','question_options','question_publish_targets','assessment_passwords','assessment_login_tokens','assessment_attempts','attempt_question_snapshots','attempt_answers','attempt_flags','attempt_integrity_events','component_submissions','calculated_results','calculated_result_items','admin_users','admin_sessions','admin_profile_overrides','admin_user_activity','pending_admin_requests','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','audit_events','audit_archives','rate_limit_records','exam_login_failures','exam_login_ip_attempts','exam_flags','newsletter_subscribers','newsletters','newsletter_deliveries','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','backup_records'];
    $snapshot = ['_backup' => ['kind' => 'institution-soft-delete-safety', 'institutionId' => $institutionId, 'slug' => $slug, 'reason' => $reason, 'createdBy' => $actorEmail, 'createdAt' => gmdate('c')]];
    foreach ($tables as $table) { $statement = $pdo->prepare("SELECT * FROM {$table} WHERE institution_id = :institution_id"); $statement->execute(['institution_id' => $institutionId]); $snapshot[$table] = $statement->fetchAll(); }
    $filename = 'institution-soft-delete-safety-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json, LOCK_EX) === false) throw new RuntimeException('Could not write the institution safety backup.');
    return ['filename' => $filename, 'path' => $path, 'size' => strlen($json), 'checksum' => hash('sha256', $json)];
}
/** Soft deletion deliberately leaves every tenant row and all academic/audit
 * history intact. No permanent removal exists in this application path. */
function mysqlSoftDeleteInstitution(int $institutionId, string $reason, string $actorEmail, string $correlationId): ?array {
    $pdo = mysqlAppPdo(); $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT id,name,slug,active,deleted_at FROM institutions WHERE id = :institution_id FOR UPDATE');
        $select->execute(['institution_id' => $institutionId]); $institution = $select->fetch();
        if (!$institution || $institution['deleted_at'] !== null) { $pdo->rollBack(); return null; }
        $backup = mysqlInstitutionSafetyBackup($pdo, $institutionId, (string)$institution['slug'], $reason, $actorEmail);
        $retention = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d H:i:s.u');
        $pdo->prepare('INSERT INTO backup_records (institution_id,filename,backup_type,created_at,created_by,size_bytes,checksum_sha256,metadata_json) VALUES (:institution_id,:filename,\'safety\',UTC_TIMESTAMP(6),:created_by,:size_bytes,:checksum_sha256,:metadata_json)')->execute(['institution_id'=>$institutionId,'filename'=>$backup['filename'],'created_by'=>$actorEmail,'size_bytes'=>$backup['size'],'checksum_sha256'=>$backup['checksum'],'metadata_json'=>json_encode(['purpose'=>'institution_soft_delete','reason'=>$reason,'retentionUntil'=>$retention], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $pdo->prepare('UPDATE institutions SET active=0,deleted_at=UTC_TIMESTAMP(6),deleted_by=:deleted_by,deletion_reason=:reason,retention_until=:retention_until WHERE id=:institution_id')->execute(['institution_id'=>$institutionId,'deleted_by'=>$actorEmail,'reason'=>$reason,'retention_until'=>$retention]);
        mysqlInsertPlatformAudit($pdo, $actorEmail, 'institution_soft_deleted', $institutionId, 'institution', (string)$institutionId, 'success', $correlationId, ['slug'=>(string)$institution['slug'],'reason'=>$reason,'safetyBackup'=>$backup['filename'],'retentionUntil'=>$retention,'permanentDeletionBuilt'=>false], ['before'=>['active'=>mysqlBool($institution['active']),'deletedAt'=>null],'after'=>['active'=>false,'softDeleted'=>true,'retentionUntil'=>$retention]]);
        $pdo->commit(); return ['id'=>$institutionId,'name'=>(string)$institution['name'],'slug'=>(string)$institution['slug'],'safetyBackup'=>$backup['filename'],'retentionUntil'=>$retention];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
/** Branding changes are platform-only and deliberately update no tenant academic table. */
function mysqlUpdateInstitutionBranding(int $institutionId, array $brand, string $actorEmail, string $correlationId): ?array {
    $pdo = mysqlAppPdo(); $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT i.name,i.slug,b.display_name,b.portal_title,b.logo_path,b.favicon_path,b.accent_source_color,b.primary_color,b.accent_color,b.nav_label,b.assessment_label,b.footer_primary,b.footer_secondary,b.footer_legal,b.result_sheet_title,b.newsletter_sender_name,b.support_email FROM institutions i INNER JOIN institution_branding b ON b.institution_id=i.id WHERE i.id=:institution_id FOR UPDATE');
        $select->execute(['institution_id' => $institutionId]); $before = $select->fetch();
        if (!$before) { $pdo->rollBack(); return null; }
        $pdo->prepare('UPDATE institutions SET name=:name WHERE id=:institution_id')->execute(['institution_id' => $institutionId, 'name' => $brand['name']]);
        $pdo->prepare('UPDATE institution_branding SET display_name=:display_name,portal_title=:portal_title,logo_path=:logo_path,accent_source_color=:accent_source_color,primary_color=:primary_color,accent_color=:accent_color,nav_label=:nav_label,assessment_label=:assessment_label,footer_primary=:footer_primary,footer_secondary=:footer_secondary,footer_legal=:footer_legal,result_sheet_title=:result_sheet_title,newsletter_sender_name=:newsletter_sender_name,support_email=:support_email,updated_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id')->execute(['institution_id'=>$institutionId,'display_name'=>$brand['displayName'],'portal_title'=>$brand['portalTitle'],'logo_path'=>$brand['logoPath'],'accent_source_color'=>$brand['accentSourceColor'],'primary_color'=>$brand['primaryColor'],'accent_color'=>$brand['accentColor'],'nav_label'=>$brand['navLabel'],'assessment_label'=>$brand['assessmentLabel'],'footer_primary'=>$brand['footerPrimary'],'footer_secondary'=>$brand['footerSecondary'],'footer_legal'=>$brand['footerLegal'],'result_sheet_title'=>$brand['resultSheetTitle'],'newsletter_sender_name'=>$brand['newsletterSenderName'],'support_email'=>$brand['supportEmail'] ?: null]);
        if ((string)$before['logo_path'] !== (string)$brand['logoPath']) {
            mysqlMarkInstitutionAssetUnreferenced($pdo, $institutionId, (string)$before['logo_path']);
            if (mysqlInstitutionLogoStorageKey((string)$brand['logoPath']) !== null) mysqlRegisterInstitutionAsset($pdo, $institutionId, 'institution_logo', (string)$brand['logoPath'], $actorEmail, ['source' => 'branding_update']);
        }
        $after = ['name'=>$brand['name'],'display_name'=>$brand['displayName'],'portal_title'=>$brand['portalTitle'],'logo_path'=>$brand['logoPath'],'accent_source_color'=>$brand['accentSourceColor'],'primary_color'=>$brand['primaryColor'],'accent_color'=>$brand['accentColor'],'nav_label'=>$brand['navLabel'],'assessment_label'=>$brand['assessmentLabel'],'footer_primary'=>$brand['footerPrimary'],'footer_secondary'=>$brand['footerSecondary'],'footer_legal'=>$brand['footerLegal'],'result_sheet_title'=>$brand['resultSheetTitle'],'newsletter_sender_name'=>$brand['newsletterSenderName'],'support_email'=>$brand['supportEmail'] ?: null];
        mysqlInsertPlatformAudit($pdo, $actorEmail, 'institution_branding_updated', $institutionId, 'institution_branding', (string)$institutionId, 'success', $correlationId, ['slug' => (string)$before['slug'], 'resolvedAccent' => ['light' => $brand['primaryColor'], 'dark' => $brand['accentColor']]], ['before' => $before, 'after' => $after]);
        $pdo->commit(); return mysqlPlatformInstitution($institutionId);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
function mysqlRows(PDO $pdo, string $table): array {
    static $allowed = [
        'academic_sessions','academic_semesters','courses','course_components','students','questions','question_options','question_publish_targets','assessment_passwords','assessment_login_tokens','assessment_attempts','component_submissions','calculated_results','calculated_result_items','roles','role_permissions','admin_users','admin_sessions','admin_profile_overrides','admin_user_activity','pending_admin_requests','admin_email_verifications','admin_password_resets','admin_two_factor_challenges','emergency_recovery_codes','audit_events','rate_limit_records','exam_login_failures','exam_login_ip_attempts','exam_flags','newsletter_subscribers','newsletters','newsletter_deliveries','grading_scale_bands','integrity_policies','institution_settings','dashboard_hidden_outcomes','attempt_integrity_events'
    ];
    if (!in_array($table, $allowed, true)) throw new LogicException('Unknown MySQL storage table.');
    $statement = $pdo->prepare("SELECT * FROM {$table} WHERE institution_id = :institution_id");
    $statement->execute(['institution_id' => mysqlCurrentInstitutionId()]);
    return $statement->fetchAll();
}
function mysqlJson(mixed $value, mixed $fallback = []): mixed {
    if ($value === null || $value === '') return $fallback;
    try { $decoded = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR); return $decoded; }
    catch (Throwable) { return $fallback; }
}
function mysqlIso(mixed $value): ?string {
    if ($value === null || $value === '') return null;
    try { return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))->format('c'); }
    catch (Throwable) { return (string)$value; }
}
function mysqlBool(mixed $value): bool { return (bool)(int)$value; }

/** A stable comparison which preserves list ordering while ignoring incidental
 * associative-key ordering from PHP/MySQL hydration. */
function mysqlTargetedComparable(mixed $value): string {
    $normalise = static function (mixed $item) use (&$normalise): mixed {
        if (!is_array($item)) return $item;
        if (array_is_list($item)) return array_map($normalise, $item);
        ksort($item);
        foreach ($item as $key => $child) $item[$key] = $normalise($child);
        return $item;
    };
    return json_encode($normalise($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}
/** @return array<string,array> */
function mysqlTargetedRecordMap(array $rows, string $key): array {
    $map = [];
    foreach ($rows as $row) if (is_array($row) && isset($row[$key]) && (string)$row[$key] !== '') $map[(string)$row[$key]] = $row;
    return $map;
}
/** @return array{0:list<array>,1:list<array>} changed, removed */
function mysqlTargetedDiffRows(array $before, array $after, string $key): array {
    $old = mysqlTargetedRecordMap($before, $key); $new = mysqlTargetedRecordMap($after, $key); $changed = [];
    foreach ($new as $id => $row) if (!isset($old[$id]) || !hash_equals(mysqlTargetedComparable($old[$id]), mysqlTargetedComparable($row))) $changed[] = $row;
    $removed = []; foreach ($old as $id => $row) if (!isset($new[$id])) $removed[] = $row;
    return [$changed, $removed];
}
function mysqlTargetedDelete(array &$plans, string $table, string $column, ?string $value, int $priority, array $additionalWhere = []): void {
    $plans[] = ['table'=>$table, 'column'=>$column, 'value'=>$value, 'priority'=>$priority, 'additionalWhere'=>$additionalWhere];
}
/**
 * Produce the smallest legacy-shaped payload needed by the existing mapper.
 * The mapper then performs per-row UPSERTs in one transaction. This preserves
 * established handler validation while replacing the former tenant-wide
 * delete/reinsert write path.
 *
 * @return array{payload:array,deletes:list<array>,sessionChildren:array,changed:bool}
 */
function mysqlTargetedPayload(array $before, array $after): array {
    $payload = []; $deletes = []; $sessionChildren = []; $changedAny = false;
    $collections = [
        'academicSessions'=>['id','academic_sessions','id'], 'semesters'=>['id','academic_semesters','id'],
        'courses'=>['id','courses','id'], 'exams'=>['id','course_components','id'], 'students'=>['id','students','id'],
        'questions'=>['id','questions','id'], 'roles'=>['id','roles','id'], 'adminUsers'=>['id','admin_users','id'],
        'passwords'=>['id','assessment_passwords','id'], 'loginTokens'=>['tokenHash','assessment_login_tokens','token_hash'],
        'sessions'=>['id','assessment_attempts','id'], 'results'=>['id','component_submissions','id'],
        'calculatedResults'=>['id','calculated_results','id'], 'adminSessions'=>['tokenHash','admin_sessions','token_hash'],
        'pendingAdminRequests'=>['id','pending_admin_requests','id'], 'adminEmailVerifications'=>['userId','admin_email_verifications','user_id'],
        'adminPasswordResets'=>['id','admin_password_resets','id'], 'admin2faChallenges'=>['tokenHash','admin_two_factor_challenges','token_hash'],
        'examFlags'=>['id','exam_flags','id'], 'newsletterSubscribers'=>['id','newsletter_subscribers','id'],
        'newsletters'=>['id','newsletters','id'], 'auditEvents'=>['id','audit_events','id'],
    ];
    foreach ($collections as $name => [$key, $table, $column]) {
        [$rows, $removed] = mysqlTargetedDiffRows((array)($before[$name] ?? []), (array)($after[$name] ?? []), $key);
        if ($rows) { $payload[$name] = $rows; $changedAny = true; }
        foreach ($removed as $row) {
            $id = (string)$row[$key];
            if ($name === 'questions') { mysqlTargetedDelete($deletes, 'question_publish_targets', 'question_id', $id, 10); mysqlTargetedDelete($deletes, 'question_options', 'question_id', $id, 10); }
            if ($name === 'sessions') { mysqlTargetedDelete($deletes, 'attempt_answers', 'attempt_id', $id, 10); mysqlTargetedDelete($deletes, 'attempt_flags', 'attempt_id', $id, 10); mysqlTargetedDelete($deletes, 'attempt_question_snapshots', 'attempt_id', $id, 10); mysqlTargetedDelete($deletes, 'attempt_integrity_events', 'attempt_id', $id, 10); mysqlTargetedDelete($deletes, 'exam_flags', 'attempt_id', $id, 15); }
            if ($name === 'calculatedResults') mysqlTargetedDelete($deletes, 'calculated_result_items', 'calculated_result_id', $id, 10);
            if ($name === 'roles') mysqlTargetedDelete($deletes, 'role_permissions', 'role_id', $id, 10);
            if ($name === 'newsletters') mysqlTargetedDelete($deletes, 'newsletter_deliveries', 'newsletter_id', $id, 10);
            mysqlTargetedDelete($deletes, $table, $column, $id, 30); $changedAny = true;
        }
        $oldRowsById = $name === 'sessions' ? mysqlTargetedRecordMap((array)($before[$name] ?? []), $key) : [];
        foreach ($rows as $row) {
            $id = (string)$row[$key];
            if ($name === 'questions') { mysqlTargetedDelete($deletes, 'question_publish_targets', 'question_id', $id, 10); mysqlTargetedDelete($deletes, 'question_options', 'question_id', $id, 10); }
            if ($name === 'sessions') {
                $old = $oldRowsById[$id] ?? null;
                $childPlan = ['questions'=>false,'answers'=>[],'flags'=>false,'integrity'=>false];
                if ($old === null || !hash_equals(mysqlTargetedComparable($old['questions'] ?? []), mysqlTargetedComparable($row['questions'] ?? []))) { $childPlan['questions'] = true; mysqlTargetedDelete($deletes, 'attempt_question_snapshots', 'attempt_id', $id, 10); }
                $oldAnswers = (array)($old['answers'] ?? []); $newAnswers = (array)($row['answers'] ?? []);
                foreach (array_unique(array_merge(array_keys($oldAnswers), array_keys($newAnswers))) as $questionId) if (!hash_equals(mysqlTargetedComparable($oldAnswers[$questionId] ?? []), mysqlTargetedComparable($newAnswers[$questionId] ?? []))) { $childPlan['answers'][] = (string)$questionId; mysqlTargetedDelete($deletes, 'attempt_answers', 'question_id', (string)$questionId, 10, ['attempt_id'=>$id]); }
                if ($old === null || !hash_equals(mysqlTargetedComparable($old['flagged'] ?? []), mysqlTargetedComparable($row['flagged'] ?? []))) { $childPlan['flags'] = true; mysqlTargetedDelete($deletes, 'attempt_flags', 'attempt_id', $id, 10); }
                if ($old === null || !hash_equals(mysqlTargetedComparable($old['integrityEvents'] ?? []), mysqlTargetedComparable($row['integrityEvents'] ?? []))) { $childPlan['integrity'] = true; mysqlTargetedDelete($deletes, 'attempt_integrity_events', 'attempt_id', $id, 10); }
                $sessionChildren[$id] = $childPlan;
            }
            if ($name === 'calculatedResults') mysqlTargetedDelete($deletes, 'calculated_result_items', 'calculated_result_id', $id, 10);
            if ($name === 'roles') mysqlTargetedDelete($deletes, 'role_permissions', 'role_id', $id, 10);
            if ($name === 'newsletters') mysqlTargetedDelete($deletes, 'newsletter_deliveries', 'newsletter_id', $id, 10);
        }
    }
    foreach (['adminProfileOverrides'=>['profileKey'=>'profile_overrides'], 'adminUserActivity'=>['userId'=>'admin_user_activity'], 'rateLimits'=>['legacyKey'=>'rate_limit_records'], 'examLoginFailures'=>['legacyKey'=>'exam_login_failures'], 'examLoginIpAttempts'=>['legacyKey'=>'exam_login_ip_attempts']] as $name => $definition) {
        $field = array_key_first($definition); $table = $definition[$field]; $old = (array)($before[$name] ?? []); $new = (array)($after[$name] ?? []); $rows = [];
        foreach ($new as $key => $row) if (!array_key_exists($key, $old) || !hash_equals(mysqlTargetedComparable($old[$key]), mysqlTargetedComparable($row))) $rows[$key] = $row;
        if ($rows) { $payload[$name] = $rows; $changedAny = true; }
        foreach ($old as $key => $_) if (!array_key_exists($key, $new)) { mysqlTargetedDelete($deletes, $table, $field === 'profileKey' ? 'profile_key' : ($field === 'userId' ? 'user_id' : 'legacy_key'), (string)$key, 30); $changedAny = true; }
    }
    foreach (['emergencyRecoveryCodes'=>'emergency_recovery_codes', 'dashboardHiddenOutcomes'=>'dashboard_hidden_outcomes'] as $name => $table) if (!hash_equals(mysqlTargetedComparable($before[$name] ?? []), mysqlTargetedComparable($after[$name] ?? []))) { $payload[$name] = $after[$name] ?? []; mysqlTargetedDelete($deletes, $table, 'institution_id', null, 10); $changedAny = true; }
    if (!hash_equals(mysqlTargetedComparable($before['settings'] ?? []), mysqlTargetedComparable($after['settings'] ?? []))) {
        $payload['settings'] = (array)($after['settings'] ?? []);
        if (!hash_equals(mysqlTargetedComparable($before['settings']['gradingScale'] ?? []), mysqlTargetedComparable($after['settings']['gradingScale'] ?? []))) mysqlTargetedDelete($deletes, 'grading_scale_bands', 'institution_id', null, 10);
        if (!hash_equals(mysqlTargetedComparable($before['settings']['integrityPolicy'] ?? []), mysqlTargetedComparable($after['settings']['integrityPolicy'] ?? []))) mysqlTargetedDelete($deletes, 'integrity_policies', 'institution_id', null, 10);
        $changedAny = true;
    }
    if (!hash_equals(mysqlTargetedComparable($before['migrations'] ?? []), mysqlTargetedComparable($after['migrations'] ?? []))) { $payload['migrations'] = $after['migrations'] ?? []; $changedAny = true; }
    usort($deletes, fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    return ['payload'=>$payload,'deletes'=>$deletes,'sessionChildren'=>$sessionChildren,'changed'=>$changedAny];
}

function mysqlLoadData(): array {
    $pdo = mysqlAppPdo();
    $data = ['students'=>[],'academicSessions'=>[],'semesters'=>[],'courses'=>[],'exams'=>[],'questions'=>[],'passwords'=>[],'sessions'=>[],'loginTokens'=>[],'adminSessions'=>[],'rateLimits'=>[],'results'=>[],'calculatedResults'=>[],'auditEvents'=>[],'examFlags'=>[],'dashboardHiddenOutcomes'=>[],'migrations'=>[],'roles'=>[],'adminUsers'=>[],'pendingAdminRequests'=>[],'adminProfileOverrides'=>[],'adminEmailVerifications'=>[],'adminPasswordResets'=>[],'admin2faChallenges'=>[],'emergencyRecoveryCodes'=>[],'adminUserActivity'=>[],'newsletterSubscribers'=>[],'newsletters'=>[],'examLoginFailures'=>[],'examLoginIpAttempts'=>[],'settings'=>['gradingScale'=>[],'integrityPolicy'=>[]]];
    foreach (mysqlRows($pdo, 'academic_sessions') as $r) $data['academicSessions'][] = ['id'=>$r['id'],'label'=>$r['label'],'isActive'=>mysqlBool($r['is_active']),'createdAt'=>mysqlIso($r['created_at'])];
    foreach (mysqlRows($pdo, 'academic_semesters') as $r) $data['semesters'][] = ['id'=>$r['id'],'sessionId'=>$r['session_id'],'label'=>$r['label'],'isActive'=>mysqlBool($r['is_active']),'startDate'=>$r['start_date'],'endDate'=>$r['end_date'],'createdAt'=>mysqlIso($r['created_at'])];
    $courses = []; foreach (mysqlRows($pdo, 'courses') as $r) { $course=['id'=>$r['id'],'code'=>$r['code'],'title'=>$r['title'],'description'=>$r['description'] ?? '','courseUnit'=>(int)$r['course_unit'],'sessionId'=>$r['session_id'] ?? '','semesterId'=>$r['semester_id'] ?? '','testMaxMark'=>(float)$r['test_max_mark'],'examMaxMark'=>(float)$r['exam_max_mark'],'legacyExamOnly'=>mysqlBool($r['legacy_exam_only']),'createdAt'=>mysqlIso($r['created_at']),'category'=>$r['category'] ?? '']; $courses[$r['id']]=$course; $data['courses'][]=$course; }
    foreach (mysqlRows($pdo, 'course_components') as $r) { $course=$courses[$r['course_id']] ?? []; $data['exams'][]=['id'=>$r['id'],'code'=>$r['code'],'title'=>$r['title'],'description'=>$r['description'] ?? '','duration'=>(int)$r['duration_minutes'],'questionCount'=>(int)$r['question_count'],'startAt'=>mysqlIso($r['start_at']),'endAt'=>mysqlIso($r['end_at']),'status'=>$r['status'],'courseUnit'=>(int)($course['courseUnit']??3),'session'=>'','courseId'=>$r['course_id'],'component'=>$r['component'],'maxMark'=>(float)$r['max_mark'],'sessionId'=>$course['sessionId']??'','semesterId'=>$course['semesterId']??'','legacyExamOnly'=>mysqlBool($r['legacy_exam_only']),'courseTitle'=>$course['title']??$r['title'],'category'=>$r['category']??($course['category']??''),'passThreshold'=>$r['pass_threshold']===null?null:(float)$r['pass_threshold']]; }
    foreach (mysqlRows($pdo, 'students') as $r) $data['students'][]=['id'=>$r['id'],'fullName'=>$r['full_name'],'matricNumber'=>$r['matric_number'],'email'=>$r['email'],'phoneNumber'=>$r['phone_number']??'','department'=>$r['department'],'active'=>mysqlBool($r['active']),'createdAt'=>mysqlIso($r['created_at'])];
    $options=[]; foreach(mysqlRows($pdo,'question_options') as $r) $options[$r['question_id']][(int)$r['option_index']]=['text'=>$r['option_text'],'correct'=>mysqlBool($r['is_correct'])];
    $targets=[]; foreach(mysqlRows($pdo,'question_publish_targets') as $r) $targets[$r['question_id']][]=$r['target_component'];
    foreach(mysqlRows($pdo,'questions') as $r) { $questionOptions=$options[$r['id']] ?? []; ksort($questionOptions); $opts=[];$correct=[];foreach($questionOptions as $i=>$item){$opts[]=$item['text'];if($item['correct'])$correct[]=$i;} $data['questions'][]=['id'=>$r['id'],'examId'=>$r['legacy_component_id']??'','text'=>$r['text'],'options'=>$opts,'correctOptions'=>$correct,'type'=>$r['type'],'topic'=>$r['topic']??'','difficulty'=>$r['difficulty']??'','status'=>$r['status'],'courseId'=>$r['course_id'],'legacyComponentId'=>$r['legacy_component_id']??null,'publishedTo'=>$targets[$r['id']]??[]]; }
    foreach(mysqlRows($pdo,'assessment_passwords') as $r) $data['passwords'][]=['id'=>$r['id'],'studentId'=>$r['student_id'],'examId'=>$r['component_id'],'passwordHash'=>$r['password_hash'],'createdAt'=>mysqlIso($r['created_at']),'expiresAt'=>mysqlIso($r['expires_at']),'usedAt'=>mysqlIso($r['used_at'])];
    foreach(mysqlRows($pdo,'assessment_login_tokens') as $r) $data['loginTokens'][]=['tokenHash'=>$r['token_hash'],'studentId'=>$r['student_id'],'examId'=>$r['component_id'],'passwordId'=>$r['password_id'],'correlationId'=>$r['correlation_id'],'deviceFingerprint'=>$r['device_fingerprint'],'expiresAt'=>mysqlIso($r['expires_at']),'usedAt'=>mysqlIso($r['used_at']),'createdAt'=>mysqlIso($r['created_at'])];
    foreach(mysqlRows($pdo,'assessment_attempts') as $r) $data['sessions'][]=mysqlJson($r['snapshot_json'], ['id'=>$r['id'],'studentId'=>$r['student_id'],'examId'=>$r['component_id'],'status'=>$r['status']]);
    $attemptEvents=[]; foreach(mysqlRows($pdo,'attempt_integrity_events') as $r) $attemptEvents[$r['attempt_id']][]=mysqlJson($r['event_json'],[]);
    foreach(mysqlRows($pdo,'component_submissions') as $r) { $snapshot=mysqlJson($r['review_snapshot_json'],[]); $data['results'][]=['id'=>$r['id'],'sessionId'=>$r['attempt_id'],'studentId'=>$r['student_id'],'examId'=>$r['component_id'],'score'=>$r['score']===null?null:(float)$r['score'],'submittedAt'=>mysqlIso($r['submitted_at']),'status'=>$r['status'],'courseUnit'=>$r['course_unit']===null?null:(int)$r['course_unit'],'grade'=>$r['grade'],'gradePoint'=>$r['grade_point']===null?null:(float)$r['grade_point'],'qualityPoints'=>$r['quality_points']===null?null:(float)$r['quality_points'],'session'=>'','courseId'=>$r['course_id'],'component'=>$r['component'],'rawScore'=>$r['raw_score']===null?null:(float)$r['raw_score'],'scaledScore'=>$r['scaled_score']===null?null:(float)$r['scaled_score'],'semesterId'=>$r['academic_semester_id'],'academicSessionId'=>$r['academic_session_id'],'academicSemesterId'=>$r['academic_semester_id'],'examSessionId'=>$r['attempt_id'],'questions'=>$snapshot['questions']??[],'integrityEvents'=>$snapshot['integrityEvents']??($attemptEvents[$r['attempt_id']]??[])]; }
    $items=[];foreach(mysqlRows($pdo,'calculated_result_items') as $r)$items[$r['calculated_result_id']][]=mysqlJson($r['item_json'],[]);foreach(mysqlRows($pdo,'calculated_results') as $r)$data['calculatedResults'][]=['id'=>$r['id'],'studentId'=>$r['student_id'],'sessionId'=>$r['session_id'],'semesterId'=>$r['semester_id'],'sourceHash'=>$r['source_hash'],'semesterGpa'=>(float)$r['semester_gpa'],'cgpa'=>(float)$r['cgpa'],'calculatedAt'=>mysqlIso($r['calculated_at']),'items'=>$items[$r['id']]??[]];
    $permissions=[];foreach(mysqlRows($pdo,'role_permissions') as $r)$permissions[$r['role_id']][]=$r['permission'];foreach(mysqlRows($pdo,'roles') as $r)$data['roles'][]=['id'=>$r['id'],'name'=>$r['name'],'description'=>$r['description']??'','maxUsers'=>(int)$r['max_users'],'systemLocked'=>mysqlBool($r['system_locked']),'permissions'=>$permissions[$r['id']]??[]];
    foreach(mysqlRows($pdo,'admin_users') as $r)$data['adminUsers'][]=['id'=>$r['id'],'roleId'=>$r['role_id'],'name'=>$r['name'],'email'=>$r['email'],'phoneNumber'=>$r['phone_number']??'','passwordHash'=>$r['password_hash'],'active'=>mysqlBool($r['active']),'verified'=>mysqlBool($r['verified']),'mustChangePassword'=>mysqlBool($r['must_change_password'] ?? 0),'createdAt'=>mysqlIso($r['created_at']),'approvedAt'=>mysqlIso($r['approved_at']),'approvedBy'=>$r['approved_by']];
    foreach(mysqlRows($pdo,'admin_sessions') as $r)$data['adminSessions'][]=['tokenHash'=>$r['token_hash'],'userId'=>$r['user_id'],'email'=>$r['email'],'name'=>$r['name'],'roleId'=>$r['role_id'],'permissions'=>mysqlJson($r['permissions_json'],[]),'correlationId'=>$r['correlation_id'],'createdAt'=>mysqlIso($r['created_at']),'lastSeenAt'=>mysqlIso($r['last_seen_at']),'expiresAt'=>mysqlIso($r['expires_at'])];
    foreach(mysqlRows($pdo,'admin_profile_overrides') as $r)$data['adminProfileOverrides'][$r['profile_key']]=mysqlJson($r['payload_json'],[]);foreach(mysqlRows($pdo,'admin_user_activity') as $r)$data['adminUserActivity'][$r['user_id']]=mysqlJson($r['payload_json'],['lastLoginAt'=>mysqlIso($r['last_login_at'])]);
    foreach(mysqlRows($pdo,'pending_admin_requests') as $r)$data['pendingAdminRequests'][]=['id'=>$r['id'],'name'=>$r['name'],'email'=>$r['email'],'phoneNumber'=>$r['phone_number'],'roleId'=>$r['role_id'],'status'=>$r['status'],'ipAddress'=>$r['ip_address'],'emailDelivery'=>$r['email_delivery'],'createdAt'=>mysqlIso($r['created_at']),'resolvedAt'=>mysqlIso($r['resolved_at']),'resolvedBy'=>$r['resolved_by']];
    foreach(mysqlRows($pdo,'admin_email_verifications') as $r)$data['adminEmailVerifications'][]=['userId'=>$r['user_id'],'email'=>$r['email'],'codeHash'=>$r['code_hash'],'attempts'=>(int)$r['attempts'],'expiresAt'=>mysqlIso($r['expires_at'])]; foreach(mysqlRows($pdo,'admin_password_resets') as $r)$data['adminPasswordResets'][]=['id'=>$r['id'],'userId'=>$r['user_id'],'email'=>$r['email'],'codeHash'=>$r['code_hash'],'attempts'=>(int)$r['attempts'],'createdAt'=>mysqlIso($r['created_at']),'expiresAt'=>mysqlIso($r['expires_at'])]; foreach(mysqlRows($pdo,'admin_two_factor_challenges') as $r)$data['admin2faChallenges'][]=['tokenHash'=>$r['token_hash'],'userId'=>$r['user_id'],'email'=>$r['email'],'roleId'=>$r['role_id'],'passwordRateLimitKey'=>$r['password_rate_limit_key'],'attempts'=>(int)$r['attempts'],'expiresAt'=>mysqlIso($r['expires_at'])];
    $codes=mysqlRows($pdo,'emergency_recovery_codes');if($codes){$data['emergencyRecoveryCodes']=['generatedAt'=>mysqlIso($codes[0]['generated_at']),'codes'=>array_map(fn($r)=>['hash'=>$r['code_hash']],$codes)];}
    foreach(mysqlRows($pdo,'rate_limit_records') as $r)$data['rateLimits'][$r['legacy_key']]=mysqlJson($r['payload_json'],[]);foreach(mysqlRows($pdo,'exam_login_failures') as $r)$data['examLoginFailures'][$r['legacy_key']]=['failedAt'=>mysqlJson($r['failed_at_json'],[]),'lockedUntil'=>$r['locked_until']?strtotime($r['locked_until']):0];foreach(mysqlRows($pdo,'exam_login_ip_attempts') as $r)$data['examLoginIpAttempts'][$r['legacy_key']]=['attemptedAt'=>mysqlJson($r['attempted_at_json'],[])];
    foreach(mysqlRows($pdo,'exam_flags') as $r)$data['examFlags'][]=['id'=>$r['id'],'sessionId'=>$r['attempt_id'],'flagType'=>$r['flag_type'],'ipAddress'=>$r['ip_address'],'resultingAction'=>$r['resulting_action'],'timestamp'=>mysqlIso($r['timestamp_at'])];
    foreach(mysqlRows($pdo,'newsletter_subscribers') as $r)$data['newsletterSubscribers'][]=['id'=>$r['id'],'studentId'=>$r['student_id'],'name'=>$r['name'],'email'=>$r['email'],'source'=>$r['source'],'status'=>$r['status'],'subscribedAt'=>mysqlIso($r['subscribed_at']),'updatedAt'=>mysqlIso($r['updated_at']),'verifiedAt'=>mysqlIso($r['verified_at'])];
    $deliveries=[];foreach(mysqlRows($pdo,'newsletter_deliveries') as $r)$deliveries[$r['newsletter_id']][]=mysqlJson($r['delivery_json'],[]);foreach(mysqlRows($pdo,'newsletters') as $r)$data['newsletters'][]=['id'=>$r['id'],'subject'=>$r['subject'],'content'=>$r['content'],'sender'=>$r['sender'],'createdBy'=>$r['created_by'],'createdAt'=>mysqlIso($r['created_at']),'recipientCount'=>(int)$r['recipient_count'],'acceptedCount'=>(int)$r['accepted_count'],'failedCount'=>(int)$r['failed_count'],'status'=>$r['status'],'deliveries'=>$deliveries[$r['id']]??[]];
    foreach(mysqlRows($pdo,'grading_scale_bands') as $r)$data['settings']['gradingScale'][]=['minScore'=>(float)$r['min_score'],'maxScore'=>(float)$r['max_score'],'grade'=>$r['grade'],'gradePoint'=>(float)$r['grade_point']];foreach(mysqlRows($pdo,'integrity_policies') as $r)$data['settings']['integrityPolicy'][$r['event_type']]=['mode'=>$r['mode'],'lockAfter'=>(int)$r['lock_after']];foreach(mysqlRows($pdo,'institution_settings') as $r){$value=mysqlJson($r['setting_json'],null);if($r['setting_key']==='legacy_migrations')$data['migrations']=$value?:[];else $data['settings'][$r['setting_key']]=$value;}foreach(mysqlRows($pdo,'dashboard_hidden_outcomes') as $r)$data['dashboardHiddenOutcomes'][]=$r['outcome_key'];
    foreach(mysqlRows($pdo,'audit_events') as $r)$data['auditEvents'][]=['id'=>$r['id'],'timestamp'=>mysqlIso($r['timestamp_at']),'actorType'=>$r['actor_type'],'actorId'=>$r['actor_id'],'actionType'=>$r['action_type'],'targetType'=>$r['target_type'],'targetId'=>$r['target_id'],'ipAddress'=>$r['ip_address'],'userAgent'=>$r['user_agent'],'outcome'=>$r['outcome'],'correlationId'=>$r['correlation_id'],'beforeAfter'=>mysqlJson($r['before_after_json'],[]),'metadata'=>mysqlJson($r['metadata_json'],[])];
    // Keep a request-local baseline. mysqlSaveData compares the handler's final
    // legacy-shaped model to this snapshot and persists only changed records.
    $GLOBALS['cacsa_mysql_loaded_snapshot'] = $data;
    return $data;
}

function mysqlSaveData(array $data): void {
    // Existing handlers can save more than once in a request (for example rate
    // limiting, then an audit event). Coalesce those calls and persist the final
    // in-memory state exactly once, after the response path has completed.
    $GLOBALS['cacsa_mysql_pending_data'] = $data;
    static $registered = false;
    if ($registered) return;
    $registered = true;
    register_shutdown_function(static function (): void {
        $pending = $GLOBALS['cacsa_mysql_pending_data'] ?? null;
        if (is_array($pending)) mysqlPersistData($pending);
    });
}

function mysqlPersistData(array $data): void {
    $baseline = $GLOBALS['cacsa_mysql_loaded_snapshot'] ?? null;
    if (!is_array($baseline)) throw new RuntimeException('MySQL persistence requires the request load snapshot.');
    $plan = mysqlTargetedPayload($baseline, $data);
    if (!$plan['changed']) return;
    // Reuse the one carefully verified mapping layer with a changed-row payload.
    // MYSQL_IMPORT_TARGETED disables the old tenant-wide clear and turns every
    // affected record into an UPSERT inside the same transaction as its scoped
    // child-row deletes.
    if (!defined('MYSQL_IMPORT_ROOT')) define('MYSQL_IMPORT_ROOT', __DIR__);
    if (!defined('MYSQL_IMPORT_IN_MEMORY')) define('MYSQL_IMPORT_IN_MEMORY', $plan['payload']);
    if (!defined('MYSQL_IMPORT_INSTITUTION_ID')) define('MYSQL_IMPORT_INSTITUTION_ID', mysqlCurrentInstitutionId());
    if (!defined('MYSQL_IMPORT_TARGETED')) define('MYSQL_IMPORT_TARGETED', true);
    if (!defined('MYSQL_IMPORT_TARGETED_DELETES')) define('MYSQL_IMPORT_TARGETED_DELETES', $plan['deletes']);
    if (!defined('MYSQL_IMPORT_TARGETED_SESSION_CHILDREN')) define('MYSQL_IMPORT_TARGETED_SESSION_CHILDREN', $plan['sessionChildren']);
    if (!defined('MYSQL_IMPORT_MANIFEST')) define('MYSQL_IMPORT_MANIFEST', ['timestamp'=>date('c'),'actorType'=>'system','actorId'=>'mysql-api','actionType'=>'mysql_storage_synchronized','targetType'=>'storage','targetId'=>'cacsa-lautech','outcome'=>'success','metadata'=>['storage'=>'mysql','legacySourcePreserved'=>true]]);
    // The importer is retained as a compatibility mapper during Stage 1, but
    // must share this request's connection rather than consume a second MySQL
    // slot during each autosave or login.
    $GLOBALS['cacsa_mysql_import_pdo'] = mysqlAppPdo();
    require __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'mysql_import_json.php';
}
