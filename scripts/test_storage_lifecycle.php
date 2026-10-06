<?php
declare(strict_types=1);

/** Disposable, guarded verification of logo asset lifecycle and routine backup retention. */
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'mysql_data_store.php';

$root = dirname(__DIR__); $pdo = mysqlMigrationPdo($root);
$slug = 'storage-lifecycle-test-' . gmdate('Ymdhis'); $institutionId = 0;
$tracked = ['students','courses','questions','component_submissions','audit_events','institution_assets'];
$hash = static function (PDO $database, string $table): string {
    $statement = $database->query("SELECT * FROM {$table} WHERE institution_id=1 ORDER BY 1");
    return hash('sha256', json_encode($statement->fetchAll(), JSON_UNESCAPED_SLASHES));
};
$before = []; foreach ($tracked as $table) $before[$table] = $hash($pdo, $table);
$assetDirectory = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'institution-logos';
$backupDirectory = '';
$initialKey = ''; $replacementKey = '';
$token = bin2hex(random_bytes(32));
$http = static function (string $url, string $token, string $method = 'GET'): array {
    $headers = ['Accept: application/json'];
    if ($method !== 'GET') {
        $csrfHeaders = array_merge($headers, ['Cookie: CBT_ADMIN_SESSION=' . $token]);
        $csrfContext = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $csrfHeaders), 'ignore_errors' => true, 'timeout' => 20]]);
        $csrfRaw = file_get_contents($url . (str_contains($url, '?') ? '&' : '?') . 'action=auth-csrf', false, $csrfContext);
        $csrf = json_decode((string)$csrfRaw, true)['csrfToken'] ?? '';
        if (!is_string($csrf) || $csrf === '') throw new RuntimeException('Could not obtain CSRF token for lifecycle API verification.');
        $headers[] = 'Cookie: CBT_ADMIN_SESSION=' . $token . '; CBT_CSRF=' . $csrf; $headers[] = 'X-CSRF-Token: ' . $csrf;
    } else {
        $headers[] = 'Cookie: CBT_ADMIN_SESSION=' . $token;
    }
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => '', 'ignore_errors' => true, 'timeout' => 30]]);
    $raw = file_get_contents($url, false, $context); $statusLine = $http_response_header[0] ?? ''; preg_match('/\s(\d{3})\s/', $statusLine, $match);
    return ['status' => (int)($match[1] ?? 0), 'body' => json_decode((string)$raw, true) ?: []];
};

