<?php
declare(strict_types=1);

/**
 * Scheduled storage lifecycle worker.
 *
 * Default is dry-run. Use --apply only from the server scheduler after first
 * reviewing its output. It reconciles branding-referenced logos before it
 * considers expiry, and it never treats a directory glob as deletion authority.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'mysql_data_store.php';

$apply = in_array('--apply', $argv, true); $institutionId = null;
foreach ($argv as $argument) if (str_starts_with($argument, '--institution=')) $institutionId = filter_var(substr($argument, 14), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$query = 'SELECT id,slug FROM institutions WHERE deleted_at IS NULL'; $parameters = [];
if ($institutionId !== null) { $query .= ' AND id=:institution_id'; $parameters['institution_id'] = $institutionId; }
$statement = $pdo->prepare($query); $statement->execute($parameters); $institutions = $statement->fetchAll();
$results = [];
foreach ($institutions as $institution) {
    $id = (int)$institution['id']; $pdo->beginTransaction();
    try {
        $reconcile = mysqlReconcileInstitutionAssets($pdo, $id, !$apply);
        $assets = mysqlPruneUnreferencedInstitutionAssets($pdo, $id, !$apply);
        $settings = $pdo->prepare("SELECT setting_json FROM institution_settings WHERE institution_id=:institution_id AND setting_key='backup' LIMIT 1"); $settings->execute(['institution_id' => $id]);
        $backupSettings = mysqlJson($settings->fetchColumn(), []); $recentCount = max(1, min(365, (int)($backupSettings['retentionCount'] ?? 14)));
        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . ($id === 1 ? '' : DIRECTORY_SEPARATOR . 'institution-' . $id);
        $backups = is_dir($directory) ? mysqlPruneRoutineBackupFiles($pdo, $id, $directory, $recentCount, !$apply) : ['routineCandidates' => 0, 'removed' => [], 'retained' => 0];
        if ($apply && ($assets['removed'] !== [] || $backups['removed'] !== [])) mysqlInsertPlatformAudit($pdo, 'storage-lifecycle-worker', 'storage_lifecycle_pruned', $id, 'institution_storage', (string)$id, 'success', 'storage-lifecycle-' . gmdate('Ymd'), ['unreferencedAssetsRemoved' => $assets['removed'], 'routineBackupsRemoved' => $backups['removed'], 'recentRoutineBackupCount' => $recentCount]);
        $pdo->commit(); $results[] = ['institutionId' => $id, 'slug' => $institution['slug'], 'reconcile' => $reconcile, 'assets' => $assets, 'backups' => $backups];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
echo json_encode(['pass' => true, 'dryRun' => !$apply, 'institutions' => $results], JSON_UNESCAPED_SLASHES) . PHP_EOL;
