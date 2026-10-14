<?php
declare(strict_types=1);

// Regression coverage for the foreign-key-safe compatibility persistence plan.
// This exercises the planner only; it does not modify the database.
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mysql_data_store.php';

function deletePlanIndex(array $plans, string $table, string $value): int {
    foreach ($plans as $index => $plan) {
        if (($plan['table'] ?? '') === $table && (string)($plan['value'] ?? '') === $value) return $index;
    }
    throw new RuntimeException("Missing deletion plan for {$table}:{$value}");
}

$before = [
    'students' => [['id' => 'student-1']],
    'passwords' => [['id' => 'password-1', 'studentId' => 'student-1']],
    'loginTokens' => [['tokenHash' => 'token-1', 'studentId' => 'student-1']],
    'sessions' => [['id' => 'attempt-1', 'studentId' => 'student-1', 'questions' => [], 'answers' => [], 'flagged' => [], 'integrityEvents' => []]],
    'results' => [['id' => 'submission-1', 'studentId' => 'student-1', 'sessionId' => 'attempt-1']],
    'calculatedResults' => [['id' => 'calculated-1', 'studentId' => 'student-1']],
    'newsletterSubscribers' => [['id' => 'subscriber-1', 'studentId' => 'student-1']],
];
$after = array_map(static fn(): array => [], $before);
$plan = mysqlTargetedPayload($before, $after);
$deletes = $plan['deletes'];

$submission = deletePlanIndex($deletes, 'component_submissions', 'submission-1');
$attempt = deletePlanIndex($deletes, 'assessment_attempts', 'attempt-1');
$student = deletePlanIndex($deletes, 'students', 'student-1');
$calculated = deletePlanIndex($deletes, 'calculated_results', 'calculated-1');
$subscriber = deletePlanIndex($deletes, 'newsletter_subscribers', 'subscriber-1');

if (!$plan['changed'] || !($submission < $attempt && $attempt < $student && $calculated < $student && $subscriber < $student)) {
    throw new RuntimeException('Student deletion plan does not respect foreign-key dependency order.');
}

echo "Student deletion persistence plan is foreign-key safe.\n";
