<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Token, X-Session-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($requestOrigin !== '') {
    $originHost = parse_url($requestOrigin, PHP_URL_HOST);
    $originPort = parse_url($requestOrigin, PHP_URL_PORT);
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $originAuthority = strtolower((string)$originHost . ($originPort ? ':' . $originPort : ''));
    if ($originHost === null || $originAuthority !== $requestHost) {
        http_response_code(403); echo json_encode(['error' => 'Cross-origin requests are not allowed.']); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const DEV_ADMIN_EMAIL = 'adepojutimothy001@gmail.com';
// Development bootstrap only. Set CBT_ADMIN_EMAIL and CBT_ADMIN_PASSWORD_HASH in production.
const DEV_ADMIN_PASSWORD_HASH = '$2y$10$2uTWijekM5e32FutE5qAievugm2JuFtVMTiXQGvu.1ijTiJc.MQD.';
const DATA_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'cbt-data.json';
const DATA_LOCK_FILE = __DIR__ . DIRECTORY_SEPARATOR . '.cbt-data.lock';
const ADMIN_TOKEN_SECONDS = 3600;
const LOGIN_TOKEN_SECONDS = 900;
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
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 5 * 1024 * 1024) respond(['error' => 'Request is too large.'], 413);
    $raw = file_get_contents('php://input', false, null, 0, 5 * 1024 * 1024 + 1);
    if (strlen((string)$raw) > 5 * 1024 * 1024) respond(['error' => 'Request is too large.'], 413);
    if ($raw === '' || $raw === false) return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) respond(['error' => 'Request body must be valid JSON.'], 400);
    return $decoded;
}

function loadData(): array {
    if (!file_exists(DATA_FILE)) {
        $data = [
            'students' => [],
            'exams' => [],
            'questions' => [],
            'passwords' => [],
            'sessions' => [],
            'loginTokens' => [],
            'adminSessions' => [],
            'rateLimits' => [],
            'results' => [],
            'settings' => ['gradingScale' => DEFAULT_GRADING_SCALE]
        ];
        saveData($data);
        return $data;
    }
    $data = json_decode(file_get_contents(DATA_FILE), true);
    if (!is_array($data)) respond(['error' => 'The data file could not be read. Restore a valid backup before continuing.'], 500);
    $migrated = false; $now = time();
    foreach (['students', 'exams', 'questions', 'passwords', 'sessions', 'results'] as $collection) {
        if (!isset($data[$collection]) || !is_array($data[$collection])) { $data[$collection] = []; $migrated = true; }
    }
    if (!isset($data['loginTokens']) || !is_array($data['loginTokens'])) { $data['loginTokens'] = []; $migrated = true; }
    if (!isset($data['adminSessions']) || !is_array($data['adminSessions'])) { $data['adminSessions'] = []; $migrated = true; }
    if (!isset($data['rateLimits']) || !is_array($data['rateLimits'])) { $data['rateLimits'] = []; $migrated = true; }
    if (!isset($data['settings']) || !is_array($data['settings'])) { $data['settings'] = ['gradingScale' => DEFAULT_GRADING_SCALE]; $migrated = true; }
    if (!isset($data['settings']['gradingScale']) || !is_array($data['settings']['gradingScale'])) { $data['settings']['gradingScale'] = DEFAULT_GRADING_SCALE; $migrated = true; }
    $liveLoginTokens = array_values(array_filter($data['loginTokens'], fn($item) => is_array($item) && !empty($item['tokenHash']) && empty($item['usedAt']) && strtotime((string)($item['expiresAt'] ?? '')) > $now));
    if (count($liveLoginTokens) !== count($data['loginTokens'])) { $data['loginTokens'] = $liveLoginTokens; $migrated = true; }
    $liveAdminSessions = array_values(array_filter($data['adminSessions'], fn($item) => is_array($item) && !empty($item['tokenHash']) && strtotime((string)($item['expiresAt'] ?? '')) > $now));
    if (count($liveAdminSessions) !== count($data['adminSessions'])) { $data['adminSessions'] = $liveAdminSessions; $migrated = true; }
    $liveRateLimits = array_filter($data['rateLimits'], fn($item) => is_array($item) && (int)($item['resetAt'] ?? 0) > $now);
    if (count($liveRateLimits) !== count($data['rateLimits'])) { $data['rateLimits'] = $liveRateLimits; $migrated = true; }
    foreach ($data['exams'] as &$exam) { if (!array_key_exists('courseUnit', $exam)) { $exam['courseUnit'] = 3; $migrated = true; } if (!array_key_exists('session', $exam)) { $exam['session'] = ''; $migrated = true; } }
    unset($exam);
    foreach ($data['questions'] as &$question) if (!array_key_exists('status', $question)) { $question['status'] = 'published'; $migrated = true; }
    unset($question);
    if ($migrated) saveData($data);
    return $data;
}

function saveData(array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents(DATA_FILE, $json, LOCK_EX) === false) respond(['error' => 'Unable to save data.'], 500);
}

