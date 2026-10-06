<?php
declare(strict_types=1);

/* Idempotently extend the tenant-owned Algebra request ledger.  This does not
 * create action tables: every Stage 2 capability is read-only or draft-only. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$pdo = mysqlMigrationPdo(dirname(__DIR__));
try {
    $exists = $pdo->query("SHOW TABLES LIKE 'algebra_requests'")->fetchColumn();
    if (!$exists) {
        throw new RuntimeException('algebra_requests is missing. Run apply_algebra_stage1_schema.php first.');
    }
    $pdo->exec("ALTER TABLE algebra_requests MODIFY capability ENUM('question_draft','performance_insight','setup_suggestion','audit_digest','anomaly_review','communication_draft','result_report') NOT NULL");
    echo json_encode(['ok' => true, 'table' => 'algebra_requests', 'stage' => 2], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Algebra Stage 2 schema failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
