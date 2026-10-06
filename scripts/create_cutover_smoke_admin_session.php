<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__);
$tokenPath = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '.cutover-smoke-admin-token';
$pdo = mysqlMigrationPdo($root);
$token = bin2hex(random_bytes(32));
$hash = hash('sha256', $token);
$permissions = ['overview','students','exams','questions','results','audit','newsletter','settings','roles'];
$insert = $pdo->prepare(
    'INSERT INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at)
     VALUES (:institution_id,:token_hash,:user_id,:email,:name,:role_id,:permissions_json,:correlation_id,:created_at,:last_seen_at,:expires_at)'
);
$now = gmdate('Y-m-d H:i:s') . '.000000';
$insert->execute([
    'institution_id' => 1,
    'token_hash' => $hash,
    'user_id' => 'bootstrap-superadmin',
    'email' => 'adepojutimothy001@gmail.com',
    'name' => 'ALGEBRA',
    'role_id' => 'superadmin',
    'permissions_json' => json_encode($permissions, JSON_THROW_ON_ERROR),
    'correlation_id' => 'mysql-cutover-e2e-' . bin2hex(random_bytes(6)),
    'created_at' => $now,
    'last_seen_at' => $now,
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 900) . '.000000',
]);
if (file_put_contents($tokenPath, $token, LOCK_EX) === false) throw new RuntimeException('Could not create the temporary smoke-test session token.');
echo "Temporary Superadmin smoke-test session created.\n";
