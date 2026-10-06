<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$slug = $argv[1] ?? '';
if (!preg_match('/^[a-z0-9][a-z0-9-]{0,118}$/', $slug)) throw new RuntimeException('Pass a valid institution slug.');
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$institution = $pdo->prepare('SELECT id,name,slug,active,created_at FROM institutions WHERE slug = :slug LIMIT 1');
$institution->execute(['slug' => $slug]); $institution = $institution->fetch();
if (!$institution) throw new RuntimeException('Institution was not found.');
$id = (int)$institution['id'];
$brand = $pdo->prepare('SELECT display_name,portal_title,logo_path,nav_label,result_sheet_title FROM institution_branding WHERE institution_id = :institution_id'); $brand->execute(['institution_id' => $id]); $brand = $brand->fetch();
$counts = [];
foreach (['roles','grading_scale_bands','integrity_policies','institution_settings','academic_sessions','academic_semesters','admin_users'] as $table) { $s = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE institution_id = :institution_id"); $s->execute(['institution_id' => $id]); $counts[$table] = (int)$s->fetchColumn(); }
$admin = $pdo->prepare("SELECT name,email,active,verified,must_change_password FROM admin_users WHERE institution_id = :institution_id AND role_id = 'admin' LIMIT 1"); $admin->execute(['institution_id' => $id]); $admin = $admin->fetch();
$roles = $pdo->prepare('SELECT id,name,system_locked FROM roles WHERE institution_id = :institution_id ORDER BY id'); $roles->execute(['institution_id' => $id]);
$logoPath = (string)($brand['logo_path'] ?? '');
echo json_encode(['institution' => ['id' => $id, 'name' => $institution['name'], 'slug' => $institution['slug'], 'active' => (bool)$institution['active']], 'branding' => ['displayName' => $brand['display_name'] ?? null, 'portalTitle' => $brand['portal_title'] ?? null, 'logoPath' => $logoPath, 'logoIsTenantUpload' => str_starts_with($logoPath, 'uploads/institution-logos/') && !str_contains(strtolower($logoPath), 'cacsa'), 'navLabel' => $brand['nav_label'] ?? null, 'resultSheetTitle' => $brand['result_sheet_title'] ?? null], 'seedCounts' => $counts, 'roles' => $roles->fetchAll(), 'initialAdmin' => $admin ? ['email' => preg_replace('/^(.{2}).+(@.+)$/', '$1***$2', (string)$admin['email']), 'active' => (bool)$admin['active'], 'verified' => (bool)$admin['verified'], 'mustChangePassword' => (bool)$admin['must_change_password']] : null], JSON_UNESCAPED_SLASHES) . PHP_EOL;