function id(): string { return bin2hex(random_bytes(8)); }
function secretToken(): string { return bin2hex(random_bytes(32)); }
function tokenHash(string $token): string { return hash('sha256', $token); }
function clientFingerprint(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'); }
function enforceRateLimit(array &$data, string $scope, int $maximum, int $windowSeconds): string {
    $now = time();
    $data['rateLimits'] ??= [];
    $data['rateLimits'] = array_filter($data['rateLimits'], fn($item) => is_array($item) && (int)($item['resetAt'] ?? 0) > $now);
    $key = hash('sha256', $scope . '|' . clientFingerprint());
    $record = $data['rateLimits'][$key] ?? ['count' => 0, 'resetAt' => $now + $windowSeconds];
    if ((int)$record['count'] >= $maximum) {
        saveData($data);
        respond(['error' => 'Too many sign-in attempts. Please wait a few minutes and try again.'], 429);
    }
    $record['count'] = (int)$record['count'] + 1;
    $data['rateLimits'][$key] = $record;
    saveData($data);
    return $key;
}
function clearRateLimit(array &$data, string $key): void { unset($data['rateLimits'][$key]); }
function bearerToken(string $fallbackHeader = ''): string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!$header && $fallbackHeader !== '' && !empty($_SERVER[$fallbackHeader])) return (string)$_SERVER[$fallbackHeader];
    return preg_match('/^Bearer\s+([A-Za-z0-9]+)$/i', $header, $match) ? $match[1] : '';
}
function auth(bool $admin = false): void {
    global $data;
    if (!$admin) return;
    $token = bearerToken('HTTP_X_ADMIN_TOKEN');
    $record = $token ? findBy($data['adminSessions'], 'tokenHash', tokenHash($token)) : null;
    if (!$record || strtotime($record['expiresAt']) <= time()) respond(['error' => 'Admin session expired. Sign in again.'], 401);
}
function findBy(array $items, string $key, mixed $value): ?array {
    foreach ($items as $item) if (($item[$key] ?? null) === $value) return $item;
    return null;
}
function replaceBy(array &$items, string $key, mixed $value, array $replacement): bool {
    foreach ($items as $index => $item) if (($item[$key] ?? null) === $value) { $items[$index] = $replacement; return true; }
    return false;
}
function requireText(mixed $value, string $label, int $max = 255): string {
    $text = is_string($value) ? trim($value) : '';
    if ($text === '' || strlen($text) > $max * 4) respond(['error' => "$label is required and must be under $max characters."], 422);
    return $text;
}
function validateStudent(array $input, array $data, ?string $currentId = null): array {
    $name = requireText($input['fullName'] ?? null, 'Full name');
    $email = requireText($input['email'] ?? null, 'Email');
    $matric = requireText($input['matricNumber'] ?? null, 'Matric number', 80);
    $department = requireText($input['department'] ?? null, 'Department');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 422);
    foreach ($data['students'] as $student) {
        if ($student['id'] !== $currentId && strcasecmp($student['matricNumber'], $matric) === 0) respond(['error' => 'That matric number is already registered.'], 409);
    }
    return ['fullName' => $name, 'email' => $email, 'matricNumber' => $matric, 'department' => $department];
}
function validateExam(array $input, array $data, ?string $currentId = null): array {
    $code = requireText($input['code'] ?? null, 'Exam code', 40);
    $title = requireText($input['title'] ?? null, 'Exam title');
    foreach ($data['exams'] as $exam) if ($exam['id'] !== $currentId && strcasecmp($exam['code'], $code) === 0) respond(['error' => 'That exam code is already in use.'], 409);
    $duration = filter_var($input['duration'] ?? null, FILTER_VALIDATE_INT);
    $count = filter_var($input['questionCount'] ?? null, FILTER_VALIDATE_INT);
    $unit = filter_var($input['courseUnit'] ?? null, FILTER_VALIDATE_INT);
    if ($duration === false || $duration < 1 || $duration > 1440 || $count === false || $count < 1 || $count > 500 || $unit === false || $unit < 1 || $unit > 6) respond(['error' => 'Duration, question count, or course unit is outside the allowed range.'], 422);
    $start = (string)($input['startAt'] ?? ''); $end = (string)($input['endAt'] ?? '');
    if (strtotime($start) === false || strtotime($end) === false || strtotime($end) <= strtotime($start)) respond(['error' => 'Provide a valid assessment window with an end after the start.'], 422);
    $status = (string)($input['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'published', 'active'], true)) respond(['error' => 'Status must be draft, published, or active.'], 422);
    return ['code' => $code, 'title' => $title, 'description' => trim((string)($input['description'] ?? '')), 'duration' => $duration, 'questionCount' => $count, 'courseUnit' => $unit, 'session' => trim((string)($input['session'] ?? '')), 'startAt' => date('c', strtotime($start)), 'endAt' => date('c', strtotime($end)), 'status' => $status];
}
function validateQuestion(array $input, array $data): array {
    $examId = (string)($input['examId'] ?? '');
    if (!findBy($data['exams'], 'id', $examId)) respond(['error' => 'Choose an existing assessment.'], 422);
    $question = requireText($input['text'] ?? null, 'Question text', 5000);
    $options = $input['options'] ?? null;
    if (!is_array($options) || count($options) < 2 || count($options) > 10 || !array_is_list($options)) respond(['error' => 'Provide between 2 and 10 options.'], 422);
    $options = array_map(fn($option) => requireText($option, 'Option', 1000), $options);
    $type = (string)($input['type'] ?? 'single');
    if (!in_array($type, ['single', 'multiple'], true)) respond(['error' => 'Question type must be single or multiple.'], 422);
    $correct = $input['correctOptions'] ?? null;
    if (!is_array($correct) || !$correct || !array_is_list($correct)) respond(['error' => 'Select the correct option or options.'], 422);
    foreach ($correct as $index) if (filter_var($index, FILTER_VALIDATE_INT) === false || (int)$index < 0 || (int)$index >= count($options)) respond(['error' => 'A correct option index is outside the options provided.'], 422);
    $correct = array_values(array_unique(array_map('intval', $correct)));
    if ($type === 'single' && count($correct) !== 1) respond(['error' => 'A single-answer question needs exactly one correct option.'], 422);
    $status = (string)($input['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'published'], true)) respond(['error' => 'Question status must be draft or published.'], 422);
    return ['examId' => $examId, 'text' => $question, 'options' => $options, 'correctOptions' => $correct, 'type' => $type, 'topic' => trim((string)($input['topic'] ?? '')), 'difficulty' => trim((string)($input['difficulty'] ?? 'medium')), 'status' => $status];
}
function listItems(array $items, array $searchFields, array $sortFields): array {
    $search = trim((string)($_GET['search'] ?? $_GET['q'] ?? ''));
    if ($search !== '') $items = array_values(array_filter($items, function ($item) use ($search, $searchFields) {
        foreach ($searchFields as $field) if (stripos((string)($item[$field] ?? ''), $search) !== false) return true;
        return false;
    }));
    $sort = (string)($_GET['sort'] ?? '');
    if (in_array($sort, $sortFields, true)) {
        $direction = ($_GET['order'] ?? $_GET['dir'] ?? 'asc') === 'desc' ? -1 : 1;
        usort($items, fn($a, $b) => $direction * strnatcasecmp((string)($a[$sort] ?? ''), (string)($b[$sort] ?? '')));
    }
    $total = count($items); $page = max(1, (int)($_GET['page'] ?? 1)); $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? $_GET['limit'] ?? 25))); $pages = (int)ceil($total / $pageSize);
    return ['items' => array_slice(array_values($items), ($page - 1) * $pageSize, $pageSize), 'meta' => ['total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'limit' => $pageSize, 'pages' => $pages, 'totalPages' => $pages]];
}
function randomPassword(): string {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 8; $i++) $password .= $characters[random_int(0, strlen($characters) - 1)];
    return $password;
}
function examWindowState(array $exam, ?int $now = null): string {
    $now ??= time();
    $startAt = strtotime((string)($exam['startAt'] ?? ''));
    $endAt = strtotime((string)($exam['endAt'] ?? ''));
    if ($startAt === false || $endAt === false || $endAt < $startAt || $now > $endAt) return 'closed';
    if ($now < $startAt) return 'scheduled';
    return 'open';
}
function publicExam(array $exam): array {
    $windowState = examWindowState($exam);
    $exam['windowState'] = $windowState;
    $exam['active'] = $exam['status'] === 'active' && $windowState === 'open';
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
    foreach ($data['results'] as &$result) { $exam = findBy($data['exams'], 'id', $result['examId']); $unit = max(1, (int)($exam['courseUnit'] ?? $result['courseUnit'] ?? 3)); $grade = gradeForScore((float)$result['score'], $data['settings']['gradingScale']); $result['courseUnit'] = $unit; $result['grade'] = $grade['grade']; $result['gradePoint'] = $grade['gradePoint']; $result['qualityPoints'] = round($unit * $grade['gradePoint'], 2); $result['session'] = $exam['session'] ?? ($result['session'] ?? ''); }
    unset($result);
}
function completeSession(array &$data, array $session, bool $auto): array {
    $questions = $session['questions'] ?? array_values(array_filter($data['questions'], fn($question) => $question['examId'] === $session['examId'] && ($question['status'] ?? 'published') === 'published'));
    $correct = 0; $review = [];
    foreach ($questions as $question) {
        $answer = $session['answers'][$question['id']] ?? []; $expected = $question['correctOptions']; sort($answer); sort($expected);
        $isCorrect = $answer === $expected; if ($isCorrect) $correct++;
        $review[] = ['id' => $question['id'], 'text' => $question['text'], 'options' => $question['options'], 'type' => $question['type'] ?? 'single', 'correctOptions' => $expected, 'answers' => $answer, 'isCorrect' => $isCorrect, 'flagged' => in_array($question['id'], $session['flagged'] ?? [], true)];
    }
    $session['status'] = $auto ? 'auto_submitted' : 'submitted'; $session['submittedAt'] = date('c'); $session['score'] = count($questions) ? round(($correct / count($questions)) * 100, 1) : 0;
    replaceBy($data['sessions'], 'id', $session['id'], $session);
    if (!findBy($data['results'], 'sessionId', $session['id'])) $data['results'][] = ['id' => id(), 'sessionId' => $session['id'], 'studentId' => $session['studentId'], 'examId' => $session['examId'], 'score' => $session['score'], 'submittedAt' => $session['submittedAt'], 'status' => $session['status'], 'questions' => $review, 'integrityEvents' => $session['integrityEvents'] ?? []];
    foreach ($data['passwords'] as &$record) if (($record['id'] ?? '') === ($session['passwordId'] ?? '')) $record['usedAt'] = $session['submittedAt'];
    unset($record);
    recalculateResults($data);
    return $session;
}
function expireSessions(array &$data): void { foreach ($data['sessions'] as $session) if ($session['status'] === 'in_progress' && strtotime($session['endsAt']) <= time()) completeSession($data, $session, true); }
function requireSession(array $data, array $input): array {
    $session = findBy($data['sessions'], 'id', (string)($input['sessionId'] ?? ''));
    $token = (string)($input['accessToken'] ?? bearerToken('HTTP_X_SESSION_TOKEN'));
    if (!$session || !$token || !hash_equals((string)($session['accessTokenHash'] ?? ''), tokenHash($token))) respond(['error' => 'Exam session authentication required.'], 401);
    return $session;
}
function sessionPayload(array $session, string $accessToken): array {
    return ['id' => $session['id'], 'startedAt' => $session['startedAt'], 'endsAt' => $session['endsAt'], 'accessToken' => $accessToken, 'answers' => $session['answers'], 'flagged' => $session['flagged'], 'status' => $session['status']];
}
function publicSessionQuestions(array $session): array { return array_map('publicQuestion', $session['questions'] ?? []); }
function studentReport(array $data, string $studentId): array {
    $student = findBy($data['students'], 'id', $studentId);
    if (!$student) respond(['error' => 'Student not found.'], 404);
    $items = array_values(array_filter($data['results'], fn($result) => $result['studentId'] === $studentId));
    usort($items, fn($a, $b) => strcmp($a['submittedAt'], $b['submittedAt']));
    foreach ($items as &$item) { $exam = findBy($data['exams'], 'id', $item['examId']); $item['courseCode'] = $exam['code'] ?? 'Unknown course'; $item['courseTitle'] = $exam['title'] ?? ''; $item['session'] = $exam['session'] ?? ($item['session'] ?? ''); }
    unset($item);
    $groups = []; $totalUnits = 0; $totalQuality = 0;
    foreach ($items as $item) { $key = $item['session'] ?: 'Unassigned session'; $groups[$key] ??= ['courseUnit' => 0, 'qualityPoints' => 0]; $groups[$key]['courseUnit'] += $item['courseUnit']; $groups[$key]['qualityPoints'] += $item['qualityPoints']; $totalUnits += $item['courseUnit']; $totalQuality += $item['qualityPoints']; }
    foreach ($groups as &$group) $group['gpa'] = $group['courseUnit'] ? round($group['qualityPoints'] / $group['courseUnit'], 2) : 0;
    unset($group);
    return ['student' => $student, 'items' => $items, 'sessions' => $groups, 'cgpa' => $totalUnits ? round($totalQuality / $totalUnits, 2) : 0];
}
function safeCsvCell(mixed $value): string {
    $value = (string)$value;
    if (preg_match('/^[=+\-@]/', $value)) $value = "'" . $value;
    return $value;
}

$dataLock = fopen(DATA_LOCK_FILE, 'c');
if ($dataLock === false || !flock($dataLock, LOCK_EX)) respond(['error' => 'Unable to lock data storage.'], 500);
$data = loadData();
$beforeExpiry = json_encode($data); expireSessions($data); recalculateResults($data); if ($beforeExpiry !== json_encode($data)) saveData($data);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($action === 'health') respond(['ok' => true]);
if ($action === 'admin-login' && $method === 'POST') {
    $input = body();
    $rateLimitKey = enforceRateLimit($data, 'admin-login', 10, 900);
    $adminEmail = getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL;
    $adminHash = getenv('CBT_ADMIN_PASSWORD_HASH') ?: DEV_ADMIN_PASSWORD_HASH;
    if (!hash_equals(strtolower($adminEmail), strtolower(trim((string)($input['email'] ?? '')))) || !password_verify((string)($input['password'] ?? ''), $adminHash)) respond(['error' => 'Invalid admin credentials.'], 401);
    $token = secretToken(); $expiresAt = date('c', time() + ADMIN_TOKEN_SECONDS);
    clearRateLimit($data, $rateLimitKey);
    $data['adminSessions'][] = ['tokenHash' => tokenHash($token), 'expiresAt' => $expiresAt, 'createdAt' => date('c')];
    saveData($data);
    respond(['token' => $token, 'expiresAt' => $expiresAt, 'user' => ['email' => $adminEmail, 'name' => 'Administrator', 'role' => 'super_admin']]);
}
if ($action === 'admin-logout' && $method === 'POST') {
    auth(true); $token = bearerToken('HTTP_X_ADMIN_TOKEN');
    $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($item) => $item['tokenHash'] !== tokenHash($token)));
    saveData($data); respond(['ok' => true]);
}

