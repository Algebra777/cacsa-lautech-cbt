<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$slug = $argv[1] ?? '';
if (!preg_match('/^[a-z0-9][a-z0-9-]{0,118}$/', $slug)) throw new RuntimeException('Pass a valid institution slug.');
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$institution = $pdo->prepare('SELECT id FROM institutions WHERE slug = :slug'); $institution->execute(['slug' => $slug]); $institutionId = (int)$institution->fetchColumn();
if ($institutionId < 2) throw new RuntimeException('Institution was not found.');
$admin = $pdo->prepare("SELECT id,email,active,verified,must_change_password FROM admin_users WHERE institution_id = :institution_id AND role_id = 'admin' LIMIT 1"); $admin->execute(['institution_id' => $institutionId]); $admin = $admin->fetch();
if (!$admin) throw new RuntimeException('Institution Admin was not found.');
$sessions = $pdo->prepare('SELECT COUNT(*) FROM admin_sessions WHERE institution_id = :institution_id AND user_id = :user_id'); $sessions->execute(['institution_id' => $institutionId, 'user_id' => $admin['id']]);
$events = $pdo->prepare("SELECT action_type,outcome,timestamp_at FROM audit_events WHERE institution_id = :institution_id AND action_type IN ('admin_login','administrator_password_changed') ORDER BY timestamp_at"); $events->execute(['institution_id' => $institutionId]);
$eventRows = $events->fetchAll();
echo json_encode(['institutionId' => $institutionId, 'admin' => ['active' => (bool)$admin['active'], 'verified' => (bool)$admin['verified'], 'mustChangePassword' => (bool)$admin['must_change_password']], 'liveSessions' => (int)$sessions->fetchColumn(), 'auditEvents' => array_map(static fn(array $event): array => ['action' => $event['action_type'], 'outcome' => $event['outcome'], 'timestamp' => $event['timestamp_at']], $eventRows)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
