<?php
declare(strict_types=1);

/**
 * One-time CACSA identity repair.
 *
 * This uses the application's normal targeted persistence mapper rather than
 * a hand-written INSERT. The password is accepted only as a transient runtime
 * value, then passed through the same PASSWORD_DEFAULT hashing used by normal
 * administrator creation. It is intentionally CLI-only and requires
 * CBT_APPLY=1 plus CBT_CACSA_SUPERADMIN_PASSWORD.
 */
if (!in_array(PHP_SAPI, ['cli', 'cgi-fcgi'], true) || getenv('CBT_APPLY') !== '1') {
    echo "Usage: CBT_APPLY=1 CBT_CACSA_SUPERADMIN_PASSWORD=<password> php-cgi -q provision_cacsa_tenant_admin_identity.php\n";
    exit(2);
}

$root = dirname(__DIR__);
$_SERVER['REQUEST_URI'] = '/BEREVION/i/cacsa-lautech/api.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
require_once $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'mysql_connection.php';
require_once $root . DIRECTORY_SEPARATOR . 'mysql_data_store.php';

try {
    if (!mysqlStorageEnabled()) throw new RuntimeException('MySQL storage must be active.');
    $institution = mysqlRequestInstitution();
    if ((int)$institution['id'] !== 1 || (string)$institution['slug'] !== 'cacsa-lautech') throw new RuntimeException('This repair is locked to CACSA LAUTECH.');

    $platform = mysqlPlatformAdminAccount();
    if (!$platform || empty($platform['active'])) throw new RuntimeException('The active Platform Super Admin account was not found.');
    $email = strtolower(trim((string)$platform['email']));
    $name = trim((string)$platform['name']);
    $password = (string)getenv('CBT_CACSA_SUPERADMIN_PASSWORD');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($name) > 255 || strlen($password) < 8) {
        throw new RuntimeException('Tenant Superadmin identity does not meet administrator validation requirements.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $data = mysqlLoadData();
    foreach ($data['adminUsers'] as $existing) {
        if (strcasecmp((string)($existing['email'] ?? ''), $email) === 0) {
            throw new RuntimeException('CACSA already has an administrator with this email; no change was made.');
        }
    }
    $role = null;
    foreach ($data['roles'] as $candidate) if (($candidate['id'] ?? '') === 'superadmin') { $role = $candidate; break; }
    if (!$role) throw new RuntimeException('CACSA Superadmin role was not found.');
    $activeInRole = count(array_filter($data['adminUsers'], static fn(array $user): bool => ($user['roleId'] ?? '') === 'superadmin' && !empty($user['active'])));
    if ($activeInRole >= (int)$role['maxUsers']) throw new RuntimeException('CACSA Superadmin role has reached its configured user limit.');

    $userId = 'cacsa-tenant-admin-' . bin2hex(random_bytes(6));
    $now = gmdate('c');
    $data['adminUsers'][] = [
        'id' => $userId, 'roleId' => 'superadmin', 'name' => $name, 'email' => $email,
        'passwordHash' => $hash, 'active' => true, 'verified' => true,
        'mustChangePassword' => false, 'createdAt' => $now, 'approvedAt' => $now,
        'approvedBy' => 'system-cacsa-identity-repair',
    ];
    $data['auditEvents'][] = [
        'id' => 'cacsa-identity-repair-' . bin2hex(random_bytes(8)), 'timestamp' => $now,
        'actorType' => 'system', 'actorId' => 'system-cacsa-identity-repair',
        'actionType' => 'administrator_created', 'targetType' => 'administrator',
        'targetId' => $userId, 'ipAddress' => '127.0.0.1', 'userAgent' => 'controlled identity repair',
        'outcome' => 'success', 'correlationId' => 'cacsa-identity-repair-' . bin2hex(random_bytes(6)),
        'beforeAfter' => [], 'metadata' => ['email' => $email, 'role' => (string)$role['name'], 'credentialSource' => 'normal_password_hashing'],
    ];

    mysqlPersistData($data);
    echo json_encode(['ok' => true, 'institution' => $institution['slug'], 'userId' => $userId, 'email' => $email, 'roleId' => 'superadmin', 'passwordHashedWithPasswordDefault' => true], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    echo 'CACSA tenant Admin provisioning failed: ' . $error->getMessage() . PHP_EOL;
    exit(1);
}
