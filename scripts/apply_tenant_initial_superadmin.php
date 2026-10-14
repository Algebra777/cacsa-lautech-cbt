<?php
declare(strict_types=1);

/**
 * Makes the first provisioned administrator in every live tenant its
 * Superadmin, and repairs tenants created before that rule was introduced.
 *
 * Run once with: CBT_APPLY=1 php-cgi -q apply_tenant_initial_superadmin.php
 */
if (!in_array(PHP_SAPI, ['cli', 'cgi-fcgi'], true) || getenv('CBT_APPLY') !== '1') {
    echo "Usage: CBT_APPLY=1 php-cgi -q apply_tenant_initial_superadmin.php\n";
    exit(2);
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$permissions = ['overview', 'students', 'exams', 'questions', 'results', 'audit', 'newsletter', 'messages', 'settings', 'roles'];
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$promoted = [];

try {
    $pdo->beginTransaction();
    $institutions = $pdo->query('SELECT id,slug FROM institutions WHERE deleted_at IS NULL ORDER BY id FOR UPDATE')->fetchAll();
    $upsertRole = $pdo->prepare("INSERT INTO roles (institution_id,id,name,description,max_users,system_locked) VALUES (:institution_id,'superadmin','Superadmin','Full system access and administrator management.',1,1) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),max_users=1,system_locked=1");
    $clearPermissions = $pdo->prepare("DELETE FROM role_permissions WHERE institution_id=:institution_id AND role_id='superadmin'");
    $insertPermission = $pdo->prepare("INSERT INTO role_permissions (institution_id,role_id,permission) VALUES (:institution_id,'superadmin',:permission)");
    $findSuperadmin = $pdo->prepare("SELECT id FROM admin_users WHERE institution_id=:institution_id AND role_id='superadmin' AND active=1 LIMIT 1 FOR UPDATE");
    $firstAdmin = $pdo->prepare("SELECT id,email FROM admin_users WHERE institution_id=:institution_id AND role_id='admin' AND active=1 ORDER BY created_at ASC,id ASC LIMIT 1 FOR UPDATE");
    $promote = $pdo->prepare("UPDATE admin_users SET role_id='superadmin' WHERE institution_id=:institution_id AND id=:id AND role_id='admin'");
    $updateSessions = $pdo->prepare("UPDATE admin_sessions SET role_id='superadmin',permissions_json=:permissions_json WHERE institution_id=:institution_id AND user_id=:user_id");
    $audit = $pdo->prepare("INSERT INTO audit_events (institution_id,id,timestamp_at,actor_type,actor_id,action_type,target_type,target_id,ip_address,user_agent,outcome,correlation_id,before_after_json,metadata_json) VALUES (:institution_id,:id,UTC_TIMESTAMP(6),'system','system-tenant-initial-superadmin','tenant_initial_admin_promoted_to_superadmin','administrator',:target_id,'127.0.0.1','controlled tenant bootstrap migration','success',:correlation,:before_after,:metadata)");

    foreach ($institutions as $institution) {
        $institutionId = (int)$institution['id'];
        $upsertRole->execute(['institution_id' => $institutionId]);
        $clearPermissions->execute(['institution_id' => $institutionId]);
        foreach ($permissions as $permission) $insertPermission->execute(['institution_id' => $institutionId, 'permission' => $permission]);

        $findSuperadmin->execute(['institution_id' => $institutionId]);
        if ($findSuperadmin->fetchColumn()) continue;
        $firstAdmin->execute(['institution_id' => $institutionId]);
        $account = $firstAdmin->fetch();
        if (!$account) continue;
        $promote->execute(['institution_id' => $institutionId, 'id' => $account['id']]);
        if ($promote->rowCount() !== 1) throw new RuntimeException('Could not promote the initial administrator for tenant ' . (string)$institution['slug'] . '.');
        $updateSessions->execute([
            'institution_id' => $institutionId,
            'user_id' => $account['id'],
            'permissions_json' => json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $audit->execute([
            'institution_id' => $institutionId,
            'id' => 'tenant-initial-superadmin-' . bin2hex(random_bytes(8)),
            'target_id' => $account['id'],
            'correlation' => 'tenant-initial-superadmin-' . bin2hex(random_bytes(6)),
            'before_after' => json_encode(['roleId' => ['before' => 'admin', 'after' => 'superadmin']], JSON_UNESCAPED_SLASHES),
            'metadata' => json_encode(['email' => $account['email'], 'reason' => 'Every tenant’s first provisioned administrator is its Superadmin.'], JSON_UNESCAPED_SLASHES),
        ]);
        $promoted[] = ['institutionId' => $institutionId, 'slug' => (string)$institution['slug'], 'userId' => (string)$account['id']];
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'promoted' => $promoted], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo 'Tenant initial Superadmin migration failed: ' . $error->getMessage() . PHP_EOL;
    exit(1);
}
