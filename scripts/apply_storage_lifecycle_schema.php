<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$pdo = mysqlMigrationPdo(dirname(__DIR__));
$pdo->exec("CREATE TABLE IF NOT EXISTS institution_assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id INT UNSIGNED NOT NULL,
  asset_type VARCHAR(40) NOT NULL,
  storage_key VARCHAR(500) NOT NULL,
  sha256 CHAR(64) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  mime_type VARCHAR(120) NULL,
  asset_state VARCHAR(24) NOT NULL DEFAULT 'active',
  created_by VARCHAR(254) NULL,
  created_at DATETIME(6) NOT NULL,
  referenced_at DATETIME(6) NULL,
  unreferenced_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  metadata_json JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_institution_asset_key (institution_id,storage_key),
  KEY ix_institution_assets_lifecycle (institution_id,asset_state,unreferenced_at),
  CONSTRAINT fk_institution_assets_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo json_encode(['pass' => true, 'table' => 'institution_assets', 'charset' => 'utf8mb4', 'foreignKeyDelete' => 'RESTRICT']) . PHP_EOL;
