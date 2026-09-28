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

// Local SMTP credentials belong in .env (which Apache is blocked from serving), never in the JSON data store.
function loadLocalEnvironment(): void {
    $path = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2); $key = trim($key); $value = trim($value);
        if (!preg_match('/^CBT_[A-Z0-9_]+$/', $key) || getenv($key) !== false) continue;
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) $value = substr($value, 1, -1);
        putenv($key . '=' . $value); $_ENV[$key] = $value;
    }
}
loadLocalEnvironment();

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
const INTEGRITY_EVENT_TYPES = ['tab_switch', 'blur', 'context_menu', 'copy', 'paste', 'devtools'];
const DEFAULT_INTEGRITY_POLICY = [
    'tab_switch' => ['mode' => 'warn', 'lockAfter' => 3],
    'blur' => ['mode' => 'warn', 'lockAfter' => 3],
    'context_menu' => ['mode' => 'warn', 'lockAfter' => 3],
    'copy' => ['mode' => 'warn', 'lockAfter' => 3],
    'paste' => ['mode' => 'warn', 'lockAfter' => 3],
    'devtools' => ['mode' => 'warn', 'lockAfter' => 3]
];
const NEWSLETTER_SENDER = 'cacsalautech001@gmail.com';
const NEWSLETTER_SENDER_NAME = 'CACSA LAUTECH';
const ADMIN_PERMISSIONS = ['overview', 'students', 'exams', 'questions', 'results', 'audit', 'newsletter', 'settings', 'roles'];
const DEFAULT_ROLES = [
    ['id' => 'superadmin', 'name' => 'Superadmin', 'description' => 'Full system access and administrator management.', 'maxUsers' => 1, 'systemLocked' => true, 'permissions' => ADMIN_PERMISSIONS],
    ['id' => 'academic_coordinator', 'name' => 'Academic Coordinator', 'description' => 'Manages assessments, questions, students, results, audit monitoring, and newsletters.', 'maxUsers' => 5, 'systemLocked' => false, 'permissions' => ['overview', 'students', 'exams', 'questions', 'results', 'audit', 'newsletter']],
    ['id' => 'assistant_academic_coordinator', 'name' => 'Assistant Academic Coordinator', 'description' => 'Supports student, assessment, question, results, and newsletter administration.', 'maxUsers' => 10, 'systemLocked' => false, 'permissions' => ['overview', 'students', 'exams', 'questions', 'results', 'newsletter']]
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
            'auditEvents' => [],
            'examFlags' => [],
            'roles' => DEFAULT_ROLES,
            'adminUsers' => [],
            'pendingAdminRequests' => [],
            'adminProfileOverrides' => [],
            'adminEmailVerifications' => [],
            'adminUserActivity' => [],
            'newsletterSubscribers' => [],
            'newsletters' => [],
            'settings' => ['gradingScale' => DEFAULT_GRADING_SCALE, 'integrityPolicy' => DEFAULT_INTEGRITY_POLICY]
        ];
        saveData($data);
        return $data;
    }
    $data = json_decode(file_get_contents(DATA_FILE), true);
    if (!is_array($data)) respond(['error' => 'The data file could not be read. Restore a valid backup before continuing.'], 500);
    $migrated = false; $now = time();
    foreach (['students', 'exams', 'questions', 'passwords', 'sessions', 'results', 'auditEvents', 'examFlags', 'roles', 'adminUsers', 'pendingAdminRequests', 'newsletterSubscribers', 'newsletters'] as $collection) {
        if (!isset($data[$collection]) || !is_array($data[$collection])) { $data[$collection] = []; $migrated = true; }
    }
    if (!isset($data['loginTokens']) || !is_array($data['loginTokens'])) { $data['loginTokens'] = []; $migrated = true; }
    if (!isset($data['adminSessions']) || !is_array($data['adminSessions'])) { $data['adminSessions'] = []; $migrated = true; }
    if (!isset($data['rateLimits']) || !is_array($data['rateLimits'])) { $data['rateLimits'] = []; $migrated = true; }
    if (!isset($data['adminUserActivity']) || !is_array($data['adminUserActivity'])) { $data['adminUserActivity'] = []; $migrated = true; }
    if (!isset($data['adminProfileOverrides']) || !is_array($data['adminProfileOverrides'])) { $data['adminProfileOverrides'] = []; $migrated = true; }
    if (!isset($data['adminEmailVerifications']) || !is_array($data['adminEmailVerifications'])) { $data['adminEmailVerifications'] = []; $migrated = true; }
    if (!isset($data['settings']) || !is_array($data['settings'])) { $data['settings'] = ['gradingScale' => DEFAULT_GRADING_SCALE, 'integrityPolicy' => DEFAULT_INTEGRITY_POLICY]; $migrated = true; }
    if (!isset($data['settings']['gradingScale']) || !is_array($data['settings']['gradingScale'])) { $data['settings']['gradingScale'] = DEFAULT_GRADING_SCALE; $migrated = true; }
    if (!isset($data['settings']['integrityPolicy']) || !is_array($data['settings']['integrityPolicy'])) { $data['settings']['integrityPolicy'] = DEFAULT_INTEGRITY_POLICY; $migrated = true; }
    if (!$data['roles']) { $data['roles'] = DEFAULT_ROLES; $migrated = true; }
    // Newsletter access is intentionally available to every administrator role.
    foreach ($data['roles'] as &$role) if (!in_array('newsletter', $role['permissions'] ?? [], true)) { $role['permissions'][] = 'newsletter'; $migrated = true; }
    unset($role);
    // Existing registered students become subscribers on upgrade as well as on future registration.
    foreach ($data['students'] as &$student) {
        if (!array_key_exists('phoneNumber', $student)) { $student['phoneNumber'] = ''; $migrated = true; }
    }
    unset($student);
    foreach ($data['students'] as $student) if (syncStudentSubscriber($data, $student)) $migrated = true;
    // Sessions issued before administrator roles were introduced had only a token and expiry.
    // The former application supported one bootstrap administrator, so preserve that valid
    // session as the bootstrap Superadmin rather than unexpectedly signing the user out.
    $bootstrapEmail = getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL;
    foreach ($data['adminSessions'] as &$adminSession) {
        if (!empty($adminSession['roleId'])) continue;
        $sessionEmail = strtolower((string)($adminSession['email'] ?? ''));
        $account = $sessionEmail !== '' ? findBy($data['adminUsers'], 'email', $sessionEmail) : null;
        $roleId = $account['roleId'] ?? 'superadmin';
        $role = findBy($data['roles'], 'id', $roleId) ?? findBy(DEFAULT_ROLES, 'id', 'superadmin');
        $adminSession['userId'] = $adminSession['userId'] ?? ($account['id'] ?? 'bootstrap-superadmin');
        $adminSession['email'] = $adminSession['email'] ?? ($account['email'] ?? $bootstrapEmail);
        $adminSession['name'] = $adminSession['name'] ?? ($account['name'] ?? 'Superadmin');
        $adminSession['roleId'] = $role['id'];
        $adminSession['permissions'] = $role['permissions'];
        $migrated = true;
    }
    unset($adminSession);
    foreach ($data['adminSessions'] as $adminSession) {
        $userId = (string)($adminSession['userId'] ?? '');
        if ($userId !== '' && empty($data['adminUserActivity'][$userId]['lastLoginAt'])) {
            $data['adminUserActivity'][$userId] = ['lastLoginAt' => (string)($adminSession['createdAt'] ?? date('c'))];
            $migrated = true;
        }
    }
    foreach ($data['adminSessions'] as $adminSession) {
        $userId = (string)($adminSession['userId'] ?? '');
        if ($userId !== '' && empty($data['adminUserActivity'][$userId]['lastLoginAt'])) {
            $data['adminUserActivity'][$userId] = ['lastLoginAt' => (string)($adminSession['createdAt'] ?? date('c'))];
            $migrated = true;
        }
    }
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
function auditEvent(array &$data, string $actorType, string $actorId, string $actionType, string $targetType, string $targetId, array $metadata = []): void {
    if ($actorType === 'admin') { $token = bearerToken('HTTP_X_ADMIN_TOKEN'); $session = $token ? findBy($data['adminSessions'] ?? [], 'tokenHash', tokenHash($token)) : null; if (!empty($session['email'])) $actorId = $session['email']; }
    $data['auditEvents'] ??= [];
    if (count($data['auditEvents']) >= 20000) array_shift($data['auditEvents']);
    $data['auditEvents'][] = [
        'id' => id(), 'timestamp' => date('c'), 'actorType' => $actorType, 'actorId' => $actorId,
        'actionType' => $actionType, 'targetType' => $targetType, 'targetId' => $targetId,
        'ipAddress' => clientFingerprint(), 'metadata' => $metadata
    ];
}
function integrityRule(array $data, string $event): array {
    $rule = $data['settings']['integrityPolicy'][$event] ?? DEFAULT_INTEGRITY_POLICY[$event] ?? ['mode' => 'warn', 'lockAfter' => 3];
    $mode = in_array(($rule['mode'] ?? ''), ['log', 'warn', 'lock'], true) ? $rule['mode'] : 'warn';
    $lockAfter = max(1, min(20, (int)($rule['lockAfter'] ?? 3)));
    return ['mode' => $mode, 'lockAfter' => $lockAfter];
}
function validateIntegrityPolicy(mixed $input): array {
    if (!is_array($input)) respond(['error' => 'Integrity policy must be an event policy map.'], 422);
    $clean = [];
    foreach (INTEGRITY_EVENT_TYPES as $event) {
        $rule = $input[$event] ?? DEFAULT_INTEGRITY_POLICY[$event];
        if (!is_array($rule) || !in_array(($rule['mode'] ?? ''), ['log', 'warn', 'lock'], true)) respond(['error' => 'Each integrity policy needs Log only, Warn student, or Auto-lock.'], 422);
        $lockAfter = (int)($rule['lockAfter'] ?? 3);
        if ($lockAfter < 1 || $lockAfter > 20) respond(['error' => 'Auto-lock thresholds must be between 1 and 20.'], 422);
        $clean[$event] = ['mode' => $rule['mode'], 'lockAfter' => $lockAfter];
    }
    return $clean;
}
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
function requiredPermission(): ?string {
    $action = (string)($_GET['action'] ?? '');
    return match ($action) {
        'students', 'students-bulk', 'exam-password', 'student-results', 'student-results-csv' => 'students',
        'exams' => 'exams', 'questions', 'questions-bulk' => 'questions', 'results', 'result-review' => 'results',
        'audit-monitor', 'audit-events' => 'audit', 'newsletter-subscribers', 'newsletters' => 'newsletter', 'settings' => 'settings', 'roles', 'admin-users', 'admin-approvals' => 'roles',
        'dashboard' => 'overview', default => null
    };
}
function auth(bool $admin = false): ?array {
    global $data;
    if (!$admin) return null;
    $token = bearerToken('HTTP_X_ADMIN_TOKEN');
    $record = $token ? findBy($data['adminSessions'], 'tokenHash', tokenHash($token)) : null;
    if (!$record || strtotime($record['expiresAt']) <= time()) respond(['error' => 'Admin session expired. Sign in again.'], 401);
    $action = (string)($_GET['action'] ?? '');
    $roleId = $record['roleId'] ?? 'superadmin';
    if (in_array($action, ['roles', 'admin-users', 'admin-approvals'], true) && $roleId !== 'superadmin') respond(['error' => 'Only the Superadmin can manage roles, administrator accounts, and approval requests.'], 403);
    $permission = requiredPermission(); $currentRole = findBy($data['roles'], 'id', $roleId); $permissions = $currentRole['permissions'] ?? ($record['permissions'] ?? ADMIN_PERMISSIONS);
    if ($permission && !in_array($permission, $permissions, true)) respond(['error' => 'Your role does not have permission to perform this action.'], 403);
    return $record;
}
function adminActorId(): string {
    global $data;
    $token = bearerToken('HTTP_X_ADMIN_TOKEN'); $record = $token ? findBy($data['adminSessions'], 'tokenHash', tokenHash($token)) : null;
    return (string)($record['email'] ?? (getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL));
}
function findBy(array $items, string $key, mixed $value): ?array {
    foreach ($items as $item) if (($item[$key] ?? null) === $value) return $item;
    return null;
}
function replaceBy(array &$items, string $key, mixed $value, array $replacement): bool {
    foreach ($items as $index => $item) if (($item[$key] ?? null) === $value) { $items[$index] = $replacement; return true; }
    return false;
}
function publicAdminUser(array $user, array $roles, array $activity = []): array {
    unset($user['passwordHash']); $role = findBy($roles, 'id', $user['roleId'] ?? '');
    $user['roleName'] = $role['name'] ?? 'Unknown role';
    $user['verified'] = array_key_exists('verified', $user) ? (bool)$user['verified'] : true;
    $user['lastLoginAt'] = $activity[$user['id']]['lastLoginAt'] ?? ($user['lastLoginAt'] ?? null);
    return $user;
}
function bootstrapAdminAccount(array $data): array {
    $override = $data['adminProfileOverrides']['bootstrap-superadmin'] ?? [];
    $environmentEmail = getenv('CBT_ADMIN_EMAIL'); $environmentPassword = getenv('CBT_ADMIN_PASSWORD_HASH');
    return [
        'id' => 'bootstrap-superadmin', 'name' => $override['name'] ?? 'Superadmin',
        'email' => $environmentEmail ?: ($override['email'] ?? DEV_ADMIN_EMAIL),
        'phoneNumber' => $override['phoneNumber'] ?? '',
        'passwordHash' => $environmentPassword ?: ($override['passwordHash'] ?? DEV_ADMIN_PASSWORD_HASH),
        'roleId' => 'superadmin', 'active' => true, 'verified' => true, 'systemLocked' => true,
        'emailManagedByEnvironment' => $environmentEmail !== false && $environmentEmail !== '',
        'passwordManagedByEnvironment' => $environmentPassword !== false && $environmentPassword !== ''
    ];
}
function currentAdminAccount(array $data, array $session): ?array {
    if (($session['userId'] ?? '') === 'bootstrap-superadmin') return bootstrapAdminAccount($data);
    return findBy($data['adminUsers'], 'id', (string)($session['userId'] ?? ''));
}
function publicAccount(array $account, array $data): array {
    $result = publicAdminUser($account, $data['roles'], $data['adminUserActivity']);
    $pending = findBy($data['adminEmailVerifications'], 'userId', (string)$account['id']);
    $result['pendingEmail'] = $pending && strtotime((string)($pending['expiresAt'] ?? '')) > time() ? ($pending['email'] ?? null) : null;
    $result['emailManagedByEnvironment'] = !empty($account['emailManagedByEnvironment']);
    $result['passwordManagedByEnvironment'] = !empty($account['passwordManagedByEnvironment']);
    return $result;
}
function newsletterSender(): string { return getenv('CBT_NEWSLETTER_SENDER') ?: NEWSLETTER_SENDER; }
function newsletterSmtpConfig(): ?array {
    $password = preg_replace('/\s+/', '', (string)(getenv('CBT_NEWSLETTER_GMAIL_APP_PASSWORD') ?: ''));
    if ($password === '') return null;
    return ['host' => getenv('CBT_NEWSLETTER_SMTP_HOST') ?: 'smtp.gmail.com', 'port' => max(1, min(65535, (int)(getenv('CBT_NEWSLETTER_SMTP_PORT') ?: 587))), 'username' => getenv('CBT_NEWSLETTER_SMTP_USERNAME') ?: newsletterSender(), 'password' => $password];
}
function smtpReply($socket): string {
    $reply = '';
    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        if ($line === false) break;
        $reply .= $line;
        if (preg_match('/^\d{3} /', $line)) break;
    }
    return $reply;
}
function smtpAccepted(string $reply, array $codes): bool {
    return (bool)preg_match('/^(\d{3})[ -]/', $reply, $match) && in_array((int)$match[1], $codes, true);
}
function smtpCommand($socket, string $command, array $codes): bool {
    if (fwrite($socket, $command . "\r\n") === false) return false;
    return smtpAccepted(smtpReply($socket), $codes);
}
function sendNewsletterMessage(string $recipient, string $subject, string $content, bool $includeUnsubscribe = true): array {
    $config = newsletterSmtpConfig();
    if (!$config) return ['ok' => false, 'reason' => 'smtp_not_configured'];
    $socket = @stream_socket_client('tcp://' . $config['host'] . ':' . $config['port'], $errorNumber, $errorText, 15, STREAM_CLIENT_CONNECT);
    if (!$socket) return ['ok' => false, 'reason' => 'smtp_connection_failed'];
    stream_set_timeout($socket, 20);
    $ok = smtpAccepted(smtpReply($socket), [220])
        && smtpCommand($socket, 'EHLO localhost', [250])
        && smtpCommand($socket, 'STARTTLS', [220])
        && stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
        && smtpCommand($socket, 'EHLO localhost', [250])
        && smtpCommand($socket, 'AUTH LOGIN', [334])
        && smtpCommand($socket, base64_encode($config['username']), [334])
        && smtpCommand($socket, base64_encode($config['password']), [235])
        && smtpCommand($socket, 'MAIL FROM:<' . newsletterSender() . '>', [250])
        && smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251])
        && smtpCommand($socket, 'DATA', [354]);
    if ($ok) {
        $safeSubject = str_replace(["\r", "\n"], '', $subject);
        $encodedSubject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($safeSubject, 'UTF-8', 'B', "\r\n") : $safeSubject;
        $unsubscribe = 'mailto:' . newsletterSender() . '?subject=' . rawurlencode('Unsubscribe');
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . NEWSLETTER_SENDER_NAME . ' <' . newsletterSender() . '>',
            'Reply-To: ' . newsletterSender(),
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ];
        if ($includeUnsubscribe) $headers[] = 'List-Unsubscribe: <' . $unsubscribe . '>';
        $body = $includeUnsubscribe ? newsletterBody($content) : "CACSA LAUTECH\n\n" . $content . "\n\n-- CACSA LAUTECH";
        $message = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/(?m)^\./', '..', $body);
        $ok = fwrite($socket, $message . "\r\n.\r\n") !== false && smtpAccepted(smtpReply($socket), [250]);
    }
    @smtpCommand($socket, 'QUIT', [221]); fclose($socket);
    return ['ok' => $ok, 'reason' => $ok ? 'accepted_by_gmail_smtp' : 'smtp_rejected_message'];
}
function publicSubscriber(array $subscriber): array {
    unset($subscriber['studentId']);
    return $subscriber;
}
function syncStudentSubscriber(array &$data, array $student): bool {
    $email = strtolower(trim((string)($student['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    foreach ($data['newsletterSubscribers'] as &$subscriber) {
        if (strcasecmp((string)($subscriber['email'] ?? ''), $email) !== 0 && ($subscriber['studentId'] ?? '') !== $student['id']) continue;
        $changed = false;
        foreach (['name' => $student['fullName'], 'email' => $email, 'studentId' => $student['id'], 'source' => 'student'] as $key => $value) if (($subscriber[$key] ?? null) !== $value) { $subscriber[$key] = $value; $changed = true; }
        if (!isset($subscriber['status'])) { $subscriber['status'] = 'active'; $changed = true; }
        if ($changed) $subscriber['updatedAt'] = date('c');
        unset($subscriber);
        return $changed;
    }
    unset($subscriber);
    $data['newsletterSubscribers'][] = ['id' => id(), 'name' => $student['fullName'], 'email' => $email, 'studentId' => $student['id'], 'source' => 'student', 'status' => 'active', 'verifiedAt' => date('c'), 'subscribedAt' => date('c'), 'updatedAt' => date('c')];
    return true;
}
function validateSubscriber(array $input): array {
    $name = requireText($input['name'] ?? null, 'Subscriber name');
    $email = strtolower(requireText($input['email'] ?? null, 'Subscriber email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid subscriber email address.'], 422);
    return ['name' => $name, 'email' => $email];
}
function newsletterBody(string $content): string {
    return "CACSA LAUTECH\n\n" . $content . "\n\n-- CACSA LAUTECH\n\nTo stop receiving these newsletters, reply to this email with the word: unsubscribe\n";
    return "CACSA LAUTECH\n\n" . $content . "\n\n— CACSA LAUTECH\n";
}
function validateAdminUser(array $input, array $data, ?string $currentId = null, bool $requirePassword = true): array {
    $name = requireText($input['name'] ?? null, 'Administrator name'); $email = strtolower(requireText($input['email'] ?? null, 'Administrator email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid administrator email.'], 422);
    foreach ($data['adminUsers'] as $user) if (($user['id'] ?? '') !== $currentId && strcasecmp((string)$user['email'], $email) === 0) respond(['error' => 'That administrator email is already in use.'], 409);
    if (strcasecmp($email, getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL) === 0) respond(['error' => 'The bootstrap Superadmin email is reserved.'], 409);
    $roleId = (string)($input['roleId'] ?? ''); $role = findBy($data['roles'], 'id', $roleId); if (!$role) respond(['error' => 'Choose a valid role.'], 422);
    $result = ['name' => $name, 'email' => $email, 'roleId' => $roleId];
    $password = (string)($input['password'] ?? '');
    if ($requirePassword && strlen($password) < 8) respond(['error' => 'Administrator passwords must have at least 8 characters.'], 422);
    if ($password !== '') $result['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
    return $result;
}
function validateAdminRegistration(array $input, array $data): array {
    $name = requireText($input['name'] ?? null, 'Full name');
    $email = strtolower(requireText($input['email'] ?? null, 'Email'));
    $password = (string)($input['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 422);
    if (strlen($password) < 8) respond(['error' => 'Use a password with at least 8 characters.'], 422);
    if (strcasecmp($email, getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL) === 0) respond(['error' => 'This email is reserved for the Superadmin account.'], 409);
    foreach ($data['adminUsers'] as $user) if (strcasecmp((string)($user['email'] ?? ''), $email) === 0) respond(['error' => 'An administrator account already uses this email.'], 409);
    foreach ($data['pendingAdminRequests'] as $request) if (strcasecmp((string)($request['email'] ?? ''), $email) === 0 && ($request['status'] ?? 'pending') === 'pending') respond(['error' => 'A request for this email is already awaiting approval.'], 409);
    return ['name' => $name, 'email' => $email, 'passwordHash' => password_hash($password, PASSWORD_DEFAULT)];
}
function publicAdminRequest(array $request, array $roles): array {
    unset($request['passwordHash']);
    $role = findBy($roles, 'id', (string)($request['roleId'] ?? ''));
    $request['roleName'] = $role['name'] ?? null;
    return $request;
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
    $phoneNumber = requireText($input['phoneNumber'] ?? null, 'Phone number', 32);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 422);
    $phoneDigits = preg_replace('/\D+/', '', $phoneNumber);
    if (!preg_match('/^[0-9+()\-\s.]+$/', $phoneNumber) || strlen((string)$phoneDigits) < 7 || strlen((string)$phoneDigits) > 16) respond(['error' => 'Enter a valid phone number.'], 422);
    foreach ($data['students'] as $student) {
        if ($student['id'] !== $currentId && strcasecmp($student['matricNumber'], $matric) === 0) respond(['error' => 'That matric number is already registered.'], 409);
    }
    return ['fullName' => $name, 'email' => $email, 'matricNumber' => $matric, 'department' => $department, 'phoneNumber' => $phoneNumber];
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
    $student = findBy($data['students'], 'id', $session['studentId']); $exam = findBy($data['exams'], 'id', $session['examId']);
    auditEvent($data, 'student', (string)$session['studentId'], $auto ? 'exam_submitted_auto' : 'exam_submitted_manual', 'exam_session', (string)$session['id'], ['matricNumber' => $student['matricNumber'] ?? '', 'course' => $exam['code'] ?? '', 'score' => $session['score']]);
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
    return ['id' => $session['id'], 'startedAt' => $session['startedAt'], 'endsAt' => $session['endsAt'], 'accessToken' => $accessToken, 'answers' => $session['answers'], 'flagged' => $session['flagged'], 'status' => $session['status'], 'lockedReason' => $session['lockedReason'] ?? null, 'integrityEvents' => $session['integrityEvents'] ?? []];
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
if ($action === 'admin-registration' && $method === 'POST') {
    $input = body();
    $rateLimitKey = enforceRateLimit($data, 'admin-registration', 5, 3600);
    $values = validateAdminRegistration($input, $data);
    $request = ['id' => id()] + $values + ['status' => 'pending', 'createdAt' => date('c'), 'ipAddress' => clientFingerprint()];
    $data['pendingAdminRequests'][] = $request;
    clearRateLimit($data, $rateLimitKey);
    auditEvent($data, 'system', $request['email'], 'administrator_registration_requested', 'administrator_request', $request['id'], ['name' => $request['name'], 'email' => $request['email']]);
    saveData($data);
    respond(['ok' => true], 201);
}
if ($action === 'admin-login' && $method === 'POST') {
    $input = body();
    $rateLimitKey = enforceRateLimit($data, 'admin-login', 10, 900);
    $bootstrapAccount = bootstrapAdminAccount($data);
    $adminEmail = $bootstrapAccount['email']; $adminHash = $bootstrapAccount['passwordHash'];
    $email = strtolower(trim((string)($input['email'] ?? ''))); $adminUser = findBy($data['adminUsers'], 'email', $email);
    if ($adminUser && empty($adminUser['active'])) respond(['error' => 'This administrator account is disabled.'], 403);
    $isBootstrap = hash_equals(strtolower($adminEmail), $email) && password_verify((string)($input['password'] ?? ''), $adminHash);
    $isUser = $adminUser && password_verify((string)($input['password'] ?? ''), (string)$adminUser['passwordHash']);
    if (!$isBootstrap && !$isUser) respond(['error' => 'Invalid admin credentials.'], 401);
    $user = $isUser ? $adminUser : $bootstrapAccount;
    $role = findBy($data['roles'], 'id', $user['roleId']) ?? findBy(DEFAULT_ROLES, 'id', 'superadmin');
    $token = secretToken(); $expiresAt = date('c', time() + ADMIN_TOKEN_SECONDS);
    clearRateLimit($data, $rateLimitKey);
    $data['adminUserActivity'][$user['id']] = ['lastLoginAt' => date('c')];
    $data['adminSessions'][] = ['tokenHash' => tokenHash($token), 'expiresAt' => $expiresAt, 'createdAt' => date('c'), 'userId' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'roleId' => $role['id'], 'permissions' => $role['permissions']];
    auditEvent($data, 'admin', $user['email'], 'admin_login', 'admin_session', tokenHash($token), ['email' => $user['email'], 'role' => $role['name']]);
    saveData($data);
    respond(['token' => $token, 'expiresAt' => $expiresAt, 'user' => ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'roleId' => $role['id'], 'role' => $role['name'], 'permissions' => $role['permissions']]]);
}
if ($action === 'admin-logout' && $method === 'POST') {
    $record = auth(true); $token = bearerToken('HTTP_X_ADMIN_TOKEN');
    $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($item) => $item['tokenHash'] !== tokenHash($token)));
    auditEvent($data, 'admin', (string)($record['email'] ?? (getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL)), 'admin_logout', 'admin_session', tokenHash($token));
    saveData($data); respond(['ok' => true]);
}
if ($action === 'admin-account' && $method === 'GET') {
    $session = auth(true); $account = currentAdminAccount($data, $session);
    if (!$account) respond(['error' => 'Administrator account was not found.'], 404);
    respond(['account' => publicAccount($account, $data)]);
}
if ($action === 'admin-account' && $method === 'PUT') {
    $session = auth(true); $account = currentAdminAccount($data, $session);
    if (!$account) respond(['error' => 'Administrator account was not found.'], 404);
    $input = body(); $name = requireText($input['name'] ?? null, 'Full name'); $phone = trim((string)($input['phoneNumber'] ?? ''));
    if ($phone !== '') { $digits = preg_replace('/\D+/', '', $phone); if (!preg_match('/^[0-9+()\-\s.]+$/', $phone) || strlen((string)$digits) < 7 || strlen((string)$digits) > 16) respond(['error' => 'Enter a valid phone number.'], 422); }
    $email = strtolower(requireText($input['email'] ?? null, 'Email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 422);
    $emailChanged = strcasecmp($email, (string)$account['email']) !== 0;
    if ($emailChanged && !empty($account['emailManagedByEnvironment'])) respond(['error' => 'This Superadmin email is managed in the server environment and cannot be changed here.'], 409);
    foreach ($data['adminUsers'] as $user) if (($user['id'] ?? '') !== $account['id'] && strcasecmp((string)($user['email'] ?? ''), $email) === 0) respond(['error' => 'Another administrator already uses this email.'], 409);
    $bootstrap = bootstrapAdminAccount($data);
    if ($account['id'] !== 'bootstrap-superadmin' && strcasecmp((string)$bootstrap['email'], $email) === 0) respond(['error' => 'That email is reserved for the Superadmin account.'], 409);
    $account['name'] = $name; $account['phoneNumber'] = $phone;
    if ($emailChanged) {
        if (!newsletterSmtpConfig()) respond(['error' => 'Email verification is unavailable until Gmail SMTP is configured.'], 503);
        $code = (string)random_int(100000, 999999);
        $delivery = sendNewsletterMessage($email, 'Confirm your CACSA LAUTECH CBT administrator email', "Hello " . $name . ",\n\nYour email-change verification code is: " . $code . "\n\nThis code expires in 15 minutes. If you did not request this change, ignore this message.", false);
        if (!$delivery['ok']) respond(['error' => 'The verification email was not accepted by the mail server. Your email was not changed.'], 503);
        $data['adminEmailVerifications'] = array_values(array_filter($data['adminEmailVerifications'], fn($item) => ($item['userId'] ?? '') !== $account['id']));
        $data['adminEmailVerifications'][] = ['userId' => $account['id'], 'email' => $email, 'codeHash' => password_hash($code, PASSWORD_DEFAULT), 'expiresAt' => date('c', time() + 900), 'attempts' => 0];
    }
    if ($account['id'] === 'bootstrap-superadmin') $data['adminProfileOverrides'][$account['id']] = array_filter(['name' => $account['name'], 'phoneNumber' => $account['phoneNumber'], 'email' => $account['email'], 'passwordHash' => $account['passwordHash'] ?? null], fn($value) => $value !== null);
    else replaceBy($data['adminUsers'], 'id', $account['id'], $account);
    foreach ($data['adminSessions'] as &$item) if (($item['userId'] ?? '') === $account['id']) $item['name'] = $account['name']; unset($item);
    auditEvent($data, 'admin', adminActorId(), 'administrator_profile_updated', 'administrator', (string)$account['id'], ['emailChangeRequested' => $emailChanged]);
    saveData($data); respond(['account' => publicAccount($account, $data), 'verificationRequired' => $emailChanged]);
}
if ($action === 'admin-account' && $method === 'POST') {
    $session = auth(true); $account = currentAdminAccount($data, $session);
    if (!$account) respond(['error' => 'Administrator account was not found.'], 404);
    $input = body(); $operation = (string)($input['operation'] ?? ''); $token = bearerToken('HTTP_X_ADMIN_TOKEN');
    if ($operation === 'verify-email') {
        $verification = findBy($data['adminEmailVerifications'], 'userId', (string)$account['id']);
        if (!$verification || strtotime((string)$verification['expiresAt']) <= time()) respond(['error' => 'That verification code has expired. Request another email change.'], 422);
        $code = trim((string)($input['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, (string)$verification['codeHash'])) {
            $verification['attempts'] = (int)($verification['attempts'] ?? 0) + 1;
            if ($verification['attempts'] >= 5) $data['adminEmailVerifications'] = array_values(array_filter($data['adminEmailVerifications'], fn($item) => ($item['userId'] ?? '') !== $account['id'])); else replaceBy($data['adminEmailVerifications'], 'userId', $account['id'], $verification);
            saveData($data); respond(['error' => 'Invalid verification code.'], 422);
        }
        $oldEmail = $account['email']; $account['email'] = $verification['email'];
        if ($account['id'] === 'bootstrap-superadmin') $data['adminProfileOverrides'][$account['id']] = array_filter(['name' => $account['name'], 'phoneNumber' => $account['phoneNumber'] ?? '', 'email' => $account['email'], 'passwordHash' => $account['passwordHash'] ?? null], fn($value) => $value !== null); else replaceBy($data['adminUsers'], 'id', $account['id'], $account);
        foreach ($data['adminSessions'] as &$item) if (($item['userId'] ?? '') === $account['id']) $item['email'] = $account['email']; unset($item);
        $data['adminEmailVerifications'] = array_values(array_filter($data['adminEmailVerifications'], fn($item) => ($item['userId'] ?? '') !== $account['id']));
        auditEvent($data, 'admin', (string)$oldEmail, 'administrator_email_verified', 'administrator', (string)$account['id'], ['oldEmail' => $oldEmail, 'newEmail' => $account['email']]); saveData($data); respond(['account' => publicAccount($account, $data)]);
    }
    if ($operation === 'change-password') {
        if (!empty($account['passwordManagedByEnvironment'])) respond(['error' => 'This password is managed in the server environment and cannot be changed here.'], 409);
        $current = (string)($input['currentPassword'] ?? ''); $next = (string)($input['newPassword'] ?? '');
        if (!password_verify($current, (string)$account['passwordHash'])) respond(['error' => 'Your current password is incorrect.'], 422);
        if (strlen($next) < 8) respond(['error' => 'Use a new password with at least 8 characters.'], 422);
        $account['passwordHash'] = password_hash($next, PASSWORD_DEFAULT);
        if ($account['id'] === 'bootstrap-superadmin') $data['adminProfileOverrides'][$account['id']] = ['name' => $account['name'], 'phoneNumber' => $account['phoneNumber'] ?? '', 'email' => $account['email'], 'passwordHash' => $account['passwordHash']]; else replaceBy($data['adminUsers'], 'id', $account['id'], $account);
        $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($item) => ($item['userId'] ?? '') !== $account['id'] || ($item['tokenHash'] ?? '') === tokenHash($token)));
        auditEvent($data, 'admin', adminActorId(), 'administrator_password_changed', 'administrator', (string)$account['id']); saveData($data); respond(['ok' => true]);
    }
    if ($operation === 'signout-others') {
        $before = count($data['adminSessions']); $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($item) => ($item['userId'] ?? '') !== $account['id'] || ($item['tokenHash'] ?? '') === tokenHash($token)));
        auditEvent($data, 'admin', adminActorId(), 'administrator_other_sessions_revoked', 'administrator', (string)$account['id'], ['revoked' => $before - count($data['adminSessions'])]); saveData($data); respond(['ok' => true]);
    }
    respond(['error' => 'Unknown account action.'], 422);
}
if ($action === 'roles' && $method === 'GET') {
    auth(true); $items = [];
    foreach ($data['roles'] as $role) {
        $count = count(array_filter($data['adminUsers'], fn($user) => ($user['roleId'] ?? '') === $role['id'] && !empty($user['active']))) + ($role['id'] === 'superadmin' ? 1 : 0);
        $items[] = $role + ['userCount' => $count];
    }
    respond(['items' => $items]);
}
if ($action === 'roles' && $method === 'PUT') {
    auth(true); $roleId = (string)($_GET['id'] ?? ''); $role = findBy($data['roles'], 'id', $roleId); if (!$role) respond(['error' => 'Role not found.'], 404);
    if (!empty($role['systemLocked'])) respond(['error' => 'The Superadmin role is system-locked.'], 403);
    $input = body(); $maxUsers = (int)($input['maxUsers'] ?? 0); $permissions = $input['permissions'] ?? null;
    if ($maxUsers < 1 || $maxUsers > 100) respond(['error' => 'Maximum users must be between 1 and 100.'], 422);
    if (!is_array($permissions) || !array_is_list($permissions) || array_diff($permissions, ADMIN_PERMISSIONS)) respond(['error' => 'Choose valid role permissions.'], 422);
    // Roles management is permanently Superadmin-only; never persist it as a coordinator permission.
    $role['maxUsers'] = $maxUsers; $role['permissions'] = array_values(array_unique(array_merge(array_diff($permissions, ['roles']), ['newsletter'])));
    replaceBy($data['roles'], 'id', $roleId, $role); auditEvent($data, 'admin', adminActorId(), 'role_updated', 'role', $roleId, ['role' => $role['name'], 'permissions' => $role['permissions']]); saveData($data); respond(['item' => $role]);
}
if ($action === 'admin-users' && $method === 'GET') {
    auth(true);
    $bootstrap = bootstrapAdminAccount($data);
    $items = [publicAdminUser($bootstrap, $data['roles'], $data['adminUserActivity'])];
    foreach ($data['adminUsers'] as $user) $items[] = publicAdminUser($user, $data['roles'], $data['adminUserActivity']);
    respond(['items' => $items]);
}
if ($action === 'admin-users' && $method === 'POST') {
    auth(true); $input = body(); $values = validateAdminUser($input, $data); $role = findBy($data['roles'], 'id', $values['roleId']);
    $count = count(array_filter($data['adminUsers'], fn($user) => ($user['roleId'] ?? '') === $role['id'] && !empty($user['active']))) + ($role['id'] === 'superadmin' ? 1 : 0);
    if ($count >= (int)$role['maxUsers']) respond(['error' => 'This role has reached its maximum number of users.'], 409);
    $user = ['id' => id()] + $values + ['active' => true, 'verified' => true, 'createdAt' => date('c')]; $data['adminUsers'][] = $user;
    auditEvent($data, 'admin', adminActorId(), 'administrator_created', 'administrator', $user['id'], ['email' => $user['email'], 'role' => $role['name']]); saveData($data); respond(['item' => publicAdminUser($user, $data['roles'], $data['adminUserActivity'])], 201);
}
if ($action === 'admin-users' && in_array($method, ['PUT', 'DELETE'], true)) {
    auth(true); $userId = (string)($_GET['id'] ?? ''); $user = findBy($data['adminUsers'], 'id', $userId); if (!$user) respond(['error' => 'Administrator not found.'], 404);
    if ($method === 'DELETE') { $user['active'] = false; replaceBy($data['adminUsers'], 'id', $userId, $user); auditEvent($data, 'admin', adminActorId(), 'administrator_disabled', 'administrator', $userId, ['email' => $user['email']]); saveData($data); respond(['item' => publicAdminUser($user, $data['roles'], $data['adminUserActivity'])]); }
    $input = body(); $values = validateAdminUser(array_merge($user, $input), $data, $userId, false); $newRole = findBy($data['roles'], 'id', $values['roleId']);
    if ($newRole['id'] !== $user['roleId']) { $count = count(array_filter($data['adminUsers'], fn($item) => ($item['roleId'] ?? '') === $newRole['id'] && !empty($item['active']))); if ($count >= (int)$newRole['maxUsers']) respond(['error' => 'This role has reached its maximum number of users.'], 409); }
    $user = array_merge($user, $values); if (array_key_exists('active', $input)) $user['active'] = (bool)$input['active']; replaceBy($data['adminUsers'], 'id', $userId, $user);
    auditEvent($data, 'admin', adminActorId(), 'administrator_updated', 'administrator', $userId, ['email' => $user['email'], 'role' => $newRole['name']]); saveData($data); respond(['item' => publicAdminUser($user, $data['roles'], $data['adminUserActivity'])]);
}

if ($action === 'admin-approvals' && $method === 'GET') {
    auth(true);
    $pending = array_values(array_filter($data['pendingAdminRequests'], fn($request) => ($request['status'] ?? 'pending') === 'pending'));
    if (($_GET['summary'] ?? '') === 'true') respond(['summary' => ['pending' => count($pending)]]);
    usort($pending, fn($a, $b) => strcmp((string)($a['createdAt'] ?? ''), (string)($b['createdAt'] ?? '')));
    $recent = array_values(array_filter($data['pendingAdminRequests'], fn($request) => in_array(($request['status'] ?? ''), ['approved', 'rejected'], true)));
    usort($recent, fn($a, $b) => strcmp((string)($b['resolvedAt'] ?? ''), (string)($a['resolvedAt'] ?? '')));
    respond(['pending' => array_map(fn($request) => publicAdminRequest($request, $data['roles']), $pending), 'recent' => array_map(fn($request) => publicAdminRequest($request, $data['roles']), array_slice($recent, 0, 20)), 'mailConfigured' => newsletterSmtpConfig() !== null]);
}
if ($action === 'admin-approvals' && $method === 'POST') {
    auth(true); $input = body(); $requestId = (string)($input['id'] ?? ''); $decision = (string)($input['decision'] ?? '');
    $request = findBy($data['pendingAdminRequests'], 'id', $requestId);
    if (!$request || ($request['status'] ?? 'pending') !== 'pending') respond(['error' => 'This approval request is no longer pending.'], 404);
    if ($decision === 'reject') {
        $request['status'] = 'rejected'; $request['resolvedAt'] = date('c'); $request['resolvedBy'] = adminActorId();
        $request['rejectionReason'] = trim((string)($input['reason'] ?? ''));
        unset($request['passwordHash']);
        replaceBy($data['pendingAdminRequests'], 'id', $requestId, $request);
        auditEvent($data, 'admin', adminActorId(), 'administrator_request_rejected', 'administrator_request', $requestId, ['email' => $request['email'], 'name' => $request['name']]);
        saveData($data); respond(['item' => publicAdminRequest($request, $data['roles'])]);
    }
    if ($decision !== 'approve') respond(['error' => 'Choose to approve or reject the request.'], 422);
    $roleId = (string)($input['roleId'] ?? ''); $role = findBy($data['roles'], 'id', $roleId);
    if (!$role || !empty($role['systemLocked'])) respond(['error' => 'Choose an assignable administrator role.'], 422);
    $count = count(array_filter($data['adminUsers'], fn($user) => ($user['roleId'] ?? '') === $role['id'] && !empty($user['active'])));
    if ($count >= (int)$role['maxUsers']) respond(['error' => 'This role has reached its maximum number of users.'], 409);
    if (!newsletterSmtpConfig()) respond(['error' => 'Email is not configured. Configure Gmail SMTP before approving this request so the requester receives their access notice.'], 503);
    $message = "Hello " . $request['name'] . ",\n\nYour CACSA LAUTECH CBT administrator account has been approved.\n\nAssigned role: " . $role['name'] . "\nAccess: " . implode(', ', array_map(fn($permission) => ucwords(str_replace('_', ' ', $permission)), $role['permissions'])) . "\n\nYou can now sign in at the administrator portal using the email and password you submitted.\n\nIf you did not request this account, contact CACSA LAUTECH immediately.";
    $delivery = sendNewsletterMessage($request['email'], 'Your CACSA LAUTECH CBT administrator access has been approved', $message, false);
    if (!$delivery['ok']) respond(['error' => 'The approval email was not accepted by the mail server. The request remains pending; check the SMTP configuration and try again.'], 503);
    $account = ['id' => id(), 'name' => $request['name'], 'email' => $request['email'], 'passwordHash' => $request['passwordHash'], 'roleId' => $role['id'], 'active' => true, 'verified' => true, 'createdAt' => date('c'), 'approvedAt' => date('c'), 'approvedBy' => adminActorId()];
    $data['adminUsers'][] = $account;
    $request['status'] = 'approved'; $request['roleId'] = $role['id']; $request['resolvedAt'] = date('c'); $request['resolvedBy'] = adminActorId(); $request['emailDelivery'] = $delivery['reason'];
    unset($request['passwordHash']);
    replaceBy($data['pendingAdminRequests'], 'id', $requestId, $request);
    auditEvent($data, 'admin', adminActorId(), 'administrator_request_approved', 'administrator', $account['id'], ['email' => $account['email'], 'name' => $account['name'], 'role' => $role['name'], 'requestId' => $requestId]);
    saveData($data); respond(['item' => publicAdminRequest($request, $data['roles']), 'emailDelivery' => $delivery['reason']]);
}

if ($action === 'newsletter-subscribers' && $method === 'GET') {
    auth(true);
    $items = $data['newsletterSubscribers'];
    $status = (string)($_GET['status'] ?? '');
    if (in_array($status, ['active', 'unsubscribed'], true)) $items = array_values(array_filter($items, fn($item) => ($item['status'] ?? 'active') === $status));
    $stats = ['total' => count($data['newsletterSubscribers']), 'active' => 0, 'unsubscribed' => 0, 'student' => 0];
    foreach ($data['newsletterSubscribers'] as $subscriber) {
        if (($subscriber['status'] ?? 'active') === 'active') $stats['active']++; else $stats['unsubscribed']++;
        if (($subscriber['source'] ?? '') === 'student') $stats['student']++;
    }
    $listed = listItems($items, ['name', 'email', 'source', 'status'], ['name', 'email', 'status', 'subscribedAt']);
    $listed['items'] = array_map('publicSubscriber', $listed['items']);
    $listed['stats'] = $stats;
    respond($listed);
}
if ($action === 'newsletter-subscribers' && $method === 'POST') {
    auth(true); $values = validateSubscriber(body());
    foreach ($data['newsletterSubscribers'] as &$subscriber) {
        if (strcasecmp((string)$subscriber['email'], $values['email']) !== 0) continue;
        $subscriber['name'] = $values['name']; $subscriber['status'] = 'active'; $subscriber['verifiedAt'] ??= date('c'); $subscriber['subscribedAt'] = date('c'); $subscriber['updatedAt'] = date('c');
        $updated = $subscriber; unset($subscriber); auditEvent($data, 'admin', adminActorId(), 'newsletter_subscriber_reactivated', 'newsletter_subscriber', $updated['id'], ['email' => $values['email']]); saveData($data); respond(['item' => publicSubscriber($updated)]);
    }
    unset($subscriber);
    $subscriber = ['id' => id(), 'name' => $values['name'], 'email' => $values['email'], 'source' => 'manual', 'status' => 'active', 'verifiedAt' => date('c'), 'subscribedAt' => date('c'), 'updatedAt' => date('c')];
    $data['newsletterSubscribers'][] = $subscriber;
    auditEvent($data, 'admin', adminActorId(), 'newsletter_subscriber_added', 'newsletter_subscriber', $subscriber['id'], ['email' => $subscriber['email']]); saveData($data); respond(['item' => publicSubscriber($subscriber)], 201);
}
if ($action === 'newsletter-subscribers' && $method === 'PUT') {
    auth(true); $subscriberId = (string)($_GET['id'] ?? ''); $subscriber = findBy($data['newsletterSubscribers'], 'id', $subscriberId); if (!$subscriber) respond(['error' => 'Subscriber not found.'], 404);
    $status = (string)(body()['status'] ?? ''); if (!in_array($status, ['active', 'unsubscribed'], true)) respond(['error' => 'Subscriber status must be active or unsubscribed.'], 422);
    $subscriber['status'] = $status; $subscriber['updatedAt'] = date('c');
    if ($status === 'active') { $subscriber['verifiedAt'] ??= date('c'); unset($subscriber['unsubscribedAt']); }
    else $subscriber['unsubscribedAt'] = date('c');
    replaceBy($data['newsletterSubscribers'], 'id', $subscriberId, $subscriber);
    auditEvent($data, 'admin', adminActorId(), $status === 'active' ? 'newsletter_subscriber_subscribed' : 'newsletter_subscriber_unsubscribed', 'newsletter_subscriber', $subscriberId, ['email' => $subscriber['email']]); saveData($data); respond(['item' => publicSubscriber($subscriber)]);
}
if ($action === 'newsletter-subscribers' && $method === 'DELETE') {
    auth(true); $subscriberId = (string)($_GET['id'] ?? ''); $subscriber = findBy($data['newsletterSubscribers'], 'id', $subscriberId); if (!$subscriber) respond(['error' => 'Subscriber not found.'], 404);
    if (($_GET['purge'] ?? '') === 'true') {
        $data['newsletterSubscribers'] = array_values(array_filter($data['newsletterSubscribers'], fn($item) => ($item['id'] ?? '') !== $subscriberId));
        auditEvent($data, 'admin', adminActorId(), 'newsletter_subscriber_deleted', 'newsletter_subscriber', $subscriberId, ['email' => $subscriber['email']]); saveData($data); respond(['ok' => true]);
    }
    $subscriber['status'] = 'unsubscribed'; $subscriber['unsubscribedAt'] = date('c'); $subscriber['updatedAt'] = date('c'); replaceBy($data['newsletterSubscribers'], 'id', $subscriberId, $subscriber);
    auditEvent($data, 'admin', adminActorId(), 'newsletter_subscriber_unsubscribed', 'newsletter_subscriber', $subscriberId, ['email' => $subscriber['email']]); saveData($data); respond(['item' => publicSubscriber($subscriber)]);
}
if ($action === 'newsletters' && $method === 'GET') {
    auth(true); $items = $data['newsletters']; usort($items, fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));
    foreach ($items as &$item) unset($item['deliveries'], $item['content']);
    unset($item);
    respond(['items' => array_slice($items, 0, 20), 'mailConfigured' => newsletterSmtpConfig() !== null]);
}
if ($action === 'newsletters' && $method === 'DELETE') {
    auth(true); $count = count($data['newsletters']); $data['newsletters'] = [];
    auditEvent($data, 'admin', adminActorId(), 'newsletter_history_cleared', 'newsletter', 'history', ['clearedCount' => $count]); saveData($data); respond(['cleared' => $count]);
}
if ($action === 'newsletters' && $method === 'POST') {
    auth(true); $input = body();
    if (!newsletterSmtpConfig()) respond(['error' => 'Gmail SMTP is not configured. Add the Gmail App Password to the local .env file, then try again.'], 503);
    $subject = str_replace(["\r", "\n"], '', requireText($input['subject'] ?? null, 'Newsletter subject', 180));
    $content = requireText($input['content'] ?? null, 'Newsletter message', 10000);
    $recipients = array_values(array_filter($data['newsletterSubscribers'], fn($subscriber) => ($subscriber['status'] ?? 'active') === 'active' && filter_var($subscriber['email'] ?? '', FILTER_VALIDATE_EMAIL)));
    if (!$recipients) respond(['error' => 'There are no active newsletter subscribers to receive this message.'], 422);
    $sender = newsletterSender();
    $deliveries = []; $accepted = 0; $failed = 0;
    foreach ($recipients as $recipient) {
        $delivery = sendNewsletterMessage($recipient['email'], $subject, $content); $ok = $delivery['ok'];
        $deliveries[] = ['subscriberId' => $recipient['id'], 'email' => $recipient['email'], 'status' => $delivery['reason']];
        if ($ok) $accepted++; else $failed++;
    }
    $newsletter = ['id' => id(), 'subject' => $subject, 'content' => $content, 'sender' => $sender, 'createdAt' => date('c'), 'createdBy' => adminActorId(), 'recipientCount' => count($recipients), 'acceptedCount' => $accepted, 'failedCount' => $failed, 'status' => $failed ? ($accepted ? 'partially_accepted' : 'failed') : 'accepted', 'deliveries' => $deliveries];
    $data['newsletters'][] = $newsletter;
    auditEvent($data, 'admin', adminActorId(), 'newsletter_sent', 'newsletter', $newsletter['id'], ['subject' => $subject, 'recipients' => count($recipients), 'accepted' => $accepted, 'failed' => $failed]); saveData($data);
    respond(['item' => $newsletter, 'accepted' => $accepted, 'failed' => $failed], 201);
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
        $data['exams'][] = $exam; auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'exam_created', 'exam', $exam['id'], ['course' => $exam['code'], 'title' => $exam['title']]); saveData($data); respond(['item' => publicExam($exam)], 201);
    }
    $examId = $_GET['id'] ?? '';
    $input = body(); $exam = findBy($data['exams'], 'id', $examId); if (!$exam) respond(['error' => 'Exam not found.'], 404);
    if ($method === 'PUT' && ($input['status'] ?? null) === 'active' && !array_key_exists('endAt', $input) && examWindowState($exam) === 'closed') {
        respond(['error' => 'This assessment window has ended. Use Edit to set a new end time before activating it.'], 422);
    }
    if ($method === 'DELETE') {
        foreach (['questions','passwords','sessions','results'] as $collection) foreach ($data[$collection] as $item) if (($item['examId'] ?? '') === $examId) respond(['error' => 'This assessment has questions or history. Remove its questions first, or keep it as draft to preserve records.'], 409);
        $data['exams'] = array_values(array_filter($data['exams'], fn($item) => $item['id'] !== $examId)); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'exam_deleted', 'exam', $examId, ['course' => $exam['code'], 'title' => $exam['title']]); saveData($data); respond(['ok' => true]);
    }
    $exam = array_merge($exam, validateExam(array_merge($exam, $input), $data, $examId));
    replaceBy($data['exams'], 'id', $examId, $exam); recalculateResults($data); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'exam_updated', 'exam', $examId, ['course' => $exam['code'], 'title' => $exam['title'], 'changedFields' => array_keys($input)]); saveData($data); respond(['item' => publicExam($exam)]);
}

