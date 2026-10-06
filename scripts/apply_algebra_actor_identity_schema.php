<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$pdo = mysqlMigrationPdo(dirname(__DIR__));
$columnExists = static function (string $table, string $column) use ($pdo): bool {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $statement->execute([$table, $column]);
    return (bool)$statement->fetchColumn();
};
$constraintExists = static function (string $table, string $constraint) use ($pdo): bool {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $statement->execute([$table, $constraint]);
    return (bool)$statement->fetchColumn();
};

try {
    if (!$columnExists('algebra_requests', 'requested_by_platform_admin_id')) {
        $pdo->exec("ALTER TABLE algebra_requests ADD COLUMN requested_by_platform_admin_id VARCHAR(128) NULL AFTER requested_by_admin_id");
    }
    $pdo->exec("ALTER TABLE algebra_requests MODIFY requested_by_admin_id VARCHAR(128) NULL");
    if (!$constraintExists('algebra_requests', 'ix_algebra_requests_platform_admin')) {
        $pdo->exec("ALTER TABLE algebra_requests ADD KEY ix_algebra_requests_platform_admin (requested_by_platform_admin_id,created_at)");
    }
    if (!$constraintExists('algebra_requests', 'fk_algebra_requests_platform_admin')) {
        $pdo->exec("ALTER TABLE algebra_requests ADD CONSTRAINT fk_algebra_requests_platform_admin FOREIGN KEY (requested_by_platform_admin_id) REFERENCES platform_admin_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT");
    }
    if (!$constraintExists('algebra_requests', 'chk_algebra_requests_one_actor')) {
        $pdo->exec("ALTER TABLE algebra_requests ADD CONSTRAINT chk_algebra_requests_one_actor CHECK ((requested_by_admin_id IS NOT NULL AND requested_by_platform_admin_id IS NULL) OR (requested_by_admin_id IS NULL AND requested_by_platform_admin_id IS NOT NULL))");
    }
    echo json_encode(['ok' => true, 'table' => 'algebra_requests', 'dbVersion' => $pdo->query('SELECT VERSION()')->fetchColumn()], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Algebra actor identity schema failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
