<?php
declare(strict_types=1);

/* A job is tenant-owned, but the platform Super Admin is stored separately
 * from tenant admin_users. Do not imitate a tenant Admin to satisfy the FK. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$pdo = mysqlMigrationPdo(dirname(__DIR__));
try {
    $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'pdf_import_jobs'")->fetchColumn();
    if (!$hasTable) throw new RuntimeException('pdf_import_jobs is missing; run apply_pdf_import_queue_schema.php first.');
    $pdo->exec("ALTER TABLE pdf_import_jobs MODIFY requested_by_admin_id VARCHAR(128) NULL");
    $check = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pdf_import_jobs' AND column_name IN ('requested_by_scope','requested_by_platform_admin_id')");
    $check->execute(); $columns = array_fill_keys($check->fetchAll(PDO::FETCH_COLUMN), true);
    if (!isset($columns['requested_by_platform_admin_id'])) $pdo->exec("ALTER TABLE pdf_import_jobs ADD COLUMN requested_by_platform_admin_id VARCHAR(128) NULL AFTER requested_by_admin_id");
    if (!isset($columns['requested_by_scope'])) $pdo->exec("ALTER TABLE pdf_import_jobs ADD COLUMN requested_by_scope ENUM('institution','platform') NOT NULL DEFAULT 'institution' AFTER requested_by_email");
    $fk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() AND table_name='pdf_import_jobs' AND constraint_name='fk_pdf_jobs_platform_admin'");
    $fk->execute();
    if (!(int)$fk->fetchColumn()) $pdo->exec("ALTER TABLE pdf_import_jobs ADD KEY ix_pdf_jobs_platform_admin (requested_by_platform_admin_id,status,created_at), ADD CONSTRAINT fk_pdf_jobs_platform_admin FOREIGN KEY (requested_by_platform_admin_id) REFERENCES platform_admin_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT");
    echo json_encode(['ok'=>true,'table'=>'pdf_import_jobs','requesterScopes'=>['institution','platform']], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'PDF import requester-scope migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
