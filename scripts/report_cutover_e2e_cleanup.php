<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mysql_data_store.php';
$data = mysqlLoadData();
$component = null;
foreach ($data['exams'] as $item) if (($item['id'] ?? '') === 'e926e66d2eb63630') { $component = $item; break; }
$temporaryQuestions = array_values(array_filter($data['questions'], fn(array $question): bool => str_starts_with((string)($question['text'] ?? ''), '[CUTOVER SMOKE]')));
$temporaryResults = array_values(array_filter($data['results'], fn(array $result): bool => ($result['id'] ?? '') === '0a184d7c9613caff'));
$temporarySessions = array_values(array_filter($data['sessions'], fn(array $session): bool => ($session['examId'] ?? '') === 'e926e66d2eb63630' && str_contains((string)($session['correlationId'] ?? ''), 'mysql-cutover-e2e')));
echo json_encode([
    'component' => $component ? ['status' => $component['status'], 'startAt' => $component['startAt'], 'endAt' => $component['endAt'], 'questionCount' => $component['questionCount']] : null,
    'temporaryQuestionCount' => count($temporaryQuestions),
    'temporarySubmissionCount' => count($temporaryResults),
    'temporarySessionCount' => count($temporarySessions),
    'calculatedResultCount' => count($data['calculatedResults']),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
