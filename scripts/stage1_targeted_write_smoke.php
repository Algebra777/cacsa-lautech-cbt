<?php
declare(strict_types=1);

/** Verify targeted UPSERT mappings beyond the exam path, on a disposable tenant. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$slug = $argv[1] ?? '';
if (!is_string($slug) || !preg_match('/^perf-stage1-[a-z0-9-]+$/', $slug)) {
    fwrite(STDERR, "Refusing: use a disposable perf-stage1-* tenant slug.\n"); exit(2);
}
$_SERVER['REQUEST_URI'] = '/BEREVION/i/' . rawurlencode($slug) . '/api.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mysql_data_store.php';
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$tenant = $pdo->prepare('SELECT id FROM institutions WHERE slug = :slug AND id <> 1 AND deleted_at IS NULL');
$tenant->execute(['slug' => $slug]);
if (!(int)$tenant->fetchColumn()) { fwrite(STDERR, "Refusing: disposable test institution was not found.\n"); exit(2); }

$original = mysqlLoadData();
$working = $original;
$changed = [];
if (!empty($working['courses'][0])) { $working['courses'][0]['description'] = '__stage1_targeted_write_smoke__'; $changed[] = 'course'; }
if (!empty($working['questions'][0])) { $working['questions'][0]['topic'] = '__stage1_targeted_write_smoke__'; $changed[] = 'question'; }
$working['settings']['studentPortalSetupMode'] = !((bool)($working['settings']['studentPortalSetupMode'] ?? false)); $changed[] = 'settings';
if (!empty($working['adminUsers'][0])) { $working['adminUsers'][0]['phoneNumber'] = 'stage1-smoke'; $changed[] = 'admin'; }
if (count($changed) < 3) throw new RuntimeException('Disposable tenant does not contain the expected write-smoke fixtures.');

mysqlPersistData($working);
$written = mysqlLoadData();
if (!empty($working['courses'][0]) && ($written['courses'][0]['description'] ?? null) !== '__stage1_targeted_write_smoke__') throw new RuntimeException('Targeted course update did not persist.');
if (!empty($working['questions'][0]) && ($written['questions'][0]['topic'] ?? null) !== '__stage1_targeted_write_smoke__') throw new RuntimeException('Targeted question update did not persist.');
if ((bool)($written['settings']['studentPortalSetupMode'] ?? false) !== (bool)$working['settings']['studentPortalSetupMode']) throw new RuntimeException('Targeted settings update did not persist.');
if (!empty($working['adminUsers'][0]) && ($written['adminUsers'][0]['phoneNumber'] ?? null) !== 'stage1-smoke') throw new RuntimeException('Targeted admin update did not persist.');

// The test tenant is safety-backed-up and removed immediately after Stage 1,
// so deliberately do not invoke the compatibility importer twice in one CLI
// process. Normal web requests coalesce writes and invoke it once at shutdown.
echo json_encode(['pass' => true, 'tenantSlug' => $slug, 'verified' => $changed, 'disposableFixtureWillBeRemoved' => true], JSON_UNESCAPED_SLASHES) . PHP_EOL;
