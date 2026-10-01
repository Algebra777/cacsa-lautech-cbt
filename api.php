<?php

declare(strict_types=1);

// Never expose PHP warnings, stack traces, or local paths through the JSON API.
// Detailed diagnostics remain in the server error log for administrators.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(static function (Throwable $error): never {
    error_log('CACSA CBT API exception: ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'An unexpected server error occurred. Please try again or contact the administrator.']);
    exit;
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    error_log("CACSA CBT API PHP warning [$severity]: $message in $file:$line");
    return true;
});

header('Content-Type: application/json; charset=utf-8');
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Cross-Origin-Opener-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data: blob:; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; font-src 'self'");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Cache-Control: no-store');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Token, X-Session-Token, X-CSRF-Token');
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
const BACKUP_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups';
const ADMIN_TOKEN_SECONDS = 7200;
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
const DEFAULT_EXAM_SECURITY = [
    'optionShuffleEnabled' => true,
    'fingerprintFlaggingEnabled' => true,
    'concurrentIpBlockEnabled' => true,
    'loginRateLimitEnabled' => true,
    'loginAttemptLimit' => 5,
    'loginAttemptWindowMinutes' => 10,
    'loginLockoutMinutes' => 15,
    'ipAttemptLimit' => 30,
    'ipAttemptWindowMinutes' => 1
];
const DEFAULT_BACKUP_SETTINGS = ['enabled' => true, 'time' => '02:00', 'retentionCount' => 14, 'lastScheduledDate' => '', 'lastBackupAt' => ''];
const NEWSLETTER_SENDER = 'cacsalautech001@gmail.com';
const NEWSLETTER_SENDER_NAME = 'CACSA LAUTECH';
const ADMIN_PERMISSIONS = ['overview', 'students', 'exams', 'questions', 'results', 'audit', 'newsletter', 'settings', 'roles'];
const DEFAULT_ROLES = [
    ['id' => 'superadmin', 'name' => 'Superadmin', 'description' => 'Full system access and administrator management.', 'maxUsers' => 1, 'systemLocked' => true, 'permissions' => ADMIN_PERMISSIONS],
    ['id' => 'academic_coordinator', 'name' => 'Academic Coordinator', 'description' => 'Manages courses, Test/Exam components, students, results, audit monitoring, settings, and newsletters.', 'maxUsers' => 5, 'systemLocked' => false, 'permissions' => ['overview', 'students', 'exams', 'questions', 'results', 'audit', 'newsletter', 'settings']],
    ['id' => 'assistant_academic_coordinator', 'name' => 'Assistant Academic Coordinator', 'description' => 'Supports courses, Test/Exam components, students, results, audit monitoring, settings, and newsletters.', 'maxUsers' => 10, 'systemLocked' => false, 'permissions' => ['overview', 'students', 'exams', 'questions', 'results', 'audit', 'newsletter', 'settings']]
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
            'academicSessions' => [],
            'semesters' => [],
            'courses' => [],
            'exams' => [],
            'questions' => [],
            'passwords' => [],
            'sessions' => [],
            'loginTokens' => [],
            'adminSessions' => [],
            'rateLimits' => [],
            'results' => [],
            // A saved, administrator-confirmed course-result snapshot for a student
            // and academic period. Component submissions remain the source of truth.
            'calculatedResults' => [],
            'auditEvents' => [],
            'examFlags' => [],
            // Dashboard visibility is a presentation preference only. It never removes
            // courses, assessment components, questions, sessions, or result records.
            'dashboardHiddenOutcomes' => [],
            'roles' => DEFAULT_ROLES,
            'adminUsers' => [],
            'pendingAdminRequests' => [],
            'adminProfileOverrides' => [],
            'adminEmailVerifications' => [],
            'adminPasswordResets' => [],
            'adminUserActivity' => [],
            'newsletterSubscribers' => [],
            'newsletters' => [],
            'settings' => ['gradingScale' => DEFAULT_GRADING_SCALE, 'integrityPolicy' => DEFAULT_INTEGRITY_POLICY, 'examSecurity' => DEFAULT_EXAM_SECURITY, 'backup' => DEFAULT_BACKUP_SETTINGS]
        ];
        saveData($data);
        return $data;
    }
    $data = json_decode(file_get_contents(DATA_FILE), true);
    if (!is_array($data)) respond(['error' => 'The data file could not be read. Restore a valid backup before continuing.'], 500);
    $migrated = false; $now = time();
    foreach (['students', 'academicSessions', 'semesters', 'courses', 'exams', 'questions', 'passwords', 'sessions', 'results', 'calculatedResults', 'auditEvents', 'examFlags', 'dashboardHiddenOutcomes', 'roles', 'adminUsers', 'pendingAdminRequests', 'newsletterSubscribers', 'newsletters', 'examLoginFailures', 'examLoginIpAttempts'] as $collection) {
        if (!isset($data[$collection]) || !is_array($data[$collection])) { $data[$collection] = []; $migrated = true; }
    }
    if (!isset($data['loginTokens']) || !is_array($data['loginTokens'])) { $data['loginTokens'] = []; $migrated = true; }
    if (!isset($data['adminSessions']) || !is_array($data['adminSessions'])) { $data['adminSessions'] = []; $migrated = true; }
    if (!isset($data['rateLimits']) || !is_array($data['rateLimits'])) { $data['rateLimits'] = []; $migrated = true; }
    if (!isset($data['adminUserActivity']) || !is_array($data['adminUserActivity'])) { $data['adminUserActivity'] = []; $migrated = true; }
    if (!isset($data['adminProfileOverrides']) || !is_array($data['adminProfileOverrides'])) { $data['adminProfileOverrides'] = []; $migrated = true; }
    if (!isset($data['adminEmailVerifications']) || !is_array($data['adminEmailVerifications'])) { $data['adminEmailVerifications'] = []; $migrated = true; }
    if (!isset($data['adminPasswordResets']) || !is_array($data['adminPasswordResets'])) { $data['adminPasswordResets'] = []; $migrated = true; }
    if (!isset($data['settings']) || !is_array($data['settings'])) { $data['settings'] = ['gradingScale' => DEFAULT_GRADING_SCALE, 'integrityPolicy' => DEFAULT_INTEGRITY_POLICY, 'resultLogoUrl' => 'CACSA%20Logo.jpeg']; $migrated = true; }
    if (!isset($data['settings']['gradingScale']) || !is_array($data['settings']['gradingScale'])) { $data['settings']['gradingScale'] = DEFAULT_GRADING_SCALE; $migrated = true; }
    if (!isset($data['settings']['integrityPolicy']) || !is_array($data['settings']['integrityPolicy'])) { $data['settings']['integrityPolicy'] = DEFAULT_INTEGRITY_POLICY; $migrated = true; }
    if (!isset($data['settings']['examSecurity']) || !is_array($data['settings']['examSecurity'])) { $data['settings']['examSecurity'] = DEFAULT_EXAM_SECURITY; $migrated = true; }
    else foreach (DEFAULT_EXAM_SECURITY as $key => $value) if (!array_key_exists($key, $data['settings']['examSecurity'])) { $data['settings']['examSecurity'][$key] = $value; $migrated = true; }
    if (!isset($data['settings']['resultLogoUrl']) || !is_string($data['settings']['resultLogoUrl'])) { $data['settings']['resultLogoUrl'] = 'CACSA%20Logo.jpeg'; $migrated = true; }
    if (!isset($data['settings']['backup']) || !is_array($data['settings']['backup'])) { $data['settings']['backup'] = DEFAULT_BACKUP_SETTINGS; $migrated = true; }
    else foreach (DEFAULT_BACKUP_SETTINGS as $key => $value) if (!array_key_exists($key, $data['settings']['backup'])) { $data['settings']['backup'][$key] = $value; $migrated = true; }
    if (!$data['roles']) { $data['roles'] = DEFAULT_ROLES; $migrated = true; }
    // Newsletter access is intentionally available to every administrator role.
    foreach ($data['roles'] as &$role) {
        if (!in_array('newsletter', $role['permissions'] ?? [], true)) { $role['permissions'][] = 'newsletter'; $migrated = true; }
        if (in_array(($role['id'] ?? ''), ['academic_coordinator', 'assistant_academic_coordinator'], true)) {
            foreach (['audit', 'settings'] as $permission) if (!in_array($permission, $role['permissions'], true)) { $role['permissions'][] = $permission; $migrated = true; }
            $defaultRole = findBy(DEFAULT_ROLES, 'id', (string)$role['id']);
            if ($defaultRole && ($role['description'] ?? '') !== $defaultRole['description']) { $role['description'] = $defaultRole['description']; $migrated = true; }
        }
    }
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
    foreach (['examLoginFailures', 'examLoginIpAttempts'] as $collection) {
        $before = $data[$collection];
        $data[$collection] = array_filter($before, function ($record) use ($collection, $now) {
            if (!is_array($record)) return false;
            $times = $collection === 'examLoginFailures' ? ($record['failedAt'] ?? []) : ($record['attemptedAt'] ?? []);
            $recent = array_filter((array)$times, fn($value) => is_int($value) && $value > $now - 7200);
            return !empty($recent) || (int)($record['lockedUntil'] ?? 0) > $now;
        });
        if (count($before) !== count($data[$collection])) $migrated = true;
    }
    $livePasswordResets = array_values(array_filter($data['adminPasswordResets'], fn($item) => is_array($item) && !empty($item['codeHash']) && strtotime((string)($item['expiresAt'] ?? '')) > $now));
    if (count($livePasswordResets) !== count($data['adminPasswordResets'])) { $data['adminPasswordResets'] = $livePasswordResets; $migrated = true; }
    foreach ($data['exams'] as &$exam) { if (!array_key_exists('courseUnit', $exam)) { $exam['courseUnit'] = 3; $migrated = true; } if (!array_key_exists('session', $exam)) { $exam['session'] = ''; $migrated = true; } }
    unset($exam);
    if (migrateAcademicStructure($data)) $migrated = true;
    // Course categories drive the student portal's real category filters. Existing
    // courses/components remain valid and are simply uncategorized until edited.
    foreach ($data['courses'] as &$course) if (!array_key_exists('category', $course)) { $course['category'] = ''; $migrated = true; }
    unset($course);
    foreach ($data['exams'] as &$exam) {
        if (array_key_exists('category', $exam)) continue;
        $course = findBy($data['courses'], 'id', (string)($exam['courseId'] ?? ''));
        $exam['category'] = (string)($course['category'] ?? ''); $migrated = true;
    }
    unset($exam);
    foreach ($data['exams'] as &$exam) {
        if (array_key_exists('passThreshold', $exam)) continue;
        // A 50% component threshold is a neutral initial value for existing records.
        // It is informational today and does not recalculate historic grades/results.
        $exam['passThreshold'] = round((float)($exam['maxMark'] ?? 100) * 0.5, 1); $migrated = true;
    }
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

