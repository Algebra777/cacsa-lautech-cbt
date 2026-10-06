<?php
declare(strict_types=1);

/** Guarded cleanup for only storage-lifecycle-test-* disposable tenants. */
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'mysql_data_store.php';

$root = dirname(__DIR__); $pdo = mysqlMigrationPdo($root);
$find = $pdo->prepare("SELECT id,slug FROM institutions WHERE slug LIKE 'storage-lifecycle-test-%' AND id <> 1"); $find->execute(); $items = $find->fetchAll(); $removed = [];
foreach ($items as $item) {
    $id = (int)$item['id']; $slug = (string)$item['slug'];
    $directory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $id;
    if (!is_dir($directory)) mkdir($directory, 0700, true);
    $safety = 'storage-lifecycle-cleanup-safety-' . gmdate('Ymd-His') . '-' . $id . '.json';
    file_put_contents($directory . DIRECTORY_SEPARATOR . $safety, json_encode(['kind'=>'storage-lifecycle-cleanup-safety','institutionId'=>$id,'slug'=>$slug,'reason'=>'Disposable storage lifecycle verification cleanup','createdAt'=>gmdate('c')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    $pdo->beginTransaction();
    try {
        mysqlInsertPlatformAudit($pdo, 'storage-lifecycle-test', 'disposable_institution_cleanup', $id, 'institution', (string)$id, 'success', 'storage-lifecycle-cleanup', ['reason'=>'Storage lifecycle verification completed; disposable tenant removal authorized.','safetyBackup'=>$safety]);
        foreach (['audit_events','admin_sessions','admin_users','role_permissions','roles','institution_settings','institution_assets','backup_records','institution_branding'] as $table) $pdo->prepare("DELETE FROM {$table} WHERE institution_id=:id")->execute(['id'=>$id]);
        $pdo->prepare('DELETE FROM institutions WHERE id=:id AND slug=:slug')->execute(['id'=>$id,'slug'=>$slug]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    foreach (glob($root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'institution-logos' . DIRECTORY_SEPARATOR . $slug . '-*') ?: [] as $path) if (is_file($path)) unlink($path);
    $removed[] = ['id'=>$id,'slug'=>$slug,'safetyBackup'=>'database/backups/institution-'.$id.'/'.$safety];
}
echo json_encode(['pass'=>true,'removed'=>$removed], JSON_UNESCAPED_SLASHES) . PHP_EOL;
