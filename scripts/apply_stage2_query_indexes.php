<?php
declare(strict_types=1);

/** Idempotently add only the indexes exercised by the Stage 2 focused queries. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$indexes = [
    'assessment_passwords' => ['ix_passwords_student_component_created' => '(institution_id,student_id,component_id,created_at)'],
    'assessment_attempts' => ['ix_attempts_active_student_component' => '(institution_id,student_id,component_id,status,ends_at)'],
    'component_submissions' => ['ix_submissions_student_component_submitted' => '(institution_id,student_id,component_id,submitted_at)'],
];
$applied=[];
foreach ($indexes as $table => $definitions) {
    $existing = $pdo->query("SHOW INDEX FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_fill_keys(array_column($existing, 'Key_name'), true);
    foreach ($definitions as $name => $columns) {
        if (isset($names[$name])) { $applied[] = $table . '.' . $name . ':existing'; continue; }
        $pdo->exec("CREATE INDEX {$name} ON {$table} {$columns}"); $applied[] = $table . '.' . $name . ':created';
    }
}
echo json_encode(['pass'=>true,'indexes'=>$applied], JSON_UNESCAPED_SLASHES) . PHP_EOL;
