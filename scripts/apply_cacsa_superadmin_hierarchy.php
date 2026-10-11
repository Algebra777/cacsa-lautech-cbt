<?php
declare(strict_types=1);

/** One-way, CACSA-only role-policy migration. */
if (!in_array(PHP_SAPI, ['cli', 'cgi-fcgi'], true) || getenv('CBT_APPLY') !== '1') {
    echo "Usage: CBT_APPLY=1 php-cgi -q apply_cacsa_superadmin_hierarchy.php\n";
    exit(2);
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$pdo = mysqlMigrationPdo(dirname(__DIR__));
try {
    $pdo->beginTransaction();
    $roles = $pdo->prepare("SELECT id FROM roles WHERE institution_id=1 AND id IN ('admin','superadmin') FOR UPDATE");
    $roles->execute();
    $found = $roles->fetchAll(PDO::FETCH_COLUMN);
    if (count($found) !== 2) throw new RuntimeException('CACSA Admin and Superadmin roles must both exist.');
    $remove = $pdo->prepare("DELETE FROM role_permissions WHERE institution_id=1 AND role_id='admin' AND permission='roles'");
    $remove->execute();
    $audit = $pdo->prepare("INSERT INTO audit_events (institution_id,id,timestamp_at,actor_type,actor_id,action_type,target_type,target_id,ip_address,user_agent,outcome,correlation_id,before_after_json,metadata_json) VALUES (1,:id,UTC_TIMESTAMP(6),'system','system-cacsa-superadmin-hierarchy','role_permission_removed','role','admin','127.0.0.1','controlled role hierarchy migration','success',:correlation,:before_after,:metadata)");
    $audit->execute([
        'id' => 'cacsa-superadmin-hierarchy-' . bin2hex(random_bytes(8)),
        'correlation' => 'cacsa-superadmin-hierarchy-' . bin2hex(random_bytes(6)),
        'before_after' => json_encode(['roles' => ['before' => true, 'after' => false]], JSON_UNESCAPED_SLASHES),
        'metadata' => json_encode(['permission' => 'roles', 'reason' => 'Tenant Superadmin exclusively manages administrator accounts and roles.'], JSON_UNESCAPED_SLASHES),
    ]);
    $pdo->commit();
    echo json_encode(['ok' => true, 'institutionId' => 1, 'adminPermissionRemoved' => 'roles'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo 'CACSA Superadmin hierarchy migration failed: ' . $error->getMessage() . PHP_EOL;
    exit(1);
}
