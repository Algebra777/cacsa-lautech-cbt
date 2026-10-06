<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$pdo = mysqlMigrationPdo(dirname(__DIR__));
$pdo->exec("CREATE TABLE IF NOT EXISTS pdf_import_jobs (
  institution_id INT UNSIGNED NOT NULL,
  id CHAR(32) NOT NULL,
  course_id VARCHAR(128) NOT NULL,
  requested_by_admin_id VARCHAR(128) NULL,
  requested_by_platform_admin_id VARCHAR(128) NULL,
  requested_by_email VARCHAR(254) NOT NULL,
  requested_by_scope ENUM('institution','platform') NOT NULL DEFAULT 'institution',
  provider ENUM('gemini','openrouter') NOT NULL,
  status ENUM('queued','running','review_ready','failed','cancelled','completed') NOT NULL DEFAULT 'queued',
  source_key VARCHAR(500) NOT NULL,
  source_filename VARCHAR(255) NOT NULL,
  source_sha256 CHAR(64) NOT NULL,
  source_size_bytes BIGINT UNSIGNED NOT NULL,
  page_count SMALLINT UNSIGNED NULL,
  parsed_items_json JSON NULL,
  low_confidence_count SMALLINT UNSIGNED NULL,
  attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
  available_at DATETIME(6) NOT NULL,
  started_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  review_expires_at DATETIME(6) NULL,
  source_expires_at DATETIME(6) NULL,
  error_code VARCHAR(80) NULL,
  error_message VARCHAR(1000) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (institution_id,id),
  KEY ix_pdf_jobs_claim (status,available_at,created_at),
  KEY ix_pdf_jobs_admin (institution_id,requested_by_admin_id,status,created_at),
  KEY ix_pdf_jobs_platform_admin (requested_by_platform_admin_id,status,created_at),
  KEY ix_pdf_jobs_course (institution_id,course_id,status,created_at),
  CONSTRAINT fk_pdf_jobs_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pdf_jobs_course FOREIGN KEY (institution_id,course_id) REFERENCES courses (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pdf_jobs_admin FOREIGN KEY (institution_id,requested_by_admin_id) REFERENCES admin_users (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pdf_jobs_platform_admin FOREIGN KEY (requested_by_platform_admin_id) REFERENCES platform_admin_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo json_encode(['pass' => true, 'table' => 'pdf_import_jobs', 'charset' => 'utf8mb4', 'foreignKeyDelete' => 'RESTRICT']) . PHP_EOL;
