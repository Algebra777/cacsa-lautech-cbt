<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const ADMIN_EMAIL = 'adepojutimothy001@gmail.com';
const ADMIN_PASSWORD = 'admin123';
const DATA_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'cbt-data.json';
const DEFAULT_GRADING_SCALE = [
    ['minScore' => 70, 'maxScore' => 100, 'grade' => 'A', 'gradePoint' => 5],
    ['minScore' => 60, 'maxScore' => 69.99, 'grade' => 'B', 'gradePoint' => 4],
    ['minScore' => 50, 'maxScore' => 59.99, 'grade' => 'C', 'gradePoint' => 3],
    ['minScore' => 45, 'maxScore' => 49.99, 'grade' => 'D', 'gradePoint' => 2],
    ['minScore' => 40, 'maxScore' => 44.99, 'grade' => 'E', 'gradePoint' => 1],
    ['minScore' => 0, 'maxScore' => 39.99, 'grade' => 'F', 'gradePoint' => 0]
];

function respond(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

function loadData(): array {
    if (!file_exists(DATA_FILE)) {
        $data = [
            'students' => [],
            'exams' => [
                ['id' => 'math101', 'code' => 'MTH 101', 'title' => 'Foundations of Algebra', 'description' => 'Core algebraic reasoning, expressions, equations, and functions.', 'duration' => 35, 'questionCount' => 10, 'startAt' => date('c', strtotime('-1 hour')), 'endAt' => date('c', strtotime('+8 hours')), 'status' => 'active'],
                ['id' => 'stat201', 'code' => 'STA 201', 'title' => 'Statistics & Probability', 'description' => 'Distributions, sampling, probability rules, and data interpretation.', 'duration' => 45, 'questionCount' => 10, 'startAt' => date('c', strtotime('-30 minutes')), 'endAt' => date('c', strtotime('+9 hours')), 'status' => 'active'],
                ['id' => 'cs105', 'code' => 'CSC 105', 'title' => 'Digital Logic', 'description' => 'Boolean algebra, logic gates, and introductory circuit design.', 'duration' => 25, 'questionCount' => 10, 'startAt' => date('c', strtotime('+1 hour')), 'endAt' => date('c', strtotime('+10 hours')), 'status' => 'draft']
            ],
            'questions' => [
                ['id' => 'q1', 'examId' => 'math101', 'text' => 'Simplify the expression: 3(2x - 4) + 5.', 'options' => ['6x - 7', '6x - 12', '6x + 1', '6x - 1'], 'correctOptions' => [0], 'type' => 'single', 'topic' => 'Expressions', 'difficulty' => 'easy'],
                ['id' => 'q2', 'examId' => 'math101', 'text' => 'If f(x) = x² - 3x + 2, what is f(2)?', 'options' => ['0', '1', '2', '4'], 'correctOptions' => [0], 'type' => 'single', 'topic' => 'Functions', 'difficulty' => 'easy'],
                ['id' => 'q3', 'examId' => 'math101', 'text' => 'Which statement is true for the equation 2x + 6 = 14?', 'options' => ['x = 3', 'x = 4', 'x = 5', 'x = 6'], 'correctOptions' => [1], 'type' => 'single', 'topic' => 'Equations', 'difficulty' => 'easy'],
                ['id' => 'q4', 'examId' => 'math101', 'text' => 'Factorise completely: x² - 9.', 'options' => ['(x - 3)(x + 3)', '(x - 9)(x + 1)', '(x - 3)²', 'x(x - 9)'], 'correctOptions' => [0], 'type' => 'single', 'topic' => 'Factorisation', 'difficulty' => 'medium'],
                ['id' => 'q5', 'examId' => 'math101', 'text' => 'A line has a gradient of 2 and passes through (1, 4). What is its y-intercept?', 'options' => ['1', '2', '3', '4'], 'correctOptions' => [0], 'type' => 'single', 'topic' => 'Coordinate geometry', 'difficulty' => 'medium']
            ],
            'passwords' => [],
            'sessions' => [],
            'loginTokens' => [],
            'results' => [],
            'settings' => ['gradingScale' => DEFAULT_GRADING_SCALE]
        ];
        saveData($data);
        return $data;
    }
    $data = json_decode(file_get_contents(DATA_FILE), true);
    if (!is_array($data)) return ['students' => [], 'exams' => [], 'questions' => [], 'passwords' => [], 'sessions' => [], 'loginTokens' => [], 'results' => []];
    $data['loginTokens'] ??= [];
    $data['settings'] ??= ['gradingScale' => DEFAULT_GRADING_SCALE];
    $data['settings']['gradingScale'] ??= DEFAULT_GRADING_SCALE;
    foreach ($data['exams'] as &$exam) { $exam['courseUnit'] ??= 3; $exam['session'] ??= ''; }
    unset($exam);
    foreach ($data['questions'] as &$question) $question['status'] ??= 'published';
    unset($question);
    return $data;
}

function saveData(array $data): void {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function id(): string { return bin2hex(random_bytes(8)); }
function auth(bool $admin = false): void {
    if (!$admin) return;
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!$header && !empty($_SERVER['HTTP_X_ADMIN_TOKEN'])) $header = 'Bearer ' . $_SERVER['HTTP_X_ADMIN_TOKEN'];
    if ($header !== 'Bearer admin-demo-token') respond(['error' => 'Admin authentication required.'], 401);
}
function findBy(array $items, string $key, mixed $value): ?array {
    foreach ($items as $item) if (($item[$key] ?? null) === $value) return $item;
    return null;
}
function replaceBy(array &$items, string $key, mixed $value, array $replacement): bool {
    foreach ($items as $index => $item) if (($item[$key] ?? null) === $value) { $items[$index] = $replacement; return true; }
    return false;
}
function randomPassword(): string {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 8; $i++) $password .= $characters[random_int(0, strlen($characters) - 1)];
    return $password;
}
function publicExam(array $exam): array {
    $now = time();
    $active = $exam['status'] === 'active' && strtotime($exam['startAt']) <= $now && strtotime($exam['endAt']) >= $now;
    $exam['active'] = $active;
    $exam['window'] = date('M j, H:i', strtotime($exam['startAt'])) . ' - ' . date('H:i', strtotime($exam['endAt']));
    return $exam;
}
function publicQuestion(array $question): array {
    unset($question['correctOptions']);
    return $question;
}
function gradeForScore(float $score, array $scale): array {
    foreach ($scale as $band) if ($score >= (float)$band['minScore'] && $score <= (float)$band['maxScore']) return ['grade' => (string)$band['grade'], 'gradePoint' => (float)$band['gradePoint']];
    return ['grade' => 'F', 'gradePoint' => 0];
}
function recalculateResults(array &$data): void {
    foreach ($data['results'] as &$result) { $exam = findBy($data['exams'], 'id', $result['examId']); $unit = max(1, (int)($exam['courseUnit'] ?? 3)); $grade = gradeForScore((float)$result['score'], $data['settings']['gradingScale']); $result['courseUnit'] = $unit; $result['grade'] = $grade['grade']; $result['gradePoint'] = $grade['gradePoint']; $result['qualityPoints'] = round($unit * $grade['gradePoint'], 2); $result['session'] = $exam['session'] ?? ''; }
    unset($result);
}
function completeSession(array &$data, array $session, bool $auto): array {
    $questions = array_values(array_filter($data['questions'], fn($question) => $question['examId'] === $session['examId'] && ($question['status'] ?? 'published') === 'published'));
    $correct = 0;
    foreach ($questions as $question) { $answer = $session['answers'][$question['id']] ?? []; sort($answer); $expected = $question['correctOptions']; sort($expected); if ($answer === $expected) $correct++; }
    $session['status'] = $auto ? 'auto_submitted' : 'submitted'; $session['submittedAt'] = date('c'); $session['score'] = count($questions) ? round(($correct / count($questions)) * 100, 1) : 0;
    replaceBy($data['sessions'], 'id', $session['id'], $session);
    if (!findBy($data['results'], 'sessionId', $session['id'])) $data['results'][] = ['id' => id(), 'sessionId' => $session['id'], 'studentId' => $session['studentId'], 'examId' => $session['examId'], 'score' => $session['score'], 'submittedAt' => $session['submittedAt'], 'status' => $session['status']];
    recalculateResults($data);
    return $session;
}
function expireSessions(array &$data): void { foreach ($data['sessions'] as $session) if ($session['status'] === 'in_progress' && strtotime($session['endsAt']) <= time()) completeSession($data, $session, true); }

