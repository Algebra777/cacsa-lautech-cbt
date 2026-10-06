<?php
declare(strict_types=1);

/* Algebra Stage 1 schema migration.  It is idempotent and intentionally
 * creates only tenant-scoped data; platform capabilities arrive in Stage 3. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$pdo = mysqlMigrationPdo(dirname(__DIR__));
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS algebra_requests (
      institution_id INT UNSIGNED NOT NULL,
      id CHAR(32) NOT NULL,
      requested_by_admin_id VARCHAR(128) NOT NULL,
      capability ENUM('question_draft','performance_insight') NOT NULL,
      status ENUM('running','completed','failed','imported') NOT NULL DEFAULT 'running',
      request_hash CHAR(64) NOT NULL,
      input_json JSON NOT NULL,
      response_json JSON NULL,
      error_code VARCHAR(80) NULL,
      error_message VARCHAR(500) NULL,
      correlation_id VARCHAR(190) NULL,
      created_at DATETIME(6) NOT NULL,
      completed_at DATETIME(6) NULL,
      PRIMARY KEY (institution_id,id),
      KEY ix_algebra_requests_admin (institution_id,requested_by_admin_id,created_at),
      KEY ix_algebra_requests_capability (institution_id,capability,status,created_at),
      CONSTRAINT fk_algebra_requests_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
      CONSTRAINT fk_algebra_requests_admin FOREIGN KEY (institution_id,requested_by_admin_id) REFERENCES admin_users (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo json_encode(['ok' => true, 'table' => 'algebra_requests'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Algebra Stage 1 schema failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
