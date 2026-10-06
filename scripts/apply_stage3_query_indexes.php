<?php
declare(strict_types=1);

/** Indexes used by Stage 3's SQL pagination and dashboard aggregates. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$indexes = [
    'component_submissions' => [
        'ix_submissions_period_student_latest' => '(institution_id,academic_session_id,academic_semester_id,student_id,submitted_at)',
        'ix_submissions_submitted_at' => '(institution_id,submitted_at)',
    ],
];
$applied=[];
foreach ($indexes as $table => $definitions) {
    $existing=$pdo->query("SHOW INDEX FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);$names=array_fill_keys(array_column($existing,'Key_name'),true);
    foreach($definitions as $name=>$columns){if(isset($names[$name])){$applied[]="{$table}.{$name}:existing";continue;}$pdo->exec("CREATE INDEX {$name} ON {$table} {$columns}");$applied[]="{$table}.{$name}:created";}
}
echo json_encode(['pass'=>true,'indexes'=>$applied],JSON_UNESCAPED_SLASHES).PHP_EOL;