$data = loadData();
$beforeExpiry = json_encode($data); expireSessions($data); recalculateResults($data); if ($beforeExpiry !== json_encode($data)) saveData($data);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($action === 'health') respond(['ok' => true, 'storage' => DATA_FILE]);
if ($action === 'admin-login' && $method === 'POST') {
    $input = body();
    if (($input['email'] ?? '') !== ADMIN_EMAIL || ($input['password'] ?? '') !== ADMIN_PASSWORD) respond(['error' => 'Invalid admin credentials.'], 401);
    respond(['token' => 'admin-demo-token', 'user' => ['email' => ADMIN_EMAIL, 'name' => 'Adepoju Timothy', 'role' => 'super_admin']]);
}

if ($action === 'exams' && $method === 'GET') {
    $items = array_map('publicExam', $data['exams']);
    if (($_GET['active'] ?? '') === 'true') $items = array_values(array_filter($items, fn($exam) => $exam['active']));
    respond(['items' => $items]);
}
if ($action === 'exams' && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    auth(true);
    if ($method === 'POST') {
        $input = body();
        $exam = ['id' => id(), 'code' => trim($input['code'] ?? ''), 'title' => trim($input['title'] ?? ''), 'description' => trim($input['description'] ?? ''), 'duration' => max(1, (int)($input['duration'] ?? 30)), 'questionCount' => max(1, (int)($input['questionCount'] ?? 10)), 'courseUnit' => max(1, min(6, (int)($input['courseUnit'] ?? 3))), 'session' => trim($input['session'] ?? ''), 'startAt' => $input['startAt'] ?? date('c'), 'endAt' => $input['endAt'] ?? date('c', strtotime('+1 day')), 'status' => $input['status'] ?? 'draft'];
        if (!$exam['code'] || !$exam['title']) respond(['error' => 'Exam code and title are required.'], 422);
        $data['exams'][] = $exam; saveData($data); respond(['item' => publicExam($exam)], 201);
    }
    $examId = $_GET['id'] ?? '';
    if ($method === 'DELETE') { $data['exams'] = array_values(array_filter($data['exams'], fn($exam) => $exam['id'] !== $examId)); saveData($data); respond(['ok' => true]); }
    $input = body(); $exam = findBy($data['exams'], 'id', $examId); if (!$exam) respond(['error' => 'Exam not found.'], 404);
    foreach (['code','title','description','startAt','endAt','status'] as $field) if (array_key_exists($field, $input)) $exam[$field] = trim((string)$input[$field]);
    foreach (['duration','questionCount','courseUnit'] as $field) if (array_key_exists($field, $input)) $exam[$field] = $field === 'courseUnit' ? max(1, min(6, (int)$input[$field])) : max(1, (int)$input[$field]);
    if (array_key_exists('session', $input)) $exam['session'] = trim((string)$input['session']);
    replaceBy($data['exams'], 'id', $examId, $exam); recalculateResults($data); saveData($data); respond(['item' => publicExam($exam)]);
}

