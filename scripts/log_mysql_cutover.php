<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$pdo = mysqlMigrationPdo(dirname(__DIR__));
$id = 'mysql-cutover-' . bin2hex(random_bytes(8));
$statement = $pdo->prepare(
    'INSERT INTO audit_events (institution_id,id,timestamp_at,actor_type,actor_id,action_type,target_type,target_id,ip_address,user_agent,outcome,correlation_id,before_after_json,metadata_json)
     VALUES (:institution_id,:id,:timestamp_at,:actor_type,:actor_id,:action_type,:target_type,:target_id,:ip_address,:user_agent,:outcome,:correlation_id,:before_after_json,:metadata_json)'
);
$statement->execute([
    'institution_id' => 1,
    'id' => $id,
    'timestamp_at' => gmdate('Y-m-d H:i:s') . '.000000',
    'actor_type' => 'system',
    'actor_id' => 'storage-migration-cli',
    'action_type' => 'mysql_storage_cutover_completed',
    'target_type' => 'storage',
    'target_id' => 'cacsa-lautech',
    'ip_address' => null,
    'user_agent' => null,
    'outcome' => 'success',
    'correlation_id' => 'mysql-cutover-' . gmdate('YmdHis'),
    'before_after_json' => json_encode([], JSON_THROW_ON_ERROR),
    'metadata_json' => json_encode([
        'storage' => 'mysql',
        'institutionId' => 1,
        'legacyJsonPreserved' => true,
        'maintenanceWindowCompleted' => true,
        'finalSourceSha256' => hash_file('sha256', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cbt-data.json'),
    ], JSON_THROW_ON_ERROR),
]);
echo json_encode(['auditEventId' => $id, 'outcome' => 'success'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