if ($action === 'students' && $method === 'GET') { auth(true); $items = $data['students']; if (($_GET['status'] ?? '') === 'active') $items = array_values(array_filter($items, fn($item) => !empty($item['active']))); if (in_array(($_GET['status'] ?? ''), ['disabled','inactive'], true)) $items = array_values(array_filter($items, fn($item) => empty($item['active']))); respond(listItems($items, ['fullName','email','matricNumber','department','phoneNumber'], ['fullName','email','matricNumber','department','phoneNumber','createdAt'])); }
if ($action === 'students' && $method === 'POST') {
    auth(true); $input = body();
    $student = ['id' => id()] + validateStudent($input, $data) + ['active' => true, 'createdAt' => date('c')];
    $data['students'][] = $student; syncStudentSubscriber($data, $student); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'student_registered', 'student', $student['id'], ['matricNumber' => $student['matricNumber'], 'name' => $student['fullName']]); saveData($data); respond(['item' => $student], 201);
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
    array_push($data['students'], ...$new); foreach ($new as $student) syncStudentSubscriber($data, $student); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'students_bulk_imported', 'student', 'bulk', ['count' => count($new)]); saveData($data); respond(['added' => count($new)], 201);
}
if ($action === 'students' && in_array($method, ['PUT','DELETE'], true)) {
    auth(true); $studentId = $_GET['id'] ?? ''; $student = findBy($data['students'], 'id', $studentId); if (!$student) respond(['error' => 'Student not found.'], 404);
    if ($method === 'DELETE') { $student['active'] = false; replaceBy($data['students'], 'id', $studentId, $student); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'student_disabled', 'student', $studentId, ['matricNumber' => $student['matricNumber'], 'name' => $student['fullName']]); saveData($data); respond(['item' => $student]); }
    $input = body(); $student = array_merge($student, validateStudent(array_merge($student, $input), $data, $studentId)); if (array_key_exists('active', $input)) $student['active'] = (bool)$input['active'];
    replaceBy($data['students'], 'id', $studentId, $student); syncStudentSubscriber($data, $student); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'student_updated', 'student', $studentId, ['matricNumber' => $student['matricNumber'], 'name' => $student['fullName'], 'changedFields' => array_keys($input)]); saveData($data); respond(['item' => $student]);
}
if ($action === 'exam-password' && $method === 'POST') {
    auth(true); $input = body(); $student = findBy($data['students'], 'matricNumber', trim($input['matricNumber'] ?? '')); $exam = findBy($data['exams'], 'id', $input['examId'] ?? '');
    if (!$student || !$exam) respond(['error' => 'Student or exam could not be found.'], 404);
    if (!$student['active']) respond(['error' => 'This student is disabled.'], 409);
    $password = randomPassword(); $record = ['id' => id(), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordHash' => password_hash($password, PASSWORD_DEFAULT), 'createdAt' => date('c'), 'expiresAt' => $exam['endAt'], 'usedAt' => null];
    $data['passwords'] = array_values(array_filter($data['passwords'], fn($item) => !($item['studentId'] === $student['id'] && $item['examId'] === $exam['id']))); $data['passwords'][] = $record; auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'exam_password_generated', 'exam_password', $record['id'], ['matricNumber' => $student['matricNumber'], 'course' => $exam['code']]); saveData($data); respond(['student' => $student, 'exam' => publicExam($exam), 'password' => $password]);
}