if ($action === 'exams' && $method === 'GET') {
    $items = array_map('publicExam', $data['exams']);
    if (($_GET['active'] ?? '') === 'true') { $items = array_values(array_filter($items, fn($exam) => $exam['active'])); respond(['items' => $items]); }
    auth(true);
    if (!empty($_GET['status'])) $items = array_values(array_filter($items, fn($exam) => $exam['status'] === $_GET['status']));
    respond(listItems($items, ['code','title','status','session'], ['code','title','duration','questionCount','courseUnit','status','startAt','endAt']));
}
if ($action === 'exams' && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    auth(true);
    if ($method === 'POST') {
        $input = body();
        $exam = ['id' => id()] + validateExam($input, $data);
        $data['exams'][] = $exam; saveData($data); respond(['item' => publicExam($exam)], 201);
    }
    $examId = $_GET['id'] ?? '';
    $input = body(); $exam = findBy($data['exams'], 'id', $examId); if (!$exam) respond(['error' => 'Exam not found.'], 404);
    if ($method === 'PUT' && ($input['status'] ?? null) === 'active' && !array_key_exists('endAt', $input) && examWindowState($exam) === 'closed') {
        respond(['error' => 'This assessment window has ended. Use Edit to set a new end time before activating it.'], 422);
    }
    if ($method === 'DELETE') {
        foreach (['questions','passwords','sessions','results'] as $collection) foreach ($data[$collection] as $item) if (($item['examId'] ?? '') === $examId) respond(['error' => 'This assessment has questions or history. Remove its questions first, or keep it as draft to preserve records.'], 409);
        $data['exams'] = array_values(array_filter($data['exams'], fn($item) => $item['id'] !== $examId)); saveData($data); respond(['ok' => true]);
    }
    $exam = array_merge($exam, validateExam(array_merge($exam, $input), $data, $examId));
    replaceBy($data['exams'], 'id', $examId, $exam); recalculateResults($data); saveData($data); respond(['item' => publicExam($exam)]);
}

