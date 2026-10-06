<?php
declare(strict_types=1);

/**
 * Phase 2 / Step 2 structural migration.
 *
 * This deliberately does not create a second institution or change courses,
 * students, questions, submissions, results, or tenant audit history.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

function phase2ColumnExists(PDO $pdo, string $table, string $column): bool {
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
    $check->execute(['table' => $table, 'column' => $column]);
    return (int)$check->fetchColumn() > 0;
}
function phase2ConstraintExists(PDO $pdo, string $table, string $constraint): bool {
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = :table AND constraint_name = :constraint');
    $check->execute(['table' => $table, 'constraint' => $constraint]);
    return (int)$check->fetchColumn() > 0;
}
function phase2IndexExists(PDO $pdo, string $table, string $index): bool {
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index');
    $check->execute(['table' => $table, 'index' => $index]);
    return (int)$check->fetchColumn() > 0;
}

$root = dirname(__DIR__);
$pdo = mysqlMigrationPdo($root);
try {
    // institutions is the registry, not tenant-owned data. Its previous
    // self-reference made safe AUTO_INCREMENT provisioning impossible.
    if (phase2ConstraintExists($pdo, 'institutions', 'fk_institutions_scope')) $pdo->exec('ALTER TABLE institutions DROP FOREIGN KEY fk_institutions_scope');
    if (phase2IndexExists($pdo, 'institutions', 'uq_institutions_scope')) $pdo->exec('ALTER TABLE institutions DROP INDEX uq_institutions_scope');
    if (phase2ColumnExists($pdo, 'institutions', 'institution_id')) $pdo->exec('ALTER TABLE institutions DROP COLUMN institution_id');

    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_admin_users (
        id VARCHAR(128) NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(254) NOT NULL,
        phone_number VARCHAR(80) NULL, password_hash VARCHAR(255) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
        verified TINYINT(1) NOT NULL DEFAULT 1, must_change_password TINYINT(1) NOT NULL DEFAULT 0,
        profile_json JSON NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NULL,
        PRIMARY KEY (id), UNIQUE KEY uq_platform_admin_singleton (id), UNIQUE KEY uq_platform_admin_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_admin_sessions (
        token_hash CHAR(64) NOT NULL, user_id VARCHAR(128) NOT NULL, email VARCHAR(254) NOT NULL,
        name VARCHAR(255) NOT NULL, correlation_id VARCHAR(190) NULL, created_at DATETIME(6) NOT NULL,
        last_seen_at DATETIME(6) NULL, expires_at DATETIME(6) NOT NULL,
        PRIMARY KEY (token_hash), KEY ix_platform_sessions_user (user_id, expires_at), KEY ix_platform_sessions_expiry (expires_at),
        CONSTRAINT fk_platform_sessions_user FOREIGN KEY (user_id) REFERENCES platform_admin_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_admin_two_factor_challenges (
        token_hash CHAR(64) NOT NULL, user_id VARCHAR(128) NOT NULL, email VARCHAR(254) NOT NULL,
        password_rate_limit_key CHAR(64) NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME(6) NOT NULL,
        PRIMARY KEY (token_hash), KEY ix_platform_2fa_expiry (expires_at),
        CONSTRAINT fk_platform_2fa_user FOREIGN KEY (user_id) REFERENCES platform_admin_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_audit_events (
        id VARCHAR(128) NOT NULL, timestamp_at DATETIME(6) NOT NULL, actor_id VARCHAR(254) NULL,
        action_type VARCHAR(150) NOT NULL, target_institution_id INT UNSIGNED NULL,
        target_type VARCHAR(100) NOT NULL, target_id VARCHAR(190) NULL, outcome VARCHAR(30) NULL,
        correlation_id VARCHAR(190) NULL, ip_address VARCHAR(64) NULL, user_agent TEXT NULL,
        before_after_json JSON NULL, metadata_json JSON NULL,
        PRIMARY KEY (id), KEY ix_platform_audit_timestamp (timestamp_at), KEY ix_platform_audit_target (target_institution_id, timestamp_at),
        CONSTRAINT fk_platform_audit_institution FOREIGN KEY (target_institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // MySQL DDL commits implicitly. The account/role migration below is the
    // data-changing portion and is therefore kept in its own transaction.
    $pdo->beginTransaction();
    // Add the full-depth tenant Admin role. The existing normal CACSA
    // administrator is the only active Academic Coordinator and is promoted
    // without changing identity, password, sessions, 2FA, or permissions.
    $permissions = ['overview','students','exams','questions','results','audit','newsletter','settings','roles'];
    $role = $pdo->prepare('INSERT INTO roles (institution_id,id,name,description,max_users,system_locked) VALUES (1, :id, :name, :description, 1, 1) ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), system_locked=VALUES(system_locked)');
    $role->execute(['id' => 'admin', 'name' => 'Admin', 'description' => 'Full operational control within this institution.']);
    $permission = $pdo->prepare('INSERT IGNORE INTO role_permissions (institution_id,role_id,permission) VALUES (1, :role_id, :permission)');
    foreach ($permissions as $value) $permission->execute(['role_id' => 'admin', 'permission' => $value]);
    $pdo->prepare("UPDATE admin_users SET role_id = 'admin' WHERE institution_id = 1 AND role_id = 'academic_coordinator' AND active = 1")->execute();

    // Preserve the existing protected bootstrap identity in a genuinely global
    // home. We retain its profile payload (including encrypted 2FA material)
    // exactly as stored; application wiring consumes it in this same step.
    $profileStatement = $pdo->prepare("SELECT payload_json FROM admin_profile_overrides WHERE institution_id = 1 AND profile_key = 'bootstrap-superadmin' LIMIT 1");
    $profileStatement->execute();
    $profileRaw = $profileStatement->fetchColumn();
    $profile = is_string($profileRaw) ? json_decode($profileRaw, true) : [];
    if (!is_array($profile)) $profile = [];
    $email = getenv('CBT_ADMIN_EMAIL') ?: (string)($profile['email'] ?? '');
    $hash = getenv('CBT_ADMIN_PASSWORD_HASH') ?: (string)($profile['passwordHash'] ?? '');
    if ($email === '' || $hash === '') throw new RuntimeException('The current protected Super Admin identity could not be safely migrated. Configure its existing email and password hash first.');
    $now = gmdate('Y-m-d H:i:s.u');
    $platform = $pdo->prepare('INSERT INTO platform_admin_users (id,name,email,phone_number,password_hash,active,verified,must_change_password,profile_json,created_at,updated_at) VALUES (:id,:name,:email,:phone,:hash,1,1,:must_change,:profile,:created_at,:updated_at) ON DUPLICATE KEY UPDATE name=VALUES(name), email=VALUES(email), phone_number=VALUES(phone_number), password_hash=VALUES(password_hash), profile_json=VALUES(profile_json), updated_at=VALUES(updated_at)');
    $platform->execute([
        'id' => 'platform-superadmin', 'name' => (string)($profile['name'] ?? 'Super Admin'), 'email' => strtolower($email),
        'phone' => (string)($profile['phoneNumber'] ?? ''), 'hash' => $hash,
        'must_change' => empty($profile['passwordHash']) ? 1 : 0,
        'profile' => json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $audit = $pdo->prepare('INSERT IGNORE INTO platform_audit_events (id,timestamp_at,actor_id,action_type,target_institution_id,target_type,target_id,outcome,correlation_id,metadata_json) VALUES (:id,:timestamp,:actor,:action,:institution,:target_type,:target_id,:outcome,:correlation,:metadata)');
    $audit->execute(['id' => 'phase2-step2-' . gmdate('YmdHis'), 'timestamp' => $now, 'actor' => 'system', 'action' => 'phase2_authorization_boundary_initialized', 'institution' => 1, 'target_type' => 'institution', 'target_id' => '1', 'outcome' => 'success', 'correlation' => 'phase2-step2', 'metadata' => json_encode(['tenantDataChanged' => false, 'adminRoleSeeded' => true], JSON_UNESCAPED_SLASHES)]);
    $pdo->commit();
    echo json_encode(['ok' => true, 'institutionRegistryCorrected' => true, 'platformTables' => ['platform_admin_users','platform_admin_sessions','platform_audit_events'], 'institutionAdminRoleReady' => true], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Phase 2 Step 2 rolled back: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