if ($action === 'questions' && $method === 'GET') { auth(true); $items = $data['questions']; if (!empty($_GET['examId'])) $items = array_values(array_filter($items, fn($item) => $item['examId'] === $_GET['examId'])); if (!empty($_GET['status'])) $items = array_values(array_filter($items, fn($item) => ($item['status'] ?? 'published') === $_GET['status'])); respond(listItems($items, ['text','topic','difficulty','status'], ['text','topic','difficulty','status'])); }
if ($action === 'questions' && in_array($method, ['POST','PUT','DELETE'], true)) {
    auth(true); $questionId = $_GET['id'] ?? '';
    if ($method === 'DELETE') { $existing = findBy($data['questions'], 'id', $questionId); if (!$existing) respond(['error' => 'Question not found.'], 404); $questionExam = findBy($data['exams'], 'id', $existing['examId']); $data['questions'] = array_values(array_filter($data['questions'], fn($item) => $item['id'] !== $questionId)); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'question_deleted', 'question', $questionId, ['examId' => $existing['examId'], 'course' => $questionExam['code'] ?? '', 'text' => substr($existing['text'], 0, 120)]); saveData($data); respond(['ok' => true]); }
    $input = body();
    if ($method === 'POST') { $question = ['id' => id()] + validateQuestion($input, $data); $questionExam = findBy($data['exams'], 'id', $question['examId']); $data['questions'][] = $question; auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'question_added', 'question', $question['id'], ['examId' => $question['examId'], 'course' => $questionExam['code'] ?? '', 'text' => substr($question['text'], 0, 120)]); saveData($data); respond(['item' => $question], 201); }
    $question = findBy($data['questions'], 'id', $questionId); if (!$question) respond(['error' => 'Question not found.'], 404);
    $question = array_merge($question, validateQuestion(array_merge($question, $input), $data));
    $questionExam = findBy($data['exams'], 'id', $question['examId']); replaceBy($data['questions'], 'id', $questionId, $question); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'question_updated', 'question', $questionId, ['examId' => $question['examId'], 'course' => $questionExam['code'] ?? '', 'text' => substr($question['text'], 0, 120), 'changedFields' => array_keys($input)]); saveData($data); respond(['item' => $question]);
}
if ($action === 'questions-bulk' && $method === 'POST') {
    auth(true); $input = body(); $rows = $input['items'] ?? null;
    if (!is_array($rows) || !$rows || count($rows) > 500 || !array_is_list($rows)) respond(['error' => 'Provide 1 to 500 question rows.'], 422);
    $new = []; foreach ($rows as $index => $row) { if (!is_array($row)) respond(['error' => 'Question row ' . ($index + 1) . ' is invalid.'], 422); $new[] = ['id' => id()] + validateQuestion($row, $data); }
    $bulkExam = findBy($data['exams'], 'id', $new[0]['examId']); array_push($data['questions'], ...$new); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'questions_bulk_imported', 'question', 'bulk', ['count' => count($new), 'course' => $bulkExam['code'] ?? '']); saveData($data); respond(['added' => count($new)], 201);
}

