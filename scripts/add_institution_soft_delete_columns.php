 <?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$columns = $pdo->query('SHOW COLUMNS FROM institutions')->fetchAll(PDO::FETCH_COLUMN);
$additions = [
    'deleted_at' => 'ADD COLUMN deleted_at DATETIME(6) NULL AFTER active',
    'deleted_by' => 'ADD COLUMN deleted_by VARCHAR(254) NULL AFTER deleted_at',
    'deletion_reason' => 'ADD COLUMN deletion_reason TEXT NULL AFTER deleted_by',
    'retention_until' => 'ADD COLUMN retention_until DATETIME(6) NULL AFTER deletion_reason',
];
foreach ($additions as $name => $statement) if (!in_array($name, $columns, true)) $pdo->exec('ALTER TABLE institutions ' . $statement);
$index = $pdo->query("SHOW INDEX FROM institutions WHERE Key_name = 'idx_institutions_deleted_at'")->fetch();
if (!$index) $pdo->exec('ALTER TABLE institutions ADD KEY idx_institutions_deleted_at (deleted_at)');
echo json_encode(['ok' => true, 'columns' => array_keys($additions)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