if ($action === 'students' && $method === 'GET') { auth(true); respond(['items' => $data['students']]); }
if ($action === 'students' && $method === 'POST') {
    auth(true); $input = body();
    if (!trim($input['fullName'] ?? '') || !trim($input['email'] ?? '') || !trim($input['matricNumber'] ?? '') || !trim($input['department'] ?? '')) respond(['error' => 'Full name, email, matric number, and department are required.'], 422);
    if (findBy($data['students'], 'matricNumber', trim($input['matricNumber']))) respond(['error' => 'That matric number is already registered.'], 409);
    $student = ['id' => id(), 'fullName' => trim($input['fullName']), 'email' => trim($input['email']), 'matricNumber' => trim($input['matricNumber']), 'department' => trim($input['department']), 'active' => true, 'createdAt' => date('c')];
    $data['students'][] = $student; saveData($data); respond(['item' => $student], 201);
}
if ($action === 'students' && in_array($method, ['PUT','DELETE'], true)) {
    auth(true); $studentId = $_GET['id'] ?? ''; $student = findBy($data['students'], 'id', $studentId); if (!$student) respond(['error' => 'Student not found.'], 404);
    if ($method === 'DELETE') { $student['active'] = false; replaceBy($data['students'], 'id', $studentId, $student); saveData($data); respond(['item' => $student]); }
    $input = body(); foreach (['fullName','email','matricNumber','department','active'] as $field) if (array_key_exists($field, $input)) $student[$field] = $field === 'active' ? (bool)$input[$field] : trim((string)$input[$field]);
    replaceBy($data['students'], 'id', $studentId, $student); saveData($data); respond(['item' => $student]);
}
if ($action === 'exam-password' && $method === 'POST') {
    auth(true); $input = body(); $student = findBy($data['students'], 'matricNumber', trim($input['matricNumber'] ?? '')); $exam = findBy($data['exams'], 'id', $input['examId'] ?? '');
    if (!$student || !$exam) respond(['error' => 'Student or exam could not be found.'], 404);
    $password = randomPassword(); $record = ['id' => id(), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordHash' => password_hash($password, PASSWORD_DEFAULT), 'createdAt' => date('c'), 'expiresAt' => $exam['endAt']];
    $data['passwords'] = array_values(array_filter($data['passwords'], fn($item) => !($item['studentId'] === $student['id'] && $item['examId'] === $exam['id']))); $data['passwords'][] = $record; saveData($data); respond(['student' => $student, 'exam' => publicExam($exam), 'password' => $password]);
}

if ($action === 'questions' && $method === 'GET') { auth(true); $items = $data['questions']; if (!empty($_GET['examId'])) $items = array_values(array_filter($items, fn($item) => $item['examId'] === $_GET['examId'])); respond(['items' => $items]); }
if ($action === 'questions' && in_array($method, ['POST','PUT','DELETE'], true)) {
    auth(true); $questionId = $_GET['id'] ?? '';
    if ($method === 'DELETE') { $data['questions'] = array_values(array_filter($data['questions'], fn($item) => $item['id'] !== $questionId)); saveData($data); respond(['ok' => true]); }
    $input = body();
    if ($method === 'POST') { $question = ['id' => id(), 'examId' => $input['examId'] ?? '', 'text' => trim($input['text'] ?? ''), 'options' => array_values($input['options'] ?? []), 'correctOptions' => array_values(array_map('intval', $input['correctOptions'] ?? [])), 'type' => ($input['type'] ?? 'single') === 'multiple' ? 'multiple' : 'single', 'topic' => trim($input['topic'] ?? ''), 'difficulty' => trim($input['difficulty'] ?? 'medium'), 'status' => ($input['status'] ?? 'draft') === 'published' ? 'published' : 'draft']; if (!$question['examId'] || !$question['text'] || count($question['options']) < 2 || !$question['correctOptions'] || ($question['type'] === 'single' && count($question['correctOptions']) !== 1)) respond(['error' => 'Provide an exam, question, at least two options, and valid correct answer selection.'], 422); $data['questions'][] = $question; saveData($data); respond(['item' => $question], 201); }
    $question = findBy($data['questions'], 'id', $questionId); if (!$question) respond(['error' => 'Question not found.'], 404); foreach (['examId','text','topic','difficulty','type'] as $field) if (array_key_exists($field, $input)) $question[$field] = trim((string)$input[$field]); if (array_key_exists('status', $input)) $question['status'] = $input['status'] === 'published' ? 'published' : 'draft'; if (array_key_exists('options', $input)) $question['options'] = array_values($input['options']); if (array_key_exists('correctOptions', $input)) $question['correctOptions'] = array_values(array_map('intval', $input['correctOptions'])); replaceBy($data['questions'], 'id', $questionId, $question); saveData($data); respond(['item' => $question]);
}
if ($action === 'questions-bulk' && $method === 'POST') {
    auth(true); $input = body(); $added = 0; foreach (($input['items'] ?? []) as $item) { if (empty($item['text']) || empty($item['examId'])) continue; $data['questions'][] = ['id' => id(), 'examId' => $item['examId'], 'text' => trim($item['text']), 'options' => array_values($item['options'] ?? []), 'correctOptions' => array_values(array_map('intval', $item['correctOptions'] ?? [0])), 'type' => ($item['type'] ?? 'single') === 'multiple' ? 'multiple' : 'single', 'topic' => $item['topic'] ?? '', 'difficulty' => $item['difficulty'] ?? 'medium', 'status' => ($item['status'] ?? 'draft') === 'published' ? 'published' : 'draft']; $added++; } saveData($data); respond(['added' => $added]);
}

if ($action === 'student-login' && $method === 'POST') {
    $input = body(); $student = findBy($data['students'], 'matricNumber', trim($input['matricNumber'] ?? '')); $exam = findBy($data['exams'], 'id', $input['examId'] ?? '');
    $passwordRecord = $student && $exam ? array_values(array_filter($data['passwords'], fn($item) => $item['studentId'] === $student['id'] && $item['examId'] === $exam['id'])) : [];
    $passwordRecord = $passwordRecord ? end($passwordRecord) : null;
    if (!$student || !$student['active'] || !$exam || !$passwordRecord || !password_verify((string)($input['password'] ?? ''), $passwordRecord['passwordHash']) || strtotime($passwordRecord['expiresAt']) < time()) respond(['error' => 'We could not verify those details.'], 401);
    if (!publicExam($exam)['active']) respond(['error' => 'This assessment is not currently available.'], 403);
    $token = id(); $data['loginTokens'][] = ['token' => $token, 'studentId' => $student['id'], 'examId' => $exam['id'], 'expiresAt' => $exam['endAt']]; saveData($data);
    respond(['student' => $student, 'exam' => publicExam($exam), 'loginToken' => $token]);
}
if ($action === 'session-start' && $method === 'POST') {
    $input = body(); $student = findBy($data['students'], 'id', $input['studentId'] ?? ''); $exam = findBy($data['exams'], 'id', $input['examId'] ?? ''); $token = findBy($data['loginTokens'], 'token', $input['loginToken'] ?? ''); if (!$student || !$exam || !$token || $token['studentId'] !== $student['id'] || $token['examId'] !== $exam['id'] || strtotime($token['expiresAt']) < time() || !publicExam($exam)['active']) respond(['error' => 'Session data is invalid or expired.'], 422);
    $questionsForExam = array_values(array_filter($data['questions'], fn($question) => $question['examId'] === $exam['id'] && ($question['status'] ?? 'published') === 'published'));
    if (!$questionsForExam) respond(['error' => 'This assessment cannot start yet because no questions have been added to it.'], 422);
    $now = time(); $session = ['id' => id(), 'studentId' => $student['id'], 'examId' => $exam['id'], 'startedAt' => date('c', $now), 'endsAt' => date('c', $now + ((int)$exam['duration'] * 60)), 'answers' => [], 'flagged' => [], 'status' => 'in_progress']; $data['sessions'][] = $session; saveData($data); respond(['session' => ['id' => $session['id'], 'startedAt' => $session['startedAt'], 'endsAt' => $session['endsAt']], 'questions' => array_map('publicQuestion', $questionsForExam)]);
}
if ($action === 'session-answer' && $method === 'POST') {
    $input = body(); $session = findBy($data['sessions'], 'id', $input['sessionId'] ?? ''); if (!$session || $session['status'] !== 'in_progress') respond(['error' => 'Exam session is no longer active.'], 409); if (strtotime($session['endsAt']) <= time()) { $session['status'] = 'auto_submitted'; replaceBy($data['sessions'], 'id', $session['id'], $session); saveData($data); respond(['error' => 'Time has expired.'], 409); } $session['answers'][(string)$input['questionId']] = array_values(array_map('intval', $input['answers'] ?? [])); replaceBy($data['sessions'], 'id', $session['id'], $session); saveData($data); respond(['ok' => true]);
}
if ($action === 'session-flag' && $method === 'POST') { $input = body(); $session = findBy($data['sessions'], 'id', $input['sessionId'] ?? ''); if (!$session) respond(['error' => 'Session not found.'], 404); $questionId = (string)$input['questionId']; if (!empty($input['flagged']) && !in_array($questionId, $session['flagged'], true)) $session['flagged'][] = $questionId; if (empty($input['flagged'])) $session['flagged'] = array_values(array_filter($session['flagged'], fn($item) => $item !== $questionId)); replaceBy($data['sessions'], 'id', $session['id'], $session); saveData($data); respond(['ok' => true]); }
if ($action === 'session-submit' && $method === 'POST') {
    $input = body(); $session = findBy($data['sessions'], 'id', $input['sessionId'] ?? ''); if (!$session) respond(['error' => 'Session not found.'], 404); if ($session['status'] !== 'in_progress') { $existing = findBy($data['results'], 'sessionId', $session['id']); respond(['result' => $existing]); } $session = completeSession($data, $session, !empty($input['auto']) || strtotime($session['endsAt']) <= time()); saveData($data); respond(['result' => findBy($data['results'], 'sessionId', $session['id'])]);
}
if ($action === 'results' && $method === 'GET') { auth(true); $items = $data['results']; foreach ($items as &$result) { $student = findBy($data['students'], 'id', $result['studentId']); $exam = findBy($data['exams'], 'id', $result['examId']); $result['studentName'] = $student['fullName'] ?? 'Unknown student'; $result['examCode'] = $exam['code'] ?? 'Unknown exam'; } respond(['items' => $items]); }
if ($action === 'settings' && $method === 'GET') { auth(true); respond(['settings' => $data['settings']]); }
if ($action === 'settings' && $method === 'PUT') { auth(true); $input = body(); $scale = $input['gradingScale'] ?? []; if (!is_array($scale) || !$scale) respond(['error' => 'Provide at least one grading-scale row.'], 422); $clean = []; foreach ($scale as $band) { if (!isset($band['minScore'], $band['maxScore'], $band['grade'], $band['gradePoint']) || (float)$band['minScore'] > (float)$band['maxScore']) respond(['error' => 'Each grading-scale row needs a valid score range, grade, and grade point.'], 422); $clean[] = ['minScore' => (float)$band['minScore'], 'maxScore' => (float)$band['maxScore'], 'grade' => strtoupper(trim((string)$band['grade'])), 'gradePoint' => max(0, (float)$band['gradePoint'])]; } usort($clean, fn($a, $b) => $b['minScore'] <=> $a['minScore']); $data['settings']['gradingScale'] = $clean; recalculateResults($data); saveData($data); respond(['settings' => $data['settings']]); }
if ($action === 'student-results' && $method === 'GET') { auth(true); $student = findBy($data['students'], 'id', $_GET['id'] ?? ''); if (!$student) respond(['error' => 'Student not found.'], 404); $items = array_values(array_filter($data['results'], fn($result) => $result['studentId'] === $student['id'])); foreach ($items as &$item) { $exam = findBy($data['exams'], 'id', $item['examId']); $item['courseCode'] = $exam['code'] ?? 'Unknown course'; $item['courseTitle'] = $exam['title'] ?? ''; $item['session'] = $exam['session'] ?? ''; } unset($item); $groups = []; $totalUnits = 0; $totalQuality = 0; foreach ($items as $item) { $key = $item['session'] ?: 'Unassigned session'; $groups[$key] ??= ['courseUnit' => 0, 'qualityPoints' => 0]; $groups[$key]['courseUnit'] += $item['courseUnit']; $groups[$key]['qualityPoints'] += $item['qualityPoints']; $totalUnits += $item['courseUnit']; $totalQuality += $item['qualityPoints']; } foreach ($groups as &$group) $group['gpa'] = $group['courseUnit'] ? round($group['qualityPoints'] / $group['courseUnit'], 2) : 0; unset($group); respond(['student' => $student, 'items' => $items, 'sessions' => $groups, 'cgpa' => $totalUnits ? round($totalQuality / $totalUnits, 2) : 0]); }
if ($action === 'dashboard' && $method === 'GET') { auth(true); $today = date('Y-m-d'); $completed = array_filter($data['results'], fn($result) => str_starts_with($result['submittedAt'], $today)); $average = count($data['results']) ? round(array_sum(array_column($data['results'], 'score')) / count($data['results']), 1) : 0; $recent = array_slice(array_reverse($data['results']), 0, 8); foreach ($recent as &$result) { $student = findBy($data['students'], 'id', $result['studentId']); $exam = findBy($data['exams'], 'id', $result['examId']); $result['studentName'] = $student['fullName'] ?? 'Unknown student'; $result['examCode'] = $exam['code'] ?? 'Unknown exam'; } respond(['stats' => ['students' => count(array_filter($data['students'], fn($student) => $student['active'])), 'activeExams' => count(array_filter($data['exams'], fn($exam) => publicExam($exam)['active'])), 'completedToday' => count($completed), 'averageScore' => $average], 'activity' => array_slice($recent, 0, 5), 'popular' => $data['exams'], 'recent' => $recent]); }

respond(['error' => 'Unknown API action.'], 404);