function backupSettings(array $data): array {
    $settings = array_merge(DEFAULT_BACKUP_SETTINGS, is_array($data['settings']['backup'] ?? null) ? $data['settings']['backup'] : []);
    $settings['enabled'] = !empty($settings['enabled']);
    $settings['time'] = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$settings['time']) ? $settings['time'] : '02:00';
    $settings['retentionCount'] = max(1, min(365, (int)$settings['retentionCount']));
    return $settings;
}
function ensureBackupDirectory(): void {
    if (!is_dir(BACKUP_DIRECTORY) && !mkdir(BACKUP_DIRECTORY, 0700, true) && !is_dir(BACKUP_DIRECTORY)) respond(['error' => 'Unable to create the backup directory.'], 500);
}
function backupFilename(string $kind = 'backup'): string {
    $prefix = $kind === 'safety' ? 'cacsa-cbt-safety-before-restore' : 'cacsa-cbt-backup';
    $base = $prefix . '_' . date('Y-m-d_His'); $filename = $base . '.json'; $counter = 1;
    while (file_exists(BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . $filename)) $filename = $base . '-' . $counter++ . '.json';
    return $filename;
}
function backupPayload(array $data): array {
    $payload = $data;
    $payload['_backup'] = ['format' => 'cacsa-cbt-backup', 'version' => 1, 'createdAt' => date('c')];
    // Sign-in secrets and active tokens must never leave the server in a backup export.
    stripBackupPasswordHashes($payload);
    unset($payload['adminSessions'], $payload['loginTokens'], $payload['adminPasswordResets'], $payload['rateLimits']);
    return $payload;
}
function stripBackupPasswordHashes(array &$value): void {
    foreach ($value as $key => &$item) {
        if (in_array((string)$key, ['passwordHash', 'password'], true)) { unset($value[$key]); continue; }
        if (is_array($item)) stripBackupPasswordHashes($item);
    }
    unset($item);
}
function backupPath(string $filename): string {
    if (!preg_match('/^cacsa-cbt-(?:backup|safety-before-restore)_\d{4}-\d{2}-\d{2}_\d{6}(?:-\d+)?\.json$/', $filename)) respond(['error' => 'Invalid backup filename.'], 422);
    $path = BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) respond(['error' => 'Backup file not found.'], 404);
    return $path;
}
function listBackups(): array {
    ensureBackupDirectory(); $items = [];
    foreach (glob(BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . 'cacsa-cbt-*.json') ?: [] as $path) {
        if (!is_file($path)) continue;
        $items[] = ['filename' => basename($path), 'createdAt' => date('c', (int)filemtime($path)), 'size' => (int)filesize($path)];
    }
    usort($items, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    return $items;
}
function applyBackupRetention(array $data): void {
    $retention = backupSettings($data)['retentionCount']; $items = listBackups();
    foreach (array_slice($items, $retention) as $item) @unlink(BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . $item['filename']);
}
function createBackup(array $data, string $kind = 'manual'): array {
    ensureBackupDirectory(); $filename = backupFilename($kind); $json = json_encode(backupPayload($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents(BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . $filename, $json, LOCK_EX) === false) respond(['error' => 'Unable to write the backup file.'], 500);
    applyBackupRetention($data); $path = BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . $filename;
    return ['filename' => $filename, 'createdAt' => date('c'), 'size' => (int)filesize($path)];
}
function validateBackupPayload(mixed $payload): array {
    if (!is_array($payload) || (($payload['_backup']['format'] ?? '') !== 'cacsa-cbt-backup') || (int)($payload['_backup']['version'] ?? 0) !== 1) respond(['error' => 'This file is not a valid CACSA CBT backup.'], 422);
    foreach (['students', 'courses', 'exams', 'questions', 'academicSessions', 'semesters', 'results', 'sessions', 'auditEvents', 'settings', 'adminUsers'] as $key) if (!array_key_exists($key, $payload) || !is_array($payload[$key])) respond(['error' => 'The backup is missing required system data (' . $key . ').'], 422);
    foreach (['students', 'courses', 'exams', 'questions', 'academicSessions', 'semesters', 'results', 'sessions', 'auditEvents', 'adminUsers'] as $collection) {
        if (!array_is_list($payload[$collection]) || count($payload[$collection]) > 50000) respond(['error' => 'The backup contains an invalid or oversized ' . $collection . ' collection.'], 422);
        foreach ($payload[$collection] as $record) if (!is_array($record)) respond(['error' => 'The backup contains an invalid ' . $collection . ' record.'], 422);
    }
    return $payload;
}
function uploadedBackupPayload(): array {
    $upload = $_FILES['backup'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) respond(['error' => 'Choose a valid backup JSON file to continue.'], 422);
    if ((int)($upload['size'] ?? 0) < 2 || (int)($upload['size'] ?? 0) > 25 * 1024 * 1024) respond(['error' => 'Backup files must be between 2 bytes and 25 MB.'], 422);
    $raw = file_get_contents((string)$upload['tmp_name']); $decoded = json_decode((string)$raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) respond(['error' => 'The uploaded file is not valid JSON.'], 422);
    return validateBackupPayload($decoded);
}
function backupSummary(array $payload): array {
    return ['students' => count($payload['students']), 'courses' => count($payload['courses']), 'components' => count($payload['exams']), 'submissions' => count($payload['results']), 'examSessions' => count($payload['sessions']), 'auditEvents' => count($payload['auditEvents'])];
}
function nextBackupRun(array $data): ?string {
    $settings = backupSettings($data); if (!$settings['enabled']) return null;
    $today = date('Y-m-d'); $candidate = strtotime($today . ' ' . $settings['time']);
    if ($candidate <= time()) $candidate = strtotime('+1 day', $candidate);
    return date('c', $candidate);
}
function runScheduledBackup(array &$data): void {
    $settings = backupSettings($data); $today = date('Y-m-d');
    if (!$settings['enabled'] || ($settings['lastScheduledDate'] === $today && listBackups()) || date('H:i') < $settings['time']) return;
    $backup = createBackup($data, 'scheduled'); $data['settings']['backup'] = $settings + ['lastScheduledDate' => $today, 'lastBackupAt' => date('c')];
    $data['settings']['backup']['lastScheduledDate'] = $today; $data['settings']['backup']['lastBackupAt'] = date('c');
    auditEvent($data, 'system', 'backup_scheduler', 'backup_created_scheduled', 'backup', $backup['filename'], ['filename' => $backup['filename']]); saveData($data);
}

function id(): string { return bin2hex(random_bytes(8)); }
function secretToken(): string { return bin2hex(random_bytes(32)); }
function tokenHash(string $token): string { return hash('sha256', $token); }
function passwordHash(string $password): string {
    return defined('PASSWORD_ARGON2ID') ? password_hash($password, PASSWORD_ARGON2ID) : password_hash($password, PASSWORD_DEFAULT);
}
function cookiePath(): string {
    $directory = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    return $directory === '/' ? '/' : rtrim($directory, '/') . '/';
}
function secureCookie(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }
function cookieValue(string $name): string { return is_string($_COOKIE[$name] ?? null) ? (string)$_COOKIE[$name] : ''; }
function setSessionCookie(string $name, string $value, int $expiresAt): void {
    setcookie($name, $value, ['expires' => $expiresAt, 'path' => cookiePath(), 'secure' => secureCookie(), 'httponly' => true, 'samesite' => 'Strict']);
}
function clearSessionCookie(string $name): void {
    setcookie($name, '', ['expires' => time() - 3600, 'path' => cookiePath(), 'secure' => secureCookie(), 'httponly' => true, 'samesite' => 'Strict']);
}
function requireCsrf(): void {
    $cookie = cookieValue('CBT_CSRF'); $header = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? ''); $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (($origin !== '' && strtolower((string)parse_url($origin, PHP_URL_HOST) . ((parse_url($origin, PHP_URL_PORT)) ? ':' . parse_url($origin, PHP_URL_PORT) : '')) !== $host) || $cookie === '' || $header === '' || !hash_equals($cookie, $header)) respond(['error' => 'Security verification failed. Refresh the page and try again.'], 403);
}
function clientFingerprint(): string {
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    // Forwarding headers are spoofable unless the request actually came through a
    // proxy controlled by this installation. Configure CBT_TRUSTED_PROXY_IPS with
    // comma-separated proxy addresses when deploying behind Nginx/Cloudflare.
    $trusted = array_values(array_filter(array_map('trim', explode(',', (string)getenv('CBT_TRUSTED_PROXY_IPS')))));
    if ($remote !== '' && in_array($remote, $trusted, true)) {
        $forwarded = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '');
        $real = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) $remote = $forwarded;
        elseif (filter_var($real, FILTER_VALIDATE_IP)) $remote = $real;
    }
    if ($remote === '::1') return '127.0.0.1';
    if (str_starts_with(strtolower($remote), '::ffff:')) return substr($remote, 7);
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}
function auditEvent(array &$data, string $actorType, string $actorId, string $actionType, string $targetType, string $targetId, array $metadata = []): void {
    if ($actorType === 'admin') { $token = cookieValue('CBT_ADMIN_SESSION'); $session = $token ? findBy($data['adminSessions'] ?? [], 'tokenHash', tokenHash($token)) : null; if (!empty($session['email'])) $actorId = $session['email']; }
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
function validateExamSecurity(mixed $input): array {
    if (!is_array($input)) respond(['error' => 'Exam security settings must be a settings map.'], 422);
    $clean = [];
    foreach (['optionShuffleEnabled', 'fingerprintFlaggingEnabled', 'concurrentIpBlockEnabled', 'loginRateLimitEnabled'] as $key) $clean[$key] = !empty($input[$key]);
    foreach (['loginAttemptLimit' => [3, 20], 'loginAttemptWindowMinutes' => [1, 60], 'loginLockoutMinutes' => [1, 120], 'ipAttemptLimit' => [5, 200], 'ipAttemptWindowMinutes' => [1, 60]] as $key => [$min, $max]) {
        $value = filter_var($input[$key] ?? DEFAULT_EXAM_SECURITY[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) respond(['error' => 'Exam security settings contain an invalid ' . $key . ' value.'], 422);
        $clean[$key] = $value;
    }
    return $clean;
}
function examSecurity(array $data): array { return array_merge(DEFAULT_EXAM_SECURITY, $data['settings']['examSecurity'] ?? []); }
/**
 * This is deliberately a small, browser-provided fingerprint rather than invasive
 * device tracking. It is only used as an investigation signal for a shared device;
 * it must never automatically lock a student because shared computer labs are valid.
 */
function browserDeviceFingerprint(array $input): string {
    $fingerprint = strtolower(trim((string)($input['deviceFingerprint'] ?? '')));
    return preg_match('/^[a-f0-9]{32,128}$/', $fingerprint) ? $fingerprint : '';
}
function sharedDeviceEvents(array $data, array $student, array $exam, string $fingerprint): array {
    if ($fingerprint === '' || empty(examSecurity($data)['fingerprintFlaggingEnabled'])) return [];
    $now = time(); $otherStudentIds = [];
    foreach ($data['sessions'] ?? [] as $session) {
        if (($session['examId'] ?? '') === $exam['id'] && ($session['studentId'] ?? '') !== $student['id'] && ($session['status'] ?? '') === 'in_progress' && strtotime((string)($session['endsAt'] ?? '')) > $now && hash_equals((string)($session['deviceFingerprint'] ?? ''), $fingerprint)) $otherStudentIds[] = $session['studentId'];
    }
    foreach ($data['loginTokens'] ?? [] as $token) {
        if (($token['examId'] ?? '') === $exam['id'] && ($token['studentId'] ?? '') !== $student['id'] && empty($token['usedAt']) && strtotime((string)($token['expiresAt'] ?? '')) > $now && hash_equals((string)($token['deviceFingerprint'] ?? ''), $fingerprint)) $otherStudentIds[] = $token['studentId'];
    }
    $otherStudentIds = array_values(array_unique($otherStudentIds));
    $events = [];
    foreach ($otherStudentIds as $otherStudentId) {
        $otherStudent = findBy($data['students'] ?? [], 'id', $otherStudentId);
        if (!$otherStudent) continue;
        $events[] = ['event' => 'shared_device_suspected', 'at' => date('c'), 'resultingAction' => 'logged', 'otherMatricNumber' => $otherStudent['matricNumber'] ?? 'Unknown', 'deviceFingerprint' => $fingerprint];
    }
    return $events;
}
function activeStudentExamSession(array $data, string $studentId, string $examId): ?array {
    foreach ($data['sessions'] ?? [] as $session) {
        if (($session['studentId'] ?? '') === $studentId && ($session['examId'] ?? '') === $examId && ($session['status'] ?? '') === 'in_progress' && strtotime((string)($session['endsAt'] ?? '')) > time()) return $session;
    }
    return null;
}
function examLoginPairKey(string $matricNumber, string $examId): string { return hash('sha256', strtolower(trim($matricNumber)) . '|' . $examId); }
function examLoginIpKey(): string { return hash('sha256', clientFingerprint()); }
function trimAttemptTimes(array $times, int $windowSeconds): array {
    $cutoff = time() - $windowSeconds;
    return array_values(array_filter($times, fn($value) => is_int($value) && $value > $cutoff));
}
function enforceExamLoginRateLimits(array &$data, array $security, string $matricNumber, string $examId): void {
    if (empty($security['loginRateLimitEnabled'])) return;
    $now = time(); $pairKey = examLoginPairKey($matricNumber, $examId); $pair = $data['examLoginFailures'][$pairKey] ?? ['failedAt' => [], 'lockedUntil' => 0];
    if ((int)($pair['lockedUntil'] ?? 0) > $now) {
        auditEvent($data, 'system', $matricNumber ?: 'unknown', 'login_lockout_triggered', 'exam', $examId, ['scope' => 'matric_exam', 'matricNumber' => $matricNumber, 'retryAfterSeconds' => (int)$pair['lockedUntil'] - $now]);
        saveData($data); respond(['error' => 'Too many failed exam login attempts. Please contact your administrator or try again later.'], 429);
    }
    $ipKey = examLoginIpKey(); $ip = $data['examLoginIpAttempts'][$ipKey] ?? ['attemptedAt' => []];
    $ip['attemptedAt'] = trimAttemptTimes((array)($ip['attemptedAt'] ?? []), ((int)$security['ipAttemptWindowMinutes']) * 60);
    if (count($ip['attemptedAt']) >= (int)$security['ipAttemptLimit']) {
        auditEvent($data, 'system', 'unknown', 'login_lockout_triggered', 'exam', $examId, ['scope' => 'ip', 'matricNumber' => $matricNumber, 'ipAttemptLimit' => (int)$security['ipAttemptLimit']]);
        saveData($data); respond(['error' => 'Too many exam login attempts from this network. Please wait a moment and contact your administrator if this continues.'], 429);
    }
    $ip['attemptedAt'][] = $now; $data['examLoginIpAttempts'][$ipKey] = $ip;
}
function recordExamLoginFailure(array &$data, array $security, string $matricNumber, string $examId, ?array $student, ?array $exam, string $reason): bool {
    $metadata = ['matricNumber' => $matricNumber, 'course' => $exam['code'] ?? '', 'reason' => $reason];
    auditEvent($data, $student ? 'student' : 'system', $student['id'] ?? ($matricNumber ?: 'unknown'), 'login_failed', 'exam', $examId, $metadata);
    if (empty($security['loginRateLimitEnabled'])) return false;
    $pairKey = examLoginPairKey($matricNumber, $examId); $record = $data['examLoginFailures'][$pairKey] ?? ['failedAt' => [], 'lockedUntil' => 0];
    $record['failedAt'] = trimAttemptTimes((array)($record['failedAt'] ?? []), ((int)$security['loginAttemptWindowMinutes']) * 60);
    $record['failedAt'][] = time();
    $locked = count($record['failedAt']) >= (int)$security['loginAttemptLimit'];
    if ($locked) {
        $record['lockedUntil'] = time() + ((int)$security['loginLockoutMinutes']) * 60;
        auditEvent($data, $student ? 'student' : 'system', $student['id'] ?? ($matricNumber ?: 'unknown'), 'login_lockout_triggered', 'exam', $examId, $metadata + ['scope' => 'matric_exam', 'lockedUntil' => date('c', $record['lockedUntil'])]);
    }
    $data['examLoginFailures'][$pairKey] = $record;
    return $locked;
}
function clearExamLoginFailures(array &$data, string $matricNumber, string $examId): void { unset($data['examLoginFailures'][examLoginPairKey($matricNumber, $examId)]); }
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
function enforceGeneralApiRateLimit(array &$data): void {
    $now = time(); $windowSeconds = 60; $maximum = max(60, min(600, (int)(getenv('CBT_API_RATE_LIMIT_PER_MINUTE') ?: 240)));
    $key = hash('sha256', 'general-api|' . clientFingerprint());
    $record = $data['rateLimits'][$key] ?? ['count' => 0, 'resetAt' => $now + $windowSeconds];
    if ((int)($record['resetAt'] ?? 0) <= $now) $record = ['count' => 0, 'resetAt' => $now + $windowSeconds];
    if ((int)$record['count'] >= $maximum) respond(['error' => 'Too many requests from this network. Please wait a moment and try again.'], 429);
    $record['count'] = (int)$record['count'] + 1; $data['rateLimits'][$key] = $record;
}
function clearRateLimit(array &$data, string $key): void { unset($data['rateLimits'][$key]); }
function requiredPermission(): ?string {
    $action = (string)($_GET['action'] ?? '');
    return match ($action) {
        'students', 'students-bulk', 'exam-password', 'student-results', 'student-results-csv' => 'students',
        'exams', 'courses', 'course-components' => 'exams', 'questions', 'questions-bulk' => 'questions', 'results', 'result-review', 'calculate-student-result' => 'results',
        'audit-monitor', 'audit-events', 'exam-session-unlock' => 'audit', 'newsletter-subscribers', 'newsletters' => 'newsletter', 'settings' => 'settings', 'roles', 'admin-users', 'admin-approvals' => 'roles',
        'academic-sessions', 'semesters' => 'settings', 'backups' => 'roles',
        'dashboard', 'dashboard-outcomes' => 'overview', default => null
    };
}
function auth(bool $admin = false): ?array {
    global $data;
    if (!$admin) return null;
    $token = cookieValue('CBT_ADMIN_SESSION');
    $record = $token ? findBy($data['adminSessions'], 'tokenHash', tokenHash($token)) : null;
    $lastSeenAt = strtotime((string)($record['lastSeenAt'] ?? $record['createdAt'] ?? ''));
    if (!$record || strtotime((string)($record['expiresAt'] ?? '')) <= time() || $lastSeenAt === false || $lastSeenAt + ADMIN_TOKEN_SECONDS <= time()) respond(['error' => 'Admin session expired after inactivity. Sign in again.'], 401);
    $account = currentAdminAccount($data, $record);
    if (!$account || empty($account['active'])) respond(['error' => 'This administrator account is no longer active.'], 403);
    $action = (string)($_GET['action'] ?? '');
    if (!empty($account['mustChangePassword']) && !in_array($action, ['admin-account', 'admin-logout'], true)) respond(['error' => 'Change the seeded Superadmin password before accessing the administration workspace.'], 403);
    $roleId = $record['roleId'] ?? 'superadmin';
    if (in_array($action, ['roles', 'admin-users', 'admin-approvals', 'backups', 'backup-restore-validate', 'backup-restore'], true) && $roleId !== 'superadmin') respond(['error' => 'Only the Superadmin can manage roles, administrator accounts, approval requests, and backups.'], 403);
    $permission = requiredPermission(); $currentRole = findBy($data['roles'], 'id', $roleId); $permissions = $currentRole['permissions'] ?? ($record['permissions'] ?? ADMIN_PERMISSIONS);
    if ($permission && !in_array($permission, $permissions, true)) respond(['error' => 'Your role does not have permission to perform this action.'], 403);
    $record['lastSeenAt'] = date('c'); $record['expiresAt'] = date('c', time() + ADMIN_TOKEN_SECONDS);
    replaceBy($data['adminSessions'], 'tokenHash', (string)$record['tokenHash'], $record);
    saveData($data);
    return $record;
}
function adminActorId(): string {
    global $data;
    $token = cookieValue('CBT_ADMIN_SESSION'); $record = $token ? findBy($data['adminSessions'], 'tokenHash', tokenHash($token)) : null;
    return (string)($record['email'] ?? (getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL));
}
function findBy(array $items, string $key, mixed $value): ?array {
    foreach ($items as $item) if (($item[$key] ?? null) === $value) return $item;
    return null;
}
function migrateAcademicStructure(array &$data): bool {
    $changed = false;
    // Approved legacy migration: retain all earlier scores as 100-mark Exam components
    // in the 2026/2027 Harmattan Semester, without inventing Test submissions.
    if ($data['exams'] && !$data['academicSessions']) {
        $data['academicSessions'][] = ['id' => 'session-2026-2027', 'label' => '2026/2027', 'isActive' => true, 'createdAt' => date('c')];
        $changed = true;
    }
    if ($data['exams'] && !$data['semesters']) {
        $data['semesters'][] = ['id' => 'semester-harmattan-2026-2027', 'sessionId' => 'session-2026-2027', 'label' => 'Harmattan Semester', 'isActive' => true, 'startDate' => '2026-09-01', 'endDate' => '2027-02-28', 'createdAt' => date('c')];
        $changed = true;
    }
    foreach ($data['exams'] as &$exam) {
        if (!empty($exam['courseId'])) continue;
        $courseId = 'legacy-course-' . (string)$exam['id'];
        $exam['courseId'] = $courseId;
        $exam['component'] = 'exam';
        $exam['maxMark'] = 100;
        $exam['sessionId'] = 'session-2026-2027';
        $exam['semesterId'] = 'semester-harmattan-2026-2027';
        $exam['legacyExamOnly'] = true;
        $data['courses'][] = ['id' => $courseId, 'code' => $exam['code'], 'title' => $exam['title'], 'description' => $exam['description'] ?? '', 'category' => '', 'courseUnit' => (int)($exam['courseUnit'] ?? 3), 'sessionId' => $exam['sessionId'], 'semesterId' => $exam['semesterId'], 'testMaxMark' => 0, 'examMaxMark' => 100, 'legacyExamOnly' => true, 'createdAt' => $exam['createdAt'] ?? date('c')];
        $changed = true;
    }
    unset($exam);
    foreach ($data['results'] as &$result) {
        $exam = findBy($data['exams'], 'id', (string)($result['examId'] ?? ''));
        if (!$exam) continue;
        if (!isset($result['courseId'])) { $result['courseId'] = $exam['courseId'] ?? ''; $changed = true; }
        if (!isset($result['component'])) { $result['component'] = $exam['component'] ?? 'exam'; $changed = true; }
        if (!isset($result['rawScore'])) { $result['rawScore'] = (float)($result['score'] ?? 0); $changed = true; }
        if (!isset($result['scaledScore'])) { $result['scaledScore'] = (float)($result['score'] ?? 0); $changed = true; }
        if (!isset($result['academicSessionId'])) { $result['academicSessionId'] = $exam['sessionId'] ?? ''; $changed = true; }
        if (!isset($result['academicSemesterId'])) { $result['academicSemesterId'] = $exam['semesterId'] ?? ''; $changed = true; }
    }
    unset($result);
    return $changed;
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
        'passwordManagedByEnvironment' => $environmentPassword !== false && $environmentPassword !== '',
        'mustChangePassword' => ($environmentPassword === false || $environmentPassword === '') && empty($override['passwordHash'])
    ];
}
function currentAdminAccount(array $data, array $session): ?array {
    if (($session['userId'] ?? '') === 'bootstrap-superadmin') return bootstrapAdminAccount($data);
    return findBy($data['adminUsers'], 'id', (string)($session['userId'] ?? ''));
}
function publicAccount(array $account, array $data): array {
    $result = publicAdminUser($account, $data['roles'], $data['adminUserActivity']);
    $role = findBy($data['roles'], 'id', (string)($account['roleId'] ?? ''));
    $result['permissions'] = $role['permissions'] ?? [];
    $result['mustChangePassword'] = !empty($account['mustChangePassword']);
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
    return "CACSA LAUTECH\n\n" . $content . "\n\n--- CACSA LAUTECH\n";
}
function validateAdminUser(array $input, array $data, ?string $currentId = null, bool $requirePassword = true): array {
    $name = requireText($input['name'] ?? null, 'Administrator name'); $email = strtolower(requireText($input['email'] ?? null, 'Administrator email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid administrator email.'], 422);
    foreach ($data['adminUsers'] as $user) if (($user['id'] ?? '') !== $currentId && strcasecmp((string)$user['email'], $email) === 0) respond(['error' => 'That administrator email is already in use.'], 409);
    if (strcasecmp($email, (string)bootstrapAdminAccount($data)['email']) === 0) respond(['error' => 'The bootstrap Superadmin email is reserved.'], 409);
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
    $phoneNumber = requireText($input['phoneNumber'] ?? null, 'Phone number', 32);
    $password = (string)($input['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 422);
    $phoneDigits = preg_replace('/\D+/', '', $phoneNumber);
    if (!preg_match('/^[0-9+()\-\s.]+$/', $phoneNumber) || strlen((string)$phoneDigits) < 7 || strlen((string)$phoneDigits) > 16) respond(['error' => 'Enter a valid phone number.'], 422);
    if (strlen($password) < 8) respond(['error' => 'Use a password with at least 8 characters.'], 422);
    if (strcasecmp($email, (string)bootstrapAdminAccount($data)['email']) === 0) respond(['error' => 'This email is reserved for the Superadmin account.'], 409);
    foreach ($data['adminUsers'] as $user) if (strcasecmp((string)($user['email'] ?? ''), $email) === 0) respond(['error' => 'An administrator account already uses this email.'], 409);
    foreach ($data['pendingAdminRequests'] as $request) if (strcasecmp((string)($request['email'] ?? ''), $email) === 0 && ($request['status'] ?? 'pending') === 'pending') respond(['error' => 'A request for this email is already awaiting approval.'], 409);
    return ['name' => $name, 'email' => $email, 'phoneNumber' => $phoneNumber, 'passwordHash' => password_hash($password, PASSWORD_DEFAULT)];
}
function publicAdminRequest(array $request, array $roles): array {
    unset($request['passwordHash']);
    $role = findBy($roles, 'id', (string)($request['roleId'] ?? ''));
    $request['roleName'] = $role['name'] ?? null;
    return $request;
}
function textLength(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
function requireText(mixed $value, string $label, int $max = 255): string {
    $text = is_string($value) ? trim($value) : '';
    if ($text === '' || textLength($text) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) respond(['error' => "$label is required and must be under $max characters."], 422);
    return $text;
}
function validateStudent(array $input, array $data, ?string $currentId = null): array {
    $name = requireText($input['fullName'] ?? null, 'Full name');
    $email = requireText($input['email'] ?? null, 'Email');
    $matric = requireText($input['matricNumber'] ?? null, 'Matric number', 32);
    $department = requireText($input['department'] ?? null, 'Department');
    $phoneNumber = requireText($input['phoneNumber'] ?? null, 'Phone number', 32);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 422);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9/_-]{3,31}$/', $matric)) respond(['error' => 'Matric number may only contain letters, numbers, slash, underscore, and hyphen.'], 422);
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
    $description = trim((string)($input['description'] ?? ''));
    if (textLength($description) > 4000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $description)) respond(['error' => 'Exam description must be under 4,000 characters.'], 422);
    return ['code' => $code, 'title' => $title, 'description' => $description, 'duration' => $duration, 'questionCount' => $count, 'courseUnit' => $unit, 'session' => trim((string)($input['session'] ?? '')), 'startAt' => date('c', strtotime($start)), 'endAt' => date('c', strtotime($end)), 'status' => $status];
}
function validateAcademicSession(array $input, array $data, ?string $currentId = null): array {
    $label = requireText($input['label'] ?? null, 'Academic session', 50);
    foreach ($data['academicSessions'] as $item) if (($item['id'] ?? '') !== $currentId && strcasecmp((string)$item['label'], $label) === 0) respond(['error' => 'That academic session already exists.'], 409);
    return ['label' => $label, 'isActive' => !empty($input['isActive'])];
}
function validateSemester(array $input, array $data, ?string $currentId = null): array {
    $sessionId = (string)($input['sessionId'] ?? '');
    if (!findBy($data['academicSessions'], 'id', $sessionId)) respond(['error' => 'Choose an existing academic session.'], 422);
    $label = requireText($input['label'] ?? null, 'Semester label', 100);
    if (!in_array($label, ['Harmattan Semester', 'Rain Semester'], true)) respond(['error' => 'Semester must be Harmattan Semester or Rain Semester.'], 422);
    $startDate = (string)($input['startDate'] ?? ''); $endDate = (string)($input['endDate'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || strtotime($endDate) < strtotime($startDate)) respond(['error' => 'Provide a valid semester start and end date.'], 422);
    foreach ($data['semesters'] as $item) if (($item['id'] ?? '') !== $currentId && ($item['sessionId'] ?? '') === $sessionId && strcasecmp((string)$item['label'], $label) === 0) respond(['error' => 'That semester label already exists for this session.'], 409);
    return ['sessionId' => $sessionId, 'label' => $label, 'isActive' => !empty($input['isActive']), 'startDate' => $startDate, 'endDate' => $endDate];
}
function ensureCoursePeriod(array &$data, array &$input): void {
    $sessionLabel = trim((string)($input['sessionLabel'] ?? ''));
    if ($sessionLabel === '' && !empty($input['sessionId'])) $sessionLabel = (string)(findBy($data['academicSessions'], 'id', (string)$input['sessionId'])['label'] ?? '');
    $sessionLabel = requireText($sessionLabel, 'Academic session', 50);
    $session = null;
    foreach ($data['academicSessions'] as $item) if (strcasecmp((string)$item['label'], $sessionLabel) === 0) { $session = $item; break; }
    if (!$session) {
        $session = ['id' => id(), 'label' => $sessionLabel, 'isActive' => !$data['academicSessions'], 'createdAt' => date('c')];
        $data['academicSessions'][] = $session;
        auditEvent($data, 'admin', adminActorId(), 'academic_session_created', 'academic_session', $session['id'], ['label' => $sessionLabel, 'source' => 'course_form']);
    }
    $semesterLabel = trim((string)($input['semesterLabel'] ?? ''));
    if ($semesterLabel === '' && !empty($input['semesterId'])) $semesterLabel = (string)(findBy($data['semesters'], 'id', (string)$input['semesterId'])['label'] ?? '');
    if (!in_array($semesterLabel, ['Harmattan Semester', 'Rain Semester'], true)) respond(['error' => 'Choose either Harmattan Semester or Rain Semester.'], 422);
    $semester = null;
    foreach ($data['semesters'] as $item) if (($item['sessionId'] ?? '') === $session['id'] && strcasecmp((string)$item['label'], $semesterLabel) === 0) { $semester = $item; break; }
    if (!$semester) {
        preg_match('/(\d{4})/', $sessionLabel, $yearMatch); $startYear = (int)($yearMatch[1] ?? date('Y'));
        $dates = $semesterLabel === 'Harmattan Semester'
            ? ['startDate' => sprintf('%04d-09-01', $startYear), 'endDate' => sprintf('%04d-02-28', $startYear + 1)]
            : ['startDate' => sprintf('%04d-03-01', $startYear + 1), 'endDate' => sprintf('%04d-08-31', $startYear + 1)];
        $semester = ['id' => id(), 'sessionId' => $session['id'], 'label' => $semesterLabel, 'isActive' => !array_filter($data['semesters'], fn($item) => ($item['sessionId'] ?? '') === $session['id'] && !empty($item['isActive']))] + $dates + ['createdAt' => date('c')];
        $data['semesters'][] = $semester;
        auditEvent($data, 'admin', adminActorId(), 'semester_created', 'semester', $semester['id'], ['label' => $semesterLabel, 'source' => 'course_form']);
    }
    $input['sessionId'] = $session['id']; $input['semesterId'] = $semester['id'];
}
function componentValues(array $input, string $prefix, float $maxMark): array {
    $duration = filter_var($input[$prefix . 'Duration'] ?? null, FILTER_VALIDATE_INT); $count = filter_var($input[$prefix . 'QuestionCount'] ?? null, FILTER_VALIDATE_INT);
    $passThreshold = filter_var($input[$prefix . 'PassThreshold'] ?? ($maxMark * 0.5), FILTER_VALIDATE_FLOAT);
    $start = (string)($input[$prefix . 'StartAt'] ?? ''); $end = (string)($input[$prefix . 'EndAt'] ?? ''); $status = (string)($input[$prefix . 'Status'] ?? 'draft');
    if ($duration === false || $duration < 1 || $duration > 1440 || $count === false || $count < 1 || $count > 500) respond(['error' => ucfirst($prefix) . ' duration and question count must be valid.'], 422);
    if ($passThreshold === false || $passThreshold < 0 || $passThreshold > $maxMark) respond(['error' => ucfirst($prefix) . ' pass threshold must be between 0 and its maximum mark.'], 422);
    if (strtotime($start) === false || strtotime($end) === false || strtotime($end) <= strtotime($start)) respond(['error' => ucfirst($prefix) . ' end time must be later than its start time.'], 422);
    if (!in_array($status, ['draft', 'published', 'active'], true)) respond(['error' => 'Choose a valid ' . $prefix . ' status.'], 422);
    return ['maxMark' => $maxMark, 'passThreshold' => round((float)$passThreshold, 1), 'duration' => $duration, 'questionCount' => $count, 'startAt' => date('c', strtotime($start)), 'endAt' => date('c', strtotime($end)), 'status' => $status];
}
function validateCourseComponent(array $input, array $course, array $component): array {
    $maxMark = filter_var($input['maxMark'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($maxMark === false || $maxMark < 0 || $maxMark > 100) respond(['error' => 'Maximum mark must be between 0 and 100.'], 422);
    $otherMax = ($component['component'] ?? 'exam') === 'test' ? (float)($course['examMaxMark'] ?? 0) : (float)($course['testMaxMark'] ?? 0);
    if ($maxMark + $otherMax <= 0 || $maxMark + $otherMax > 100) respond(['error' => 'Test and Exam maximum marks must total more than 0 and not exceed 100.'], 422);
    $values = componentValues([
        'componentDuration' => $input['duration'] ?? null,
        'componentPassThreshold' => $input['passThreshold'] ?? null,
        'componentQuestionCount' => $input['questionCount'] ?? null,
        'componentStartAt' => $input['startAt'] ?? null,
        'componentEndAt' => $input['endAt'] ?? null,
        'componentStatus' => $input['status'] ?? null,
    ], 'component', (float)$maxMark);
    return $values;
}
function validateCourse(array $input, array $data, ?string $currentId = null): array {
    $code = requireText($input['code'] ?? null, 'Course code', 40); $title = requireText($input['title'] ?? null, 'Course title');
    $category = requireText($input['category'] ?? null, 'Course category', 100);
    foreach ($data['courses'] as $course) if (($course['id'] ?? '') !== $currentId && strcasecmp((string)$course['code'], $code) === 0 && ($course['sessionId'] ?? '') === (string)($input['sessionId'] ?? '')) respond(['error' => 'That course code already exists in this academic session.'], 409);
    $unit = filter_var($input['courseUnit'] ?? null, FILTER_VALIDATE_INT); if ($unit === false || $unit < 1 || $unit > 6) respond(['error' => 'Course unit must be between 1 and 6.'], 422);
    $sessionId = (string)($input['sessionId'] ?? ''); $semesterId = (string)($input['semesterId'] ?? '');
    $session = findBy($data['academicSessions'], 'id', $sessionId); $semester = findBy($data['semesters'], 'id', $semesterId);
    if (!$session || !$semester || ($semester['sessionId'] ?? '') !== $sessionId) respond(['error' => 'Choose a matching academic session and semester.'], 422);
    $testMax = (float)($input['testMaxMark'] ?? 30); $examMax = (float)($input['examMaxMark'] ?? 70);
    if ($testMax < 0 || $examMax < 0 || $testMax + $examMax <= 0 || $testMax + $examMax > 100) respond(['error' => 'Test and Exam maximum marks must add up to a value greater than 0 and not exceed 100.'], 422);
    return ['code' => $code, 'title' => $title, 'description' => trim((string)($input['description'] ?? '')), 'category' => $category, 'courseUnit' => $unit, 'sessionId' => $sessionId, 'semesterId' => $semesterId, 'testMaxMark' => $testMax, 'examMaxMark' => $examMax, 'test' => componentValues($input, 'test', $testMax), 'exam' => componentValues($input, 'exam', $examMax)];
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
    $topic = trim((string)($input['topic'] ?? '')); $difficulty = trim((string)($input['difficulty'] ?? 'medium'));
    if (textLength($topic) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $topic) || !in_array($difficulty, ['easy', 'medium', 'hard'], true)) respond(['error' => 'Question topic or difficulty is invalid.'], 422);
    return ['examId' => $examId, 'text' => $question, 'options' => $options, 'correctOptions' => $correct, 'type' => $type, 'topic' => $topic, 'difficulty' => $difficulty, 'status' => $status];
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
    $exam['component'] = $exam['component'] ?? 'exam';
    $exam['componentLabel'] = ucfirst((string)$exam['component']);
    $exam['maxMark'] = (float)($exam['maxMark'] ?? 100);
    $exam['courseTitle'] = $exam['courseTitle'] ?? $exam['title'];
    $exam['displayTitle'] = $exam['courseTitle'] . ' --- ' . $exam['componentLabel'];
    return $exam;
}
function sessionQuestionWithOptionOrder(array $question, bool $shuffleOptions): array {
    $originalOptions = array_values($question['options'] ?? []);
    $order = array_keys($originalOptions);
    if ($shuffleOptions && count($order) > 1) shuffle($order);
    $question['originalOptions'] = $originalOptions;
    $question['optionOrder'] = $order;
    $question['options'] = array_values(array_map(fn($originalIndex) => $originalOptions[$originalIndex], $order));
    return $question;
}
function originalAnswerIndexes(array $question, array $displayedAnswerIndexes): array {
    $order = $question['optionOrder'] ?? array_keys($question['options'] ?? []);
    $original = [];
    foreach ($displayedAnswerIndexes as $index) if (array_key_exists((int)$index, $order)) $original[] = (int)$order[(int)$index];
    $original = array_values(array_unique($original)); sort($original);
    return $original;
}
function publicQuestion(array $question): array {
    unset($question['correctOptions'], $question['optionOrder'], $question['originalOptions']);
    return $question;
}
function gradeForScore(float $score, array $scale): array {
    foreach ($scale as $band) if ($score >= (float)$band['minScore'] && $score <= (float)$band['maxScore']) return ['grade' => (string)$band['grade'], 'gradePoint' => (float)$band['gradePoint']];
    return ['grade' => 'F', 'gradePoint' => 0];
}
function courseResultSummaries(array $data, string $studentId, string $sessionId = '', string $semesterId = ''): array {
    $rows = [];
    foreach ($data['courses'] as $course) {
        if ($sessionId !== '' && ($course['sessionId'] ?? '') !== $sessionId) continue;
        if ($semesterId !== '' && ($course['semesterId'] ?? '') !== $semesterId) continue;
        $components = array_values(array_filter($data['exams'], fn($exam) => ($exam['courseId'] ?? '') === ($course['id'] ?? '')));
        $latest = [];
        foreach ($data['results'] as $result) {
            if (($result['studentId'] ?? '') !== $studentId || ($result['courseId'] ?? '') !== ($course['id'] ?? '')) continue;
            $component = (string)($result['component'] ?? 'exam');
            if (!isset($latest[$component]) || strcmp((string)($latest[$component]['submittedAt'] ?? ''), (string)($result['submittedAt'] ?? '')) < 0) $latest[$component] = $result;
        }
        $testMax = (float)($course['testMaxMark'] ?? 0); $examMax = (float)($course['examMaxMark'] ?? 0);
        $test = $latest['test'] ?? null; $exam = $latest['exam'] ?? null;
        $complete = ($testMax <= 0 || $test !== null) && ($examMax <= 0 || $exam !== null) && ($testMax + $examMax > 0);
        if (!$test && !$exam) continue;
        $total = ($test ? (float)($test['scaledScore'] ?? $test['score'] ?? 0) : 0) + ($exam ? (float)($exam['scaledScore'] ?? $exam['score'] ?? 0) : 0);
        $grade = $complete ? gradeForScore($total, $data['settings']['gradingScale']) : ['grade' => 'In progress', 'gradePoint' => 0];
        $unit = max(1, (int)($course['courseUnit'] ?? 3));
        $session = findBy($data['academicSessions'], 'id', (string)($course['sessionId'] ?? ''));
        $semester = findBy($data['semesters'], 'id', (string)($course['semesterId'] ?? ''));
        $rows[] = ['courseId' => $course['id'], 'courseCode' => $course['code'], 'courseTitle' => $course['title'], 'courseUnit' => $unit, 'sessionId' => $course['sessionId'] ?? '', 'semesterId' => $course['semesterId'] ?? '', 'sessionLabel' => $session['label'] ?? 'Unassigned session', 'semesterLabel' => $semester['label'] ?? 'Unassigned semester', 'testMaxMark' => $testMax, 'examMaxMark' => $examMax, 'testScore' => $test ? round((float)($test['scaledScore'] ?? $test['score'] ?? 0), 1) : null, 'examScore' => $exam ? round((float)($exam['scaledScore'] ?? $exam['score'] ?? 0), 1) : null, 'total' => $complete ? round($total, 1) : null, 'grade' => $grade['grade'], 'gradePoint' => $grade['gradePoint'], 'qualityPoints' => $complete ? round($unit * $grade['gradePoint'], 2) : 0, 'status' => $complete ? 'completed' : 'in_progress', 'testResult' => $test, 'examResult' => $exam];
    }
    usort($rows, fn($a, $b) => strnatcasecmp($a['courseCode'], $b['courseCode']));
    return $rows;
}
function recalculateResults(array &$data): void {
    foreach ($data['results'] as &$result) {
        $exam = findBy($data['exams'], 'id', $result['examId']);
        if (!$exam) continue;
        if (empty($result['examSessionId'])) {
            $attempt = findBy($data['sessions'], 'id', (string)($result['sessionId'] ?? ''));
            if (!$attempt) foreach ($data['sessions'] as $candidate) if (($candidate['studentId'] ?? '') === ($result['studentId'] ?? '') && ($candidate['examId'] ?? '') === ($result['examId'] ?? '') && (($candidate['submittedAt'] ?? '') === ($result['submittedAt'] ?? ''))) { $attempt = $candidate; break; }
            if ($attempt) $result['examSessionId'] = $attempt['id'];
        }
        // Repair records written during the academic migration: sessionId is the CBT
        // attempt identifier, while academicSessionId is the teaching-session identifier.
        if (!empty($result['examSessionId']) && findBy($data['academicSessions'], 'id', (string)($result['sessionId'] ?? ''))) $result['sessionId'] = $result['examSessionId'];
        $raw = (float)($result['rawScore'] ?? $result['score'] ?? 0);
        $scaled = round(($raw / 100) * (float)($exam['maxMark'] ?? 100), 1);
        $result['rawScore'] = $raw; $result['scaledScore'] = $scaled; $result['score'] = $scaled;
        $result['courseId'] = $exam['courseId'] ?? ($result['courseId'] ?? ''); $result['component'] = $exam['component'] ?? ($result['component'] ?? 'exam');
        $result['academicSessionId'] = $exam['sessionId'] ?? ($result['academicSessionId'] ?? ''); $result['academicSemesterId'] = $exam['semesterId'] ?? ($result['academicSemesterId'] ?? '');
        $result['courseUnit'] = max(1, (int)($exam['courseUnit'] ?? $result['courseUnit'] ?? 3));
    }
    unset($result);
}
function completeSession(array &$data, array $session, bool $auto): array {
    $questions = $session['questions'] ?? array_values(array_filter($data['questions'], fn($question) => $question['examId'] === $session['examId'] && ($question['status'] ?? 'published') === 'published'));
    $correct = 0; $review = [];
    foreach ($questions as $question) {
        $displayedAnswers = $session['answers'][$question['id']] ?? [];
        $answer = originalAnswerIndexes($question, $displayedAnswers); $expected = $question['correctOptions']; sort($expected);
        $isCorrect = $answer === $expected; if ($isCorrect) $correct++;
        // Reviews deliberately use the original authoring order, never the student's shuffled order.
        $review[] = ['id' => $question['id'], 'text' => $question['text'], 'options' => $question['originalOptions'] ?? $question['options'], 'type' => $question['type'] ?? 'single', 'correctOptions' => $expected, 'answers' => $answer, 'isCorrect' => $isCorrect, 'flagged' => in_array($question['id'], $session['flagged'] ?? [], true)];
    }
    $session['status'] = $auto ? 'auto_submitted' : 'submitted'; $session['submittedAt'] = date('c'); $session['rawScore'] = count($questions) ? round(($correct / count($questions)) * 100, 1) : 0;
    replaceBy($data['sessions'], 'id', $session['id'], $session);
    $examForResult = findBy($data['exams'], 'id', $session['examId']);
    $scaledScore = round(($session['rawScore'] / 100) * (float)($examForResult['maxMark'] ?? 100), 1);
    $session['score'] = $scaledScore;
    replaceBy($data['sessions'], 'id', $session['id'], $session);
    if (!findBy($data['results'], 'examSessionId', $session['id'])) $data['results'][] = ['id' => id(), 'sessionId' => $session['id'], 'examSessionId' => $session['id'], 'studentId' => $session['studentId'], 'examId' => $session['examId'], 'courseId' => $examForResult['courseId'] ?? '', 'component' => $examForResult['component'] ?? 'exam', 'academicSessionId' => $examForResult['sessionId'] ?? '', 'academicSemesterId' => $examForResult['semesterId'] ?? '', 'rawScore' => $session['rawScore'], 'scaledScore' => $scaledScore, 'score' => $scaledScore, 'submittedAt' => $session['submittedAt'], 'status' => $session['status'], 'questions' => $review, 'integrityEvents' => $session['integrityEvents'] ?? []];
    foreach ($data['passwords'] as &$record) if (($record['id'] ?? '') === ($session['passwordId'] ?? '')) $record['usedAt'] = $session['submittedAt'];
    unset($record);
    $student = findBy($data['students'], 'id', $session['studentId']); $exam = findBy($data['exams'], 'id', $session['examId']);
    auditEvent($data, 'student', (string)$session['studentId'], $auto ? 'exam_submitted_auto' : 'exam_submitted_manual', 'exam_session', (string)$session['id'], ['matricNumber' => $student['matricNumber'] ?? '', 'course' => $exam['code'] ?? '', 'component' => $exam['component'] ?? 'exam', 'rawScore' => $session['rawScore'], 'scaledScore' => $session['score']]);
    recalculateResults($data);
    return $session;
}
function expireSessions(array &$data): void { foreach ($data['sessions'] as $session) if (in_array(($session['status'] ?? ''), ['in_progress', 'locked'], true) && strtotime($session['endsAt']) <= time()) completeSession($data, $session, true); }
function requireSession(array $data, array $input): array {
    $session = findBy($data['sessions'], 'id', (string)($input['sessionId'] ?? ''));
    $token = cookieValue('CBT_EXAM_SESSION');
    if (!$session || !$token || !hash_equals((string)($session['accessTokenHash'] ?? ''), tokenHash($token))) respond(['error' => 'Exam session authentication required.'], 401);
    return $session;
}
function sessionPayload(array $session): array {
    return ['id' => $session['id'], 'startedAt' => $session['startedAt'], 'endsAt' => $session['endsAt'], 'answers' => $session['answers'], 'flagged' => $session['flagged'], 'status' => $session['status'], 'lockedReason' => $session['lockedReason'] ?? null, 'integrityEvents' => $session['integrityEvents'] ?? []];
}
function publicSessionQuestions(array $session): array { return array_map('publicQuestion', $session['questions'] ?? []); }
function resultCalculationSourceHash(array $allRows, array $periodRows, array $gradingScale): string {
    $serialize = static function (array $row): array {
        $component = static fn(?array $result): array => $result ? [
            'id' => $result['id'] ?? '', 'submittedAt' => $result['submittedAt'] ?? '',
            'scaledScore' => $result['scaledScore'] ?? $result['score'] ?? 0,
            'rawScore' => $result['rawScore'] ?? 0, 'status' => $result['status'] ?? ''
        ] : [];
        return [
            'courseId' => $row['courseId'] ?? '', 'unit' => $row['courseUnit'] ?? 0,
            'test' => $component($row['testResult'] ?? null), 'exam' => $component($row['examResult'] ?? null)
        ];
    };
    return hash('sha256', json_encode([
        'all' => array_map($serialize, $allRows), 'period' => array_map($serialize, $periodRows), 'scale' => $gradingScale
    ], JSON_UNESCAPED_SLASHES));
}
function savedCalculation(array $data, string $studentId, string $sessionId, string $semesterId, string $sourceHash): ?array {
    $saved = null;
    foreach ($data['calculatedResults'] as $item) if (($item['studentId'] ?? '') === $studentId && ($item['sessionId'] ?? '') === $sessionId && ($item['semesterId'] ?? '') === $semesterId) { $saved = $item; break; }
    return $saved && hash_equals((string)($saved['sourceHash'] ?? ''), $sourceHash) ? $saved : null;
}
function calculationSnapshotItems(array $items): array {
    return array_values(array_map(static fn(array $item): array => [
        'courseId' => $item['courseId'], 'courseCode' => $item['courseCode'], 'courseTitle' => $item['courseTitle'], 'courseUnit' => $item['courseUnit'],
        'testScore' => $item['testScore'], 'examScore' => $item['examScore'], 'total' => $item['total'], 'grade' => $item['grade'],
        'gradePoint' => $item['gradePoint'], 'qualityPoints' => $item['qualityPoints'], 'status' => $item['status']
    ], array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === 'completed')));
}
function studentReport(array $data, string $studentId, string $sessionId = '', string $semesterId = ''): array {
    $student = findBy($data['students'], 'id', $studentId);
    if (!$student) respond(['error' => 'Student not found.'], 404);
    $all = courseResultSummaries($data, $studentId);
    $availablePeriods = [];
    foreach ($all as $row) {
        $key = $row['sessionId'] . '|' . $row['semesterId'];
        $latestSubmittedAt = max((string)($row['testResult']['submittedAt'] ?? ''), (string)($row['examResult']['submittedAt'] ?? ''));
        if (!isset($availablePeriods[$key]) || strcmp($latestSubmittedAt, (string)($availablePeriods[$key]['latestSubmittedAt'] ?? '')) > 0) $availablePeriods[$key] = ['sessionId' => $row['sessionId'], 'semesterId' => $row['semesterId'], 'sessionLabel' => $row['sessionLabel'], 'semesterLabel' => $row['semesterLabel'], 'latestSubmittedAt' => $latestSubmittedAt];
    }
    // A student may have several completed periods. Do not blend them into a misleading
    // “semester” sheet: the UI asks the administrator to choose one first.
    usort($availablePeriods, fn($a, $b) => strcmp((string)$b['latestSubmittedAt'], (string)$a['latestSubmittedAt']));
    if (($sessionId === '' || $semesterId === '') && $availablePeriods) { $sessionId = $availablePeriods[0]['sessionId']; $semesterId = $availablePeriods[0]['semesterId']; }
    $items = ($sessionId === '' || $semesterId === '') ? [] : array_values(array_filter($all, fn($row) => $row['sessionId'] === $sessionId && $row['semesterId'] === $semesterId));
    $periodUnits = 0; $periodQuality = 0; $totalUnits = 0; $totalQuality = 0;
    foreach ($all as $row) if ($row['status'] === 'completed') { $totalUnits += $row['courseUnit']; $totalQuality += $row['qualityPoints']; }
    foreach ($items as $row) if ($row['status'] === 'completed') { $periodUnits += $row['courseUnit']; $periodQuality += $row['qualityPoints']; }
    $selectedSession = $sessionId !== '' ? findBy($data['academicSessions'], 'id', $sessionId) : null;
    $selectedSemester = $semesterId !== '' ? findBy($data['semesters'], 'id', $semesterId) : null;
    $semesterGpa = $periodUnits ? round($periodQuality / $periodUnits, 2) : 0;
    $cgpa = $totalUnits ? round($totalQuality / $totalUnits, 2) : 0;
    $sourceHash = ($sessionId !== '' && $semesterId !== '') ? resultCalculationSourceHash($all, $items, $data['settings']['gradingScale']) : '';
    $calculation = $sourceHash !== '' ? savedCalculation($data, $studentId, $sessionId, $semesterId, $sourceHash) : null;
    return ['student' => $student, 'items' => $items, 'availablePeriods' => array_values($availablePeriods), 'selectedSession' => $selectedSession, 'selectedSemester' => $selectedSemester, 'semesterGpa' => $semesterGpa, 'cgpa' => $cgpa, 'calculated' => $calculation !== null, 'calculatedResult' => $calculation, 'sourceHash' => $sourceHash];
}
function safeCsvCell(mixed $value): string {
    $value = (string)$value;
    if (preg_match('/^[=+\-@]/', $value)) $value = "'" . $value;
    return $value;
}

$dataLock = fopen(DATA_LOCK_FILE, 'c');
if ($dataLock === false || !flock($dataLock, LOCK_EX)) respond(['error' => 'Unable to lock data storage.'], 500);
$data = loadData();
runScheduledBackup($data);
$beforeExpiry = json_encode($data); expireSessions($data); recalculateResults($data); if ($beforeExpiry !== json_encode($data)) saveData($data);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
enforceGeneralApiRateLimit($data);

if ($action === 'health') respond(['ok' => true]);
if ($action === 'auth-csrf' && $method === 'GET') {
    $token = secretToken(); setSessionCookie('CBT_CSRF', $token, time() + ADMIN_TOKEN_SECONDS);
    respond(['csrfToken' => $token]);
}
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) requireCsrf();
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
if ($action === 'admin-password-reset-request' && $method === 'POST') {
    $input = body();
    $rateLimitKey = enforceRateLimit($data, 'admin-password-reset-request', 5, 3600);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 422);

    // The bootstrap Superadmin cannot be suspended after a self-service reset: doing so
    // would leave the institution with nobody able to reactivate the account. Its password
    // must instead be recovered through the protected deployment/environment process.
    $bootstrap = bootstrapAdminAccount($data);
    if (hash_equals(strtolower((string)$bootstrap['email']), $email)) respond(['error' => 'The Superadmin account uses the protected recovery process. Contact the system owner for Superadmin recovery.'], 409);

    $account = findBy($data['adminUsers'], 'email', $email);
    if (!$account || empty($account['active'])) respond(['error' => 'No active administrator account was found for that email address.'], 404);
    if (empty($account['verified'])) respond(['error' => 'This administrator account must verify its email before resetting its password.'], 403);
    if (newsletterSmtpConfig() === null) respond(['error' => 'Email delivery is not configured. Contact the Superadmin.'], 503);

    $code = (string)random_int(100000, 999999);
    $delivery = sendNewsletterMessage(
        $account['email'],
        'CACSA LAUTECH password reset code',
        "Hello " . $account['name'] . ",\n\nYour CACSA LAUTECH administrator password reset code is: " . $code . "\n\nThis six-digit code expires in 2 minutes. If you did not request a password reset, contact your Superadmin immediately.",
        false
    );
    if (!$delivery['ok']) respond(['error' => 'The reset email was not accepted by the mail server. Please try again or contact the Superadmin.'], 503);

    $data['adminPasswordResets'] = array_values(array_filter($data['adminPasswordResets'], fn($item) => ($item['userId'] ?? '') !== $account['id']));
    $data['adminPasswordResets'][] = ['id' => id(), 'userId' => $account['id'], 'email' => $account['email'], 'codeHash' => password_hash($code, PASSWORD_DEFAULT), 'expiresAt' => date('c', time() + 120), 'attempts' => 0, 'createdAt' => date('c')];
    clearRateLimit($data, $rateLimitKey);
    auditEvent($data, 'system', $account['email'], 'administrator_password_reset_requested', 'administrator', $account['id'], ['email' => $account['email']]);
    saveData($data);
    respond(['ok' => true, 'expiresAt' => date('c', time() + 120)]);
}
if ($action === 'admin-password-reset-confirm' && $method === 'POST') {
    $input = body();
    $rateLimitKey = enforceRateLimit($data, 'admin-password-reset-confirm', 10, 900);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $code = trim((string)($input['code'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) respond(['error' => 'Enter the email address and six-digit code exactly as received.'], 422);
    if (strlen($password) < 8) respond(['error' => 'Use a new password with at least 8 characters.'], 422);

    $account = findBy($data['adminUsers'], 'email', $email);
    $reset = findBy($data['adminPasswordResets'], 'email', $email);
    if (!$account || !$reset || empty($account['active']) || strtotime((string)($reset['expiresAt'] ?? '')) <= time()) respond(['error' => 'This reset code is invalid or has expired. Request a new code.'], 422);
    if (!password_verify($code, (string)$reset['codeHash'])) {
        $reset['attempts'] = (int)($reset['attempts'] ?? 0) + 1;
        if ($reset['attempts'] >= 5) $data['adminPasswordResets'] = array_values(array_filter($data['adminPasswordResets'], fn($item) => ($item['id'] ?? '') !== $reset['id']));
        else replaceBy($data['adminPasswordResets'], 'id', $reset['id'], $reset);
        saveData($data);
        respond(['error' => 'The six-digit code is incorrect.'], 422);
    }

    $account['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
    $account['active'] = false;
    $account['suspendedAt'] = date('c');
    $account['suspensionReason'] = 'password_reset_pending_superadmin_reactivation';
    replaceBy($data['adminUsers'], 'id', $account['id'], $account);
    $data['adminPasswordResets'] = array_values(array_filter($data['adminPasswordResets'], fn($item) => ($item['id'] ?? '') !== $reset['id']));
    $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($item) => ($item['userId'] ?? '') !== $account['id']));
    clearRateLimit($data, $rateLimitKey);
    auditEvent($data, 'system', $account['email'], 'administrator_password_reset_completed', 'administrator', $account['id'], ['accountStatus' => 'suspended_pending_superadmin_reactivation']);
    saveData($data);
    respond(['ok' => true]);
}
if ($action === 'admin-login' && $method === 'POST') {
    $input = body();
    $rateLimitKey = enforceRateLimit($data, 'admin-login', 10, 900);
    $bootstrapAccount = bootstrapAdminAccount($data);
    $adminEmail = $bootstrapAccount['email']; $adminHash = $bootstrapAccount['passwordHash'];
    $email = strtolower(trim((string)($input['email'] ?? ''))); $adminUser = findBy($data['adminUsers'], 'email', $email);
    $isBootstrap = hash_equals(strtolower($adminEmail), $email) && password_verify((string)($input['password'] ?? ''), $adminHash);
    $isUser = $adminUser && password_verify((string)($input['password'] ?? ''), (string)$adminUser['passwordHash']);
    if (!$isBootstrap && !$isUser) {
        auditEvent($data, 'system', $email !== '' ? $email : 'unknown', 'admin_login_failed', 'administrator_login', $email !== '' ? $email : 'unknown');
        saveData($data); respond(['error' => 'Invalid admin credentials.'], 401);
    }
    $user = $isUser ? $adminUser : $bootstrapAccount;
    if (empty($user['active'])) {
        auditEvent($data, 'system', $email, 'admin_login_failed', 'administrator_login', $email, ['reason' => 'account_inactive']);
        saveData($data); respond(['error' => 'Invalid admin credentials.'], 401);
    }
    $role = findBy($data['roles'], 'id', $user['roleId']) ?? findBy(DEFAULT_ROLES, 'id', 'superadmin');
    $token = secretToken(); $expiresAt = date('c', time() + ADMIN_TOKEN_SECONDS);
    clearRateLimit($data, $rateLimitKey);
    $data['adminUserActivity'][$user['id']] = ['lastLoginAt' => date('c')];
    $data['adminSessions'][] = ['tokenHash' => tokenHash($token), 'expiresAt' => $expiresAt, 'createdAt' => date('c'), 'lastSeenAt' => date('c'), 'userId' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'roleId' => $role['id'], 'permissions' => $role['permissions']];
    auditEvent($data, 'admin', $user['email'], 'admin_login', 'admin_session', tokenHash($token), ['email' => $user['email'], 'role' => $role['name']]);
    saveData($data); setSessionCookie('CBT_ADMIN_SESSION', $token, strtotime($expiresAt));
    respond(['expiresAt' => $expiresAt, 'user' => ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'roleId' => $role['id'], 'role' => $role['name'], 'permissions' => $role['permissions'], 'mustChangePassword' => !empty($user['mustChangePassword'])]]);
}
if ($action === 'admin-logout' && $method === 'POST') {
    $record = auth(true); $token = cookieValue('CBT_ADMIN_SESSION');
    $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($item) => $item['tokenHash'] !== tokenHash($token)));
    auditEvent($data, 'admin', (string)($record['email'] ?? (getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL)), 'admin_logout', 'admin_session', tokenHash($token));
    saveData($data); clearSessionCookie('CBT_ADMIN_SESSION'); respond(['ok' => true]);
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
    $input = body(); $operation = (string)($input['operation'] ?? ''); $token = cookieValue('CBT_ADMIN_SESSION');
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
    if ($method === 'DELETE') {
        if (($_GET['permanently'] ?? '') === 'true') {
            $role = findBy($data['roles'], 'id', (string)($user['roleId'] ?? ''));
            if (($user['roleId'] ?? '') === 'superadmin' || !empty($role['systemLocked'])) respond(['error' => 'The Superadmin account is system-protected and cannot be deleted.'], 403);
            $data['adminUsers'] = array_values(array_filter($data['adminUsers'], fn($item) => ($item['id'] ?? '') !== $userId));
            $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($session) => ($session['userId'] ?? '') !== $userId));
            unset($data['adminUserActivity'][$userId]);
            auditEvent($data, 'admin', adminActorId(), 'administrator_deleted', 'administrator', $userId, ['email' => $user['email'], 'role' => $role['name'] ?? 'Unknown']); saveData($data); respond(['ok' => true]);
        }
        $user['active'] = false; replaceBy($data['adminUsers'], 'id', $userId, $user); auditEvent($data, 'admin', adminActorId(), 'administrator_disabled', 'administrator', $userId, ['email' => $user['email']]); saveData($data); respond(['item' => publicAdminUser($user, $data['roles'], $data['adminUserActivity'])]);
    }
    $input = body(); $values = validateAdminUser(array_merge($user, $input), $data, $userId, false); $newRole = findBy($data['roles'], 'id', $values['roleId']);
    if ($newRole['id'] !== $user['roleId']) { $count = count(array_filter($data['adminUsers'], fn($item) => ($item['roleId'] ?? '') === $newRole['id'] && !empty($item['active']))); if ($count >= (int)$newRole['maxUsers']) respond(['error' => 'This role has reached its maximum number of users.'], 409); }
    $user = array_merge($user, $values);
    if (array_key_exists('active', $input)) {
        $user['active'] = (bool)$input['active'];
        if ($user['active']) { unset($user['suspendedAt'], $user['suspensionReason']); }
    }
    replaceBy($data['adminUsers'], 'id', $userId, $user);
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
    $account = ['id' => id(), 'name' => $request['name'], 'email' => $request['email'], 'phoneNumber' => $request['phoneNumber'] ?? '', 'passwordHash' => $request['passwordHash'], 'roleId' => $role['id'], 'active' => true, 'verified' => true, 'createdAt' => date('c'), 'approvedAt' => date('c'), 'approvedBy' => adminActorId()];
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

