<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mysql_data_store.php';
$data = mysqlLoadData();
echo json_encode([
    'students' => count($data['students']),
    'courses' => count($data['courses']),
    'components' => count($data['exams']),
    'questions' => count($data['questions']),
    'attempts' => count($data['sessions']),
    'submissions' => count($data['results']),
    'auditEvents' => count($data['auditEvents']),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