try {
    if (!is_dir($assetDirectory) && !mkdir($assetDirectory, 0750, true) && !is_dir($assetDirectory)) throw new RuntimeException('Could not create test asset directory.');
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO institutions (name,slug,active,created_at) VALUES (:name,:slug,1,UTC_TIMESTAMP(6))')->execute(['name' => 'Storage Lifecycle Test', 'slug' => $slug]);
    $institutionId = (int)$pdo->lastInsertId();
    $initialKey = 'uploads/institution-logos/' . $slug . '-original.png';
    $pdo->prepare("INSERT INTO institution_branding (institution_id,display_name,portal_title,logo_path,favicon_path,accent_source_color,primary_color,accent_color,nav_label,assessment_label,footer_primary,footer_secondary,footer_legal,result_sheet_title,newsletter_sender_name,support_email,updated_at) VALUES (:id,'Storage Lifecycle Test','Storage Lifecycle Test CBT',:logo,'favicon.php','#16774d','#16774d','#105839','Storage Lifecycle Test CBT','Assessment centre','Test tenant','Disposable','© test','Storage Lifecycle Test CBT','Storage Lifecycle Test','storage-lifecycle@example.invalid',UTC_TIMESTAMP(6))")->execute(['id' => $institutionId, 'logo' => $initialKey]);
    $pdo->prepare("INSERT INTO roles (institution_id,id,name,description,max_users,system_locked) VALUES (:id,'admin','Admin','Disposable lifecycle tester',1,1)")->execute(['id' => $institutionId]);
    foreach (['settings','roles'] as $permission) $pdo->prepare("INSERT INTO role_permissions (institution_id,role_id,permission) VALUES (:id,'admin',:permission)")->execute(['id' => $institutionId, 'permission' => $permission]);
    $pdo->prepare("INSERT INTO admin_users (institution_id,id,role_id,name,email,password_hash,active,verified,must_change_password,created_at) VALUES (:institution_id,'storage-lifecycle-admin','admin','Storage Lifecycle Admin','storage-lifecycle@example.invalid','not-used',1,1,0,UTC_TIMESTAMP(6))")->execute(['institution_id' => $institutionId]);
    $pdo->prepare("INSERT INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at) VALUES (:institution_id,:token_hash,'storage-lifecycle-admin','storage-lifecycle@example.invalid','Storage Lifecycle Admin','admin','[\"settings\",\"roles\"]','storage-lifecycle-test',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'2099-12-31 23:59:59.000000')")->execute(['institution_id' => $institutionId, 'token_hash' => hash('sha256', $token)]);
    $pdo->prepare("INSERT INTO institution_settings (institution_id,setting_key,setting_json) VALUES (:institution_id,'backup','{\"enabled\":true,\"time\":\"02:00\",\"retentionCount\":14,\"lastScheduledDate\":\"\",\"lastBackupAt\":\"\"}')")->execute(['institution_id' => $institutionId]);
    $pdo->commit();
    file_put_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $initialKey), 'original-test-logo', LOCK_EX);
    mysqlRegisterInstitutionAsset($pdo, $institutionId, 'institution_logo', $initialKey, 'storage-lifecycle-test', ['source' => 'test']);

    $replacementKey = 'uploads/institution-logos/' . $slug . '-replacement.png';
    file_put_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $replacementKey), 'replacement-test-logo', LOCK_EX);
    $updated = mysqlUpdateInstitutionBranding($institutionId, ['name' => 'Storage Lifecycle Test', 'displayName' => 'Storage Lifecycle Test', 'portalTitle' => 'Storage Lifecycle Test CBT', 'logoPath' => $replacementKey, 'accentSourceColor' => '#16774d', 'primaryColor' => '#16774d', 'accentColor' => '#105839', 'navLabel' => 'Storage Lifecycle Test CBT', 'assessmentLabel' => 'Assessment centre', 'footerPrimary' => 'Test tenant', 'footerSecondary' => 'Disposable', 'footerLegal' => '© test', 'resultSheetTitle' => 'Storage Lifecycle Test CBT', 'newsletterSenderName' => 'Storage Lifecycle Test', 'supportEmail' => 'storage-lifecycle@example.invalid'], 'storage-lifecycle-test', 'storage-lifecycle-test');
    if (!$updated) throw new RuntimeException('Branding update did not return the disposable institution.');
    $assets = $pdo->prepare('SELECT storage_key,asset_state FROM institution_assets WHERE institution_id=:id ORDER BY storage_key'); $assets->execute(['id' => $institutionId]); $assets = $assets->fetchAll();
    $states = []; foreach ($assets as $asset) $states[$asset['storage_key']] = $asset['asset_state'];
    if (($states[$initialKey] ?? '') !== 'unreferenced' || ($states[$replacementKey] ?? '') !== 'active') throw new RuntimeException('Logo replacement asset states were not applied safely.');
    $pdo->prepare("UPDATE institution_assets SET unreferenced_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 8 DAY), expires_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 HOUR) WHERE institution_id=:id AND storage_key=:key")->execute(['id' => $institutionId, 'key' => $initialKey]);
    $assetPrune = mysqlPruneUnreferencedInstitutionAssets($pdo, $institutionId, false);
    if (is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $initialKey))) throw new RuntimeException('Expired unreferenced logo was not removed.');
    if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $replacementKey))) throw new RuntimeException('Active logo was removed incorrectly.');

    $backupDirectory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
    if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) throw new RuntimeException('Could not create disposable backup directory.');
    $routine = [];
    foreach (['newest' => '-1 day', 'month-a' => '-3 months', 'month-b' => '-3 months -1 day', 'expired' => '-14 months'] as $label => $when) {
        $filename = 'cacsa-cbt-backup_2020-01-01_00000' . count($routine) . '.json'; $path = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, '{"test":true}', LOCK_EX); touch($path, strtotime($when));
        mysqlRegisterBackupRecord($institutionId, ['filename' => $filename, 'size' => filesize($path), 'checksum' => hash_file('sha256', $path)], 'scheduled', 'storage-lifecycle-test'); $routine[] = $filename;
    }
    $safety = $backupDirectory . DIRECTORY_SEPARATOR . 'cacsa-cbt-safety-before-restore_2020-01-01_000000.json'; file_put_contents($safety, '{"safety":true}', LOCK_EX); touch($safety, strtotime('-20 months'));
    $backupPrune = mysqlPruneRoutineBackupFiles($pdo, $institutionId, $backupDirectory, 1, false);
    if (!is_file($safety) || count($backupPrune['removed']) < 1) throw new RuntimeException('Routine retention did not preserve safety snapshots or prune stale routine backups.');

    $backupResponse = $http('http://localhost/BEREVION/i/' . rawurlencode($slug) . '/api.php?action=backups', $token, 'POST');
    $apiBackup = (array)($backupResponse['body']['backup'] ?? []);
    if ($backupResponse['status'] !== 200 || empty($apiBackup['filename']) || empty($apiBackup['checksum'])) throw new RuntimeException('The live backup endpoint did not create a checksum-backed backup record: HTTP ' . $backupResponse['status'] . ' ' . json_encode($backupResponse['body'], JSON_UNESCAPED_SLASHES));
    $record = $pdo->prepare('SELECT checksum_sha256,backup_type FROM backup_records WHERE institution_id=:id AND filename=:filename'); $record->execute(['id' => $institutionId, 'filename' => $apiBackup['filename']]); $record = $record->fetch();
    if (!$record || !hash_equals((string)$apiBackup['checksum'], (string)$record['checksum_sha256'])) throw new RuntimeException('The live backup endpoint did not persist matching backup metadata.');

    foreach ($before as $table => $value) if (!hash_equals($value, $hash($pdo, $table))) throw new RuntimeException("CACSA {$table} changed during storage lifecycle verification.");
    $result = ['pass' => true, 'institutionId' => $institutionId, 'assetPrune' => $assetPrune, 'backupPrune' => $backupPrune, 'apiBackup' => ['filename' => $apiBackup['filename'], 'checksumRecorded' => true], 'cacsaHashes' => 'unchanged'];
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($institutionId > 1) {
        $snapshot = ['kind' => 'storage-lifecycle-test-safety-backup', 'institutionId' => $institutionId, 'slug' => $slug, 'createdAt' => gmdate('c')];
        if ($backupDirectory === '') $backupDirectory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
        if (!is_dir($backupDirectory)) mkdir($backupDirectory, 0700, true);
        file_put_contents($backupDirectory . DIRECTORY_SEPARATOR . 'storage-lifecycle-test-safety-' . gmdate('Ymd-His') . '.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        $pdo->beginTransaction();
        try {
            mysqlInsertPlatformAudit($pdo, 'storage-lifecycle-test', 'disposable_institution_cleanup', $institutionId, 'institution', (string)$institutionId, 'success', 'storage-lifecycle-cleanup', ['reason' => 'Storage lifecycle verification completed; disposable tenant removal authorized.']);
            foreach (['audit_events','admin_sessions','admin_users','role_permissions','roles','institution_settings','institution_assets','backup_records','institution_branding'] as $table) $pdo->prepare("DELETE FROM {$table} WHERE institution_id=:id")->execute(['id' => $institutionId]);
            $pdo->prepare('DELETE FROM institutions WHERE id=:id AND slug=:slug')->execute(['id' => $institutionId, 'slug' => $slug]);
            $pdo->commit();
        } catch (Throwable $cleanupError) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $cleanupError; }
    }
    foreach ([$initialKey, $replacementKey] as $key) if ($key !== '') { $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key); if (is_file($path)) unlink($path); }
}
echo json_encode($result ?? ['pass' => false], JSON_UNESCAPED_SLASHES) . PHP_EOL;