if ($action === 'academic-sessions' && $method === 'GET') { auth(true); respond(['items' => $data['academicSessions']]); }
if ($action === 'academic-sessions' && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    auth(true); $input = body(); $sessionId = (string)($_GET['id'] ?? '');
    if ($method === 'POST') {
        $item = ['id' => id()] + validateAcademicSession($input, $data) + ['createdAt' => date('c')];
        if ($item['isActive']) foreach ($data['academicSessions'] as &$session) $session['isActive'] = false; unset($session);
        $data['academicSessions'][] = $item; auditEvent($data, 'admin', adminActorId(), 'academic_session_created', 'academic_session', $item['id'], ['label' => $item['label']]); saveData($data); respond(['item' => $item], 201);
    }
    $item = findBy($data['academicSessions'], 'id', $sessionId); if (!$item) respond(['error' => 'Academic session not found.'], 404);
    if ($method === 'DELETE') {
        if (array_filter($data['courses'], fn($course) => ($course['sessionId'] ?? '') === $sessionId) || array_filter($data['semesters'], fn($semester) => ($semester['sessionId'] ?? '') === $sessionId)) respond(['error' => 'This session has courses or semesters and cannot be deleted.'], 409);
        $data['academicSessions'] = array_values(array_filter($data['academicSessions'], fn($session) => ($session['id'] ?? '') !== $sessionId)); auditEvent($data, 'admin', adminActorId(), 'academic_session_deleted', 'academic_session', $sessionId); saveData($data); respond(['ok' => true]);
    }
    $item = array_merge($item, validateAcademicSession(array_merge($item, $input), $data, $sessionId)); if ($item['isActive']) foreach ($data['academicSessions'] as &$session) $session['isActive'] = false; unset($session);
    replaceBy($data['academicSessions'], 'id', $sessionId, $item); auditEvent($data, 'admin', adminActorId(), 'academic_session_updated', 'academic_session', $sessionId, ['label' => $item['label']]); saveData($data); respond(['item' => $item]);
}
if ($action === 'semesters' && $method === 'GET') { auth(true); respond(['items' => $data['semesters']]); }
if ($action === 'semesters' && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    auth(true); $input = body(); $semesterId = (string)($_GET['id'] ?? '');
    if ($method === 'POST') {
        $item = ['id' => id()] + validateSemester($input, $data) + ['createdAt' => date('c')];
        if ($item['isActive']) foreach ($data['semesters'] as &$semester) if (($semester['sessionId'] ?? '') === $item['sessionId']) $semester['isActive'] = false; unset($semester);
        $data['semesters'][] = $item; auditEvent($data, 'admin', adminActorId(), 'semester_created', 'semester', $item['id'], ['label' => $item['label']]); saveData($data); respond(['item' => $item], 201);
    }
    $item = findBy($data['semesters'], 'id', $semesterId); if (!$item) respond(['error' => 'Semester not found.'], 404);
    if ($method === 'DELETE') {
        if (array_filter($data['courses'], fn($course) => ($course['semesterId'] ?? '') === $semesterId)) respond(['error' => 'This semester has courses and cannot be deleted.'], 409);
        $data['semesters'] = array_values(array_filter($data['semesters'], fn($semester) => ($semester['id'] ?? '') !== $semesterId)); auditEvent($data, 'admin', adminActorId(), 'semester_deleted', 'semester', $semesterId); saveData($data); respond(['ok' => true]);
    }
    $item = array_merge($item, validateSemester(array_merge($item, $input), $data, $semesterId)); if ($item['isActive']) foreach ($data['semesters'] as &$semester) if (($semester['sessionId'] ?? '') === $item['sessionId']) $semester['isActive'] = false; unset($semester);
    replaceBy($data['semesters'], 'id', $semesterId, $item); auditEvent($data, 'admin', adminActorId(), 'semester_updated', 'semester', $semesterId, ['label' => $item['label']]); saveData($data); respond(['item' => $item]);
}
if ($action === 'course-components' && in_array($method, ['PUT', 'DELETE'], true)) {
    auth(true); $componentId = (string)($_GET['id'] ?? ''); $component = findBy($data['exams'], 'id', $componentId);
    if (!$component || empty($component['courseId'])) respond(['error' => 'Course component not found.'], 404);
    $course = findBy($data['courses'], 'id', (string)$component['courseId']); if (!$course) respond(['error' => 'The parent course could not be found.'], 404);
    $kind = (string)($component['component'] ?? 'exam');
    if ($method === 'DELETE') {
        if (array_filter($data['results'], fn($result) => ($result['examId'] ?? '') === $componentId)) respond(['error' => 'This component has submitted results and cannot be deleted.'], 409);
        $remainingMax = $kind === 'test' ? (float)($course['examMaxMark'] ?? 0) : (float)($course['testMaxMark'] ?? 0);
        if ($remainingMax <= 0) respond(['error' => 'A course must retain at least one scored component.'], 422);
        $data['questions'] = array_values(array_filter($data['questions'], fn($question) => ($question['examId'] ?? '') !== $componentId));
        $data['exams'] = array_values(array_filter($data['exams'], fn($exam) => ($exam['id'] ?? '') !== $componentId));
        if ($kind === 'test') $course['testMaxMark'] = 0; else $course['examMaxMark'] = 0;
        replaceBy($data['courses'], 'id', $course['id'], $course);
        auditEvent($data, 'admin', adminActorId(), 'course_component_deleted', 'course_component', $componentId, ['course' => $course['code'], 'component' => $kind]); saveData($data); respond(['ok' => true]);
    }
    $values = validateCourseComponent(body(), $course, $component);
    $component = array_merge($component, $values); replaceBy($data['exams'], 'id', $componentId, $component);
    if ($kind === 'test') $course['testMaxMark'] = $values['maxMark']; else $course['examMaxMark'] = $values['maxMark'];
    replaceBy($data['courses'], 'id', $course['id'], $course); recalculateResults($data);
    auditEvent($data, 'admin', adminActorId(), 'course_component_updated', 'course_component', $componentId, ['course' => $course['code'], 'component' => $kind, 'status' => $component['status'], 'maxMark' => $component['maxMark']]); saveData($data); respond(['item' => publicExam($component)]);
}

