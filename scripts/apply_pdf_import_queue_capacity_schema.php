<?php
declare(strict_types=1);

/** Adds the indexes used by tenant-safe PDF queue admission checks. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$pdo = mysqlMigrationPdo(dirname(__DIR__));
if (!(bool)$pdo->query("SHOW TABLES LIKE 'pdf_import_jobs'")->fetchColumn()) throw new RuntimeException('pdf_import_jobs is missing; run apply_pdf_import_queue_schema.php first.');
$indexes = $pdo->query("SELECT index_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='pdf_import_jobs'")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('ix_pdf_jobs_capacity', $indexes, true)) $pdo->exec('ALTER TABLE pdf_import_jobs ADD KEY ix_pdf_jobs_capacity (institution_id,status,created_at)');
if (!in_array('ix_pdf_jobs_created', $indexes, true)) $pdo->exec('ALTER TABLE pdf_import_jobs ADD KEY ix_pdf_jobs_created (created_at)');
echo json_encode(['pass' => true, 'table' => 'pdf_import_jobs', 'indexes' => ['ix_pdf_jobs_capacity', 'ix_pdf_jobs_created']], JSON_UNESCAPED_SLASHES) . PHP_EOL;