if ($action === 'student-login' && $method === 'POST') {
    $input = body(); $matricNumber = trim((string)($input['matricNumber'] ?? '')); $rateLimitKey = enforceRateLimit($data, 'student-login', 30, 900); $student = findBy($data['students'], 'matricNumber', $matricNumber); $exam = findBy($data['exams'], 'id', $input['examId'] ?? '');
    $passwordRecord = $student && $exam ? array_values(array_filter($data['passwords'], fn($item) => $item['studentId'] === $student['id'] && $item['examId'] === $exam['id'])) : [];
    $passwordRecord = $passwordRecord ? end($passwordRecord) : null;
    if (!$student || !$student['active'] || !$exam || !$passwordRecord || !password_verify((string)($input['password'] ?? ''), $passwordRecord['passwordHash']) || strtotime($passwordRecord['expiresAt']) < time()) respond(['error' => 'We could not verify those details.'], 401);
    if (!empty($passwordRecord['usedAt'])) respond(['error' => 'This exam password has already been used. Ask an administrator for a new one.'], 409);
    foreach ($data['results'] as $result) if ($result['studentId'] === $student['id'] && $result['examId'] === $exam['id'] && strtotime($result['submittedAt']) >= strtotime($passwordRecord['createdAt'])) respond(['error' => 'This exam password has already been used. Ask an administrator for a new one.'], 409);
    if (!publicExam($exam)['active']) respond(['error' => 'This assessment is not currently available.'], 403);
    $token = secretToken(); clearRateLimit($data, $rateLimitKey); $data['loginTokens'][] = ['tokenHash' => tokenHash($token), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordId' => $passwordRecord['id'], 'expiresAt' => date('c', min(strtotime($exam['endAt']), time() + LOGIN_TOKEN_SECONDS)), 'usedAt' => null]; auditEvent($data, 'student', $student['id'], 'student_exam_login', 'exam', $exam['id'], ['matricNumber' => $student['matricNumber'], 'course' => $exam['code']]); saveData($data);
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
    $session = ['id' => id(), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordId' => $passwordRecord['id'], 'accessTokenHash' => tokenHash($accessToken), 'startedAt' => date('c', $now), 'endsAt' => date('c', min($now + ((int)$exam['duration'] * 60), strtotime($exam['endAt']))), 'questions' => $questionsForExam, 'answers' => [], 'flagged' => [], 'integrityEvents' => [], 'ipAddress' => clientFingerprint(), 'status' => 'in_progress'];
    $data['sessions'][] = $session; auditEvent($data, 'student', $student['id'], 'exam_started', 'exam_session', $session['id'], ['matricNumber' => $student['matricNumber'], 'course' => $exam['code']]); saveData($data); respond(['session' => sessionPayload($session, $accessToken), 'questions' => publicSessionQuestions($session)]);
}
if ($action === 'session-resume' && $method === 'POST') {
    $input = body(); $session = requireSession($data, $input);
    if ($session['status'] === 'locked') respond(['session' => sessionPayload($session, (string)($input['accessToken'] ?? bearerToken('HTTP_X_SESSION_TOKEN'))), 'questions' => []]);
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
// Browsers cannot observe operating-system screenshots (especially on mobile). These are integrity signals, not proof of misconduct.
if ($action === 'session-integrity' && $method === 'POST') {
    $input = body(); $session = requireSession($data, $input);
    if ($session['status'] !== 'in_progress') respond(['error' => 'Exam session is no longer active.'], 409);
    $event = (string)($input['event'] ?? '');
    if (!in_array($event, INTEGRITY_EVENT_TYPES, true)) respond(['error' => 'Invalid integrity event.'], 422);
    $session['integrityEvents'] ??= []; $sameTypeCount = count(array_filter($session['integrityEvents'], fn($item) => ($item['event'] ?? '') === $event)) + 1;
    $rule = integrityRule($data, $event); $resultingAction = $rule['mode'];
    if ($rule['mode'] === 'lock' || ($rule['mode'] === 'warn' && $sameTypeCount >= $rule['lockAfter'])) $resultingAction = 'locked';
    $flag = ['id' => id(), 'sessionId' => $session['id'], 'flagType' => $event, 'timestamp' => date('c'), 'resultingAction' => $resultingAction, 'ipAddress' => clientFingerprint()];
    if (count($session['integrityEvents']) < 1000) $session['integrityEvents'][] = ['id' => $flag['id'], 'event' => $event, 'at' => $flag['timestamp'], 'resultingAction' => $resultingAction];
    $data['examFlags'][] = $flag;
    if ($resultingAction === 'locked') {
        $session['status'] = 'locked'; $session['lockedAt'] = $flag['timestamp']; $session['lockedReason'] = $event;
        foreach ($data['passwords'] as &$password) if (($password['id'] ?? '') === ($session['passwordId'] ?? '')) $password['usedAt'] = $flag['timestamp'];
        unset($password);
    }
    replaceBy($data['sessions'], 'id', $session['id'], $session);
    $student = findBy($data['students'], 'id', $session['studentId']); $exam = findBy($data['exams'], 'id', $session['examId']);
    auditEvent($data, 'student', (string)$session['studentId'], 'exam_integrity_' . $event, 'exam_session', $session['id'], ['matricNumber' => $student['matricNumber'] ?? '', 'course' => $exam['code'] ?? '', 'resultingAction' => $resultingAction, 'occurrence' => $sameTypeCount]);
    saveData($data); respond(['ok' => true, 'resultingAction' => $resultingAction, 'locked' => $resultingAction === 'locked', 'lockedReason' => $session['lockedReason'] ?? null]);
}
if ($action === 'session-submit' && $method === 'POST') {
    $input = body(); $session = requireSession($data, $input); if ($session['status'] !== 'in_progress') { $existing = findBy($data['results'], 'sessionId', $session['id']); respond(['result' => $existing]); } $session = completeSession($data, $session, strtotime($session['endsAt']) <= time()); saveData($data); respond(['result' => findBy($data['results'], 'sessionId', $session['id'])]);
}
if ($action === 'audit-monitor' && $method === 'GET') {
    auth(true); $now = time(); $items = [];
    foreach ($data['sessions'] as $session) {
        if (!in_array(($session['status'] ?? ''), ['in_progress', 'locked'], true)) continue;
        $student = findBy($data['students'], 'id', $session['studentId']); $exam = findBy($data['exams'], 'id', $session['examId']);
        $events = array_reverse($session['integrityEvents'] ?? []); $last = $events[0] ?? null;
        $status = $session['status'] === 'locked' ? 'Locked' : ($last ? 'Flagged — ' . str_replace('_', ' ', (string)$last['event']) : 'Normal');
        $items[] = ['id' => $session['id'], 'studentName' => $student['fullName'] ?? 'Unknown student', 'matricNumber' => $student['matricNumber'] ?? '', 'course' => $exam['code'] ?? 'Unknown course', 'courseTitle' => $exam['title'] ?? '', 'startedAt' => $session['startedAt'], 'elapsedSeconds' => max(0, $now - strtotime($session['startedAt'])), 'remainingSeconds' => max(0, strtotime($session['endsAt']) - $now), 'ipAddress' => $session['ipAddress'] ?? 'unknown', 'status' => $status, 'locked' => $session['status'] === 'locked', 'events' => $events];
    }
    usort($items, fn($a, $b) => strcmp($a['startedAt'], $b['startedAt'])); respond(['items' => $items, 'serverTime' => date('c')]);
}
if ($action === 'audit-events' && $method === 'GET') {
    auth(true); $items = $data['auditEvents']; $from = trim((string)($_GET['from'] ?? '')); $to = trim((string)($_GET['to'] ?? '')); $actor = strtolower(trim((string)($_GET['actor'] ?? ''))); $type = trim((string)($_GET['type'] ?? '')); $course = strtolower(trim((string)($_GET['course'] ?? '')));
    $items = array_values(array_filter($items, function ($item) use ($from, $to, $actor, $type, $course) {
        $timestamp = (string)($item['timestamp'] ?? ''); $meta = $item['metadata'] ?? []; $actorText = strtolower((string)($item['actorId'] ?? '') . ' ' . (string)($meta['matricNumber'] ?? '') . ' ' . (string)($meta['name'] ?? ''));
        if ($from !== '' && substr($timestamp, 0, 10) < $from) return false;
        if ($to !== '' && substr($timestamp, 0, 10) > $to) return false;
        if ($actor !== '' && !str_contains($actorText, $actor)) return false;
        if ($type !== '' && ($item['actionType'] ?? '') !== $type) return false;
        if ($course !== '' && !str_contains(strtolower((string)($meta['course'] ?? '')), $course)) return false;
        return true;
    }));
    usort($items, fn($a, $b) => strcmp((string)$b['timestamp'], (string)$a['timestamp']));
    $total = count($items); $pageSize = max(5, min(100, (int)($_GET['pageSize'] ?? 20))); $pages = max(1, (int)ceil($total / $pageSize)); $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
    $items = array_slice($items, ($page - 1) * $pageSize, $pageSize);
    foreach ($items as &$item) { $meta = $item['metadata'] ?? []; $item['actor'] = $item['actorType'] === 'student' ? ($meta['matricNumber'] ?? $item['actorId']) : ($item['actorId'] ?: 'System'); $item['target'] = !empty($meta['course']) ? ($meta['course'] . (!empty($meta['text']) ? ' · ' . $meta['text'] : '')) : ($meta['matricNumber'] ?? $meta['name'] ?? $item['targetId']); } unset($item);
    respond(['items' => $items, 'meta' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total, 'pages' => $pages]]);
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
    auth(true); $input = body(); $scale = $input['gradingScale'] ?? ($data['settings']['gradingScale'] ?? DEFAULT_GRADING_SCALE);
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
    $data['settings']['gradingScale'] = $clean;
    $data['settings']['integrityPolicy'] = validateIntegrityPolicy($input['integrityPolicy'] ?? ($data['settings']['integrityPolicy'] ?? DEFAULT_INTEGRITY_POLICY));
    recalculateResults($data); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'settings_updated', 'settings', 'integrity_and_grading', ['changed' => array_keys($input)]); saveData($data); respond(['settings' => $data['settings']]);
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