if ($action === 'courses' && $method === 'GET') {
    auth(true); $items = $data['courses'];
    foreach ($items as &$course) {
        $session = findBy($data['academicSessions'], 'id', (string)($course['sessionId'] ?? '')); $semester = findBy($data['semesters'], 'id', (string)($course['semesterId'] ?? ''));
        $course['sessionLabel'] = $session['label'] ?? ''; $course['semesterLabel'] = $semester['label'] ?? '';
        $course['components'] = array_values(array_map('publicExam', array_filter($data['exams'], fn($exam) => ($exam['courseId'] ?? '') === ($course['id'] ?? ''))));
    }
    unset($course); respond(listItems($items, ['code', 'title', 'sessionLabel', 'semesterLabel'], ['code', 'title', 'courseUnit']));
}
if ($action === 'courses' && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    auth(true); $input = body(); $courseId = (string)($_GET['id'] ?? '');
    if ($method === 'POST') {
        ensureCoursePeriod($data, $input);
        $values = validateCourse($input, $data); $course = ['id' => id()] + array_diff_key($values, ['test' => true, 'exam' => true]) + ['createdAt' => date('c')];
        $components = [];
        foreach (['test', 'exam'] as $component) {
            $valuesForComponent = $values[$component]; $components[] = ['id' => id(), 'courseId' => $course['id'], 'component' => $component, 'code' => $course['code'], 'title' => $course['title'], 'courseTitle' => $course['title'], 'description' => $course['description'], 'category' => $course['category'], 'courseUnit' => $course['courseUnit'], 'sessionId' => $course['sessionId'], 'semesterId' => $course['semesterId']] + $valuesForComponent + ['createdAt' => date('c')];
        }
        $data['courses'][] = $course; array_push($data['exams'], ...$components); auditEvent($data, 'admin', adminActorId(), 'course_created', 'course', $course['id'], ['course' => $course['code'], 'testMaxMark' => $course['testMaxMark'], 'examMaxMark' => $course['examMaxMark']]); saveData($data); respond(['item' => $course, 'components' => array_map('publicExam', $components)], 201);
    }
    $course = findBy($data['courses'], 'id', $courseId); if (!$course) respond(['error' => 'Course not found.'], 404);
    if ($method === 'DELETE') {
        $components = array_values(array_filter($data['exams'], fn($exam) => ($exam['courseId'] ?? '') === $courseId));
        foreach ($components as $component) if (array_filter($data['results'], fn($result) => ($result['examId'] ?? '') === $component['id'])) respond(['error' => 'This course has result history and cannot be deleted.'], 409);
        $componentIds = array_column($components, 'id'); $data['questions'] = array_values(array_filter($data['questions'], fn($question) => !in_array($question['examId'] ?? '', $componentIds, true))); $data['exams'] = array_values(array_filter($data['exams'], fn($exam) => ($exam['courseId'] ?? '') !== $courseId)); $data['courses'] = array_values(array_filter($data['courses'], fn($item) => ($item['id'] ?? '') !== $courseId)); auditEvent($data, 'admin', adminActorId(), 'course_deleted', 'course', $courseId, ['course' => $course['code']]); saveData($data); respond(['ok' => true]);
    }
    $input = array_merge($course, $input); ensureCoursePeriod($data, $input);
    $values = validateCourse($input, $data, $courseId); $course = array_merge($course, array_diff_key($values, ['test' => true, 'exam' => true])); replaceBy($data['courses'], 'id', $courseId, $course);
    foreach (['test', 'exam'] as $component) {
        $existing = array_values(array_filter($data['exams'], fn($exam) => ($exam['courseId'] ?? '') === $courseId && ($exam['component'] ?? 'exam') === $component)); $exam = $existing[0] ?? null;
        $componentData = ['courseId' => $courseId, 'component' => $component, 'code' => $course['code'], 'title' => $course['title'], 'courseTitle' => $course['title'], 'description' => $course['description'], 'category' => $course['category'], 'courseUnit' => $course['courseUnit'], 'sessionId' => $course['sessionId'], 'semesterId' => $course['semesterId']] + $values[$component];
        if ($exam) replaceBy($data['exams'], 'id', $exam['id'], array_merge($exam, $componentData)); else $data['exams'][] = ['id' => id()] + $componentData + ['createdAt' => date('c')];
    }
    recalculateResults($data); auditEvent($data, 'admin', adminActorId(), 'course_updated', 'course', $courseId, ['course' => $course['code'], 'testMaxMark' => $course['testMaxMark'], 'examMaxMark' => $course['examMaxMark']]); saveData($data); respond(['item' => $course]);
}