if ($action === 'students' && $method === 'GET') { auth(true); $items = $data['students']; if (($_GET['status'] ?? '') === 'active') $items = array_values(array_filter($items, fn($item) => !empty($item['active']))); if (in_array(($_GET['status'] ?? ''), ['disabled','inactive'], true)) $items = array_values(array_filter($items, fn($item) => empty($item['active']))); respond(listItems($items, ['fullName','email','matricNumber','department'], ['fullName','email','matricNumber','department','createdAt'])); }
if ($action === 'students' && $method === 'POST') {
    auth(true); $input = body();
    $student = ['id' => id()] + validateStudent($input, $data) + ['active' => true, 'createdAt' => date('c')];
    $data['students'][] = $student; saveData($data); respond(['item' => $student], 201);
}
if ($action === 'students-bulk' && $method === 'POST') {
    auth(true); $input = body(); $rows = $input['items'] ?? null;
    if (!is_array($rows) || !$rows || count($rows) > 500 || !array_is_list($rows)) respond(['error' => 'Provide 1 to 500 student rows.'], 422);
    $new = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) respond(['error' => 'Student row ' . ($index + 1) . ' is invalid.'], 422);
        $student = ['id' => id()] + validateStudent($row, ['students' => array_merge($data['students'], $new)]) + ['active' => true, 'createdAt' => date('c')];
        $new[] = $student;
    }
    array_push($data['students'], ...$new); saveData($data); respond(['added' => count($new)], 201);
}
if ($action === 'students' && in_array($method, ['PUT','DELETE'], true)) {
    auth(true); $studentId = $_GET['id'] ?? ''; $student = findBy($data['students'], 'id', $studentId); if (!$student) respond(['error' => 'Student not found.'], 404);
    if ($method === 'DELETE') { $student['active'] = false; replaceBy($data['students'], 'id', $studentId, $student); saveData($data); respond(['item' => $student]); }
    $input = body(); $student = array_merge($student, validateStudent(array_merge($student, $input), $data, $studentId)); if (array_key_exists('active', $input)) $student['active'] = (bool)$input['active'];
    replaceBy($data['students'], 'id', $studentId, $student); saveData($data); respond(['item' => $student]);
}
if ($action === 'exam-password' && $method === 'POST') {
    auth(true); $input = body(); $student = findBy($data['students'], 'matricNumber', trim($input['matricNumber'] ?? '')); $exam = findBy($data['exams'], 'id', $input['examId'] ?? '');
    if (!$student || !$exam) respond(['error' => 'Student or exam could not be found.'], 404);
    if (!$student['active']) respond(['error' => 'This student is disabled.'], 409);
    $password = randomPassword(); $record = ['id' => id(), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordHash' => password_hash($password, PASSWORD_DEFAULT), 'createdAt' => date('c'), 'expiresAt' => $exam['endAt'], 'usedAt' => null];
    $data['passwords'] = array_values(array_filter($data['passwords'], fn($item) => !($item['studentId'] === $student['id'] && $item['examId'] === $exam['id']))); $data['passwords'][] = $record; saveData($data); respond(['student' => $student, 'exam' => publicExam($exam), 'password' => $password]);
}

if ($action === 'questions' && $method === 'GET') { auth(true); $items = $data['questions']; if (!empty($_GET['examId'])) $items = array_values(array_filter($items, fn($item) => $item['examId'] === $_GET['examId'])); if (!empty($_GET['status'])) $items = array_values(array_filter($items, fn($item) => ($item['status'] ?? 'published') === $_GET['status'])); respond(listItems($items, ['text','topic','difficulty','status'], ['text','topic','difficulty','status'])); }
if ($action === 'questions' && in_array($method, ['POST','PUT','DELETE'], true)) {
    auth(true); $questionId = $_GET['id'] ?? '';
    if ($method === 'DELETE') { if (!findBy($data['questions'], 'id', $questionId)) respond(['error' => 'Question not found.'], 404); $data['questions'] = array_values(array_filter($data['questions'], fn($item) => $item['id'] !== $questionId)); saveData($data); respond(['ok' => true]); }
    $input = body();
    if ($method === 'POST') { $question = ['id' => id()] + validateQuestion($input, $data); $data['questions'][] = $question; saveData($data); respond(['item' => $question], 201); }
    $question = findBy($data['questions'], 'id', $questionId); if (!$question) respond(['error' => 'Question not found.'], 404);
    $question = array_merge($question, validateQuestion(array_merge($question, $input), $data));
    replaceBy($data['questions'], 'id', $questionId, $question); saveData($data); respond(['item' => $question]);
}
if ($action === 'questions-bulk' && $method === 'POST') {
    auth(true); $input = body(); $rows = $input['items'] ?? null;
    if (!is_array($rows) || !$rows || count($rows) > 500 || !array_is_list($rows)) respond(['error' => 'Provide 1 to 500 question rows.'], 422);
    $new = []; foreach ($rows as $index => $row) { if (!is_array($row)) respond(['error' => 'Question row ' . ($index + 1) . ' is invalid.'], 422); $new[] = ['id' => id()] + validateQuestion($row, $data); }
    array_push($data['questions'], ...$new); saveData($data); respond(['added' => count($new)], 201);
}

if ($action === 'student-login' && $method === 'POST') {
    $input = body(); $matricNumber = trim((string)($input['matricNumber'] ?? '')); $rateLimitKey = enforceRateLimit($data, 'student-login', 30, 900); $student = findBy($data['students'], 'matricNumber', $matricNumber); $exam = findBy($data['exams'], 'id', $input['examId'] ?? '');
    $passwordRecord = $student && $exam ? array_values(array_filter($data['passwords'], fn($item) => $item['studentId'] === $student['id'] && $item['examId'] === $exam['id'])) : [];
    $passwordRecord = $passwordRecord ? end($passwordRecord) : null;
    if (!$student || !$student['active'] || !$exam || !$passwordRecord || !password_verify((string)($input['password'] ?? ''), $passwordRecord['passwordHash']) || strtotime($passwordRecord['expiresAt']) < time()) respond(['error' => 'We could not verify those details.'], 401);
    if (!empty($passwordRecord['usedAt'])) respond(['error' => 'This exam password has already been used. Ask an administrator for a new one.'], 409);
    foreach ($data['results'] as $result) if ($result['studentId'] === $student['id'] && $result['examId'] === $exam['id'] && strtotime($result['submittedAt']) >= strtotime($passwordRecord['createdAt'])) respond(['error' => 'This exam password has already been used. Ask an administrator for a new one.'], 409);
    if (!publicExam($exam)['active']) respond(['error' => 'This assessment is not currently available.'], 403);
    $token = secretToken(); clearRateLimit($data, $rateLimitKey); $data['loginTokens'][] = ['tokenHash' => tokenHash($token), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordId' => $passwordRecord['id'], 'expiresAt' => date('c', min(strtotime($exam['endAt']), time() + LOGIN_TOKEN_SECONDS)), 'usedAt' => null]; saveData($data);
    respond(['student' => $student, 'exam' => publicExam($exam), 'loginToken' => $token]);
}
if ($action === 'session-start' && $method === 'POST') {
    $input = body(); $student = findBy($data['students'], 'id', $input['studentId'] ?? ''); $exam = findBy($data['exams'], 'id', $input['examId'] ?? ''); $login = (string)($input['loginToken'] ?? ''); $token = $login ? findBy($data['loginTokens'], 'tokenHash', tokenHash($login)) : null;
    if (!$student || !$student['active'] || !$exam || !$token || $token['studentId'] !== $student['id'] || $token['examId'] !== $exam['id'] || !empty($token['usedAt']) || strtotime($token['expiresAt']) <= time() || !publicExam($exam)['active']) respond(['error' => 'Login expired. Verify your exam password again.'], 401);
    $passwordRecord = findBy($data['passwords'], 'id', $token['passwordId']);
    if (!$passwordRecord || !empty($passwordRecord['usedAt'])) respond(['error' => 'This exam password is no longer valid.'], 409);
    foreach ($data['loginTokens'] as &$item) if (($item['tokenHash'] ?? '') === $token['tokenHash']) $item['usedAt'] = date('c');
    unset($item);
    foreach ($data['sessions'] as $existing) if ($existing['studentId'] === $student['id'] && $existing['examId'] === $exam['id'] && $existing['status'] === 'in_progress') {
        $accessToken = secretToken(); $existing['accessTokenHash'] = tokenHash($accessToken); $existing['passwordId'] = $passwordRecord['id'];
        $existing['questions'] ??= array_values(array_filter($data['questions'], fn($question) => $question['examId'] === $exam['id'] && ($question['status'] ?? 'published') === 'published'));
        $existing['integrityEvents'] ??= [];
        replaceBy($data['sessions'], 'id', $existing['id'], $existing); saveData($data);
        respond(['session' => sessionPayload($existing, $accessToken), 'questions' => publicSessionQuestions($existing)]);
    }
    $questionsForExam = array_values(array_filter($data['questions'], fn($question) => $question['examId'] === $exam['id'] && ($question['status'] ?? 'published') === 'published'));
    $count = (int)$exam['questionCount'];
    if (count($questionsForExam) < $count) respond(['error' => "This assessment needs $count published questions before it can start; " . count($questionsForExam) . ' are available.'], 422);
    $questionsForExam = array_slice($questionsForExam, 0, $count);
    $now = time(); $accessToken = secretToken();
    $session = ['id' => id(), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordId' => $passwordRecord['id'], 'accessTokenHash' => tokenHash($accessToken), 'startedAt' => date('c', $now), 'endsAt' => date('c', min($now + ((int)$exam['duration'] * 60), strtotime($exam['endAt']))), 'questions' => $questionsForExam, 'answers' => [], 'flagged' => [], 'integrityEvents' => [], 'status' => 'in_progress'];
    $data['sessions'][] = $session; saveData($data); respond(['session' => sessionPayload($session, $accessToken), 'questions' => publicSessionQuestions($session)]);
}
if ($action === 'session-resume' && $method === 'POST') {
    $input = body(); $session = requireSession($data, $input);
    if ($session['status'] !== 'in_progress') respond(['error' => 'This attempt has already been submitted.'], 409);
    respond(['session' => sessionPayload($session, (string)($input['accessToken'] ?? bearerToken('HTTP_X_SESSION_TOKEN'))), 'questions' => publicSessionQuestions($session)]);
}
if ($action === 'session-answer' && $method === 'POST') {
    $input = body(); $session = requireSession($data, $input);
    if ($session['status'] !== 'in_progress') respond(['error' => 'Exam session is no longer active.'], 409);
    $question = findBy($session['questions'] ?? [], 'id', (string)($input['questionId'] ?? ''));
    if (!$question) respond(['error' => 'Question is not part of this attempt.'], 422);
    $answers = $input['answers'] ?? null;
    if (!is_array($answers) || !array_is_list($answers)) respond(['error' => 'Answers must be a list.'], 422);
    foreach ($answers as $answer) if (filter_var($answer, FILTER_VALIDATE_INT) === false || (int)$answer < 0 || (int)$answer >= count($question['options'])) respond(['error' => 'An answer is outside the available options.'], 422);
    $answers = array_values(array_unique(array_map('intval', $answers)));
    if (($question['type'] ?? 'single') === 'single' && count($answers) > 1) respond(['error' => 'Only one answer is allowed for this question.'], 422);
    $session['answers'][$question['id']] = $answers; replaceBy($data['sessions'], 'id', $session['id'], $session); saveData($data); respond(['ok' => true]);
}
if ($action === 'session-flag' && $method === 'POST') { $input = body(); $session = requireSession($data, $input); if ($session['status'] !== 'in_progress') respond(['error' => 'Exam session is no longer active.'], 409); $questionId = (string)($input['questionId'] ?? ''); if (!findBy($session['questions'] ?? [], 'id', $questionId)) respond(['error' => 'Question is not part of this attempt.'], 422); if (!empty($input['flagged']) && !in_array($questionId, $session['flagged'], true)) $session['flagged'][] = $questionId; if (empty($input['flagged'])) $session['flagged'] = array_values(array_filter($session['flagged'], fn($item) => $item !== $questionId)); replaceBy($data['sessions'], 'id', $session['id'], $session); saveData($data); respond(['ok' => true]); }
if ($action === 'session-integrity' && $method === 'POST') { $input = body(); $session = requireSession($data, $input); if ($session['status'] !== 'in_progress') respond(['error' => 'Exam session is no longer active.'], 409); $event = (string)($input['event'] ?? ''); if (!in_array($event, ['hidden','blur'], true)) respond(['error' => 'Invalid integrity event.'], 422); $session['integrityEvents'] ??= []; if (count($session['integrityEvents']) < 1000) $session['integrityEvents'][] = ['event' => $event, 'at' => date('c')]; replaceBy($data['sessions'], 'id', $session['id'], $session); saveData($data); respond(['ok' => true]); }
if ($action === 'session-submit' && $method === 'POST') {
    $input = body(); $session = requireSession($data, $input); if ($session['status'] !== 'in_progress') { $existing = findBy($data['results'], 'sessionId', $session['id']); respond(['result' => $existing]); } $session = completeSession($data, $session, strtotime($session['endsAt']) <= time()); saveData($data); respond(['result' => findBy($data['results'], 'sessionId', $session['id'])]);
}
if ($action === 'results' && $method === 'GET') {
    auth(true); $items = $data['results'];
    if (!empty($_GET['status'])) $items = array_values(array_filter($items, fn($item) => $item['status'] === $_GET['status']));
    foreach ($items as &$result) { $student = findBy($data['students'], 'id', $result['studentId']); $exam = findBy($data['exams'], 'id', $result['examId']); $result['studentName'] = $student['fullName'] ?? 'Unknown student'; $result['examCode'] = $exam['code'] ?? 'Unknown exam'; unset($result['questions'], $result['integrityEvents']); }
    unset($result);
    respond(listItems($items, ['studentName','examCode','status','grade'], ['studentName','examCode','score','grade','submittedAt','status']));
}
if ($action === 'result-review' && $method === 'GET') {
    auth(true); $result = findBy($data['results'], 'id', (string)($_GET['id'] ?? ''));
    if (!$result) respond(['error' => 'Result not found.'], 404);
    $session = findBy($data['sessions'], 'id', $result['sessionId']);
    $questions = $result['questions'] ?? [];
    if (!$questions && $session) {
        foreach (($session['questions'] ?? []) as $question) {
            $answers = $session['answers'][$question['id']] ?? []; $correct = $question['correctOptions']; sort($answers); sort($correct);
            $questions[] = ['id' => $question['id'], 'text' => $question['text'], 'options' => $question['options'], 'type' => $question['type'] ?? 'single', 'correctOptions' => $correct, 'answers' => $answers, 'isCorrect' => $answers === $correct, 'flagged' => in_array($question['id'], $session['flagged'] ?? [], true)];
        }
    }
    respond(['result' => $result, 'student' => findBy($data['students'], 'id', $result['studentId']), 'exam' => findBy($data['exams'], 'id', $result['examId']), 'questions' => $questions, 'integrityEvents' => $result['integrityEvents'] ?? ($session['integrityEvents'] ?? [])]);
}
if ($action === 'settings' && $method === 'GET') { auth(true); respond(['settings' => $data['settings']]); }
if ($action === 'settings' && $method === 'PUT') {
    auth(true); $input = body(); $scale = $input['gradingScale'] ?? null;
    if (!is_array($scale) || !$scale || count($scale) > 20 || !array_is_list($scale)) respond(['error' => 'Provide 1 to 20 grading-scale rows.'], 422);
    $clean = [];
    foreach ($scale as $band) {
        if (!is_array($band) || !isset($band['minScore'], $band['maxScore'], $band['grade'], $band['gradePoint']) || !is_numeric($band['minScore']) || !is_numeric($band['maxScore']) || !is_numeric($band['gradePoint'])) respond(['error' => 'Each grading-scale row needs numeric score limits and grade point.'], 422);
        $min = (float)$band['minScore']; $max = (float)$band['maxScore']; $point = (float)$band['gradePoint'];
        $grade = requireText($band['grade'], 'Grade', 10);
        if ($min < 0 || $max > 100 || $min > $max || $point < 0 || $point > 5) respond(['error' => 'Score ranges must stay within 0–100, and grade points within 0–5.'], 422);
        $clean[] = ['minScore' => $min, 'maxScore' => $max, 'grade' => strtoupper($grade), 'gradePoint' => $point];
    }
    for ($score = 0; $score <= 1000; $score++) { $value = $score / 10; $matches = 0; foreach ($clean as $band) if ($value >= $band['minScore'] && $value <= $band['maxScore']) $matches++; if ($matches !== 1) respond(['error' => 'Grading ranges must cover every score from 0 to 100 exactly once.'], 422); }
    usort($clean, fn($a, $b) => $b['minScore'] <=> $a['minScore']);
    $data['settings']['gradingScale'] = $clean; recalculateResults($data); saveData($data); respond(['settings' => $data['settings']]);
}
if ($action === 'student-results' && $method === 'GET') { auth(true); respond(studentReport($data, (string)($_GET['id'] ?? ''))); }
if ($action === 'student-results-csv' && $method === 'GET') {
    auth(true); $report = studentReport($data, (string)($_GET['id'] ?? ''));
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="student-results.csv"');
    $stream = fopen('php://output', 'w');
    fputcsv($stream, ['Student', safeCsvCell($report['student']['fullName']), 'Matric', safeCsvCell($report['student']['matricNumber'])]);
    fputcsv($stream, ['Session', 'Course', 'Unit', 'Score', 'Grade', 'Grade Point', 'Quality Points', 'Submitted At', 'Status']);
    foreach ($report['items'] as $item) fputcsv($stream, [safeCsvCell($item['session']), safeCsvCell($item['courseCode'] . ' - ' . $item['courseTitle']), $item['courseUnit'], $item['score'], safeCsvCell($item['grade']), $item['gradePoint'], $item['qualityPoints'], $item['submittedAt'], $item['status']]);
    foreach ($report['sessions'] as $name => $group) fputcsv($stream, ['GPA - ' . safeCsvCell($name), '', $group['courseUnit'], '', '', '', $group['qualityPoints'], '', $group['gpa']]);
    fputcsv($stream, ['CGPA', $report['cgpa']]); fclose($stream); exit;
}
if ($action === 'dashboard' && $method === 'GET') {
    auth(true); $today = date('Y-m-d'); $completed = array_filter($data['results'], fn($result) => str_starts_with($result['submittedAt'], $today));
    $average = count($data['results']) ? round(array_sum(array_column($data['results'], 'score')) / count($data['results']), 1) : 0;
    $recent = array_slice(array_reverse($data['results']), 0, 8);
    foreach ($recent as &$result) { $student = findBy($data['students'], 'id', $result['studentId']); $exam = findBy($data['exams'], 'id', $result['examId']); $result['studentName'] = $student['fullName'] ?? 'Unknown student'; $result['examCode'] = $exam['code'] ?? 'Unknown exam'; unset($result['questions'], $result['integrityEvents']); } unset($result);
    $labels = ['0-39','40-49','50-59','60-69','70-100']; $counts = array_fill_keys($labels, 0);
    foreach ($data['results'] as $result) { $score = (float)$result['score']; $label = $score < 40 ? '0-39' : ($score < 50 ? '40-49' : ($score < 60 ? '50-59' : ($score < 70 ? '60-69' : '70-100'))); $counts[$label]++; }
    $distribution = []; foreach ($counts as $label => $count) $distribution[] = ['label' => $label, 'count' => $count];
    $passScores = array_values(array_map(fn($band) => (float)$band['minScore'], array_filter($data['settings']['gradingScale'], fn($band) => (float)$band['gradePoint'] > 0)));
    $passScore = $passScores ? min($passScores) : 100;
    $outcomes = []; $popular = [];
    foreach ($data['exams'] as $exam) { $examResults = array_values(array_filter($data['results'], fn($result) => $result['examId'] === $exam['id'])); $attempts = count($examResults); $passed = count(array_filter($examResults, fn($result) => $result['score'] >= $passScore)); $popular[] = publicExam($exam) + ['attempts' => $attempts]; $outcomes[] = ['examId' => $exam['id'], 'code' => $exam['code'], 'title' => $exam['title'], 'attempts' => $attempts, 'passed' => $passed, 'failed' => $attempts - $passed, 'passRate' => $attempts ? round(100 * $passed / $attempts, 1) : 0, 'averageScore' => $attempts ? round(array_sum(array_column($examResults, 'score')) / $attempts, 1) : 0]; }
    usort($popular, fn($a, $b) => $b['attempts'] <=> $a['attempts']);
    respond(['stats' => ['students' => count(array_filter($data['students'], fn($student) => $student['active'])), 'activeExams' => count(array_filter($data['exams'], fn($exam) => publicExam($exam)['active'])), 'completedToday' => count($completed), 'averageScore' => $average], 'activity' => array_slice($recent, 0, 5), 'popular' => array_slice($popular, 0, 8), 'recent' => $recent, 'scoreDistribution' => $distribution, 'courseOutcomes' => $outcomes, 'passScore' => $passScore]);
}

respond(['error' => 'Unknown API action.'], 404);