if ($action === 'exams' && $method === 'GET') {
    $items = array_map('publicExam', $data['exams']);
    if (($_GET['active'] ?? '') === 'true') {
        $items = array_values(array_filter($items, fn($exam) => $exam['active']));
        foreach ($items as &$item) {
            $publishedQuestions = array_values(array_filter($data['questions'], fn($question) => ($question['examId'] ?? '') === $item['id'] && ($question['status'] ?? 'published') === 'published'));
            $item['availableQuestionCount'] = count($publishedQuestions);
            $item['questionMode'] = !$publishedQuestions ? 'Questions pending' : (array_filter($publishedQuestions, fn($question) => ($question['type'] ?? 'single') === 'multiple') ? 'Single & multiple choice' : 'Single choice');
        }
        unset($item);
        $now = time();
        $studentsTesting = count(array_filter($data['sessions'], fn($session) => in_array(($session['status'] ?? ''), ['in_progress', 'locked'], true) && strtotime((string)($session['endsAt'] ?? '')) > $now));
        $activeSession = findBy($data['academicSessions'], 'isActive', true);
        $activeSemester = findBy($data['semesters'], 'isActive', true);
        respond(['items' => $items, 'liveStatus' => ['openComponents' => count($items), 'studentsTesting' => $studentsTesting, 'clientIp' => clientFingerprint(), 'singleSessionLockActive' => !empty(examSecurity($data)['concurrentIpBlockEnabled'])], 'activePeriod' => ['sessionLabel' => $activeSession['label'] ?? '', 'semesterLabel' => $activeSemester['label'] ?? '']]);
    }
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
    auth(true); enforceRateLimit($data, 'students-bulk', 10, 600); $input = body(); $rows = $input['items'] ?? null;
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
    if ($method === 'DELETE') {
        if (($_GET['permanently'] ?? '') !== 'true') {
            $student['active'] = false; replaceBy($data['students'], 'id', $studentId, $student);
            auditEvent($data, 'admin', adminActorId(), 'student_disabled', 'student', $studentId, ['matricNumber' => $student['matricNumber'], 'name' => $student['fullName']]); saveData($data); respond(['item' => $student]);
        }
        $studentSessions = array_values(array_filter($data['sessions'], fn($item) => ($item['studentId'] ?? '') === $studentId));
        $sessionIds = array_fill_keys(array_column($studentSessions, 'id'), true);
        $studentResults = array_values(array_filter($data['results'], fn($item) => ($item['studentId'] ?? '') === $studentId));
        $data['students'] = array_values(array_filter($data['students'], fn($item) => ($item['id'] ?? '') !== $studentId));
        $data['passwords'] = array_values(array_filter($data['passwords'], fn($item) => ($item['studentId'] ?? '') !== $studentId));
        $data['loginTokens'] = array_values(array_filter($data['loginTokens'], fn($item) => ($item['studentId'] ?? '') !== $studentId));
        $data['sessions'] = array_values(array_filter($data['sessions'], fn($item) => ($item['studentId'] ?? '') !== $studentId));
        $data['results'] = array_values(array_filter($data['results'], fn($item) => ($item['studentId'] ?? '') !== $studentId));
        $data['calculatedResults'] = array_values(array_filter($data['calculatedResults'], fn($item) => ($item['studentId'] ?? '') !== $studentId));
        $data['examFlags'] = array_values(array_filter($data['examFlags'], fn($item) => !isset($sessionIds[(string)($item['sessionId'] ?? '')])));
        foreach ($data['exams'] as $exam) clearExamLoginFailures($data, (string)$student['matricNumber'], (string)($exam['id'] ?? ''));
        auditEvent($data, 'admin', adminActorId(), 'student_deleted', 'student', $studentId, [
            'matricNumber' => $student['matricNumber'], 'name' => $student['fullName'],
            'deletedSessions' => count($studentSessions), 'deletedResults' => count($studentResults)
        ]);
        saveData($data); respond(['ok' => true]);
    }
    $input = body(); $student = array_merge($student, validateStudent(array_merge($student, $input), $data, $studentId)); if (array_key_exists('active', $input)) $student['active'] = (bool)$input['active'];
    replaceBy($data['students'], 'id', $studentId, $student); syncStudentSubscriber($data, $student); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'student_updated', 'student', $studentId, ['matricNumber' => $student['matricNumber'], 'name' => $student['fullName'], 'changedFields' => array_keys($input)]); saveData($data); respond(['item' => $student]);
}
if ($action === 'exam-password' && $method === 'POST') {
    auth(true); $input = body(); $student = findBy($data['students'], 'matricNumber', trim($input['matricNumber'] ?? '')); $exam = findBy($data['exams'], 'id', $input['examId'] ?? '');
    if (!$student || !$exam) respond(['error' => 'Student or exam could not be found.'], 404);
    if (!$student['active']) respond(['error' => 'This student is disabled.'], 409);
    if (strtotime((string)($exam['endAt'] ?? '')) <= time()) respond(['error' => 'This assessment window has already closed. Update its window before generating a password.'], 409);
    if (strtotime((string)($exam['endAt'] ?? '')) <= time()) respond(['error' => 'This assessment window has already closed. Update its window before generating a password.'], 409);
    $password = randomPassword(); $record = ['id' => id(), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordHash' => password_hash($password, PASSWORD_DEFAULT), 'createdAt' => date('c'), 'expiresAt' => $exam['endAt'], 'usedAt' => null];
    $data['passwords'] = array_values(array_filter($data['passwords'], fn($item) => !($item['studentId'] === $student['id'] && $item['examId'] === $exam['id']))); $data['passwords'][] = $record; clearExamLoginFailures($data, $student['matricNumber'], $exam['id']); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'exam_password_generated', 'exam_password', $record['id'], ['matricNumber' => $student['matricNumber'], 'course' => $exam['code']]); saveData($data); respond(['student' => $student, 'exam' => publicExam($exam), 'password' => $password]);
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
    auth(true); enforceRateLimit($data, 'questions-bulk', 10, 600); $input = body(); $rows = $input['items'] ?? null;
    if (!is_array($rows) || !$rows || count($rows) > 500 || !array_is_list($rows)) respond(['error' => 'Provide 1 to 500 question rows.'], 422);
    $new = []; foreach ($rows as $index => $row) { if (!is_array($row)) respond(['error' => 'Question row ' . ($index + 1) . ' is invalid.'], 422); $new[] = ['id' => id()] + validateQuestion($row, $data); }
    $bulkExam = findBy($data['exams'], 'id', $new[0]['examId']); array_push($data['questions'], ...$new); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'questions_bulk_imported', 'question', 'bulk', ['count' => count($new), 'course' => $bulkExam['code'] ?? '']); saveData($data); respond(['added' => count($new)], 201);
}

if ($action === 'student-login' && $method === 'POST') {
    $input = body(); $matricNumber = trim((string)($input['matricNumber'] ?? '')); $examId = (string)($input['examId'] ?? ''); $security = examSecurity($data); $student = findBy($data['students'], 'matricNumber', $matricNumber); $exam = findBy($data['exams'], 'id', $examId); $deviceFingerprint = browserDeviceFingerprint($input);
    enforceExamLoginRateLimits($data, $security, $matricNumber, $examId);
    $passwordRecord = $student && $exam ? array_values(array_filter($data['passwords'], fn($item) => $item['studentId'] === $student['id'] && $item['examId'] === $exam['id'])) : [];
    $passwordRecord = $passwordRecord ? end($passwordRecord) : null;
    if ($student && $student['active'] && $exam && !$passwordRecord) {
        clearExamLoginFailures($data, $matricNumber, $examId); auditEvent($data, 'student', $student['id'], 'login_failed', 'exam', $examId, ['matricNumber' => $matricNumber, 'course' => $exam['code'], 'reason' => 'password_not_issued_for_component']); saveData($data);
        respond(['error' => 'No password has been issued for this ' . ($exam['componentLabel'] ?? ucfirst((string)($exam['component'] ?? 'assessment'))) . '. Tests and Exams use separate passwords; ask your administrator to generate the correct component password.'], 409);
    }
    if ($student && $student['active'] && $exam && !$passwordRecord) {
        recordExamLoginFailure($data, $security, $matricNumber, $examId, $student, $exam, 'password_not_issued_for_component'); saveData($data);
        respond(['error' => 'No password has been issued for this ' . ($exam['componentLabel'] ?? ucfirst((string)($exam['component'] ?? 'assessment'))) . '. Tests and Exams use separate passwords; ask your administrator to generate the correct component password.'], 409);
    }
    if (!$student || !$student['active'] || !$exam || !$passwordRecord || !password_verify((string)($input['password'] ?? ''), $passwordRecord['passwordHash']) || strtotime($passwordRecord['expiresAt']) < time()) { $locked = recordExamLoginFailure($data, $security, $matricNumber, $examId, $student, $exam, 'credentials_not_verified'); saveData($data); respond(['error' => $locked ? 'Too many failed exam login attempts. Please contact your administrator or try again later.' : 'We could not verify those details.'], $locked ? 429 : 401); }
    if (!empty($passwordRecord['usedAt'])) { $locked = recordExamLoginFailure($data, $security, $matricNumber, $examId, $student, $exam, 'password_already_used'); saveData($data); respond(['error' => $locked ? 'Too many failed exam login attempts. Please contact your administrator or try again later.' : 'This exam password has already been used. Ask an administrator for a new one.'], $locked ? 429 : 409); }
    foreach ($data['results'] as $result) if ($result['studentId'] === $student['id'] && $result['examId'] === $exam['id'] && strtotime($result['submittedAt']) >= strtotime($passwordRecord['createdAt'])) { $locked = recordExamLoginFailure($data, $security, $matricNumber, $examId, $student, $exam, 'result_already_recorded'); saveData($data); respond(['error' => $locked ? 'Too many failed exam login attempts. Please contact your administrator or try again later.' : 'This exam password has already been used. Ask an administrator for a new one.'], $locked ? 429 : 409); }
    if (!publicExam($exam)['active']) { $locked = recordExamLoginFailure($data, $security, $matricNumber, $examId, $student, $exam, 'assessment_not_available'); saveData($data); respond(['error' => $locked ? 'Too many failed exam login attempts. Please contact your administrator or try again later.' : 'This assessment is not currently available.'], $locked ? 429 : 403); }
    $activeSession = activeStudentExamSession($data, $student['id'], $exam['id']); $requestIp = clientFingerprint();
    if ($activeSession && !empty(examSecurity($data)['concurrentIpBlockEnabled']) && !empty($activeSession['ipAddress']) && $activeSession['ipAddress'] !== $requestIp) {
        $event = ['event' => 'concurrent_login_blocked', 'at' => date('c'), 'resultingAction' => 'blocked', 'originalIpAddress' => $activeSession['ipAddress'], 'attemptedIpAddress' => $requestIp];
        $activeSession['integrityEvents'] ??= []; $activeSession['integrityEvents'][] = $event; replaceBy($data['sessions'], 'id', $activeSession['id'], $activeSession);
        auditEvent($data, 'student', $student['id'], 'concurrent_login_blocked', 'exam_session', $activeSession['id'], ['matricNumber' => $student['matricNumber'], 'course' => $exam['code'], 'originalIpAddress' => $activeSession['ipAddress'], 'attemptedIpAddress' => $requestIp]);
        saveData($data); respond(['error' => 'This exam is already in progress on another device/network.'], 409);
    }
    $sharedDeviceEvents = sharedDeviceEvents($data, $student, $exam, $deviceFingerprint);
    foreach ($sharedDeviceEvents as $event) auditEvent($data, 'student', $student['id'], 'shared_device_suspected', 'exam', $exam['id'], ['matricNumber' => $student['matricNumber'], 'otherMatricNumber' => $event['otherMatricNumber'], 'course' => $exam['code'], 'deviceFingerprint' => $deviceFingerprint]);
    // Attach the same signal to the already-active student's session so the live monitor
    // updates immediately, even if this second student does not proceed to start an exam.
    if ($sharedDeviceEvents) foreach ($data['sessions'] as &$session) {
        if (($session['examId'] ?? '') !== $exam['id'] || ($session['studentId'] ?? '') === $student['id'] || ($session['status'] ?? '') !== 'in_progress' || !hash_equals((string)($session['deviceFingerprint'] ?? ''), $deviceFingerprint)) continue;
        $session['integrityEvents'] ??= []; $session['integrityEvents'][] = ['event' => 'shared_device_suspected', 'at' => date('c'), 'resultingAction' => 'logged', 'otherMatricNumber' => $student['matricNumber'], 'deviceFingerprint' => $deviceFingerprint];
    }
    unset($session);
    $token = secretToken(); $expiresAt = min(strtotime($exam['endAt']), time() + LOGIN_TOKEN_SECONDS); clearExamLoginFailures($data, $matricNumber, $examId); $data['loginTokens'][] = ['tokenHash' => tokenHash($token), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordId' => $passwordRecord['id'], 'expiresAt' => date('c', $expiresAt), 'usedAt' => null, 'deviceFingerprint' => $deviceFingerprint, 'sharedDeviceEvents' => $sharedDeviceEvents]; auditEvent($data, 'student', $student['id'], 'student_exam_login', 'exam', $exam['id'], ['matricNumber' => $student['matricNumber'], 'course' => $exam['code']]); saveData($data);
    setSessionCookie('CBT_EXAM_LOGIN', $token, $expiresAt); respond(['student' => $student, 'exam' => publicExam($exam)]);
}
if ($action === 'session-start' && $method === 'POST') {
    $input = body(); $login = cookieValue('CBT_EXAM_LOGIN'); $token = $login ? findBy($data['loginTokens'], 'tokenHash', tokenHash($login)) : null; $student = $token ? findBy($data['students'], 'id', $token['studentId']) : null; $exam = $token ? findBy($data['exams'], 'id', $token['examId']) : null;
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
        clearSessionCookie('CBT_EXAM_LOGIN'); setSessionCookie('CBT_EXAM_SESSION', $accessToken, strtotime($existing['endsAt'])); respond(['session' => sessionPayload($existing), 'questions' => publicSessionQuestions($existing)]);
    }
    $questionsForExam = array_values(array_filter($data['questions'], fn($question) => $question['examId'] === $exam['id'] && ($question['status'] ?? 'published') === 'published'));
    $count = (int)$exam['questionCount'];
    if (count($questionsForExam) < $count) respond(['error' => "This assessment needs $count published questions before it can start; " . count($questionsForExam) . ' are available.'], 422);
    shuffle($questionsForExam);
    $questionsForExam = array_slice($questionsForExam, 0, $count);
    $questionsForExam = array_map(fn($question) => sessionQuestionWithOptionOrder($question, !empty(examSecurity($data)['optionShuffleEnabled'])), $questionsForExam);
    $now = time(); $accessToken = secretToken();
    $session = ['id' => id(), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordId' => $passwordRecord['id'], 'accessTokenHash' => tokenHash($accessToken), 'startedAt' => date('c', $now), 'endsAt' => date('c', min($now + ((int)$exam['duration'] * 60), strtotime($exam['endAt']))), 'questions' => $questionsForExam, 'answers' => [], 'flagged' => [], 'integrityEvents' => $token['sharedDeviceEvents'] ?? [], 'ipAddress' => clientFingerprint(), 'deviceFingerprint' => $token['deviceFingerprint'] ?? '', 'status' => 'in_progress'];
    $data['sessions'][] = $session; auditEvent($data, 'student', $student['id'], 'exam_started', 'exam_session', $session['id'], ['matricNumber' => $student['matricNumber'], 'course' => $exam['code']]); saveData($data); clearSessionCookie('CBT_EXAM_LOGIN'); setSessionCookie('CBT_EXAM_SESSION', $accessToken, strtotime($session['endsAt'])); respond(['session' => sessionPayload($session), 'questions' => publicSessionQuestions($session)]);
}
if ($action === 'session-resume' && $method === 'POST') {
    $input = body(); $session = requireSession($data, $input);
    if ($session['status'] === 'locked') respond(['session' => sessionPayload($session), 'questions' => []]);
    if ($session['status'] !== 'in_progress') respond(['error' => 'This attempt has already been submitted.'], 409);
    respond(['session' => sessionPayload($session), 'questions' => publicSessionQuestions($session)]);
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
    $session['integrityEvents'] ??= [];
    // An administrator unlock begins a new monitored segment. Keep the full audit history,
    // but do not carry the previous segment's occurrence count into the resumed attempt.
    $lastUnlockIndex = -1; foreach ($session['integrityEvents'] as $index => $integrityEvent) if (($integrityEvent['event'] ?? '') === 'admin_unlocked') $lastUnlockIndex = $index;
    $eventsSinceUnlock = $lastUnlockIndex >= 0 ? array_slice($session['integrityEvents'], $lastUnlockIndex + 1) : $session['integrityEvents'];
    $sameTypeCount = count(array_filter($eventsSinceUnlock, fn($item) => ($item['event'] ?? '') === $event)) + 1;
    $rule = integrityRule($data, $event); $resultingAction = $rule['mode'];
    // Returning from the administrator's unlock screen naturally creates a blur/tab signal.
    // Log that hand-off, but never let it immediately reverse an authorised unlock.
    if (strtotime((string)($session['unlockGraceUntil'] ?? '')) > time()) $resultingAction = 'grace_logged';
    elseif ($rule['mode'] === 'lock' || ($rule['mode'] === 'warn' && $sameTypeCount >= $rule['lockAfter'])) $resultingAction = 'locked';
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
    $input = body(); $session = requireSession($data, $input); if ($session['status'] !== 'in_progress') { $existing = findBy($data['results'], 'examSessionId', $session['id']); clearSessionCookie('CBT_EXAM_SESSION'); respond(['result' => $existing]); } $session = completeSession($data, $session, strtotime($session['endsAt']) <= time()); saveData($data); clearSessionCookie('CBT_EXAM_SESSION'); respond(['result' => findBy($data['results'], 'examSessionId', $session['id'])]);
}
if ($action === 'exam-session-unlock' && $method === 'POST') {
    auth(true); $input = body(); $sessionId = (string)($input['sessionId'] ?? ''); $session = findBy($data['sessions'], 'id', $sessionId);
    if (!$session) respond(['error' => 'Exam session not found.'], 404);
    if (($session['status'] ?? '') !== 'locked') respond(['error' => 'Only a currently locked session can be unlocked.'], 409);
    if (strtotime((string)($session['endsAt'] ?? '')) <= time()) respond(['error' => 'This assessment time has ended and the session cannot be unlocked.'], 409);
    $student = findBy($data['students'], 'id', $session['studentId']); $exam = findBy($data['exams'], 'id', $session['examId']);
    $session['status'] = 'in_progress'; unset($session['lockedReason']); $session['unlockedAt'] = date('c'); $session['unlockGraceUntil'] = date('c', time() + 20); $session['integrityEvents'] ??= [];
    $session['integrityEvents'][] = ['event' => 'admin_unlocked', 'at' => date('c'), 'resultingAction' => 'unlocked', 'admin' => adminActorId()];
    replaceBy($data['sessions'], 'id', $sessionId, $session);
    auditEvent($data, 'admin', adminActorId(), 'exam_session_unlocked', 'exam_session', $sessionId, ['matricNumber' => $student['matricNumber'] ?? '', 'course' => $exam['code'] ?? '', 'component' => $exam['component'] ?? 'exam']);
    saveData($data); respond(['ok' => true, 'sessionId' => $sessionId, 'status' => 'in_progress']);
}
if ($action === 'audit-monitor' && $method === 'GET') {
    auth(true); $now = time(); $items = [];
    foreach ($data['sessions'] as $session) {
        if (!in_array(($session['status'] ?? ''), ['in_progress', 'locked'], true)) continue;
        $student = findBy($data['students'], 'id', $session['studentId']); $exam = findBy($data['exams'], 'id', $session['examId']);
        $events = array_reverse($session['integrityEvents'] ?? []); $last = $events[0] ?? null;
        $status = $session['status'] === 'locked' ? 'Locked' : ($last ? 'Flagged --- ' . str_replace('_', ' ', (string)$last['event']) : 'Normal');
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
    auth(true);
    $sessionId = (string)($_GET['sessionId'] ?? ''); $semesterId = (string)($_GET['semesterId'] ?? '');
    $groups = [];
    foreach ($data['results'] as $result) {
        if ($sessionId !== '' && ($result['academicSessionId'] ?? '') !== $sessionId) continue;
        if ($semesterId !== '' && ($result['academicSemesterId'] ?? '') !== $semesterId) continue;
        $studentId = (string)($result['studentId'] ?? ''); if ($studentId === '') continue;
        $groups[$studentId] ??= [];
        $groups[$studentId][] = $result;
    }
    $items = [];
    foreach ($groups as $studentId => $records) {
        usort($records, fn($a, $b) => strcmp((string)($b['submittedAt'] ?? ''), (string)($a['submittedAt'] ?? '')));
        $latest = $records[0]; $student = findBy($data['students'], 'id', $studentId) ?? [];
        $latestSessionId = (string)($latest['academicSessionId'] ?? ''); $latestSemesterId = (string)($latest['academicSemesterId'] ?? '');
        $report = studentReport($data, $studentId, $latestSessionId, $latestSemesterId);
        $session = findBy($data['academicSessions'], 'id', $latestSessionId) ?? [];
        $semester = findBy($data['semesters'], 'id', $latestSemesterId) ?? [];
        $items[] = ['studentId' => $studentId, 'studentName' => $student['fullName'] ?? 'Unknown student', 'matricNumber' => $student['matricNumber'] ?? '', 'completedComponents' => count($records), 'latestSubmittedAt' => $latest['submittedAt'] ?? '', 'sessionId' => $latestSessionId, 'semesterId' => $latestSemesterId, 'sessionLabel' => $session['label'] ?? 'Unassigned session', 'semesterLabel' => $semester['label'] ?? 'Unassigned semester', 'calculated' => !empty($report['calculated'])];
    }
    $periods = [];
    foreach ($data['results'] as $result) {
        $key = ($result['academicSessionId'] ?? '') . '|' . ($result['academicSemesterId'] ?? '');
        if (isset($periods[$key])) continue;
        $session = findBy($data['academicSessions'], 'id', (string)($result['academicSessionId'] ?? '')) ?? [];
        $semester = findBy($data['semesters'], 'id', (string)($result['academicSemesterId'] ?? '')) ?? [];
        $periods[$key] = ['sessionId' => $result['academicSessionId'] ?? '', 'semesterId' => $result['academicSemesterId'] ?? '', 'sessionLabel' => $session['label'] ?? 'Unassigned session', 'semesterLabel' => $semester['label'] ?? 'Unassigned semester'];
    }
    usort($periods, fn($a, $b) => strnatcasecmp($b['sessionLabel'] . ' ' . $b['semesterLabel'], $a['sessionLabel'] . ' ' . $a['semesterLabel']));
    $response = listItems($items, ['studentName','matricNumber'], ['studentName','matricNumber','completedComponents','latestSubmittedAt']);
    $response['periods'] = array_values($periods); respond($response);
}
if ($action === 'calculate-student-result' && $method === 'POST') {
    auth(true); $input = body(); $studentId = (string)($input['studentId'] ?? ''); $sessionId = (string)($input['sessionId'] ?? ''); $semesterId = (string)($input['semesterId'] ?? '');
    if ($studentId === '' || $sessionId === '' || $semesterId === '') respond(['error' => 'Choose a student, academic session, and semester before calculating a result.'], 422);
    $report = studentReport($data, $studentId, $sessionId, $semesterId);
    $completed = calculationSnapshotItems($report['items']);
    if (!$completed) respond(['error' => 'No completed courses are available for this period. Submit all required components for at least one course first.'], 422);
    $snapshot = ['id' => id(), 'studentId' => $studentId, 'sessionId' => $sessionId, 'semesterId' => $semesterId, 'sourceHash' => $report['sourceHash'], 'items' => $completed, 'semesterGpa' => $report['semesterGpa'], 'cgpa' => $report['cgpa'], 'calculatedAt' => date('c')];
    $data['calculatedResults'] = array_values(array_filter($data['calculatedResults'], fn($item) => !((($item['studentId'] ?? '') === $studentId) && (($item['sessionId'] ?? '') === $sessionId) && (($item['semesterId'] ?? '') === $semesterId))));
    $data['calculatedResults'][] = $snapshot;
    $student = $report['student']; auditEvent($data, 'admin', adminActorId(), 'student_result_calculated', 'student_result', $studentId, ['matricNumber' => $student['matricNumber'] ?? '', 'sessionId' => $sessionId, 'semesterId' => $semesterId, 'completedCourses' => count($completed)]);
    saveData($data); respond(['calculatedResult' => $snapshot]);
}
if ($action === 'result-review' && $method === 'GET') {
    auth(true); $result = findBy($data['results'], 'id', (string)($_GET['id'] ?? ''));
    if (!$result) respond(['error' => 'Result not found.'], 404);
    $session = findBy($data['sessions'], 'id', $result['examSessionId'] ?? $result['sessionId']);
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
    $data['settings']['examSecurity'] = validateExamSecurity($input['examSecurity'] ?? ($data['settings']['examSecurity'] ?? DEFAULT_EXAM_SECURITY));
    $logoUrl = trim((string)($input['resultLogoUrl'] ?? ($data['settings']['resultLogoUrl'] ?? 'CACSA%20Logo.jpeg')));
    if ($logoUrl === '' || strlen($logoUrl) > 500 || !preg_match('/^[A-Za-z0-9._\/%-]+$/', $logoUrl) || str_contains($logoUrl, '..')) respond(['error' => 'Provide a valid local result-sheet logo image path.'], 422);
    $data['settings']['resultLogoUrl'] = $logoUrl;
    recalculateResults($data); auditEvent($data, 'admin', getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL, 'settings_updated', 'settings', 'integrity_and_grading', ['changed' => array_keys($input)]); saveData($data); respond(['settings' => $data['settings']]);
}
if ($action === 'student-results' && $method === 'GET') {
    auth(true);
    respond(studentReport($data, (string)($_GET['id'] ?? ''), (string)($_GET['sessionId'] ?? ''), (string)($_GET['semesterId'] ?? '')));
}
if ($action === 'student-results-csv' && $method === 'GET') {
    auth(true); $report = studentReport($data, (string)($_GET['id'] ?? ''), (string)($_GET['sessionId'] ?? ''), (string)($_GET['semesterId'] ?? ''));
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="student-results.csv"');
    $stream = fopen('php://output', 'w');
    fputcsv($stream, ['Student', safeCsvCell($report['student']['fullName']), 'Matric', safeCsvCell($report['student']['matricNumber'])]);
    fputcsv($stream, ['Academic Session', safeCsvCell($report['selectedSession']['label'] ?? ''), 'Semester', safeCsvCell($report['selectedSemester']['label'] ?? '')]);
    fputcsv($stream, ['Course code', 'Course title', 'Unit', 'Test score', 'Exam score', 'Total', 'Grade', 'Grade point', 'Quality points', 'Status']);
    foreach ($report['items'] as $item) fputcsv($stream, [safeCsvCell($item['courseCode']), safeCsvCell($item['courseTitle']), $item['courseUnit'], $item['testScore'] ?? '', $item['examScore'] ?? '', $item['total'] ?? '', safeCsvCell($item['grade'] ?? ''), $item['gradePoint'] ?? '', $item['qualityPoints'] ?? '', safeCsvCell($item['status'] ?? '')]);
    fputcsv($stream, ['Semester GPA', $report['semesterGpa'] ?? '']);
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
    foreach ($data['exams'] as $exam) { $examResults = array_values(array_filter($data['results'], fn($result) => $result['examId'] === $exam['id'])); $attempts = count($examResults); $passed = count(array_filter($examResults, fn($result) => $result['score'] >= $passScore)); $popular[] = publicExam($exam) + ['attempts' => $attempts]; $outcomes[] = ['examId' => $exam['id'], 'code' => $exam['code'], 'title' => $exam['title'], 'component' => $exam['component'] ?? 'exam', 'componentLabel' => ucfirst((string)($exam['component'] ?? 'exam')), 'attempts' => $attempts, 'passed' => $passed, 'failed' => $attempts - $passed, 'passRate' => $attempts ? round(100 * $passed / $attempts, 1) : 0, 'averageScore' => $attempts ? round(array_sum(array_column($examResults, 'score')) / $attempts, 1) : 0]; }
    usort($popular, fn($a, $b) => $b['attempts'] <=> $a['attempts']);
    $hiddenOutcomeIds = array_fill_keys(array_map('strval', $data['dashboardHiddenOutcomes'] ?? []), true);
    $hiddenOutcomes = array_values(array_filter($outcomes, fn($outcome) => isset($hiddenOutcomeIds[(string)$outcome['examId']])));
    $visibleOutcomes = array_values(array_filter($outcomes, fn($outcome) => !isset($hiddenOutcomeIds[(string)$outcome['examId']])));
    $outcomePageSize = max(3, min(20, (int)($_GET['outcomePageSize'] ?? 5)));
    $outcomeTotal = count($visibleOutcomes); $outcomePages = max(1, (int)ceil($outcomeTotal / $outcomePageSize));
    $outcomePage = max(1, min($outcomePages, (int)($_GET['outcomePage'] ?? 1)));
    $visibleOutcomePage = array_slice($visibleOutcomes, ($outcomePage - 1) * $outcomePageSize, $outcomePageSize);
    respond(['stats' => ['students' => count(array_filter($data['students'], fn($student) => $student['active'])), 'activeExams' => count(array_filter($data['exams'], fn($exam) => publicExam($exam)['active'])), 'completedToday' => count($completed), 'averageScore' => $average], 'activity' => array_slice($recent, 0, 5), 'popular' => array_slice($popular, 0, 8), 'recent' => $recent, 'scoreDistribution' => $distribution, 'courseOutcomes' => $visibleOutcomePage, 'courseOutcomesMeta' => ['page' => $outcomePage, 'pageSize' => $outcomePageSize, 'total' => $outcomeTotal, 'pages' => $outcomePages], 'hiddenCourseOutcomes' => array_map(fn($outcome) => ['examId' => $outcome['examId'], 'code' => $outcome['code'], 'title' => $outcome['title'], 'componentLabel' => $outcome['componentLabel']], $hiddenOutcomes), 'passScore' => $passScore]);
}

if ($action === 'dashboard-outcomes' && $method === 'POST') {
    auth(true); $input = body(); $operation = (string)($input['operation'] ?? ''); $ids = $input['examIds'] ?? [];
    if (!in_array($operation, ['hide', 'restore'], true) || !is_array($ids) || !$ids || count($ids) > 100) respond(['error' => 'Choose one or more dashboard outcome rows to update.'], 422);
    $known = array_fill_keys(array_map(fn($exam) => (string)$exam['id'], $data['exams']), true);
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn($id) => isset($known[$id]))));
    if (!$ids) respond(['error' => 'The selected dashboard outcome rows no longer exist.'], 422);
    $hidden = array_values(array_unique(array_map('strval', $data['dashboardHiddenOutcomes'] ?? [])));
    if ($operation === 'hide') $hidden = array_values(array_unique(array_merge($hidden, $ids)));
    else $hidden = array_values(array_filter($hidden, fn($id) => !in_array($id, $ids, true)));
    $data['dashboardHiddenOutcomes'] = $hidden;
    auditEvent($data, 'admin', adminActorId(), $operation === 'hide' ? 'dashboard_outcomes_hidden' : 'dashboard_outcomes_restored', 'dashboard_outcome', 'bulk', ['examIds' => $ids, 'count' => count($ids)]);
    saveData($data); respond(['hiddenIds' => $hidden]);
}

if ($action === 'backups' && $method === 'GET') {
    auth(true);
    if (!empty($_GET['download'])) {
        $filename = (string)$_GET['download']; $path = backupPath($filename);
        header('Content-Type: application/json; charset=utf-8'); header('Content-Length: ' . (string)filesize($path)); header('Content-Disposition: attachment; filename="' . $filename . '"');
        readfile($path); exit;
    }
    respond(['items' => listBackups(), 'settings' => backupSettings($data), 'nextRunAt' => nextBackupRun($data), 'directory' => 'database/backups/']);
}
if ($action === 'backups' && $method === 'POST') {
    auth(true); enforceRateLimit($data, 'backups-create', 10, 3600); $backup = createBackup($data); $settings = backupSettings($data); $settings['lastBackupAt'] = date('c'); $data['settings']['backup'] = $settings;
    auditEvent($data, 'admin', adminActorId(), 'backup_created_manual', 'backup', $backup['filename'], ['filename' => $backup['filename']]); saveData($data); respond(['backup' => $backup, 'items' => listBackups()]);
}
if ($action === 'backups' && $method === 'PUT') {
    auth(true); $input = body(); $time = (string)($input['time'] ?? '');
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) respond(['error' => 'Choose a valid daily backup time.'], 422);
    $retention = filter_var($input['retentionCount'] ?? null, FILTER_VALIDATE_INT);
    if ($retention === false || $retention < 1 || $retention > 365) respond(['error' => 'Backup retention must be between 1 and 365 files.'], 422);
    $settings = backupSettings($data); $settings['enabled'] = !empty($input['enabled']); $settings['time'] = $time; $settings['retentionCount'] = $retention; $data['settings']['backup'] = $settings;
    applyBackupRetention($data); auditEvent($data, 'admin', adminActorId(), 'backup_settings_updated', 'backup_settings', 'scheduled_backup', ['enabled' => $settings['enabled'], 'time' => $time, 'retentionCount' => $retention]); saveData($data); respond(['settings' => $settings, 'nextRunAt' => nextBackupRun($data)]);
}
if ($action === 'backups' && $method === 'DELETE') {
    auth(true); $filename = (string)($_GET['filename'] ?? ''); $path = backupPath($filename);
    if (!unlink($path)) respond(['error' => 'Unable to delete the backup file.'], 500);
    auditEvent($data, 'admin', adminActorId(), 'backup_deleted', 'backup', $filename, ['filename' => $filename]); saveData($data); respond(['ok' => true]);
}
if ($action === 'backup-restore-validate' && $method === 'POST') {
    auth(true); enforceRateLimit($data, 'backup-restore-validate', 10, 3600); $payload = uploadedBackupPayload();
    respond(['filename' => basename((string)($_FILES['backup']['name'] ?? 'backup.json')), 'createdAt' => $payload['_backup']['createdAt'] ?? '', 'summary' => backupSummary($payload)]);
}
if ($action === 'backup-restore' && $method === 'POST') {
    auth(true); enforceRateLimit($data, 'backup-restore', 3, 3600);
    if (trim((string)($_POST['confirmation'] ?? '')) !== 'RESTORE') respond(['error' => 'Type RESTORE exactly before replacing current data.'], 422);
    $payload = uploadedBackupPayload(); $restoredFilename = basename((string)($_FILES['backup']['name'] ?? 'backup.json'));
    $safetyBackup = createBackup($data, 'safety'); $currentAdminUsers = $data['adminUsers']; $currentAdminSessions = $data['adminSessions']; $currentActivity = $data['adminUserActivity'];
    unset($payload['_backup']);
    // Backups deliberately exclude password hashes, so current admin credentials and the
    // active admin session remain intact while all academic/system records are restored.
    $payload['adminUsers'] = $currentAdminUsers; $payload['adminSessions'] = $currentAdminSessions; $payload['adminUserActivity'] = $currentActivity;
    $payload['loginTokens'] = []; $payload['adminPasswordResets'] = []; $payload['rateLimits'] = [];
    $payload['settings']['backup'] = array_merge(DEFAULT_BACKUP_SETTINGS, is_array($payload['settings']['backup'] ?? null) ? $payload['settings']['backup'] : []);
    auditEvent($payload, 'admin', adminActorId(), 'backup_restored', 'backup', $restoredFilename, ['restoredBackup' => $restoredFilename, 'safetyBackup' => $safetyBackup['filename']]);
    saveData($payload); $data = $payload;
    respond(['ok' => true, 'restoredBackup' => $restoredFilename, 'safetyBackup' => $safetyBackup['filename']]);
}

respond(['error' => 'Unknown API action.'], 404);
