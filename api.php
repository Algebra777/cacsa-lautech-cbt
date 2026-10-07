<?php

declare(strict_types=1);

class AlgebraProviderException extends RuntimeException {
    public function __construct(string $message, public readonly int $httpStatus = 502) { parent::__construct($message); }
}
final class AlgebraDailyQuotaException extends AlgebraProviderException {
    public function __construct(public readonly string $blockedUntil) { parent::__construct("Algebra's daily AI limit has been reached. Please try again later.", 429); }
}
class PdfUploadException extends RuntimeException {
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly string $userMessage = '', public readonly array $details = []) { parent::__construct($message); }
}

// Never expose PHP warnings, stack traces, or local paths through the JSON API.
// Detailed diagnostics remain in the server error log for administrators.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(static function (Throwable $error): never {
    $correlationId = (string)($_SERVER['CBT_ALGEBRA_CORRELATION_ID'] ?? '');
    error_log('Berevion API exception' . ($correlationId !== '' ? ' [' . $correlationId . ']' : '') . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
    if (!headers_sent()) {
        $httpStatus = 500;
        if ($error instanceof PdfUploadException) $httpStatus = $error->httpStatus;
        elseif ($error instanceof InstitutionSuspendedException) $httpStatus = 403;
        elseif ($error instanceof InstitutionUnavailableException) $httpStatus = 404;
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        if ($correlationId !== '') header('X-Correlation-ID: ' . $correlationId);
    }
    if ($error instanceof PdfUploadException) {
        $payload = ['error' => $error->userMessage !== '' ? $error->userMessage : $error->getMessage()];
        if ($error->details) $payload['details'] = $error->details;
    } else {
        $payload = $error instanceof InstitutionSuspendedException ? ['error' => 'This institution access has been suspended - contact the platform administrator.', 'institutionSuspended' => true] : ($error instanceof InstitutionUnavailableException ? ['error' => 'This institution is unavailable.'] : ['error' => 'An unexpected server error occurred. Please try again or contact the administrator.']);
    }
    if ($correlationId !== '') $payload['correlationId'] = $correlationId;
    echo json_encode($payload);
    exit;
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    error_log("Berevion API PHP warning [$severity]: $message in $file:$line");
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
        if (!preg_match('/^(?:CBT_|GEMINI_|OPENROUTER_|DB_|MYSQL_)[A-Z0-9_]+$/', $key) || getenv($key) !== false) continue;
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) $value = substr($value, 1, -1);
        putenv($key . '=' . $value); $_ENV[$key] = $value;
    }
}
loadLocalEnvironment();

$composerAutoload = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_readable($composerAutoload)) require_once $composerAutoload;
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_data_store.php';

const DEV_ADMIN_EMAIL = 'adepojutimothy001@gmail.com';
// Development bootstrap only. Set CBT_ADMIN_EMAIL and CBT_ADMIN_PASSWORD_HASH in production.
const DEV_ADMIN_PASSWORD_HASH = '$2y$10$2uTWijekM5e32FutE5qAievugm2JuFtVMTiXQGvu.1ijTiJc.MQD.';
const DATA_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'cbt-data.json';
const DATA_LOCK_FILE = __DIR__ . DIRECTORY_SEPARATOR . '.cbt-data.lock';
// This flag is intentionally checked before the JSON store is opened. It lets a
// deployment pause all application writes during a storage cutover safely.
const MAINTENANCE_FLAG_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '.maintenance.json';
const BACKUP_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups';
const TWO_FACTOR_KEY_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '.two-factor.key';
const INSTITUTION_LOGO_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'institution-logos';
const PDF_IMPORT_UPLOAD_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pdf-imports';
const ROUTINE_BACKUP_MONTHLY_RETENTION_MONTHS = 12;
const INSTITUTION_ASSET_GRACE_DAYS = 7;
const PDF_IMPORT_SOURCE_RETENTION_HOURS = 24;
const PDF_IMPORT_REVIEW_RETENTION_HOURS = 168;
const PDF_IMPORT_MAX_ATTEMPTS = 3;
const PDF_IMPORT_MAX_BYTES = 10 * 1024 * 1024;
const PDF_IMPORT_MAX_PAGES = 150;
const PDF_IMPORT_MAX_QUESTIONS = 100;
const OPENROUTER_PDF_IMPORT_MAX_BYTES = 10 * 1024 * 1024;
const OPENROUTER_PDF_IMPORT_MAX_PAGES = 150;
const OPENROUTER_PDF_IMPORT_MAX_QUESTIONS = 100;
const STRICT_IMPORT_MAX_BYTES = 2 * 1024 * 1024;
const STRICT_IMPORT_MAX_QUESTIONS = 500;
const ADMIN_TOKEN_SECONDS = 7200;
// Administrator sign-in is deliberately persistent by product policy. The
// server still revokes a session on explicit logout, account deactivation,
// password/session revocation, or emergency recovery.
const ADMIN_SESSION_EXPIRES_AT = '2099-12-31T23:59:59+00:00';
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
const DEFAULT_AUDIT_RETENTION_SETTINGS = ['months' => 12, 'time' => '02:15', 'lastRunDate' => '', 'lastArchivedAt' => '', 'lastArchiveFilename' => ''];
const ALGEBRA_MAX_DRAFT_QUESTIONS = 25;
const ALGEBRA_MAX_PROMPT_LENGTH = 2000;
const NEWSLETTER_SENDER = 'cacsalautech001@gmail.com';
const NEWSLETTER_SENDER_NAME = 'CACSA LAUTECH';
const DEFAULT_INSTITUTION_BRANDING = [
    'displayName' => 'CACSA LAUTECH', 'portalTitle' => 'CACSA LAUTECH CBT',
    'logoPath' => 'CACSA%20Logo.jpeg', 'faviconPath' => 'favicon.php',
    'primaryColor' => '#16774d', 'accentColor' => '#105839',
    'navLabel' => 'CACSA LAUTECH CBT', 'assessmentLabel' => 'Assessment centre',
    'footerPrimary' => 'LAUTECH Academic Directorate Certified Node',
    'footerSecondary' => 'Assessment timing supplied by the CBT service',
    'footerLegal' => '© {year} CACSA LAUTECH. All rights reserved.',
    'resultSheetTitle' => 'CACSA LAUTECH CBT',
    'newsletterSenderName' => 'CACSA LAUTECH', 'supportEmail' => NEWSLETTER_SENDER,
];
const ADMIN_PERMISSIONS = ['overview', 'students', 'exams', 'questions', 'results', 'audit', 'newsletter', 'messages', 'settings', 'roles'];
require_once __DIR__ . DIRECTORY_SEPARATOR . 'pdf_import_queue.php';
const DEFAULT_ROLES = [
    ['id' => 'admin', 'name' => 'Admin', 'description' => 'Full operational control within this institution.', 'maxUsers' => 1, 'systemLocked' => true, 'permissions' => ADMIN_PERMISSIONS],
    // Retained only while the Phase 2 migration converts the historical local
    // bootstrap identity into the separate platform account.
    ['id' => 'superadmin', 'name' => 'Superadmin', 'description' => 'Full system access and administrator management.', 'maxUsers' => 1, 'systemLocked' => true, 'permissions' => ADMIN_PERMISSIONS],
    ['id' => 'academic_coordinator', 'name' => 'Academic Coordinator', 'description' => 'Manages courses, Test/Exam components, students, results, audit monitoring, settings, newsletters, and support messages.', 'maxUsers' => 5, 'systemLocked' => false, 'permissions' => ['overview', 'students', 'exams', 'questions', 'results', 'audit', 'newsletter', 'messages', 'settings']],
    ['id' => 'assistant_academic_coordinator', 'name' => 'Assistant Academic Coordinator', 'description' => 'Supports courses, Test/Exam components, students, results, audit monitoring, settings, newsletters, and support messages.', 'maxUsers' => 10, 'systemLocked' => false, 'permissions' => ['overview', 'students', 'exams', 'questions', 'results', 'audit', 'newsletter', 'messages', 'settings']]
];

function respond(mixed $data, int $status = 200): never {
    http_response_code($status);
    $correlationId = (string)($_SERVER['CBT_ALGEBRA_CORRELATION_ID'] ?? '');
    if ($correlationId !== '') {
        header('X-Correlation-ID: ' . $correlationId);
        if (is_array($data) && !array_key_exists('correlationId', $data)) $data['correlationId'] = $correlationId;
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 5 * 1024 * 1024) respond(['error' => 'Request is too large.'], 413);
    // Test-only CLI request transport; Apache requests always use php://input.
    $raw = PHP_SAPI === 'cli' && getenv('CBT_ALGEBRA_MOCK_BODY') !== false
        ? (string)getenv('CBT_ALGEBRA_MOCK_BODY')
        : file_get_contents('php://input', false, null, 0, 5 * 1024 * 1024 + 1);
    if (strlen((string)$raw) > 5 * 1024 * 1024) respond(['error' => 'Request is too large.'], 413);
    if ($raw === '' || $raw === false) return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) respond(['error' => 'Request body must be valid JSON.'], 400);
    return $decoded;
}

function loadData(): array {
    if (mysqlStorageEnabled()) return mysqlLoadData();
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
            // Records schema upgrades that are safe to run again when restored data
            // from an older backup is brought back into service.
            'migrations' => [],
            'roles' => DEFAULT_ROLES,
            'adminUsers' => [],
            'pendingAdminRequests' => [],
            'adminProfileOverrides' => [],
            'adminEmailVerifications' => [],
            'adminPasswordResets' => [],
            'admin2faChallenges' => [],
            'emergencyRecoveryCodes' => [],
            'adminUserActivity' => [],
            'newsletterSubscribers' => [],
            'newsletters' => [],
            'settings' => ['gradingScale' => DEFAULT_GRADING_SCALE, 'integrityPolicy' => DEFAULT_INTEGRITY_POLICY, 'examSecurity' => DEFAULT_EXAM_SECURITY, 'backup' => DEFAULT_BACKUP_SETTINGS, 'auditRetention' => DEFAULT_AUDIT_RETENTION_SETTINGS, 'studentPortalSetupMode' => false]
        ];
        saveData($data);
        return $data;
    }
    $data = json_decode(file_get_contents(DATA_FILE), true);
    if (!is_array($data)) respond(['error' => 'The data file could not be read. Restore a valid backup before continuing.'], 500);
    $migrated = false; $now = time();
    foreach (['students', 'academicSessions', 'semesters', 'courses', 'exams', 'questions', 'passwords', 'sessions', 'results', 'calculatedResults', 'auditEvents', 'examFlags', 'dashboardHiddenOutcomes', 'roles', 'adminUsers', 'pendingAdminRequests', 'newsletterSubscribers', 'newsletters', 'examLoginFailures', 'examLoginIpAttempts', 'emergencyRecoveryCodes'] as $collection) {
        if (!isset($data[$collection]) || !is_array($data[$collection])) { $data[$collection] = []; $migrated = true; }
    }
    if (!isset($data['loginTokens']) || !is_array($data['loginTokens'])) { $data['loginTokens'] = []; $migrated = true; }
    if (!isset($data['adminSessions']) || !is_array($data['adminSessions'])) { $data['adminSessions'] = []; $migrated = true; }
    if (!isset($data['rateLimits']) || !is_array($data['rateLimits'])) { $data['rateLimits'] = []; $migrated = true; }
    if (!isset($data['adminUserActivity']) || !is_array($data['adminUserActivity'])) { $data['adminUserActivity'] = []; $migrated = true; }
    if (!isset($data['adminProfileOverrides']) || !is_array($data['adminProfileOverrides'])) { $data['adminProfileOverrides'] = []; $migrated = true; }
    if (!isset($data['adminEmailVerifications']) || !is_array($data['adminEmailVerifications'])) { $data['adminEmailVerifications'] = []; $migrated = true; }
    if (!isset($data['adminPasswordResets']) || !is_array($data['adminPasswordResets'])) { $data['adminPasswordResets'] = []; $migrated = true; }
    if (!isset($data['admin2faChallenges']) || !is_array($data['admin2faChallenges'])) { $data['admin2faChallenges'] = []; $migrated = true; }
    $activeTwoFactorChallenges = array_values(array_filter($data['admin2faChallenges'], fn($item) => is_array($item) && strtotime((string)($item['expiresAt'] ?? '')) > $now));
    if (count($activeTwoFactorChallenges) !== count($data['admin2faChallenges'])) { $data['admin2faChallenges'] = $activeTwoFactorChallenges; $migrated = true; }
    if (!isset($data['settings']) || !is_array($data['settings'])) { $data['settings'] = ['gradingScale' => DEFAULT_GRADING_SCALE, 'integrityPolicy' => DEFAULT_INTEGRITY_POLICY, 'resultLogoUrl' => 'CACSA%20Logo.jpeg']; $migrated = true; }
    if (!isset($data['migrations']) || !is_array($data['migrations'])) { $data['migrations'] = []; $migrated = true; }
    if (!isset($data['settings']['gradingScale']) || !is_array($data['settings']['gradingScale'])) { $data['settings']['gradingScale'] = DEFAULT_GRADING_SCALE; $migrated = true; }
    if (!isset($data['settings']['integrityPolicy']) || !is_array($data['settings']['integrityPolicy'])) { $data['settings']['integrityPolicy'] = DEFAULT_INTEGRITY_POLICY; $migrated = true; }
    if (!isset($data['settings']['examSecurity']) || !is_array($data['settings']['examSecurity'])) { $data['settings']['examSecurity'] = DEFAULT_EXAM_SECURITY; $migrated = true; }
    else foreach (DEFAULT_EXAM_SECURITY as $key => $value) if (!array_key_exists($key, $data['settings']['examSecurity'])) { $data['settings']['examSecurity'][$key] = $value; $migrated = true; }
    if (!isset($data['settings']['resultLogoUrl']) || !is_string($data['settings']['resultLogoUrl'])) { $data['settings']['resultLogoUrl'] = 'CACSA%20Logo.jpeg'; $migrated = true; }
    if (!isset($data['settings']['backup']) || !is_array($data['settings']['backup'])) { $data['settings']['backup'] = DEFAULT_BACKUP_SETTINGS; $migrated = true; }
    else foreach (DEFAULT_BACKUP_SETTINGS as $key => $value) if (!array_key_exists($key, $data['settings']['backup'])) { $data['settings']['backup'][$key] = $value; $migrated = true; }
    if (!isset($data['settings']['auditRetention']) || !is_array($data['settings']['auditRetention'])) { $data['settings']['auditRetention'] = DEFAULT_AUDIT_RETENTION_SETTINGS; $migrated = true; }
    else foreach (DEFAULT_AUDIT_RETENTION_SETTINGS as $key => $value) if (!array_key_exists($key, $data['settings']['auditRetention'])) { $data['settings']['auditRetention'][$key] = $value; $migrated = true; }
    if (!isset($data['settings']['studentPortalSetupMode']) || !is_bool($data['settings']['studentPortalSetupMode'])) { $data['settings']['studentPortalSetupMode'] = false; $migrated = true; }
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
    $liveAdminSessions = array_values(array_filter($data['adminSessions'], fn($item) => is_array($item) && !empty($item['tokenHash'])));
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
    // This upgrade changes only the live question-bank ownership model. Exam-session
    // snapshots already stored in $data['sessions'] are intentionally never touched.
    // A fresh server backup and an immutable audit trail are written before any record
    // is changed, and the migration itself is field-based so a later re-run is safe.
    if (sharedQuestionPoolMigrationNeeded($data)) {
        $safetyBackup = createBackup($data, 'manual');
        auditEvent($data, 'system', 'system', 'shared_question_pool_migration_started', 'question_bank', 'shared-pool-v1', ['safetyBackup' => $safetyBackup['filename'], 'questionCount' => count($data['questions'])]);
        $migration = migrateSharedQuestionPools($data);
        auditEvent($data, 'system', 'system', 'shared_question_pool_migrated', 'question_bank', 'shared-pool-v1', ['safetyBackup' => $safetyBackup['filename'], 'migratedQuestions' => $migration['migrated'], 'unresolvedQuestions' => $migration['unresolved']]);
        $migrated = true;
    }
    if ($migrated) saveData($data);
    return $data;
}

function institutionBranding(): array {
    static $branding = null;
    if (is_array($branding)) return $branding;
    $stored = mysqlStorageEnabled() ? mysqlInstitutionBranding() : null;
    $branding = array_merge(DEFAULT_INSTITUTION_BRANDING, is_array($stored) ? $stored : []);
    return $branding;
}
function platformBranding(): array {
    static $branding = null;
    if (is_array($branding)) return $branding;
    $fallback = [
        'displayName'=>'Berevion','portalTitle'=>'Berevion','tagline'=>'Examine. Verify. Excel.',
        'logoPath'=>'uploads/platform-branding/berevion-logo.png','faviconPath'=>'uploads/platform-branding/berevion-logo.png',
        'accentSourceColor'=>'#14D2BA','primaryColor'=>'#0D8475','accentColor'=>'#14D2BA',
        'navyColor'=>'#00205D','midBlueColor'=>'#024DB2','brightBlueColor'=>'#0094FE',
        'navLabel'=>'Berevion','assessmentLabel'=>'Examine. Verify. Excel.','footerPrimary'=>'Berevion assessment platform',
        'footerSecondary'=>'Examine. Verify. Excel.','footerLegal'=>'© {year} Berevion. All rights reserved.',
        'resultSheetTitle'=>'Berevion','newsletterSenderName'=>'Berevion','supportEmail'=>''
    ];
    $stored = mysqlStorageEnabled() ? mysqlPlatformBranding() : null;
    $branding = array_merge($fallback, is_array($stored) ? $stored : []);
    return $branding;
}
function institutionBrandName(): string { return (string)institutionBranding()['displayName']; }
function institutionPortalTitle(): string { return (string)institutionBranding()['portalTitle']; }
function institutionSupportEmail(): string {
    $email = trim((string)institutionBranding()['supportEmail']);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : NEWSLETTER_SENDER;
}
function institutionNewsletterSenderName(): string { return (string)institutionBranding()['newsletterSenderName']; }

function activeMaintenance(): ?array {
    if (!is_readable(MAINTENANCE_FLAG_FILE)) return null;
    try {
        $status = json_decode((string)file_get_contents(MAINTENANCE_FLAG_FILE), true, 32, JSON_THROW_ON_ERROR);
        return is_array($status) && !empty($status['enabled']) ? $status : null;
    } catch (Throwable) {
        // A malformed deployment flag must fail closed: no live data writes during cutover.
        return ['enabled' => true];
    }
}

function maintenanceResponse(): never {
    respond([
        'error' => 'Brief maintenance is in progress. Please check back shortly.',
        'maintenance' => true,
    ], 503);
}

function saveData(array $data): void {
    if (mysqlStorageEnabled()) { mysqlSaveData($data); return; }
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
function auditRetentionSettings(array $data): array {
    $settings = array_merge(DEFAULT_AUDIT_RETENTION_SETTINGS, is_array($data['settings']['auditRetention'] ?? null) ? $data['settings']['auditRetention'] : []);
    $settings['months'] = max(1, min(120, (int)$settings['months']));
    $settings['time'] = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$settings['time']) ? $settings['time'] : '02:15';
    $settings['lastRunDate'] = is_string($settings['lastRunDate'] ?? null) ? $settings['lastRunDate'] : '';
    $settings['lastArchivedAt'] = is_string($settings['lastArchivedAt'] ?? null) ? $settings['lastArchivedAt'] : '';
    $settings['lastArchiveFilename'] = is_string($settings['lastArchiveFilename'] ?? null) ? $settings['lastArchiveFilename'] : '';
    return $settings;
}
function backupInstitutionId(): int { return mysqlStorageEnabled() ? mysqlCurrentInstitutionId() : 1; }
function tenantBackupDirectory(): string {
    // Preserve CACSA's verified Phase 1 archive location. Every later tenant
    // receives a separate directory, so it can never enumerate CACSA files.
    return backupInstitutionId() === 1 ? BACKUP_DIRECTORY : BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . 'institution-' . backupInstitutionId();
}
function ensureBackupDirectory(): void {
    $directory = tenantBackupDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) respond(['error' => 'Unable to create the institution backup directory.'], 500);
}
function backupPrefix(string $kind = 'backup'): string { return $kind === 'safety' ? 'berevion-safety-before-restore' : 'berevion-backup'; }
function isBackupFilename(string $filename): bool { return (bool)preg_match('/^(?:berevion|cacsa-cbt)-(?:backup|safety-before-restore)_\d{4}-\d{2}-\d{2}_\d{6}(?:-\d+)?\.json$/', $filename); }
function isSafetyBackupFilename(string $filename): bool { return str_starts_with($filename, 'berevion-safety-before-restore_') || str_starts_with($filename, 'cacsa-cbt-safety-before-restore_'); }
function isRoutineBackupFilename(string $filename): bool { return str_starts_with($filename, 'berevion-backup_') || str_starts_with($filename, 'cacsa-cbt-backup_'); }
function isAuditArchiveFilename(string $filename): bool { return (bool)preg_match('/^(?:berevion|cacsa-cbt)-audit-archive_\d{4}-\d{2}-\d{2}_\d{6}(?:-\d+)?\.json$/', $filename); }
function backupFilename(string $kind = 'backup'): string {
    $prefix = backupPrefix($kind);
    $base = $prefix . '_' . date('Y-m-d_His'); $filename = $base . '.json'; $counter = 1;
    while (file_exists(tenantBackupDirectory() . DIRECTORY_SEPARATOR . $filename)) $filename = $base . '-' . $counter++ . '.json';
    return $filename;
}
function backupPayload(array $data): array {
    $payload = $data;
    $payload['_backup'] = ['format' => 'berevion-backup', 'version' => 2, 'createdAt' => date('c'), 'institutionId' => backupInstitutionId()];
    // Sign-in secrets and active tokens must never leave the server in a backup export.
    stripBackupPasswordHashes($payload);
    unset($payload['adminSessions'], $payload['loginTokens'], $payload['adminPasswordResets'], $payload['rateLimits']);
    return $payload;
}
function stripBackupPasswordHashes(array &$value): void {
    foreach ($value as $key => &$item) {
        if (in_array((string)$key, ['passwordHash', 'password', 'twoFactor', 'twoFactorPending', 'admin2faChallenges', 'emergencyRecoveryCodes'], true)) { unset($value[$key]); continue; }
        if (is_array($item)) stripBackupPasswordHashes($item);
    }
    unset($item);
}
function backupPath(string $filename): string {
    if (!isBackupFilename($filename)) respond(['error' => 'Invalid backup filename.'], 422);
    $path = tenantBackupDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) respond(['error' => 'Backup file not found.'], 404);
    return $path;
}
function auditArchiveFilename(): string {
    $base = 'berevion-audit-archive_' . date('Y-m-d_His'); $filename = $base . '.json'; $counter = 1;
    while (file_exists(tenantBackupDirectory() . DIRECTORY_SEPARATOR . $filename)) $filename = $base . '-' . $counter++ . '.json';
    return $filename;
}
function auditArchivePath(string $filename): string {
    if (!isAuditArchiveFilename($filename)) respond(['error' => 'Invalid audit archive filename.'], 422);
    $path = tenantBackupDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) respond(['error' => 'Audit archive file not found.'], 404);
    return $path;
}
function listBackups(): array {
    ensureBackupDirectory(); $items = []; $records = mysqlStorageEnabled() ? mysqlBackupRecordMap(backupInstitutionId()) : [];
    foreach (glob(tenantBackupDirectory() . DIRECTORY_SEPARATOR . '*-*.json') ?: [] as $path) {
        if (!is_file($path) || !isBackupFilename(basename($path))) continue;
        $filename = basename($path); $record = $records[$filename] ?? [];
        $items[] = ['filename' => $filename, 'createdAt' => date('c', (int)filemtime($path)), 'size' => (int)filesize($path), 'type' => (string)($record['type'] ?? (isSafetyBackupFilename($filename) ? 'safety' : 'legacy')), 'checksum' => $record['checksum'] ?? null];
    }
    usort($items, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    return $items;
}
function routineBackupRetentionItems(array $items, int $recentCount): array {
    $routine = array_values(array_filter($items, static fn(array $item): bool => isRoutineBackupFilename((string)$item['filename'])));
    usort($routine, fn(array $a, array $b): int => strcmp((string)$b['createdAt'], (string)$a['createdAt']));
    $keep = []; $months = []; $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . ROUTINE_BACKUP_MONTHLY_RETENTION_MONTHS . ' months');
    foreach ($routine as $offset => $item) {
        $filename = (string)$item['filename'];
        if ($offset < $recentCount) { $keep[$filename] = true; continue; }
        $created = new DateTimeImmutable((string)$item['createdAt']);
        if ($created < $cutoff) continue;
        $month = $created->setTimezone(new DateTimeZone('UTC'))->format('Y-m');
        if (!isset($months[$month])) { $months[$month] = true; $keep[$filename] = true; }
    }
    return $keep;
}
function applyBackupRetention(array $data): void {
    $recentCount = backupSettings($data)['retentionCount']; $items = listBackups(); $keep = routineBackupRetentionItems($items, $recentCount);
    foreach ($items as $item) {
        $filename = (string)$item['filename'];
        // Safety snapshots and audit archives are intentionally outside routine
        // retention. They are evidence/recovery material, never file-count churn.
        if (!isRoutineBackupFilename($filename) || isset($keep[$filename])) continue;
        $path = tenantBackupDirectory() . DIRECTORY_SEPARATOR . $filename;
        if (@unlink($path) && mysqlStorageEnabled()) mysqlDeleteBackupRecord(backupInstitutionId(), $filename);
    }
}
function createBackup(array $data, string $kind = 'manual'): array {
    ensureBackupDirectory(); $filename = backupFilename($kind); $json = json_encode(backupPayload($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents(tenantBackupDirectory() . DIRECTORY_SEPARATOR . $filename, $json, LOCK_EX) === false) respond(['error' => 'Unable to write the backup file.'], 500);
    $path = tenantBackupDirectory() . DIRECTORY_SEPARATOR . $filename;
    $backup = ['filename' => $filename, 'createdAt' => date('c'), 'size' => (int)filesize($path), 'checksum' => hash_file('sha256', $path), 'type' => $kind === 'safety' ? 'safety' : $kind];
    if (mysqlStorageEnabled()) {
        try { mysqlRegisterBackupRecord(backupInstitutionId(), $backup, $backup['type'], null, ['retentionClass' => $backup['type'] === 'safety' ? 'safety' : 'routine']); }
        catch (Throwable $error) { @unlink($path); throw $error; }
    }
    applyBackupRetention($data);
    return $backup;
}
function validateBackupPayload(mixed $payload): array {
    if (!is_array($payload) || !in_array(($payload['_backup']['format'] ?? ''), ['cacsa-cbt-backup','berevion-backup'], true) || !in_array((int)($payload['_backup']['version'] ?? 0), [1, 2], true)) respond(['error' => 'This file is not a valid CBT backup.'], 422);
    // Version 1 predates tenancy and is therefore CACSA-only. Version 2 records
    // its owner explicitly; neither may be restored across tenant boundaries.
    $backupInstitution = (int)($payload['_backup']['institutionId'] ?? 1);
    if ($backupInstitution !== backupInstitutionId()) respond(['error' => 'This backup belongs to a different institution and cannot be restored here.'], 403);
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

function sharedQuestionPoolMigrationNeeded(array $data): bool {
    foreach ($data['questions'] ?? [] as $question) {
        if (!is_array($question) || empty($question['courseId']) || !isset($question['publishedTo']) || !is_array($question['publishedTo']) || !array_key_exists('legacyComponentId', $question)) return true;
    }
    return false;
}

function normalizeQuestionPublishTargets(mixed $targets): array {
    $targets = is_array($targets) ? $targets : [];
    return array_values(array_unique(array_filter(array_map(static fn($target) => strtolower(trim((string)$target)), $targets), static fn($target) => in_array($target, ['test', 'exam'], true))));
}

function questionsPublishedForComponent(array $data, array $component): array {
    $courseId = (string)($component['courseId'] ?? '');
    $target = strtolower((string)($component['component'] ?? 'exam')) === 'test' ? 'test' : 'exam';
    if ($courseId === '') return [];
    return array_values(array_filter($data['questions'] ?? [], static fn($question) => ($question['courseId'] ?? '') === $courseId && in_array($target, normalizeQuestionPublishTargets($question['publishedTo'] ?? []), true)));
}

function migrateSharedQuestionPools(array &$data): array {
    $components = [];
    foreach ($data['exams'] ?? [] as $component) if (!empty($component['id'])) $components[(string)$component['id']] = $component;
    $migrated = 0; $unresolved = 0;
    foreach ($data['questions'] as &$question) {
        if (!is_array($question)) { $unresolved++; continue; }
        $legacyComponentId = (string)($question['legacyComponentId'] ?? $question['examId'] ?? '');
        $component = $components[$legacyComponentId] ?? null;
        if (!is_array($component) || empty($component['courseId'])) { $unresolved++; continue; }
        $before = [$question['courseId'] ?? null, $question['publishedTo'] ?? null, $question['legacyComponentId'] ?? null];
        $componentType = strtolower((string)($component['component'] ?? 'exam')) === 'test' ? 'test' : 'exam';
        $question['courseId'] = (string)$component['courseId'];
        // Keep the original component id (and existing examId) for transition-time
        // traceability while all new management views use courseId + publishedTo.
        $question['legacyComponentId'] = $legacyComponentId;
        if (!isset($question['publishedTo']) || !is_array($question['publishedTo'])) {
            $question['publishedTo'] = ($question['status'] ?? 'draft') === 'published' ? [$componentType] : [];
        } else {
            $question['publishedTo'] = normalizeQuestionPublishTargets($question['publishedTo']);
        }
        if ($before !== [$question['courseId'], $question['publishedTo'], $question['legacyComponentId']]) $migrated++;
    }
    unset($question);
    $data['migrations']['sharedQuestionPoolsV1'] = ['completedAt' => date('c'), 'migratedQuestions' => $migrated, 'unresolvedQuestions' => $unresolved];
    return ['migrated' => $migrated, 'unresolved' => $unresolved];
}
function uploadedPdfQuestionImport(int $maximumBytes = PDF_IMPORT_MAX_BYTES, string $provider = 'Gemini'): array {
    $upload = $_FILES['pdf'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) respond(['error' => 'Choose a PDF file to continue.'], 422);
    $size = (int)($upload['size'] ?? 0); $path = (string)($upload['tmp_name'] ?? '');
    if ($size < 5 || $size > $maximumBytes) respond(['error' => $provider . ' PDF imports must be no larger than ' . round($maximumBytes / 1024 / 1024) . ' MB. Split the document into smaller sections and try again.'], 422);
    if ($path === '' || !is_uploaded_file($path)) respond(['error' => 'The PDF upload could not be verified. Please choose the file again.'], 422);
    $header = file_get_contents($path, false, null, 0, 8);
    $mime = function_exists('mime_content_type') ? (string)@mime_content_type($path) : '';
    $filename = (string)($upload['name'] ?? 'questions.pdf');
    if (!str_starts_with((string)$header, '%PDF-') || ($mime !== '' && !in_array($mime, ['application/pdf', 'application/x-pdf', 'application/octet-stream'], true))) respond(['error' => 'Upload a valid PDF document, not a renamed file.'], 422);
    return ['path' => $path, 'filename' => basename($filename), 'size' => $size];
}
function queuePdfImportSource(string $provider): array {
    $limits = pdfQueueLimits($provider); $upload = uploadedPdfQuestionImport((int)$limits['bytes'], (string)$limits['label']);
    $institutionId = mysqlCurrentInstitutionId(); $directory = PDF_IMPORT_UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0700, true)) throw new PdfUploadException('PDF storage initialization failed: mkdir', 503, 'The PDF import service is temporarily unavailable. The server could not initialize storage. Please try again in a moment or contact the administrator with code: PDF_STORAGE_INIT_FAILED.');
        if (!is_dir($directory)) throw new PdfUploadException('PDF storage verification failed: directory not created', 503, 'The PDF import service is temporarily unavailable. Storage verification failed. Please try again in a moment or contact the administrator with code: PDF_STORAGE_VERIFY_FAILED.');
    }
    $jobId = bin2hex(random_bytes(16)); $filename = $jobId . '.pdf'; $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($upload['path'], $destination)) throw new PdfUploadException('PDF file move failed: move_uploaded_file(' . $upload['path'] . ' => ' . $destination . ')', 503, 'The PDF file could not be stored. The server may have insufficient disk space or permissions. Please try again in a moment or contact the administrator with code: PDF_STORAGE_MOVE_FAILED.');
    @chmod($destination, 0640);
    $checksum = hash_file('sha256', $destination);
    if ($checksum === false) throw new PdfUploadException('PDF checksum generation failed: hash_file', 503, 'The PDF file was stored but could not be verified. Please try uploading again.');
    return ['id' => $jobId, 'sourceKey' => 'uploads/pdf-imports/institution-' . $institutionId . '/' . $filename, 'filename' => $upload['filename'], 'size' => $upload['size'], 'checksum' => $checksum];
}
function pdfImportJobPayload(array $row, bool $includeItems = false): array {
    $items = $includeItems && (string)$row['status'] === 'review_ready' ? mysqlJson($row['parsed_items_json'] ?? null, []) : [];
    return ['id' => (string)$row['id'], 'courseId' => (string)$row['course_id'], 'provider' => (string)$row['provider'], 'status' => (string)$row['status'], 'filename' => (string)$row['source_filename'], 'pages' => $row['page_count'] === null ? null : (int)$row['page_count'], 'items' => is_array($items) ? $items : [], 'lowConfidence' => $row['low_confidence_count'] === null ? 0 : (int)$row['low_confidence_count'], 'attemptCount' => (int)$row['attempt_count'], 'error' => in_array((string)$row['status'], ['failed','cancelled'], true) ? (string)($row['error_message'] ?? '') : '', 'createdAt' => mysqlIso($row['created_at']), 'reviewExpiresAt' => mysqlIso($row['review_expires_at'])];
}
function currentPdfImportJob(string $jobId, array $session, bool $forUpdate = false): ?array {
    $requester = pdfImportRequester($session);
    $field = $requester['scope'] === 'platform' ? 'requested_by_platform_admin_id' : 'requested_by_admin_id';
    $statement = mysqlAppPdo()->prepare('SELECT * FROM pdf_import_jobs WHERE institution_id=:institution_id AND id=:id AND requested_by_scope=:scope AND ' . $field . '=:requester_id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $statement->execute(['institution_id' => mysqlCurrentInstitutionId(), 'id' => $jobId, 'scope' => $requester['scope'], 'requester_id' => $requester['id']]);
    return $statement->fetch() ?: null;
}
/** Return only the current caller's recoverable import for one explicitly scoped course. */
function latestRecoverablePdfImportJob(string $courseId, array $session): ?array {
    $requester = pdfImportRequester($session);
    $field = $requester['scope'] === 'platform' ? 'requested_by_platform_admin_id' : 'requested_by_admin_id';
    $statement = mysqlAppPdo()->prepare("SELECT * FROM pdf_import_jobs WHERE institution_id=:institution_id AND course_id=:course_id AND requested_by_scope=:scope AND {$field}=:requester_id AND status IN ('queued','running','review_ready') ORDER BY created_at DESC LIMIT 1");
    $statement->execute(['institution_id' => mysqlCurrentInstitutionId(), 'course_id' => $courseId, 'scope' => $requester['scope'], 'requester_id' => $requester['id']]);
    return $statement->fetch() ?: null;
}
/** Keep platform identities structurally separate from tenant admin_users.
 * Platform work is accepted only from an explicit /i/{slug}/ tenant route;
 * it never impersonates a tenant administrator. */
function pdfImportRequester(array $session): array {
    $scope = (string)($session['scope'] ?? 'institution');
    $id = trim((string)($session['userId'] ?? ''));
    if ($scope === 'platform') {
        if (!requestHasExplicitInstitutionPath() || $id === '') respond(['error' => 'Select an institution before creating a PDF import job.'], 403);
        return ['scope' => 'platform', 'id' => $id];
    }
    if ($scope !== 'institution' || $id === '' || $id === 'bootstrap-superadmin') {
        respond(['error' => 'Sign in with this institution’s Admin account before creating a PDF import job.'], 403);
    }
    return ['scope' => 'institution', 'id' => $id];
}
function schedulePdfImportSourceExpiry(PDO $pdo, int $institutionId, string $sourceKey): void {
    mysqlMarkInstitutionAssetUnreferenced($pdo, $institutionId, $sourceKey, PDF_IMPORT_SOURCE_RETENTION_HOURS);
}
function strictQuestionImportSource(): array {
    $pasted = trim((string)($_POST['text'] ?? ''));
    $upload = $_FILES['source'] ?? null;
    $hasFile = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($pasted !== '' && $hasFile) respond(['error' => 'Paste the strict-format text or choose one file, not both.'], 422);
    if ($pasted !== '') {
        if (strlen($pasted) > STRICT_IMPORT_MAX_BYTES) respond(['error' => 'Pasted text is too large. Split it into smaller question sets and try again.'], 422);
        return ['filename' => 'Pasted strict-format text', 'text' => $pasted];
    }
    if (!$hasFile || !is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) respond(['error' => 'Paste strict-format text or choose a .txt or .docx file to continue.'], 422);
    $path = (string)($upload['tmp_name'] ?? ''); $size = (int)($upload['size'] ?? 0); $filename = basename((string)($upload['name'] ?? 'questions.txt'));
    if ($size < 2 || $size > STRICT_IMPORT_MAX_BYTES) respond(['error' => 'Strict-format files must be no larger than 2 MB. Split the questions into smaller files and try again.'], 422);
    if ($path === '' || !is_uploaded_file($path)) respond(['error' => 'The uploaded file could not be verified. Please choose it again.'], 422);
    $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension === 'txt') {
        $text = file_get_contents($path);
        if ($text === false) respond(['error' => 'The text file could not be read.'], 422);
    } elseif ($extension === 'docx') {
        if (!class_exists('ZipArchive')) respond(['error' => 'Word import requires the PHP ZIP extension, which is not enabled on this server.'], 503);
        $archive = new ZipArchive();
        if ($archive->open($path) !== true) respond(['error' => 'The Word file could not be read. Upload a valid .docx document.'], 422);
        $documentXml = $archive->getFromName('word/document.xml'); $archive->close();
        if ($documentXml === false) respond(['error' => 'The Word file has no readable document content. Upload a valid .docx document.'], 422);
        $documentXml = preg_replace('/<w:tab[^>]*\/>/i', "\t", $documentXml) ?? $documentXml;
        $documentXml = preg_replace('/<\/w:p>/i', "\n", $documentXml) ?? $documentXml;
        $text = html_entity_decode(strip_tags($documentXml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    } else {
        respond(['error' => 'Use a plain-text (.txt) or Word (.docx) file for strict-format import.'], 422);
    }
    $text = trim((string)$text);
    if ($text === '') respond(['error' => 'The uploaded file contains no readable text.'], 422);
    return ['filename' => $filename, 'text' => $text];
}
function parseStrictQuestionText(string $text): array {
    $lines = preg_split('/\R/u', str_replace("\r\n", "\n", $text)) ?: [];
    $items = []; $current = null; $questionNumber = 0;
    $finish = static function (?array $question, int $number) use (&$items): void {
        if ($question === null) return;
        if (!$question['answerSeen']) respond(['error' => 'No ANS: line found for question ' . $number . '.'], 422);
        if (count($question['options']) < 2) respond(['error' => 'Question ' . $number . ' needs at least two options (for example, A) and B).'], 422);
        $indexes = [];
        foreach ($question['answers'] as $letter) {
            if (!array_key_exists($letter, $question['options'])) respond(['error' => 'Question ' . $number . ' marks ' . $letter . ' as correct, but no ' . $letter . ') option was found.'], 422);
            $indexes[] = array_search($letter, array_keys($question['options']), true);
        }
        $items[] = ['questionText' => trim($question['text']), 'options' => array_values($question['options']), 'correctOptionIndexes' => $indexes, 'confidence' => 'high'];
    };
    foreach ($lines as $lineNumber => $line) {
        $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$line) ?? (string)$line);
        if ($line === '') continue;
        if (preg_match('/^Q:\s*$/iu', $line)) respond(['error' => 'Question ' . ($questionNumber + 1) . ' has an empty Q: line.'], 422);
        if (preg_match('/^Q:\s*(.+)$/iu', $line, $match)) {
            $finish($current, $questionNumber); $questionNumber++;
            if ($questionNumber > STRICT_IMPORT_MAX_QUESTIONS) respond(['error' => 'Strict-format import supports at most ' . STRICT_IMPORT_MAX_QUESTIONS . ' questions per import.'], 422);
            $current = ['text' => trim($match[1]), 'options' => [], 'answers' => [], 'answerSeen' => false];
            continue;
        }
        if ($current === null) respond(['error' => 'Expected Q: to start question 1 (line ' . ($lineNumber + 1) . ').'], 422);
        if (preg_match('/^ANS:\s*(.*)$/iu', $line, $match)) {
            if ($current['answerSeen']) respond(['error' => 'Question ' . $questionNumber . ' has more than one ANS: line.'], 422);
            $letters = preg_split('/\s*,\s*/', strtoupper(trim($match[1]))) ?: [];
            if (!$letters || array_filter($letters, fn($letter) => !preg_match('/^[A-J]$/', $letter))) respond(['error' => 'Question ' . $questionNumber . ' has an invalid ANS: line. Use letters such as ANS: B or ANS: B,D.'], 422);
            $current['answers'] = array_values(array_unique($letters)); $current['answerSeen'] = true;
            continue;
        }
        if (preg_match('/^([A-J])\)\s*(.+)$/iu', $line, $match)) {
            if ($current['answerSeen']) respond(['error' => 'Question ' . $questionNumber . ' has an option after its ANS: line. Put ANS: after all options.'], 422);
            $letter = strtoupper($match[1]);
            if (array_key_exists($letter, $current['options'])) respond(['error' => 'Question ' . $questionNumber . ' repeats option ' . $letter . ').'], 422);
            $current['options'][$letter] = trim($match[2]);
            continue;
        }
        if ($current['answerSeen']) respond(['error' => 'Unexpected content after ANS: for question ' . $questionNumber . ' (line ' . ($lineNumber + 1) . ').'], 422);
        $current['text'] .= ' ' . $line;
    }
    $finish($current, $questionNumber);
    if (!$items) respond(['error' => 'No questions were found. Start each question with Q:.'], 422);
    return $items;
}
function listAuditArchives(): array {
    ensureBackupDirectory(); $items = [];
    foreach (glob(tenantBackupDirectory() . DIRECTORY_SEPARATOR . '*-audit-archive_*.json') ?: [] as $path) {
        if (!is_file($path) || !isAuditArchiveFilename(basename($path))) continue;
        $summary = ['eventCount' => null, 'from' => '', 'to' => ''];
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (is_array($decoded)) {
            $summary['eventCount'] = is_array($decoded['events'] ?? null) ? count($decoded['events']) : null;
            $summary['from'] = (string)($decoded['_auditArchive']['from'] ?? '');
            $summary['to'] = (string)($decoded['_auditArchive']['to'] ?? '');
        }
        $items[] = ['filename' => basename($path), 'createdAt' => date('c', (int)filemtime($path)), 'size' => (int)filesize($path)] + $summary;
    }
    usort($items, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
    return $items;
}
function decodeQuestionImportJson(string $content): mixed {
    $content = trim($content);
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
    $decoded = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    $start = strpos($content, '{'); $end = strrpos($content, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    }
    return null;
}
function questionTextFingerprint(string $text): string {
    $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    return hash('sha256', strtolower($normalized));
}
function requireUniqueQuestionBatch(array $questions, array $data, string $courseId, string $ignoreQuestionId = '', bool $skipExisting = false): array {
    $seen = [];
    foreach ($questions as $index => $question) {
        $text = (string)($question['text'] ?? $question['questionText'] ?? '');
        $fingerprint = questionTextFingerprint($text);
        if (isset($seen[$fingerprint])) respond(['error' => 'Question ' . ($index + 1) . ' repeats question ' . ($seen[$fingerprint] + 1) . '. Each imported question must be unique. Review the source PDF and try again.'], 422);
        $seen[$fingerprint] = $index;
    }
    $existing = [];
    foreach ($data['questions'] as $question) if (($question['courseId'] ?? '') === $courseId && (string)($question['id'] ?? '') !== $ignoreQuestionId) $existing[questionTextFingerprint((string)($question['text'] ?? ''))] = true;
    $existingIndexes = [];
    foreach ($seen as $fingerprint => $index) if (isset($existing[$fingerprint])) {
        if (!$skipExisting) respond(['error' => 'Question ' . ($index + 1) . ' already exists in this shared course question bank. Remove duplicate Drafts or edit the question before importing.'], 422);
        $existingIndexes[] = $index;
    }
    return $existingIndexes;
}
function requireUniqueParsedQuestions(array $items, string $provider): void {
    $seen = [];
    foreach ($items as $index => $item) {
        $fingerprint = questionTextFingerprint((string)($item['questionText'] ?? ''));
        if (isset($seen[$fingerprint])) respond(['error' => $provider . ' repeated question ' . ($seen[$fingerprint] + 1) . ' as question ' . ($index + 1) . '. Nothing was imported. Try again or use Strict-format import so every question can be read exactly.'], 422);
        $seen[$fingerprint] = $index;
    }
}
function parsePdfQuestionsWithGemini(string $text, int $maximumQuestions = PDF_IMPORT_MAX_QUESTIONS): array {
    $apiKey = trim((string)getenv('GEMINI_API_KEY'));
    if ($apiKey === '') respond(['error' => 'Gemini PDF import is not configured yet. Add GEMINI_API_KEY to the server .env file, then try again.'], 503);
    if (!function_exists('curl_init')) respond(['error' => 'Gemini PDF import requires the PHP cURL extension, which is not enabled on this server.'], 503);
    $instruction = 'Extract the first complete assessment questions in this source section, up to ' . $maximumQuestions . '. Page markers preserve source order; read every marked page before producing the next distinct question. Never invent a correct answer. Return only strict JSON: {"questions":[{"questionText":"...","options":["..."],"correctOptionIndexes":[0],"confidence":"high"}],"truncated":false}. Each question needs 2 to 10 options and zero-based answer indexes. Mark confidence low whenever the answer key is missing, ambiguous, or uncertain. Every returned item must be a different question from the source, in source order. Never repeat a question, option set, or answer merely to reach a count; return fewer questions instead. If more than ' . $maximumQuestions . ' complete questions are present, return the first ' . $maximumQuestions . ' and set truncated true. Preserve wording and options faithfully.\n\nPDF TEXT:\n' . $text;
    // Bulk extraction needs output room; this model supports the low thinking level.
    $payload = ['model' => (string)(getenv('CBT_GEMINI_MODEL') ?: 'gemini-3.8-flash'), 'input' => $instruction, 'generation_config' => ['temperature' => 0, 'thinking_level' => 'low', 'max_output_tokens' => 24000]];
    $raw = false; $error = ''; $status = 0; $response = null;
    foreach ([0, 400000, 1200000] as $delay) {
        if ($delay) usleep($delay);
        $curl = curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 180, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $raw = curl_exec($curl); $error = curl_error($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        $response = json_decode((string)$raw, true);
        if ($raw !== false && $error === '' && $status >= 200 && $status < 300 && is_array($response)) break;
        if (!in_array($status, [0, 429, 500, 502, 503, 504], true)) break;
    }
    if ($raw === false || $error !== '') { error_log('Berevion Gemini PDF import failed: ' . $error); respond(['error' => 'Gemini could not be reached after automatic retries. Check your connection and try again.'], 502); }
    if ($status < 200 || $status >= 300 || !is_array($response)) {
        $providerMessage = trim((string)($response['error']['message'] ?? ''));
        error_log('Berevion Gemini PDF import response failed: HTTP ' . $status . ($providerMessage !== '' ? ' --- ' . substr($providerMessage, 0, 300) : ''));
        if ($status === 429) respond(['error' => 'Gemini is temporarily rate-limited. The importer retried automatically; wait a moment and try again, or use OpenRouter PDF import.'], 429);
        if (in_array($status, [500, 502, 503, 504], true)) respond(['error' => 'Gemini is temporarily unavailable. The importer retried automatically; please try again in a moment, or use OpenRouter PDF import.'], 503);
        respond(['error' => $providerMessage !== '' ? 'Gemini rejected this request: ' . $providerMessage : 'Gemini could not process this PDF.'], 502);
    }
    if (($response['status'] ?? 'completed') !== 'completed') respond(['error' => 'Gemini did not finish reading this PDF. Try again, or split the document into smaller sections.'], 422);
    $content = '';
    foreach (($response['steps'] ?? []) as $step) if (($step['type'] ?? '') === 'model_output') foreach (($step['content'] ?? []) as $part) if (($part['type'] ?? '') === 'text') $content .= (string)($part['text'] ?? '');
    $decoded = decodeQuestionImportJson($content);
    $rows = is_array($decoded) && array_is_list($decoded) ? $decoded : ($decoded['questions'] ?? null);
    if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > $maximumQuestions) respond(['error' => 'Gemini could not identify between 1 and ' . $maximumQuestions . ' reviewable questions in this PDF section.'], 422);
    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $options = is_array($row['options'] ?? null) ? array_values(array_map(fn($option) => trim((string)$option), $row['options'])) : [];
        if (count($options) > 10) $options = array_slice($options, 0, 10);
        while (count($options) < 2) $options[] = '';
        $correct = is_array($row['correctOptionIndexes'] ?? null) ? array_values(array_unique(array_filter(array_map('intval', $row['correctOptionIndexes']), fn($index) => $index >= 0 && $index < count($options)))) : [];
        $confidence = ($row['confidence'] ?? '') === 'high' && trim((string)($row['questionText'] ?? '')) !== '' && !empty($correct) ? 'high' : 'low';
        $items[] = ['questionText' => trim((string)($row['questionText'] ?? '')), 'options' => $options, 'correctOptionIndexes' => $correct, 'confidence' => $confidence];
    }
    if (!$items) respond(['error' => 'Gemini did not return usable question entries. Try a clearer PDF section.'], 422);
    requireUniqueParsedQuestions($items, 'Gemini');
    usort($items, fn($a, $b) => ($a['confidence'] === 'low' ? 0 : 1) <=> ($b['confidence'] === 'low' ? 0 : 1));
    return $items;
}
function parsePdfQuestionsWithOpenRouter(string $text, int $maximumQuestions = OPENROUTER_PDF_IMPORT_MAX_QUESTIONS): array {
    $apiKey = trim((string)getenv('OPENROUTER_API_KEY'));
    if ($apiKey === '') respond(['error' => 'OpenRouter PDF import is not configured yet. Add OPENROUTER_API_KEY to the server .env file, then try again.'], 503);
    if (!function_exists('curl_init')) respond(['error' => 'OpenRouter PDF import requires the PHP cURL extension, which is not enabled on this server.'], 503);
    $maximum = $maximumQuestions;
    $instruction = 'Extract the first complete assessment questions in this source section, up to ' . $maximum . '. Page markers preserve source order; read every marked page before producing the next distinct question. Never invent a correct answer. Return only strict JSON: {"questions":[{"questionText":"...","options":["..."],"correctOptionIndexes":[0],"confidence":"high"}],"truncated":false}. Each question needs 2 to 10 options and zero-based answer indexes. Mark confidence low whenever the answer key is missing, ambiguous, or uncertain. Every returned item must be a different question from the source, in source order. Never repeat a question, option set, or answer merely to reach a count; return fewer questions instead. If more than ' . $maximum . ' complete questions are present, return the first ' . $maximum . ' and set truncated true. Preserve question wording and options faithfully.\n\nPDF TEXT:\n' . $text;
    $questionSchema = ['type' => 'object', 'properties' => ['questions' => ['type' => 'array', 'minItems' => 0, 'maxItems' => $maximum, 'items' => ['type' => 'object', 'properties' => ['questionText' => ['type' => 'string'], 'options' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 10, 'items' => ['type' => 'string']], 'correctOptionIndexes' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9]], 'confidence' => ['type' => 'string', 'enum' => ['high', 'low']]], 'required' => ['questionText', 'options', 'correctOptionIndexes', 'confidence'], 'additionalProperties' => false]], 'truncated' => ['type' => 'boolean']], 'required' => ['questions', 'truncated'], 'additionalProperties' => false];
    $payload = ['model' => (string)(getenv('CBT_OPENROUTER_MODEL') ?: 'openrouter/free'), 'messages' => [['role' => 'system', 'content' => 'You are a precise assessment-question extractor. Follow the requested JSON schema exactly and never add prose.'], ['role' => 'user', 'content' => $instruction]], 'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'assessment_questions', 'strict' => true, 'schema' => $questionSchema]], 'provider' => ['require_parameters' => true, 'allow_fallbacks' => true], 'temperature' => 0, 'max_tokens' => 12000];
    $raw = false; $error = ''; $status = 0; $response = null;
    foreach ([0, 400000, 1200000] as $delay) {
        if ($delay) usleep($delay);
        $curl = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 180, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey, 'X-Title: ' . institutionPortalTitle()], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $raw = curl_exec($curl); $error = curl_error($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        $response = json_decode((string)$raw, true);
        if ($raw !== false && $error === '' && $status >= 200 && $status < 300 && is_array($response)) break;
        if (!in_array($status, [0, 408, 429, 500, 502, 503, 504], true)) break;
    }
    if ($raw === false || $error !== '') { error_log('Berevion OpenRouter PDF import connection failed after retries: HTTP ' . $status . ' --- ' . $error); respond(['error' => 'OpenRouter could not be reached after automatic retries. Check this server’s outbound HTTPS connection, then try again.'], 502); }
    if ($status < 200 || $status >= 300 || !is_array($response)) {
        $providerMessage = trim((string)($response['error']['message'] ?? ''));
        error_log('Berevion OpenRouter PDF import response failed: HTTP ' . $status . ($providerMessage !== '' ? ' --- ' . substr($providerMessage, 0, 300) : ''));
        if ($status === 401) respond(['error' => 'OpenRouter rejected the configured API key. Update OPENROUTER_API_KEY and try again.'], 503);
        if ($status === 403) respond(['error' => 'OpenRouter denied this request. Check the API key account and its access settings.'], 503);
        if ($status === 429) respond(['error' => 'OpenRouter is temporarily rate-limited. Wait a moment, then try again.'], 429);
        respond(['error' => $providerMessage !== '' ? 'OpenRouter rejected this request: ' . $providerMessage : 'OpenRouter could not process this PDF. Please try again or use a smaller section.'], 502);
    }
    $content = $response['choices'][0]['message']['content'] ?? '';
    if (is_array($content)) $content = implode("\n", array_map(fn($part) => is_array($part) ? (string)($part['text'] ?? '') : (string)$part, $content));
    $decoded = decodeQuestionImportJson((string)$content);
    $rows = is_array($decoded) && array_is_list($decoded) ? $decoded : ($decoded['questions'] ?? null);
    if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > $maximum) respond(['error' => 'OpenRouter could not identify between 1 and ' . $maximum . ' reviewable questions in this PDF.'], 422);
    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $options = is_array($row['options'] ?? null) ? array_values(array_map(fn($option) => trim((string)$option), $row['options'])) : [];
        if (count($options) > 10) $options = array_slice($options, 0, 10);
        while (count($options) < 2) $options[] = '';
        $correct = is_array($row['correctOptionIndexes'] ?? null) ? array_values(array_unique(array_filter(array_map('intval', $row['correctOptionIndexes']), fn($index) => $index >= 0 && $index < count($options)))) : [];
        $confidence = ($row['confidence'] ?? '') === 'high' && trim((string)($row['questionText'] ?? '')) !== '' && !empty($correct) ? 'high' : 'low';
        $items[] = ['questionText' => trim((string)($row['questionText'] ?? '')), 'options' => $options, 'correctOptionIndexes' => $correct, 'confidence' => $confidence];
    }
    if (!$items) respond(['error' => 'OpenRouter did not return usable question entries. Try a clearer PDF section.'], 422);
    requireUniqueParsedQuestions($items, 'OpenRouter');
    usort($items, fn($a, $b) => ($a['confidence'] === 'low' ? 0 : 1) <=> ($b['confidence'] === 'low' ? 0 : 1));
    return $items;
}
function pdfExtractionSections(array $pages, int $pagesPerSection = 5): array {
    $sections = []; $buffer = [];
    foreach ($pages as $index => $content) {
        $buffer[] = "--- PDF PAGE " . ($index + 1) . " ---\n" . $content;
        if (count($buffer) >= $pagesPerSection) { $sections[] = implode("\n\n", $buffer); $buffer = []; }
    }
    if ($buffer) $sections[] = implode("\n\n", $buffer);
    return $sections;
}
function extractPdfQuestionCandidates(string $provider = 'gemini'): array {
    @set_time_limit(900);
    $openRouter = $provider === 'openrouter';
    $maximumBytes = $openRouter ? OPENROUTER_PDF_IMPORT_MAX_BYTES : PDF_IMPORT_MAX_BYTES;
    $maximumPages = $openRouter ? OPENROUTER_PDF_IMPORT_MAX_PAGES : PDF_IMPORT_MAX_PAGES;
    $maximumQuestions = $openRouter ? OPENROUTER_PDF_IMPORT_MAX_QUESTIONS : PDF_IMPORT_MAX_QUESTIONS;
    $providerName = $openRouter ? 'OpenRouter' : 'Gemini';
    $upload = uploadedPdfQuestionImport($maximumBytes, $providerName);
    if (!class_exists('Smalot\\PdfParser\\Parser')) respond(['error' => 'PDF import is unavailable because the PDF parser is not installed.'], 503);
    try {
        $parser = new \Smalot\PdfParser\Parser(); $document = $parser->parseFile($upload['path']); $pages = $document->getPages();
        if (count($pages) > $maximumPages) respond(['error' => 'This PDF has more than ' . $maximumPages . ' pages. Split it by topic or chapter, keeping its questions and answer key together, then import each section separately.'], 422);
        $pageText = [];
        foreach ($pages as $index => $page) {
            $content = trim((string)$page->getText());
            if ($content !== '') $pageText[] = $content;
        }
    } catch (Throwable $error) {
        error_log('Berevion PDF parsing failed: ' . $error->getMessage()); respond(['error' => 'This PDF could not be read. Upload a text-based PDF; scanned image-only PDFs need OCR and are not supported yet.'], 422);
    }
    $text = trim(implode("\n\n", $pageText));
    if ($text === '') respond(['error' => 'This PDF has no extractable text. Upload a text-based PDF; scanned image-only PDFs are not supported yet.'], 422);
    $items = [];
    foreach (pdfExtractionSections($pageText) as $section) {
        $remaining = $maximumQuestions - count($items);
        if ($remaining <= 0) break;
        $sectionLimit = min(20, $remaining);
        $sectionItems = $openRouter ? parsePdfQuestionsWithOpenRouter($section, $sectionLimit) : parsePdfQuestionsWithGemini($section, $sectionLimit);
        array_push($items, ...$sectionItems);
    }
    if (count($items) > $maximumQuestions) $items = array_slice($items, 0, $maximumQuestions);
    requireUniqueParsedQuestions($items, $providerName);
    usort($items, fn($a, $b) => ($a['confidence'] === 'low' ? 0 : 1) <=> ($b['confidence'] === 'low' ? 0 : 1));
    return ['filename' => $upload['filename'], 'pages' => count($pages), 'items' => $items, 'limits' => ['pages' => $maximumPages, 'questions' => $maximumQuestions, 'bytes' => $maximumBytes]];
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
function runScheduledAuditArchival(array &$data, bool $force = false): ?array {
    $settings = auditRetentionSettings($data); $today = date('Y-m-d');
    if (!$force && ($settings['lastRunDate'] === $today || date('H:i') < $settings['time'])) return null;
    $cutoff = (new DateTimeImmutable('now'))->modify('-' . $settings['months'] . ' months');
    $archived = []; $active = [];
    foreach ($data['auditEvents'] ?? [] as $event) {
        $timestamp = strtotime((string)($event['timestamp'] ?? ''));
        if (is_array($event) && $timestamp !== false && $timestamp < $cutoff->getTimestamp()) $archived[] = $event;
        else $active[] = $event;
    }
    $settings['lastRunDate'] = $today;
    if (!$archived) {
        $data['settings']['auditRetention'] = $settings;
        saveData($data);
        return null;
    }
    ensureBackupDirectory(); $filename = auditArchiveFilename();
    $timestamps = array_column($archived, 'timestamp'); sort($timestamps);
    $payload = ['_auditArchive' => ['format' => 'berevion-audit-archive', 'version' => 1, 'createdAt' => date('c'), 'retentionMonths' => $settings['months'], 'from' => (string)($timestamps[0] ?? ''), 'to' => (string)($timestamps[count($timestamps) - 1] ?? '')], 'events' => $archived];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents(tenantBackupDirectory() . DIRECTORY_SEPARATOR . $filename, $json, LOCK_EX) === false) respond(['error' => 'Unable to write the audit archive file.'], 500);
    $data['auditEvents'] = array_values($active);
    $settings['lastArchivedAt'] = date('c'); $settings['lastArchiveFilename'] = $filename;
    $data['settings']['auditRetention'] = $settings;
    auditEvent($data, 'system', 'audit_archiver', 'audit_events_archived', 'audit_archive', $filename, ['filename' => $filename, 'eventCount' => count($archived), 'from' => $payload['_auditArchive']['from'], 'to' => $payload['_auditArchive']['to'], 'retentionMonths' => $settings['months']]);
    saveData($data);
    return ['filename' => $filename, 'eventCount' => count($archived)];
}

function id(): string { return bin2hex(random_bytes(8)); }
function secretToken(): string { return bin2hex(random_bytes(32)); }
function tokenHash(string $token): string { return hash('sha256', $token); }
function passwordHash(string $password): string {
    return defined('PASSWORD_ARGON2ID') ? password_hash($password, PASSWORD_ARGON2ID) : password_hash($password, PASSWORD_DEFAULT);
}
function twoFactorEncryptionKey(): string {
    $configured = trim((string)getenv('CBT_2FA_ENCRYPTION_KEY'));
    if ($configured !== '') return hash('sha256', $configured, true);
    $directory = dirname(TWO_FACTOR_KEY_FILE);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Could not create the two-factor key directory.');
    $encoded = is_readable(TWO_FACTOR_KEY_FILE) ? trim((string)file_get_contents(TWO_FACTOR_KEY_FILE)) : '';
    if ($encoded === '') {
        $encoded = base64_encode(random_bytes(32));
        if (file_put_contents(TWO_FACTOR_KEY_FILE, $encoded . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Could not create the two-factor encryption key.');
        @chmod(TWO_FACTOR_KEY_FILE, 0600);
    }
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) throw new RuntimeException('The two-factor encryption key is invalid.');
    return $key;
}
function encryptTwoFactorSecret(string $secret): string {
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL is required for two-factor authentication.');
    $iv = random_bytes(12); $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', twoFactorEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false || strlen($tag) !== 16) throw new RuntimeException('Could not protect the two-factor secret.');
    return 'v1.' . base64_encode($iv . $tag . $ciphertext);
}
function decryptTwoFactorSecret(string $encrypted): ?string {
    if (!str_starts_with($encrypted, 'v1.') || !function_exists('openssl_decrypt')) return null;
    $payload = base64_decode(substr($encrypted, 3), true);
    if ($payload === false || strlen($payload) <= 28) return null;
    $iv = substr($payload, 0, 12); $tag = substr($payload, 12, 16); $ciphertext = substr($payload, 28);
    $secret = openssl_decrypt($ciphertext, 'aes-256-gcm', twoFactorEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($secret) && preg_match('/^[A-Z2-7]{16,128}$/', $secret) ? $secret : null;
}
function base32Encode(string $value): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $buffer = 0; $bits = 0; $output = '';
    foreach (unpack('C*', $value) as $byte) {
        $buffer = ($buffer << 8) | $byte; $bits += 8;
        while ($bits >= 5) { $bits -= 5; $output .= $alphabet[($buffer >> $bits) & 31]; }
    }
    if ($bits > 0) $output .= $alphabet[($buffer << (5 - $bits)) & 31];
    return $output;
}
function base32Decode(string $value): ?string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $value = strtoupper(preg_replace('/[\s-]+/', '', $value));
    if ($value === '' || preg_match('/[^A-Z2-7]/', $value)) return null;
    $buffer = 0; $bits = 0; $output = '';
    foreach (str_split($value) as $character) {
        $position = strpos($alphabet, $character); if ($position === false) return null;
        $buffer = ($buffer << 5) | $position; $bits += 5;
        while ($bits >= 8) { $bits -= 8; $output .= chr(($buffer >> $bits) & 255); }
    }
    return $output === '' ? null : $output;
}
function twoFactorCode(string $secret, int $offset = 0): string {
    $key = base32Decode($secret); if ($key === null) return '';
    $counter = intdiv(time(), 30) + $offset; $binaryCounter = pack('N2', 0, $counter);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true); $index = ord($hash[19]) & 15;
    $number = ((ord($hash[$index]) & 127) << 24) | ((ord($hash[$index + 1]) & 255) << 16) | ((ord($hash[$index + 2]) & 255) << 8) | (ord($hash[$index + 3]) & 255);
    return str_pad((string)($number % 1000000), 6, '0', STR_PAD_LEFT);
}
function verifyTwoFactorCode(string $secret, string $code): bool {
    if (!preg_match('/^\d{6}$/', $code)) return false;
    foreach ([-1, 0, 1] as $offset) if (hash_equals(twoFactorCode($secret, $offset), $code)) return true;
    return false;
}
function twoFactorEnabled(array $account): bool {
    return !empty($account['twoFactor']['enabled']) && is_string($account['twoFactor']['secretEncrypted'] ?? null);
}
function emergencyRecoveryCode(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $groups = [];
    for ($group = 0; $group < 4; $group++) {
        $value = '';
        for ($index = 0; $index < 5; $index++) $value .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $groups[] = $value;
    }
    return 'BEREVION-' . implode('-', $groups);
}
function normalizeEmergencyRecoveryCode(string $code): string {
    return strtoupper(trim(preg_replace('/\s+/', '', $code)));
}
function emergencyRecoverySummary(array $data): array {
    $record = is_array($data['emergencyRecoveryCodes'] ?? null) ? $data['emergencyRecoveryCodes'] : [];
    $codes = is_array($record['codes'] ?? null) ? $record['codes'] : [];
    return ['active' => count($codes) > 0, 'remaining' => count($codes), 'generatedAt' => $record['generatedAt'] ?? null];
}
function cookiePath(): string {
    // Use the public request URI rather than SCRIPT_NAME. On the ngrok/XAMPP
    // path SCRIPT_NAME can be the physical "CACSA LAUTECH CBT" directory even
    // though the browser is visiting /BEREVION. That made a successful
    // sign-in set a cookie for a different path, so the next admin request did
    // not send it back. REQUEST_URI always reflects the browser-visible route.
    $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $apiMarker = stripos($requestPath, '/api.php');
    $directory = $apiMarker === false
        ? str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')))
        : substr($requestPath, 0, $apiMarker);
    if ($directory === '/' || $directory === '.' || $directory === '\\') return '/';
    $segments = array_values(array_filter(explode('/', trim($directory, '/')), static fn(string $segment): bool => $segment !== ''));
    if (!$segments) return '/';
    return '/' . implode('/', array_map(static fn(string $segment): string => rawurlencode(rawurldecode($segment)), $segments)) . '/';
}
function secureCookie(): bool {
    $forwardedProtocol = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    return $forwardedProtocol === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}
function cookieValue(string $name): string { return is_string($_COOKIE[$name] ?? null) ? (string)$_COOKIE[$name] : ''; }
function cookieValues(string $name): array {
    // PHP collapses duplicate cookie names into one $_COOKIE entry. Older
    // releases used other paths, so retain all values from the raw header and
    // let authentication choose the currently valid session rather than a
    // stale duplicate selected by the browser/PHP parser.
    $values = [];
    $primary = cookieValue($name);
    if ($primary !== '') $values[] = $primary;
    foreach (explode(';', (string)($_SERVER['HTTP_COOKIE'] ?? '')) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key === $name && $value !== '') $values[] = rawurldecode($value);
    }
    return array_values(array_unique($values));
}
function setSessionCookie(string $name, string $value, int $expiresAt): void {
    $path = cookiePath();
    if ($path !== '/') clearLegacyRootCookie($name);
    setcookie($name, $value, ['expires' => $expiresAt, 'path' => $path, 'secure' => secureCookie(), 'httponly' => true, 'samesite' => 'Strict']);
}
function clearSessionCookie(string $name): void {
    $path = cookiePath();
    setcookie($name, '', ['expires' => time() - 3600, 'path' => $path, 'secure' => secureCookie(), 'httponly' => true, 'samesite' => 'Strict']);
    if ($path !== '/') clearLegacyRootCookie($name);
}
function clearLegacyRootCookie(string $name): void {
    // Remove the broad pre-tenant cookie path during a successful login. The
    // current, browser-visible path is set immediately afterwards.
    setcookie($name, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => secureCookie(), 'httponly' => true, 'samesite' => 'Strict']);
}
function requireCsrf(): void {
    $cookie = cookieValue('CBT_CSRF'); $header = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? ''); $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (($origin !== '' && strtolower((string)parse_url($origin, PHP_URL_HOST) . ((parse_url($origin, PHP_URL_PORT)) ? ':' . parse_url($origin, PHP_URL_PORT) : '')) !== $host) || $cookie === '' || $header === '' || !hash_equals($cookie, $header)) respond(['error' => 'Security verification failed. Refresh the page and try again.'], 403);
}
function clientFingerprint(): string {
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    // A tunnel/reverse proxy (such as ngrok) makes REMOTE_ADDR the proxy address.
    // X-Forwarded-For can be spoofed on direct traffic: in a fixed production
    // proxy deployment, only accept it after allow-listing the trusted proxy IPs.
    $forwarded = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '');
    $real = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if (filter_var($forwarded, FILTER_VALIDATE_IP)) $remote = $forwarded;
    elseif (filter_var($real, FILTER_VALIDATE_IP)) $remote = $real;
    if ($remote === '::1') return '127.0.0.1';
    if (str_starts_with(strtolower($remote), '::ffff:')) return substr($remote, 7);
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}
function auditUserAgent(): string {
    $agent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $agent === '' ? 'unknown' : substr($agent, 0, 1000);
}
function auditOutcome(string $actionType, array $metadata): string {
    if (in_array(($metadata['outcome'] ?? null), ['success', 'failure'], true)) return $metadata['outcome'];
    return preg_match('/(?:failed|blocked|lockout|denied|rejected)/i', $actionType) ? 'failure' : 'success';
}
function auditCorrelationId(array $metadata = []): string {
    if (!empty($metadata['correlationId']) && is_string($metadata['correlationId'])) return substr($metadata['correlationId'], 0, 80);
    global $data;
    if (isset($data) && is_array($data)) {
        $adminSession = adminSessionRecord($data);
        if ($adminSession) return (string)($adminSession['correlationId'] ?? ('admin-' . substr((string)$adminSession['tokenHash'], 0, 16)));
        $examToken = cookieValue('CBT_EXAM_SESSION');
        if ($examToken !== '') {
            $session = findBy($data['sessions'] ?? [], 'accessTokenHash', tokenHash($examToken));
            if ($session) return (string)($session['correlationId'] ?? ('exam-' . substr((string)$session['accessTokenHash'], 0, 16)));
        }
        $loginToken = cookieValue('CBT_EXAM_LOGIN');
        if ($loginToken !== '') {
            $session = findBy($data['loginTokens'] ?? [], 'tokenHash', tokenHash($loginToken));
            if ($session) return (string)($session['correlationId'] ?? ('exam-login-' . substr((string)$session['tokenHash'], 0, 16)));
        }
    }
    static $requestCorrelationId = null;
    return $requestCorrelationId ??= 'request-' . id();
}
function auditSummaryValue(mixed $value): mixed {
    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) return $value;
    if (is_string($value)) return strlen($value) > 180 ? substr($value, 0, 177) . '...' : $value;
    if (is_array($value)) return count($value) . ' item' . (count($value) === 1 ? '' : 's');
    return substr((string)$value, 0, 180);
}
function auditBeforeAfter(array $before, array $after, array $fields): array {
    $changes = [];
    foreach ($fields as $field) {
        $old = $before[$field] ?? null; $new = $after[$field] ?? null;
        if ($old !== $new) $changes[$field] = ['before' => auditSummaryValue($old), 'after' => auditSummaryValue($new)];
    }
    return $changes;
}
function auditEvent(array &$data, string $actorType, string $actorId, string $actionType, string $targetType, string $targetId, array $metadata = []): void {
    if ($actorType === 'admin') { $session = adminSessionRecord($data); if (!empty($session['email'])) $actorId = $session['email']; }
    $data['auditEvents'] ??= [];
    if (count($data['auditEvents']) >= 20000) array_shift($data['auditEvents']);
    $data['auditEvents'][] = [
        'id' => id(), 'timestamp' => date('c'), 'actorType' => $actorType, 'actorId' => $actorId,
        'actionType' => $actionType, 'targetType' => $targetType, 'targetId' => $targetId,
        'ipAddress' => clientFingerprint(), 'userAgent' => auditUserAgent(), 'outcome' => auditOutcome($actionType, $metadata),
        'correlationId' => auditCorrelationId($metadata), 'beforeAfter' => is_array($metadata['beforeAfter'] ?? null) ? $metadata['beforeAfter'] : [], 'metadata' => $metadata
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
/**
 * Resolve administrator identity according to the server-visible route.
 *
 * A browser may legitimately hold a platform cookie at /BEREVION/ and a
 * tenant cookie at /BEREVION/i/{slug}/.  On an explicit tenant route the
 * tenant identity must win when it is valid; otherwise a platform Super
 * Admin would silently mask the tenant Admin and make tenant-only features
 * appear unavailable.  If no tenant session is present, the platform session
 * remains a valid explicit-support-access fallback.
 */
function mysqlRouteAwareAdminSession(): ?array {
    if (!mysqlStorageEnabled()) return null;
    $tokens = cookieValues('CBT_ADMIN_SESSION');
    $tenantFirst = requestHasExplicitInstitutionPath();
    foreach ($tokens as $token) {
        $hash = tokenHash($token);
        $candidate = $tenantFirst ? mysqlTenantSession($hash) : mysqlPlatformSession($hash);
        if (adminSessionIsLive($candidate)) return $candidate;
    }
    foreach ($tokens as $token) {
        $hash = tokenHash($token);
        $candidate = $tenantFirst ? mysqlPlatformSession($hash) : mysqlTenantSession($hash);
        if (adminSessionIsLive($candidate)) return $candidate;
    }
    return null;
}
function currentRequestUsesPlatformSession(): bool {
    if (!mysqlStorageEnabled()) return false;
    $resolved = mysqlRouteAwareAdminSession();
    if ($resolved !== null) return ($resolved['scope'] ?? '') === 'platform';
    foreach (cookieValues('CBT_ADMIN_SESSION') as $token) if (mysqlPlatformSession(tokenHash($token)) !== null) return true;
    return false;
}
function consumeCurrentRequestRateLimit(string $key, int $maximum, int $windowSeconds): bool {
    if (!mysqlStorageEnabled()) return false;
    return currentRequestUsesPlatformSession()
        ? mysqlConsumePlatformRateLimit($key, $maximum, $windowSeconds)
        : mysqlConsumeRateLimit($key, $maximum, $windowSeconds);
}
function enforceRateLimit(array &$data, string $scope, int $maximum, int $windowSeconds): string {
    $now = time();
    $key = hash('sha256', $scope . '|' . clientFingerprint());
    if (mysqlStorageEnabled()) {
        if (!consumeCurrentRequestRateLimit($key, $maximum, $windowSeconds)) respond(['error' => 'Too many sign-in attempts. Please wait a few minutes and try again.'], 429);
        return $key;
    }
    $data['rateLimits'] ??= [];
    $data['rateLimits'] = array_filter($data['rateLimits'], fn($item) => is_array($item) && (int)($item['resetAt'] ?? 0) > $now);
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
function enforceSystemRateLimit(array &$data, string $scope, int $maximum, int $windowSeconds, string $label = 'PDF-import'): void {
    if (mysqlStorageEnabled()) {
        if (!consumeCurrentRequestRateLimit(hash('sha256', $scope . '|system'), $maximum, $windowSeconds)) respond(['error' => 'The daily ' . $label . ' allowance has been reached. Please try again tomorrow.'], 429);
        return;
    }
    $now = time(); $data['rateLimits'] ??= []; $data['rateLimits'] = array_filter($data['rateLimits'], fn($item) => is_array($item) && (int)($item['resetAt'] ?? 0) > $now);
    $key = hash('sha256', $scope . '|system'); $record = $data['rateLimits'][$key] ?? ['count' => 0, 'resetAt' => $now + $windowSeconds];
    if ((int)$record['count'] >= $maximum) { saveData($data); respond(['error' => 'The daily ' . $label . ' allowance has been reached. Please try again tomorrow.'], 429); }
    $record['count'] = (int)$record['count'] + 1; $data['rateLimits'][$key] = $record; saveData($data);
}
function enforceGeneralApiRateLimit(array &$data): void {
    $now = time(); $windowSeconds = 60; $action = (string)($_GET['action'] ?? '');
    // Exam traffic must not share one broad IP bucket: a legitimate campus
    // lab can place hundreds of students behind one NAT address.  Login has
    // its own failed-credential controls; an active attempt is limited by its
    // opaque session token, while admin traffic is limited by its own session.
    if ($action === 'student-login') return;
    $identity = 'ip:' . clientFingerprint(); $scope = 'public-read'; $maximum = 180;
    $examToken = cookieValue('CBT_EXAM_SESSION'); $adminToken = cookieValue('CBT_ADMIN_SESSION');
    if (in_array($action, ['session-answer','session-resume','session-start','session-submit'], true) && $examToken !== '') {
        $identity = 'exam:' . tokenHash($examToken);
        [$scope, $maximum] = match ($action) {
            'session-answer' => ['exam-autosave', 1200],
            'session-resume' => ['exam-resume', 120],
            'session-start' => ['exam-start', 30],
            default => ['exam-submit', 20],
        };
    } elseif ($adminToken !== '') {
        $identity = 'admin:' . tokenHash($adminToken); $scope = 'admin-' . ($action ?: 'request'); $maximum = 600;
    } elseif ($action === 'health') {
        $scope = 'health'; $maximum = 1000;
    }
    $key = hash('sha256', $scope . '|' . $identity);
    if (mysqlStorageEnabled()) {
        if (!consumeCurrentRequestRateLimit($key, $maximum, $windowSeconds)) respond(['error' => 'Too many requests from this network. Please wait a moment and try again.'], 429);
        return;
    }
    $record = $data['rateLimits'][$key] ?? ['count' => 0, 'resetAt' => $now + $windowSeconds];
    if ((int)($record['resetAt'] ?? 0) <= $now) $record = ['count' => 0, 'resetAt' => $now + $windowSeconds];
    if ((int)$record['count'] >= $maximum) respond(['error' => 'Too many requests from this network. Please wait a moment and try again.'], 429);
    $record['count'] = (int)$record['count'] + 1; $data['rateLimits'][$key] = $record;
}
function clearRateLimit(array &$data, string $key): void {
    if (mysqlStorageEnabled()) { if (!currentRequestUsesPlatformSession()) mysqlClearRateLimit($key); return; }
    unset($data['rateLimits'][$key]);
}
function requiredPermission(): ?string {
    $action = (string)($_GET['action'] ?? '');
    return match ($action) {
        'students', 'students-bulk', 'exam-password', 'student-results', 'student-results-csv' => 'students',
        'exams', 'courses', 'course-components', 'algebra-setup-suggestion' => 'exams', 'questions', 'questions-bulk', 'questions-bulk-delete', 'questions-publish-target', 'questions-unpublish-target', 'questions-deduplicate', 'strict-question-parse', 'strict-question-import', 'pdf-question-parse', 'pdf-question-import', 'openrouter-pdf-question-parse', 'openrouter-pdf-question-import', 'pdf-import-jobs', 'pdf-import-job-import', 'algebra-question-draft', 'algebra-question-import' => 'questions', 'results', 'result-review', 'calculate-student-result', 'reset-student-result', 'component-submission-delete', 'algebra-performance-insight', 'algebra-result-report' => 'results',
        'audit-monitor', 'audit-events', 'audit-archives', 'exam-session-unlock', 'algebra-audit-digest', 'algebra-anomaly-flags' => 'audit', 'newsletter-subscribers', 'newsletters', 'algebra-communication-draft' => 'newsletter', 'settings' => 'settings', 'roles', 'admin-users', 'admin-approvals' => 'roles',
        'academic-sessions', 'semesters' => 'settings', 'backups', 'emergency-codes' => 'roles',
        'dashboard', 'dashboard-outcomes', 'dashboard-portal-mode' => 'overview', default => null
    };
}
function isInstitutionAdminRole(string $roleId): bool {
    // superadmin is accepted only as a short-lived compatibility role for the
    // migrated CACSA bootstrap session; newly provisioned tenants use admin.
    return in_array($roleId, ['admin', 'superadmin'], true);
}
function requestHasExplicitInstitutionPath(): bool {
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    return (bool)preg_match('~(?:^|/)i/[a-z0-9][a-z0-9-]{0,118}(?:/|$)~i', $path);
}
/**
 * Tenant settings are always tied to the institution resolved from the URL.
 * A platform session may manage them only after deliberately entering that
 * tenant path; the unscoped platform workspace never implies institution 1.
 */
function canManageCurrentInstitutionSettings(array $session): bool {
    if (($session['scope'] ?? '') === 'platform') return mysqlStorageEnabled() && requestHasExplicitInstitutionPath();
    return isInstitutionAdminRole((string)($session['roleId'] ?? ''));
}
function adminSessionIsLive(?array $record): bool {
    // Do not expire an administrator because of elapsed time or inactivity.
    // A record is valid until one of the deliberate revocation actions removes
    // it from storage (logout, account disablement, password/session revoke,
    // or emergency recovery).
    return is_array($record) && !empty($record['tokenHash']);
}
function adminSessionRecord(array $data): ?array {
    if (mysqlStorageEnabled()) {
        $resolved = mysqlRouteAwareAdminSession();
        if (adminSessionIsLive($resolved)) return $resolved;
    }
    // Check every same-name cookie, if a legacy path left duplicates behind.
    // Only a live record may authenticate this request; a stale token can
    // never mask a valid newly-issued session.
    $tokens = cookieValues('CBT_ADMIN_SESSION');
    foreach ($tokens as $token) {
        $tokenHash = tokenHash($token);
        $platformRecord = mysqlStorageEnabled() ? mysqlPlatformSession($tokenHash) : null;
        if (adminSessionIsLive($platformRecord)) return $platformRecord;
        $institutionRecord = findBy($data['adminSessions'] ?? [], 'tokenHash', $tokenHash);
        if (adminSessionIsLive($institutionRecord)) return $institutionRecord;
    }
    return null;
}
function auth(bool $admin = false): ?array {
    global $data;
    if (!$admin) return null;
    $record = adminSessionRecord($data);
    if (!adminSessionIsLive($record)) {
        respond(['error' => 'No active admin sign-in was found. Please sign in again.'], 401);
    }
    $account = currentAdminAccount($data, $record);
    if (!$account || empty($account['active'])) {
        respond(['error' => 'This administrator account is no longer active.'], 403);
    }
    $action = (string)($_GET['action'] ?? '');
    if (!empty($account['mustChangePassword']) && !in_array($action, ['admin-account', 'admin-logout'], true)) {
        respond(['error' => 'Change the initial temporary administrator password before accessing the administration workspace.'], 403);
    }
    $roleId = $record['roleId'] ?? 'superadmin';
    $isPlatform = ($record['scope'] ?? '') === 'platform';
    if (in_array($action, ['roles', 'admin-users', 'admin-approvals', 'backups', 'backup-restore-validate', 'backup-restore', 'emergency-codes', 'component-submission-delete'], true) && !$isPlatform && !isInstitutionAdminRole((string)$roleId)) respond(['error' => 'Only this institution’s Admin can manage roles, administrator accounts, approval requests, backups, emergency codes, and submission deletion.'], 403);
    $permission = requiredPermission(); $currentRole = findBy($data['roles'], 'id', $roleId); $permissions = $isPlatform ? ADMIN_PERMISSIONS : ($currentRole['permissions'] ?? ($record['permissions'] ?? ADMIN_PERMISSIONS));
    if ($permission && !in_array($permission, $permissions, true)) {
        respond(['error' => 'Your role does not have permission to perform this action.'], 403);
    }
    $record['lastSeenAt'] = date('c'); $record['expiresAt'] = ADMIN_SESSION_EXPIRES_AT;
    if ($isPlatform) mysqlUpdatePlatformSession((string)$record['tokenHash'], (string)$record['lastSeenAt'], (string)$record['expiresAt']);
    elseif (mysqlStorageEnabled()) mysqlUpdateTenantSession((string)$record['tokenHash'], (string)$record['lastSeenAt'], (string)$record['expiresAt']);
    else { replaceBy($data['adminSessions'], 'tokenHash', (string)$record['tokenHash'], $record); saveData($data); }
    return $record;
}
function adminActorId(): string {
    global $data;
    $record = adminSessionRecord($data);
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
    unset($user['passwordHash'], $user['twoFactor'], $user['twoFactorPending']); $role = findBy($roles, 'id', $user['roleId'] ?? '');
    $user['roleName'] = $role['name'] ?? 'Unknown role';
    $user['verified'] = array_key_exists('verified', $user) ? (bool)$user['verified'] : true;
    $user['lastLoginAt'] = $activity[$user['id']]['lastLoginAt'] ?? ($user['lastLoginAt'] ?? null);
    return $user;
}
function bootstrapAdminAccount(array $data): array {
    if (mysqlStorageEnabled()) {
        $platform = mysqlPlatformAdminAccount();
        if ($platform) return $platform;
    }
    $override = $data['adminProfileOverrides']['bootstrap-superadmin'] ?? [];
    $environmentEmail = getenv('CBT_ADMIN_EMAIL'); $environmentPassword = getenv('CBT_ADMIN_PASSWORD_HASH');
    $account = [
        'id' => 'bootstrap-superadmin', 'name' => $override['name'] ?? 'Superadmin',
        'email' => $environmentEmail ?: ($override['email'] ?? DEV_ADMIN_EMAIL),
        'phoneNumber' => $override['phoneNumber'] ?? '',
        'passwordHash' => $environmentPassword ?: ($override['passwordHash'] ?? DEV_ADMIN_PASSWORD_HASH),
        'roleId' => 'superadmin', 'active' => true, 'verified' => true, 'systemLocked' => true,
        'emailManagedByEnvironment' => $environmentEmail !== false && $environmentEmail !== '',
        'passwordManagedByEnvironment' => $environmentPassword !== false && $environmentPassword !== '',
        'mustChangePassword' => ($environmentPassword === false || $environmentPassword === '') && empty($override['passwordHash'])
    ];
    foreach (['twoFactor', 'twoFactorPending'] as $field) if (isset($override[$field]) && is_array($override[$field])) $account[$field] = $override[$field];
    return $account;
}
function institutionRecoveryAdminAccount(array $data): ?array {
    foreach ($data['adminUsers'] as $account) {
        if (($account['roleId'] ?? '') === 'admin' && !empty($account['active'])) return $account;
    }
    return null;
}
function currentAdminAccount(array $data, array $session): ?array {
    if (($session['scope'] ?? '') === 'platform') return bootstrapAdminAccount($data);
    if (($session['userId'] ?? '') === 'bootstrap-superadmin') {
        return [
            'id' => 'bootstrap-superadmin',
            'name' => (string)($session['name'] ?? 'Superadmin'),
            'email' => (string)($session['email'] ?? ''),
            'roleId' => (string)($session['roleId'] ?? 'superadmin'),
            'active' => true,
            'verified' => true,
            'scope' => 'institution',
            'mustChangePassword' => false,
        ];
    }
    return findBy($data['adminUsers'], 'id', (string)($session['userId'] ?? ''));
}
function persistAdminAccount(array &$data, array $account): void {
    if (($account['scope'] ?? '') === 'platform' && mysqlStorageEnabled()) { mysqlPersistPlatformAdmin($account); return; }
    if (($account['id'] ?? '') !== 'bootstrap-superadmin') { replaceBy($data['adminUsers'], 'id', (string)$account['id'], $account); return; }
    $override = is_array($data['adminProfileOverrides']['bootstrap-superadmin'] ?? null) ? $data['adminProfileOverrides']['bootstrap-superadmin'] : [];
    foreach (['name', 'phoneNumber', 'email', 'passwordHash', 'mustChangePassword'] as $field) if (array_key_exists($field, $account) && $account[$field] !== null) $override[$field] = $account[$field];
    foreach (['twoFactor', 'twoFactorPending'] as $field) {
        if (array_key_exists($field, $account) && is_array($account[$field])) $override[$field] = $account[$field];
        else unset($override[$field]);
    }
    $data['adminProfileOverrides']['bootstrap-superadmin'] = $override;
}
function publicAccount(array $account, array $data): array {
    $result = publicAdminUser($account, $data['roles'], $data['adminUserActivity']);
    if (($account['scope'] ?? '') === 'platform') $result['roleName'] = 'Super Admin';
    $role = findBy($data['roles'], 'id', (string)($account['roleId'] ?? ''));
    $result['permissions'] = ($account['scope'] ?? '') === 'platform' ? ADMIN_PERMISSIONS : ($role['permissions'] ?? []);
    $result['mustChangePassword'] = !empty($account['mustChangePassword']);
    $pending = findBy($data['adminEmailVerifications'], 'userId', (string)$account['id']);
    $result['pendingEmail'] = $pending && strtotime((string)($pending['expiresAt'] ?? '')) > time() ? ($pending['email'] ?? null) : null;
    $result['emailManagedByEnvironment'] = !empty($account['emailManagedByEnvironment']);
    $result['passwordManagedByEnvironment'] = !empty($account['passwordManagedByEnvironment']);
    $result['twoFactorEnabled'] = twoFactorEnabled($account);
    return $result;
}
function newsletterSender(): string { return getenv('CBT_NEWSLETTER_SENDER') ?: institutionSupportEmail(); }
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
            'From: ' . institutionNewsletterSenderName() . ' <' . newsletterSender() . '>',
            'Reply-To: ' . newsletterSender(),
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ];
        if ($includeUnsubscribe) $headers[] = 'List-Unsubscribe: <' . $unsubscribe . '>';
        $body = $includeUnsubscribe ? newsletterBody($content) : institutionBrandName() . "\n\n" . $content . "\n\n-- " . institutionBrandName();
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
    return institutionBrandName() . "\n\n" . $content . "\n\n-- " . institutionBrandName() . "\n\nTo stop receiving these newsletters, reply to this email with the word: unsubscribe\n";
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
function requirePlatformSuperAdmin(): array {
    if (!mysqlStorageEnabled()) respond(['error' => 'Platform institution management is unavailable until MySQL storage is active.'], 503);
    $session = auth(true);
    // This endpoint is intentionally tied to platform_admin_sessions. A
    // tenant admin never reaches it, even if they manipulate paths or IDs.
    if (($session['scope'] ?? '') !== 'platform' || ($session['roleId'] ?? '') !== 'platform_super_admin') respond(['error' => 'Resource not found.'], 404);
    return $session;
}
function institutionProvisionSlug(string $value): string {
    $slug = strtolower(trim($value));
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,118}[a-z0-9])?$/', $slug)) respond(['error' => 'Institution slug may use lowercase letters, numbers, and single hyphens only.'], 422);
    return $slug;
}
function institutionLogoUpload(string $slug): array {
    $upload = $_FILES['logo'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) respond(['error' => 'Choose an institution logo to continue.'], 422);
    $size = (int)($upload['size'] ?? 0); $path = (string)($upload['tmp_name'] ?? '');
    if ($size < 32 || $size > 2 * 1024 * 1024) respond(['error' => 'Institution logos must be between 32 bytes and 2 MB.'], 422);
    if ($path === '' || !is_uploaded_file($path)) respond(['error' => 'The logo upload could not be verified. Please choose the file again.'], 422);
    $image = @getimagesize($path); $type = is_array($image) ? (int)($image[2] ?? 0) : 0;
    $extensions = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];
    if (defined('IMAGETYPE_WEBP')) $extensions[constant('IMAGETYPE_WEBP')] = 'webp';
    if (!isset($extensions[$type])) respond(['error' => 'Use a valid PNG, JPEG, or WebP image for the institution logo.'], 422);
    if (!is_dir(INSTITUTION_LOGO_DIRECTORY) && !mkdir(INSTITUTION_LOGO_DIRECTORY, 0750, true) && !is_dir(INSTITUTION_LOGO_DIRECTORY)) throw new RuntimeException('Could not create the institution-logo directory.');
    $filename = $slug . '-' . bin2hex(random_bytes(12)) . '.' . $extensions[$type];
    $destination = INSTITUTION_LOGO_DIRECTORY . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($path, $destination)) throw new RuntimeException('Could not store the institution logo.');
    @chmod($destination, 0640);
    return ['path' => 'uploads/institution-logos/' . $filename, 'absolutePath' => $destination];
}
function optionalInstitutionLogoUpload(string $slug): ?array {
    $upload = $_FILES['logo'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    return institutionLogoUpload($slug);
}
function accentRgb(string $hex): array {
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}
function accentLuminance(string $hex): float {
    $channels = array_map(static function (int $value): float { $value /= 255; return $value <= .04045 ? $value / 12.92 : (($value + .055) / 1.055) ** 2.4; }, accentRgb($hex));
    return .2126 * $channels[0] + .7152 * $channels[1] + .0722 * $channels[2];
}
function accentContrast(string $foreground, string $background): float {
    $a = accentLuminance($foreground); $b = accentLuminance($background);
    return (max($a, $b) + .05) / (min($a, $b) + .05);
}
function accentMix(string $source, string $target, float $amount): string {
    $from = accentRgb($source); $to = accentRgb($target); $values = [];
    foreach ([0, 1, 2] as $index) $values[] = (int)round($from[$index] + ($to[$index] - $from[$index]) * $amount);
    return sprintf('#%02x%02x%02x', ...$values);
}
function accessibleAccentVariant(string $source, string $background, string $toward): string {
    if (accentContrast($source, $background) >= 4.5) return $source;
    for ($step = 1; $step <= 100; $step++) { $variant = accentMix($source, $toward, $step / 100); if (accentContrast($variant, $background) >= 4.5) return $variant; }
    return $toward;
}
function institutionAccentProfile(?string $value, bool $acknowledged = false): array {
    $source = strtolower(trim((string)($value ?: '#16774d')));
    if (!preg_match('/^#[0-9a-f]{6}$/', $source)) respond(['error' => 'Choose a valid six-digit accent colour.'], 422);
    $lightRaw = accentContrast($source, '#ffffff'); $darkRaw = accentContrast($source, '#101714');
    $adjusted = $lightRaw < 4.5 || $darkRaw < 4.5;
    if ($adjusted && !$acknowledged && $source !== '#16774d') respond(['error' => 'This accent needs an accessible light or dark adjustment. Review the contrast warning and confirm before saving.', 'contrast' => ['lightRaw' => round($lightRaw, 2), 'darkRaw' => round($darkRaw, 2)]], 422);
    $light = accessibleAccentVariant($source, '#ffffff', '#000000');
    $dark = accessibleAccentVariant($source, '#101714', '#ffffff');
    return ['source' => $source, 'primary' => $light, 'accent' => $dark, 'lightContrast' => round(accentContrast($light, '#ffffff'), 2), 'darkContrast' => round(accentContrast($dark, '#101714'), 2), 'adjusted' => $adjusted, 'lightRaw' => round($lightRaw, 2), 'darkRaw' => round($darkRaw, 2)];
}
function provisionTemporaryPassword(): string { return 'CBT-' . strtoupper(bin2hex(random_bytes(8))) . '-a9!'; }
function validateStudent(array $input, array $data, ?string $currentId = null): array {
    $name = requireText($input['fullName'] ?? null, 'Full name');
    $email = requireText($input['email'] ?? null, 'Email');
    $matric = requireText($input['matricNumber'] ?? null, 'Matric number', 32);
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
    $courseId = trim((string)($input['courseId'] ?? ''));
    // Accept a legacy component id only while transitioning old clients. New forms
    // submit courseId and every new question begins life as an unpublished Draft.
    if ($courseId === '' && !empty($input['examId'])) {
        $legacyComponent = findBy($data['exams'], 'id', (string)$input['examId']);
        $courseId = (string)($legacyComponent['courseId'] ?? '');
    }
    if (!$courseId || !findBy($data['courses'], 'id', $courseId)) respond(['error' => 'Choose an existing course question bank.'], 422);
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
    $topic = trim((string)($input['topic'] ?? '')); $difficulty = trim((string)($input['difficulty'] ?? 'medium'));
    if (textLength($topic) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $topic) || !in_array($difficulty, ['easy', 'medium', 'hard'], true)) respond(['error' => 'Question topic or difficulty is invalid.'], 422);
    return ['courseId' => $courseId, 'text' => $question, 'options' => $options, 'correctOptions' => $correct, 'type' => $type, 'topic' => $topic, 'difficulty' => $difficulty];
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
    $component = findBy($data['exams'], 'id', (string)($session['examId'] ?? ''));
    $questions = $session['questions'] ?? ($component ? questionsPublishedForComponent($data, $component) : []);
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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
// Do this before acquiring the store lock or loading data. The maintenance
// window therefore prevents both deliberate writes and scheduled maintenance
// routines from changing the legacy JSON file during a final import.
if (activeMaintenance() !== null) maintenanceResponse();

// JSON storage needs a process-wide file lock. MySQL persistence is now made
// of scoped, transactional row changes, so serialising every tenant and every
// request behind this legacy lock would defeat database concurrency.
$dataLock = null;
if (!mysqlStorageEnabled()) {
    $dataLock = fopen(DATA_LOCK_FILE, 'c');
    if ($dataLock === false || !flock($dataLock, LOCK_EX)) respond(['error' => 'Unable to lock data storage.'], 500);
}

/**
 * Stage 2 fast path: autosave is the hottest write in an assessment.  It must
 * not hydrate every student, course, result and audit row merely to change one
 * answer.  The attempt snapshot remains the canonical exam view; the narrow
 * attempt_answers table remains the indexed answer projection.
 */
function mysqlFastAttempt(string $attemptId, bool $forUpdate = false): ?array {
    $sql = 'SELECT id,student_id,component_id,password_id,status,started_at,ends_at,submitted_at,raw_score,scaled_score,access_token_hash,correlation_id,device_fingerprint,ip_address,locked_at,locked_reason,snapshot_json FROM assessment_attempts WHERE institution_id = :institution_id AND id = :id';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $statement = mysqlAppPdo()->prepare($sql);
    $statement->execute(['institution_id' => mysqlCurrentInstitutionId(), 'id' => $attemptId]);
    return $statement->fetch() ?: null;
}
function mysqlUtcEpoch(mixed $value): int {
    if ($value === null || $value === '') return 0;
    return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))->getTimestamp();
}
function mysqlFastSessionAnswer(): never {
    $input = body(); $attemptId = trim((string)($input['sessionId'] ?? '')); $token = cookieValue('CBT_EXAM_SESSION');
    if ($attemptId === '' || $token === '') respond(['error' => 'Exam session authentication required.'], 401);
    // Independent attempts can still briefly contend on InnoDB's foreign-key
    // index during an exam-start burst. Retry only database deadlocks; never
    // retry validation/authentication failures or replay an already-committed
    // answer update.
    for ($retry = 0; $retry < 5; $retry++) {
        $pdo = mysqlAppPdo(); $pdo->beginTransaction();
        try {
        $row = mysqlFastAttempt($attemptId, true);
        if (!$row || !hash_equals((string)$row['access_token_hash'], tokenHash($token))) { $pdo->rollBack(); respond(['error' => 'Exam session authentication required.'], 401); }
        if ((string)$row['status'] !== 'in_progress') { $pdo->rollBack(); respond(['error' => 'Exam session is no longer active.'], 409); }
        if (mysqlUtcEpoch($row['ends_at']) <= time()) { $pdo->rollBack(); respond(['error' => 'Exam session is no longer active.'], 409); }
        $session = mysqlJson($row['snapshot_json'], []); $questionId = (string)($input['questionId'] ?? '');
        $question = findBy((array)($session['questions'] ?? []), 'id', $questionId);
        if (!$question) { $pdo->rollBack(); respond(['error' => 'Question is not part of this attempt.'], 422); }
        $answers = $input['answers'] ?? null;
        if (!is_array($answers) || !array_is_list($answers)) { $pdo->rollBack(); respond(['error' => 'Answers must be a list.'], 422); }
        foreach ($answers as $answer) if (filter_var($answer, FILTER_VALIDATE_INT) === false || (int)$answer < 0 || (int)$answer >= count($question['options'] ?? [])) { $pdo->rollBack(); respond(['error' => 'An answer is outside the available options.'], 422); }
        $answers = array_values(array_unique(array_map('intval', $answers)));
        if (($question['type'] ?? 'single') === 'single' && count($answers) > 1) { $pdo->rollBack(); respond(['error' => 'Only one answer is allowed for this question.'], 422); }
        $session['answers'] ??= []; $session['answers'][$questionId] = $answers;
        $update = $pdo->prepare('UPDATE assessment_attempts SET snapshot_json = :snapshot_json WHERE institution_id = :institution_id AND id = :id');
        $update->execute(['snapshot_json' => json_encode($session, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'institution_id' => mysqlCurrentInstitutionId(), 'id' => $attemptId]);
        $delete = $pdo->prepare('DELETE FROM attempt_answers WHERE institution_id = :institution_id AND attempt_id = :attempt_id AND question_id = :question_id');
        $delete->execute(['institution_id' => mysqlCurrentInstitutionId(), 'attempt_id' => $attemptId, 'question_id' => $questionId]);
        if ($answers) {
            $insert = $pdo->prepare('INSERT INTO attempt_answers (institution_id,attempt_id,question_id,option_index) VALUES (:institution_id,:attempt_id,:question_id,:option_index)');
            foreach ($answers as $answer) $insert->execute(['institution_id' => mysqlCurrentInstitutionId(), 'attempt_id' => $attemptId, 'question_id' => $questionId, 'option_index' => $answer]);
        }
            $pdo->commit(); respond(['ok' => true]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($retry < 4 && mysqlRetryableTransactionFailure($error)) {
                usleep((int)(2000 * (1 << $retry) + random_int(0, 2000)));
                continue;
            }
            throw $error;
        }
    }
    throw new RuntimeException('Autosave transaction retry budget exhausted.');
}
function mysqlFastExam(string $componentId): ?array {
    $statement = mysqlAppPdo()->prepare('SELECT cc.*,c.title AS course_title,c.course_unit,c.session_id,c.semester_id,c.test_max_mark,c.exam_max_mark,c.legacy_exam_only AS course_legacy_exam_only FROM course_components cc INNER JOIN courses c ON c.institution_id=cc.institution_id AND c.id=cc.course_id WHERE cc.institution_id=:institution_id AND cc.id=:id LIMIT 1');
    $statement->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>$componentId]); $row=$statement->fetch(); if (!$row) return null;
    return ['id'=>$row['id'],'code'=>$row['code'],'title'=>$row['title'],'description'=>$row['description'] ?? '','duration'=>(int)$row['duration_minutes'],'questionCount'=>(int)$row['question_count'],'startAt'=>mysqlIso($row['start_at']),'endAt'=>mysqlIso($row['end_at']),'status'=>$row['status'],'courseUnit'=>(int)$row['course_unit'],'courseId'=>$row['course_id'],'component'=>$row['component'],'maxMark'=>(float)$row['max_mark'],'sessionId'=>$row['session_id'] ?? '','semesterId'=>$row['semester_id'] ?? '','legacyExamOnly'=>mysqlBool($row['legacy_exam_only']),'courseTitle'=>$row['course_title'],'category'=>$row['category'] ?? '','passThreshold'=>$row['pass_threshold'] === null ? null : (float)$row['pass_threshold']];
}
function mysqlFastStudentByMatric(string $matricNumber): ?array {
    $statement=mysqlAppPdo()->prepare('SELECT id,full_name,matric_number,email,phone_number,department,active,created_at FROM students WHERE institution_id=:institution_id AND matric_number=:matric_number LIMIT 1');
    $statement->execute(['institution_id'=>mysqlCurrentInstitutionId(),'matric_number'=>$matricNumber]); $row=$statement->fetch(); if (!$row) return null;
    return ['id'=>$row['id'],'fullName'=>$row['full_name'],'matricNumber'=>$row['matric_number'],'email'=>$row['email'],'phoneNumber'=>$row['phone_number'] ?? '','department'=>$row['department'],'active'=>mysqlBool($row['active']),'createdAt'=>mysqlIso($row['created_at'])];
}
function mysqlFastStudentById(string $studentId): ?array {
    $statement=mysqlAppPdo()->prepare('SELECT id,full_name,matric_number,email,phone_number,department,active,created_at FROM students WHERE institution_id=:institution_id AND id=:id LIMIT 1');
    $statement->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>$studentId]); $row=$statement->fetch(); if (!$row) return null;
    return ['id'=>$row['id'],'fullName'=>$row['full_name'],'matricNumber'=>$row['matric_number'],'email'=>$row['email'],'phoneNumber'=>$row['phone_number'] ?? '','department'=>$row['department'],'active'=>mysqlBool($row['active']),'createdAt'=>mysqlIso($row['created_at'])];
}
function mysqlFastSecurity(): array {
    $statement=mysqlAppPdo()->prepare("SELECT setting_json FROM institution_settings WHERE institution_id=:institution_id AND setting_key='examSecurity' LIMIT 1");
    $statement->execute(['institution_id'=>mysqlCurrentInstitutionId()]); return array_merge(DEFAULT_EXAM_SECURITY, (array)mysqlJson($statement->fetchColumn(), []));
}
function mysqlFastAudit(PDO $pdo, string $actorType, string $actorId, string $action, string $targetType, string $targetId, array $metadata=[]): void {
    $statement=$pdo->prepare('INSERT INTO audit_events (institution_id,id,timestamp_at,actor_type,actor_id,action_type,target_type,target_id,ip_address,user_agent,outcome,correlation_id,before_after_json,metadata_json) VALUES (:institution_id,:id,UTC_TIMESTAMP(6),:actor_type,:actor_id,:action_type,:target_type,:target_id,:ip_address,:user_agent,:outcome,:correlation_id,:before_after_json,:metadata_json)');
    $statement->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>id(),'actor_type'=>$actorType,'actor_id'=>$actorId,'action_type'=>$action,'target_type'=>$targetType,'target_id'=>$targetId,'ip_address'=>clientFingerprint(),'user_agent'=>auditUserAgent(),'outcome'=>auditOutcome($action,$metadata),'correlation_id'=>auditCorrelationId($metadata),'before_after_json'=>json_encode($metadata['beforeAfter'] ?? [],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'metadata_json'=>json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
}
/**
 * Keep exam-start reads proportional to the questions actually assigned to an
 * attempt.  The first query deliberately returns IDs only; question text and
 * options are hydrated after the random subset is chosen.
 */
function mysqlFastQuestionIdsForComponent(array $exam): array {
    $target=strtolower((string)($exam['component'] ?? 'exam')) === 'test' ? 'test' : 'exam';
    $statement=mysqlAppPdo()->prepare("SELECT q.id FROM questions q INNER JOIN question_publish_targets t ON t.institution_id=q.institution_id AND t.question_id=q.id WHERE q.institution_id=:institution_id AND q.course_id=:course_id AND q.status='published' AND t.target_component=:target ORDER BY q.id");
    $statement->execute(['institution_id'=>mysqlCurrentInstitutionId(),'course_id'=>$exam['courseId'],'target'=>$target]);
    return array_map('strval', array_column($statement->fetchAll(), 'id'));
}
function mysqlFastQuestionsByIds(array $exam, array $ids): array {
    if (!$ids) return [];
    $target=strtolower((string)($exam['component'] ?? 'exam')) === 'test' ? 'test' : 'exam';
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $questions=mysqlAppPdo()->prepare("SELECT id,legacy_component_id,text,type,topic,difficulty,status FROM questions WHERE institution_id=? AND course_id=? AND status='published' AND id IN ({$marks})");
    $questions->execute(array_merge([mysqlCurrentInstitutionId(), $exam['courseId']], $ids));
    $rowsById=[]; foreach($questions->fetchAll() as $row) $rowsById[$row['id']]=$row;
    $options=mysqlAppPdo()->prepare("SELECT question_id,option_index,option_text,is_correct FROM question_options WHERE institution_id=? AND question_id IN ({$marks}) ORDER BY question_id,option_index");
    $options->execute(array_merge([mysqlCurrentInstitutionId()],$ids)); $byQuestion=[]; foreach($options->fetchAll() as $option){$byQuestion[$option['question_id']][]=$option;}
    $items=[]; foreach($ids as $id){$row=$rowsById[$id]??null;if(!$row)continue;$choices=[];$correct=[];foreach($byQuestion[$row['id']] ?? [] as $option){$choices[]=$option['option_text'];if(mysqlBool($option['is_correct']))$correct[]=(int)$option['option_index'];}$items[]=['id'=>$row['id'],'examId'=>$row['legacy_component_id'] ?? '','legacyComponentId'=>$row['legacy_component_id'] ?? null,'courseId'=>$exam['courseId'],'text'=>$row['text'],'options'=>$choices,'correctOptions'=>$correct,'type'=>$row['type'],'topic'=>$row['topic'] ?? '','difficulty'=>$row['difficulty'] ?? '','status'=>$row['status'],'publishedTo'=>[$target]];}
    return $items;
}
function mysqlFastSessionPayload(array $session): array { return sessionPayload($session); }
function mysqlFastSessionResume(): never {
    $input=body();$attemptId=trim((string)($input['sessionId'] ?? ''));$token=cookieValue('CBT_EXAM_SESSION'); if($attemptId===''||$token==='')respond(['error'=>'Exam session authentication required.'],401);
    $row=mysqlFastAttempt($attemptId); if(!$row||!hash_equals((string)$row['access_token_hash'],tokenHash($token)))respond(['error'=>'Exam session authentication required.'],401);
    $session=mysqlJson($row['snapshot_json'],[]); if((string)$row['status']==='locked')respond(['session'=>mysqlFastSessionPayload($session),'questions'=>[]]); if((string)$row['status']!=='in_progress'||mysqlUtcEpoch($row['ends_at'])<=time())respond(['error'=>'This attempt has already been submitted.'],409);
    respond(['session'=>mysqlFastSessionPayload($session),'questions'=>publicSessionQuestions($session)]);
}
function mysqlFastRecordLoginFailure(array $security, string $matricNumber, string $examId, ?array $student, ?array $exam, string $reason): bool {
    $pdo=mysqlAppPdo();$pdo->beginTransaction();try{$key=examLoginPairKey($matricNumber,$examId);$select=$pdo->prepare('SELECT failed_at_json,locked_until FROM exam_login_failures WHERE institution_id=:institution_id AND legacy_key=:legacy_key FOR UPDATE');$select->execute(['institution_id'=>mysqlCurrentInstitutionId(),'legacy_key'=>$key]);$row=$select->fetch();$failed=trimAttemptTimes((array)mysqlJson($row['failed_at_json'] ?? null,[]),(int)$security['loginAttemptWindowMinutes']*60);$failed[]=time();$pairLocked=count($failed)>=(int)$security['loginAttemptLimit'];$until=$pairLocked ? time()+(int)$security['loginLockoutMinutes']*60 : 0;$write=$pdo->prepare('INSERT INTO exam_login_failures (institution_id,legacy_key,failed_at_json,locked_until) VALUES (:institution_id,:legacy_key,:failed_at_json,:locked_until) ON DUPLICATE KEY UPDATE failed_at_json=VALUES(failed_at_json),locked_until=VALUES(locked_until)');$write->execute(['institution_id'=>mysqlCurrentInstitutionId(),'legacy_key'=>$key,'failed_at_json'=>json_encode($failed,JSON_THROW_ON_ERROR),'locked_until'=>$until?gmdate('Y-m-d H:i:s',$until).'.000000':null]);$ipLocked=false;if(!empty($security['loginRateLimitEnabled'])){$ipKey=examLoginIpKey();$ipSelect=$pdo->prepare('SELECT attempted_at_json FROM exam_login_ip_attempts WHERE institution_id=:institution_id AND legacy_key=:legacy_key FOR UPDATE');$ipSelect->execute(['institution_id'=>mysqlCurrentInstitutionId(),'legacy_key'=>$ipKey]);$ipAttempts=trimAttemptTimes((array)mysqlJson($ipSelect->fetchColumn(),[]),(int)$security['ipAttemptWindowMinutes']*60);$ipAttempts[]=time();$ipLocked=count($ipAttempts)>=(int)$security['ipAttemptLimit'];$ipWrite=$pdo->prepare('INSERT INTO exam_login_ip_attempts (institution_id,legacy_key,attempted_at_json) VALUES (:institution_id,:legacy_key,:attempted_at_json) ON DUPLICATE KEY UPDATE attempted_at_json=VALUES(attempted_at_json)');$ipWrite->execute(['institution_id'=>mysqlCurrentInstitutionId(),'legacy_key'=>$ipKey,'attempted_at_json'=>json_encode($ipAttempts,JSON_THROW_ON_ERROR)]);}$metadata=['matricNumber'=>$matricNumber,'course'=>$exam['code'] ?? '','reason'=>$reason];mysqlFastAudit($pdo,$student?'student':'system',$student['id'] ?? ($matricNumber?:'unknown'),'login_failed','exam',$examId,$metadata);if($pairLocked)mysqlFastAudit($pdo,$student?'student':'system',$student['id'] ?? ($matricNumber?:'unknown'),'login_lockout_triggered','exam',$examId,$metadata+['scope'=>'matric_exam','lockedUntil'=>date('c',$until)]);if($ipLocked)mysqlFastAudit($pdo,'system','unknown','login_lockout_triggered','exam',$examId,$metadata+['scope'=>'ip_failed_attempts','ipAttemptLimit'=>(int)$security['ipAttemptLimit']]);$pdo->commit();return $pairLocked||$ipLocked;}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
/** Block an active assessment resumed from a different network, as the legacy path did. */
function mysqlFastBlockConcurrentStudentAttempt(array $student, array $exam, array $security): void {
    if (empty($security['concurrentIpBlockEnabled'])) return;
    $pdo=mysqlAppPdo();$pdo->beginTransaction();
    try {
        $find=$pdo->prepare("SELECT id,ip_address,snapshot_json FROM assessment_attempts WHERE institution_id=:institution_id AND student_id=:student_id AND component_id=:component_id AND status='in_progress' AND ends_at>UTC_TIMESTAMP(6) ORDER BY started_at DESC LIMIT 1 FOR UPDATE");
        $find->execute(['institution_id'=>mysqlCurrentInstitutionId(),'student_id'=>$student['id'],'component_id'=>$exam['id']]);$active=$find->fetch();$requestIp=clientFingerprint();
        if($active&&!empty($active['ip_address'])&&!hash_equals((string)$active['ip_address'],$requestIp)){
            $event=['event'=>'concurrent_login_blocked','at'=>date('c'),'resultingAction'=>'blocked','originalIpAddress'=>$active['ip_address'],'attemptedIpAddress'=>$requestIp];$snapshot=mysqlJson($active['snapshot_json'],[]);$snapshot['integrityEvents'][]=$event;
            $pdo->prepare('UPDATE assessment_attempts SET snapshot_json=:snapshot_json WHERE institution_id=:institution_id AND id=:id')->execute(['snapshot_json'=>json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'institution_id'=>mysqlCurrentInstitutionId(),'id'=>$active['id']]);
            $pdo->prepare('INSERT INTO attempt_integrity_events (institution_id,attempt_id,event_json) VALUES (:institution_id,:attempt_id,:event_json)')->execute(['institution_id'=>mysqlCurrentInstitutionId(),'attempt_id'=>$active['id'],'event_json'=>json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            mysqlFastAudit($pdo,'student',$student['id'],'concurrent_login_blocked','exam_session',$active['id'],['matricNumber'=>$student['matricNumber'],'course'=>$exam['code'],'originalIpAddress'=>$active['ip_address'],'attemptedIpAddress'=>$requestIp]);$pdo->commit();respond(['error'=>'This exam is already in progress on another device/network.'],409);
        }
        $pdo->commit();
    } catch(Throwable $error) { if($pdo->inTransaction())$pdo->rollBack();throw $error; }
}
/** Shared-device detection remains an audit signal only; it never blocks a valid lab user. */
function mysqlFastRecordSharedDeviceSignals(array $student, array $exam, string $fingerprint): void {
    $security=mysqlFastSecurity();if($fingerprint===''||empty($security['fingerprintFlaggingEnabled']))return;
    $pdo=mysqlAppPdo();$pdo->beginTransaction();
    try {
        $query=$pdo->prepare("SELECT a.id,a.snapshot_json,s.matric_number FROM assessment_attempts a INNER JOIN students s ON s.institution_id=a.institution_id AND s.id=a.student_id WHERE a.institution_id=:institution_id AND a.component_id=:component_id AND a.student_id<>:student_id AND a.status='in_progress' AND a.ends_at>UTC_TIMESTAMP(6) AND a.device_fingerprint=:fingerprint FOR UPDATE");
        $query->execute(['institution_id'=>mysqlCurrentInstitutionId(),'component_id'=>$exam['id'],'student_id'=>$student['id'],'fingerprint'=>$fingerprint]);
        foreach($query->fetchAll() as $other){$event=['event'=>'shared_device_suspected','at'=>date('c'),'resultingAction'=>'logged','otherMatricNumber'=>$student['matricNumber'],'deviceFingerprint'=>$fingerprint];$snapshot=mysqlJson($other['snapshot_json'],[]);$snapshot['integrityEvents'][]=$event;$pdo->prepare('UPDATE assessment_attempts SET snapshot_json=:snapshot_json WHERE institution_id=:institution_id AND id=:id')->execute(['snapshot_json'=>json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'institution_id'=>mysqlCurrentInstitutionId(),'id'=>$other['id']]);$pdo->prepare('INSERT INTO attempt_integrity_events (institution_id,attempt_id,event_json) VALUES (:institution_id,:attempt_id,:event_json)')->execute(['institution_id'=>mysqlCurrentInstitutionId(),'attempt_id'=>$other['id'],'event_json'=>json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);mysqlFastAudit($pdo,'student',$student['id'],'shared_device_suspected','exam',$exam['id'],['matricNumber'=>$student['matricNumber'],'otherMatricNumber'=>$other['matric_number'],'course'=>$exam['code'],'deviceFingerprint'=>$fingerprint]);}
        $pdo->commit();
    } catch(Throwable $error) { if($pdo->inTransaction())$pdo->rollBack();throw $error; }
}
function mysqlFastStudentLogin(): never {
    $input=body();$matric=trim((string)($input['matricNumber']??''));$examId=(string)($input['examId']??'');$student=mysqlFastStudentByMatric($matric);$exam=mysqlFastExam($examId);$security=mysqlFastSecurity();
    $failure=$security['loginRateLimitEnabled'] ? mysqlAppPdo()->prepare('SELECT locked_until FROM exam_login_failures WHERE institution_id=:institution_id AND legacy_key=:legacy_key LIMIT 1') : null;
    if($failure){$failure->execute(['institution_id'=>mysqlCurrentInstitutionId(),'legacy_key'=>examLoginPairKey($matric,$examId)]);$until=$failure->fetchColumn();if($until&&mysqlUtcEpoch($until)>time())respond(['error'=>'Too many failed exam login attempts. Please contact your administrator or try again later.'],429);}
    $password=null;if($student&&$exam){$stmt=mysqlAppPdo()->prepare('SELECT id,password_hash,created_at,expires_at,used_at FROM assessment_passwords WHERE institution_id=:institution_id AND student_id=:student_id AND component_id=:component_id ORDER BY created_at DESC LIMIT 1');$stmt->execute(['institution_id'=>mysqlCurrentInstitutionId(),'student_id'=>$student['id'],'component_id'=>$exam['id']]);$row=$stmt->fetch();if($row)$password=['id'=>$row['id'],'passwordHash'=>$row['password_hash'],'createdAt'=>mysqlIso($row['created_at']),'expiresAt'=>mysqlIso($row['expires_at']),'usedAt'=>mysqlIso($row['used_at'])];}
    $reason='credentials_not_verified';$status=401;$message='We could not verify those details.';
    if($student&&$student['active']&&$exam&&!$password){$reason='password_not_issued_for_component';$status=409;$message='No password has been issued for this '.ucfirst((string)($exam['component']??'assessment')).'. Tests and Exams use separate passwords; ask your administrator to generate the correct component password.';}
    elseif(!$student||!$student['active']||!$exam||!$password||!password_verify((string)($input['password']??''),(string)$password['passwordHash'])||strtotime((string)$password['expiresAt'])<time()){}
    elseif(!empty($password['usedAt'])){$reason='password_already_used';$status=409;$message='This exam password has already been used. Ask an administrator for a new one.';}
    elseif(!publicExam($exam)['active']){$reason='assessment_not_available';$status=403;$message='This assessment is not currently available.';}
    else {$used=mysqlAppPdo()->prepare('SELECT 1 FROM component_submissions WHERE institution_id=:institution_id AND student_id=:student_id AND component_id=:component_id AND submitted_at>=:created_at LIMIT 1');$used->execute(['institution_id'=>mysqlCurrentInstitutionId(),'student_id'=>$student['id'],'component_id'=>$exam['id'],'created_at'=>(new DateTimeImmutable((string)$password['createdAt']))->format('Y-m-d H:i:s.u')]);if($used->fetchColumn()){$reason='result_already_recorded';$status=409;$message='This exam password has already been used. Ask an administrator for a new one.';}else{mysqlFastBlockConcurrentStudentAttempt($student,$exam,$security);$fingerprint=browserDeviceFingerprint($input);mysqlFastRecordSharedDeviceSignals($student,$exam,$fingerprint);$token=secretToken();$expires=min(strtotime((string)$exam['endAt']),time()+LOGIN_TOKEN_SECONDS);$correlation='exam-'.id();$pdo=mysqlAppPdo();$pdo->beginTransaction();try{$clear=$pdo->prepare('DELETE FROM exam_login_failures WHERE institution_id=:institution_id AND legacy_key=:legacy_key');$clear->execute(['institution_id'=>mysqlCurrentInstitutionId(),'legacy_key'=>examLoginPairKey($matric,$examId)]);$insert=$pdo->prepare('INSERT INTO assessment_login_tokens (institution_id,token_hash,student_id,component_id,password_id,correlation_id,device_fingerprint,expires_at,used_at,created_at) VALUES (:institution_id,:token_hash,:student_id,:component_id,:password_id,:correlation_id,:device_fingerprint,:expires_at,NULL,UTC_TIMESTAMP(6))');$insert->execute(['institution_id'=>mysqlCurrentInstitutionId(),'token_hash'=>tokenHash($token),'student_id'=>$student['id'],'component_id'=>$exam['id'],'password_id'=>$password['id'],'correlation_id'=>$correlation,'device_fingerprint'=>$fingerprint,'expires_at'=>gmdate('Y-m-d H:i:s',$expires).'.000000']);mysqlFastAudit($pdo,'student',$student['id'],'student_exam_login','exam',$exam['id'],['matricNumber'=>$student['matricNumber'],'course'=>$exam['code'],'correlationId'=>$correlation]);$pdo->commit();setSessionCookie('CBT_EXAM_LOGIN',$token,$expires);respond(['student'=>$student,'exam'=>publicExam($exam)]);}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}}}
    $locked=mysqlFastRecordLoginFailure($security,$matric,$examId,$student,$exam,$reason);respond(['error'=>$locked?'Too many failed exam login attempts. Please contact your administrator or try again later.':$message],$locked?429:$status);
}
function mysqlFastSessionStart(): never {
    $raw = cookieValue('CBT_EXAM_LOGIN'); if ($raw === '') respond(['error'=>'Login expired. Verify your exam password again.'],401);
    $pdo = mysqlAppPdo(); $pdo->beginTransaction();
    try {
        $tokenHash = tokenHash($raw); $lookup = $pdo->prepare('SELECT token_hash,student_id,component_id,password_id,correlation_id,device_fingerprint,expires_at,used_at FROM assessment_login_tokens WHERE institution_id=:institution_id AND token_hash=:token_hash FOR UPDATE');
        $lookup->execute(['institution_id'=>mysqlCurrentInstitutionId(),'token_hash'=>$tokenHash]); $token=$lookup->fetch();
        if (!$token || $token['used_at'] !== null || mysqlUtcEpoch($token['expires_at']) <= time()) { $pdo->rollBack(); respond(['error'=>'Login expired. Verify your exam password again.'],401); }
        $student=mysqlFastStudentById((string)$token['student_id']); $exam=mysqlFastExam((string)$token['component_id']);
        $password=$pdo->prepare('SELECT id,used_at FROM assessment_passwords WHERE institution_id=:institution_id AND id=:id FOR UPDATE'); $password->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>$token['password_id']]); $passwordRow=$password->fetch();
        if (!$student || !$student['active'] || !$exam || !$passwordRow || $passwordRow['used_at'] !== null || !publicExam($exam)['active']) { $pdo->rollBack(); respond(['error'=>'Login expired. Verify your exam password again.'],401); }
        $existing=$pdo->prepare("SELECT id,ends_at,snapshot_json FROM assessment_attempts WHERE institution_id=:institution_id AND student_id=:student_id AND component_id=:component_id AND status='in_progress' AND ends_at>UTC_TIMESTAMP(6) ORDER BY started_at DESC LIMIT 1 FOR UPDATE"); $existing->execute(['institution_id'=>mysqlCurrentInstitutionId(),'student_id'=>$student['id'],'component_id'=>$exam['id']]); $active=$existing->fetch(); $access=secretToken();
        if ($active) {
            $session=mysqlJson($active['snapshot_json'],[]); $session['accessTokenHash']=tokenHash($access); $session['passwordId']=$passwordRow['id'];
            $pdo->prepare('UPDATE assessment_attempts SET access_token_hash=:token_hash,password_id=:password_id,snapshot_json=:snapshot_json WHERE institution_id=:institution_id AND id=:id')->execute(['token_hash'=>tokenHash($access),'password_id'=>$passwordRow['id'],'snapshot_json'=>json_encode($session,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'institution_id'=>mysqlCurrentInstitutionId(),'id'=>$active['id']]);
            $pdo->prepare('UPDATE assessment_login_tokens SET used_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND token_hash=:token_hash')->execute(['institution_id'=>mysqlCurrentInstitutionId(),'token_hash'=>$tokenHash]); $pdo->commit(); clearSessionCookie('CBT_EXAM_LOGIN'); setSessionCookie('CBT_EXAM_SESSION',$access,mysqlUtcEpoch($active['ends_at'])); respond(['session'=>sessionPayload($session),'questions'=>publicSessionQuestions($session)]);
        }
        $questionIds=mysqlFastQuestionIdsForComponent($exam); $required=(int)$exam['questionCount']; if(count($questionIds)<$required){$pdo->rollBack();respond(['error'=>"This assessment needs $required published questions before it can start; ".count($questionIds).' are available.'],422);} shuffle($questionIds); $questions=mysqlFastQuestionsByIds($exam,array_slice($questionIds,0,$required)); if(count($questions)!==$required){$pdo->rollBack();respond(['error'=>'Published questions changed while this assessment was starting. Please try again.'],409);} $security=mysqlFastSecurity(); $questions=array_map(fn($q)=>sessionQuestionWithOptionOrder($q,!empty($security['optionShuffleEnabled'])),$questions);
        $now=time();$ends=min($now+(int)$exam['duration']*60,strtotime((string)$exam['endAt']));$session=['id'=>id(),'studentId'=>$student['id'],'examId'=>$exam['id'],'passwordId'=>$passwordRow['id'],'accessTokenHash'=>tokenHash($access),'correlationId'=>$token['correlation_id']?:('exam-'.id()),'startedAt'=>date('c',$now),'endsAt'=>date('c',$ends),'questions'=>$questions,'answers'=>[],'flagged'=>[],'integrityEvents'=>[],'ipAddress'=>clientFingerprint(),'deviceFingerprint'=>$token['device_fingerprint']??'','status'=>'in_progress'];
        $insert=$pdo->prepare('INSERT INTO assessment_attempts (institution_id,id,student_id,component_id,password_id,status,started_at,ends_at,access_token_hash,correlation_id,device_fingerprint,ip_address,snapshot_json) VALUES (:institution_id,:id,:student_id,:component_id,:password_id,:status,:started_at,:ends_at,:access_token_hash,:correlation_id,:device_fingerprint,:ip_address,:snapshot_json)');$insert->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>$session['id'],'student_id'=>$student['id'],'component_id'=>$exam['id'],'password_id'=>$passwordRow['id'],'status'=>'in_progress','started_at'=>gmdate('Y-m-d H:i:s',$now).'.000000','ends_at'=>gmdate('Y-m-d H:i:s',$ends).'.000000','access_token_hash'=>$session['accessTokenHash'],'correlation_id'=>$session['correlationId'],'device_fingerprint'=>$session['deviceFingerprint'],'ip_address'=>$session['ipAddress'],'snapshot_json'=>json_encode($session,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        $insertQuestion=$pdo->prepare('INSERT INTO attempt_question_snapshots (institution_id,attempt_id,question_id,ordinal,question_json) VALUES (:institution_id,:attempt_id,:question_id,:ordinal,:question_json)');foreach($questions as $ordinal=>$question)$insertQuestion->execute(['institution_id'=>mysqlCurrentInstitutionId(),'attempt_id'=>$session['id'],'question_id'=>$question['id'],'ordinal'=>$ordinal,'question_json'=>json_encode($question,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        $pdo->prepare('UPDATE assessment_login_tokens SET used_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND token_hash=:token_hash')->execute(['institution_id'=>mysqlCurrentInstitutionId(),'token_hash'=>$tokenHash]);mysqlFastAudit($pdo,'student',$student['id'],'exam_started','exam_session',$session['id'],['matricNumber'=>$student['matricNumber'],'course'=>$exam['code'],'correlationId'=>$session['correlationId']]);$pdo->commit();clearSessionCookie('CBT_EXAM_LOGIN');setSessionCookie('CBT_EXAM_SESSION',$access,$ends);respond(['session'=>sessionPayload($session),'questions'=>publicSessionQuestions($session)]);
    } catch(Throwable $error) { if($pdo->inTransaction())$pdo->rollBack(); throw $error; }
}
function mysqlFastSubmissionPayload(array $row): ?array {
    $statement=mysqlAppPdo()->prepare('SELECT id,attempt_id,student_id,course_id,component_id,academic_session_id,academic_semester_id,component,status,raw_score,scaled_score,score,submitted_at,grade,grade_point,quality_points,course_unit,review_snapshot_json FROM component_submissions WHERE institution_id=:institution_id AND attempt_id=:attempt_id LIMIT 1');$statement->execute(['institution_id'=>mysqlCurrentInstitutionId(),'attempt_id'=>$row['id']]);$result=$statement->fetch();if(!$result)return null;$review=mysqlJson($result['review_snapshot_json'],[]);return ['id'=>$result['id'],'sessionId'=>$result['attempt_id'],'examSessionId'=>$result['attempt_id'],'studentId'=>$result['student_id'],'examId'=>$result['component_id'],'courseId'=>$result['course_id'],'component'=>$result['component'],'academicSessionId'=>$result['academic_session_id'],'academicSemesterId'=>$result['academic_semester_id'],'rawScore'=>(float)$result['raw_score'],'scaledScore'=>(float)$result['scaled_score'],'score'=>(float)$result['score'],'submittedAt'=>mysqlIso($result['submitted_at']),'status'=>$result['status'],'questions'=>$review['questions']??[],'integrityEvents'=>$review['integrityEvents']??[]];
}
function mysqlFastSessionSubmit(): never {
    $input=body();$attemptId=trim((string)($input['sessionId']??''));$token=cookieValue('CBT_EXAM_SESSION');if($attemptId===''||$token==='')respond(['error'=>'Exam session authentication required.'],401);$pdo=mysqlAppPdo();$pdo->beginTransaction();try{$row=mysqlFastAttempt($attemptId,true);if(!$row||!hash_equals((string)$row['access_token_hash'],tokenHash($token))){$pdo->rollBack();respond(['error'=>'Exam session authentication required.'],401);}if((string)$row['status']!=='in_progress'){$pdo->rollBack();clearSessionCookie('CBT_EXAM_SESSION');respond(['result'=>mysqlFastSubmissionPayload($row)]);}$session=mysqlJson($row['snapshot_json'],[]);$exam=mysqlFastExam((string)$row['component_id']);$student=mysqlFastStudentById((string)$row['student_id']);if(!$exam||!$student){$pdo->rollBack();respond(['error'=>'This exam session is no longer available.'],409);}$questions=(array)($session['questions']??[]);$correct=0;$review=[];foreach($questions as $question){$displayed=(array)($session['answers'][$question['id']]??[]);$answer=originalAnswerIndexes($question,$displayed);$expected=array_values(array_map('intval',(array)($question['correctOptions']??[])));sort($expected);$isCorrect=$answer===$expected;if($isCorrect)$correct++;$review[]=['id'=>$question['id'],'text'=>$question['text'],'options'=>$question['originalOptions']??$question['options'],'type'=>$question['type']??'single','correctOptions'=>$expected,'answers'=>$answer,'isCorrect'=>$isCorrect,'flagged'=>in_array($question['id'],(array)($session['flagged']??[]),true)];}$auto=mysqlUtcEpoch($row['ends_at'])<=time();$submittedAt=date('c');$raw=count($questions)?round(($correct/count($questions))*100,1):0.0;$scaled=round(($raw/100)*(float)$exam['maxMark'],1);$session['status']=$auto?'auto_submitted':'submitted';$session['submittedAt']=$submittedAt;$session['rawScore']=$raw;$session['score']=$scaled;$result=['id'=>id(),'sessionId'=>$row['id'],'examSessionId'=>$row['id'],'studentId'=>$student['id'],'examId'=>$exam['id'],'courseId'=>$exam['courseId'],'component'=>$exam['component'],'academicSessionId'=>$exam['sessionId'],'academicSemesterId'=>$exam['semesterId'],'rawScore'=>$raw,'scaledScore'=>$scaled,'score'=>$scaled,'submittedAt'=>$submittedAt,'status'=>$session['status'],'questions'=>$review,'integrityEvents'=>$session['integrityEvents']??[]];$pdo->prepare('UPDATE assessment_attempts SET status=:status,submitted_at=:submitted_at,raw_score=:raw_score,scaled_score=:scaled_score,snapshot_json=:snapshot_json WHERE institution_id=:institution_id AND id=:id')->execute(['status'=>$session['status'],'submitted_at'=>(new DateTimeImmutable($submittedAt))->format('Y-m-d H:i:s.u'),'raw_score'=>$raw,'scaled_score'=>$scaled,'snapshot_json'=>json_encode($session,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'institution_id'=>mysqlCurrentInstitutionId(),'id'=>$row['id']]);$existing=$pdo->prepare('SELECT id FROM component_submissions WHERE institution_id=:institution_id AND attempt_id=:attempt_id LIMIT 1 FOR UPDATE');$existing->execute(['institution_id'=>mysqlCurrentInstitutionId(),'attempt_id'=>$row['id']]);if(!$existing->fetchColumn()){$pdo->prepare('INSERT INTO component_submissions (institution_id,id,attempt_id,student_id,course_id,component_id,academic_session_id,academic_semester_id,component,status,raw_score,scaled_score,score,submitted_at,course_unit,review_snapshot_json) VALUES (:institution_id,:id,:attempt_id,:student_id,:course_id,:component_id,:academic_session_id,:academic_semester_id,:component,:status,:raw_score,:scaled_score,:score,:submitted_at,:course_unit,:review_snapshot_json)')->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>$result['id'],'attempt_id'=>$row['id'],'student_id'=>$student['id'],'course_id'=>$exam['courseId'],'component_id'=>$exam['id'],'academic_session_id'=>$exam['sessionId']?:null,'academic_semester_id'=>$exam['semesterId']?:null,'component'=>$exam['component'],'status'=>$session['status'],'raw_score'=>$raw,'scaled_score'=>$scaled,'score'=>$scaled,'submitted_at'=>(new DateTimeImmutable($submittedAt))->format('Y-m-d H:i:s.u'),'course_unit'=>$exam['courseUnit'],'review_snapshot_json'=>json_encode(['questions'=>$review,'integrityEvents'=>$result['integrityEvents']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}if(!empty($row['password_id']))$pdo->prepare('UPDATE assessment_passwords SET used_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id')->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>$row['password_id']]);mysqlFastAudit($pdo,'student',$student['id'],$auto?'exam_submitted_auto':'exam_submitted_manual','exam_session',$row['id'],['matricNumber'=>$student['matricNumber'],'course'=>$exam['code'],'component'=>$exam['component'],'rawScore'=>$raw,'scaledScore'=>$scaled,'correlationId'=>$row['correlation_id']]);$pdo->commit();clearSessionCookie('CBT_EXAM_SESSION');respond(['result'=>$result]);}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
/** Authenticate an admin read without hydrating every tenant table. */
function mysqlFastRequireAdmin(): array {
    $action=(string)($_GET['action']??'');$record=mysqlRouteAwareAdminSession();
    if(!$record)respond(['error'=>'No active admin sign-in was found. Please sign in again.'],401);
    if(!empty($record['mustChangePassword'])&&!in_array($action,['admin-account','admin-logout'],true))respond(['error'=>'Change the initial temporary administrator password before accessing the administration workspace.'],403);
    $permission=requiredPermission();$permissions=(($record['scope']??'')==='platform')?ADMIN_PERMISSIONS:(array)($record['permissions']??[]);
    if($permission&&!in_array($permission,$permissions,true))respond(['error'=>'Your role does not have permission to perform this action.'],403);
    $now=date('c');$record['lastSeenAt']=$now;$record['expiresAt']=ADMIN_SESSION_EXPIRES_AT;
    if(($record['scope']??'')==='platform')mysqlUpdatePlatformSession((string)$record['tokenHash'],$now,ADMIN_SESSION_EXPIRES_AT);else mysqlUpdateTenantSession((string)$record['tokenHash'],$now,ADMIN_SESSION_EXPIRES_AT);
    return $record;
}
function mysqlFastSetting(string $key, mixed $fallback=null): mixed {
    $statement=mysqlAppPdo()->prepare('SELECT setting_json FROM institution_settings WHERE institution_id=:institution_id AND setting_key=:setting_key LIMIT 1');$statement->execute(['institution_id'=>mysqlCurrentInstitutionId(),'setting_key'=>$key]);return mysqlJson($statement->fetchColumn(),$fallback);
}
function mysqlFastDashboard(): never {
    mysqlFastRequireAdmin();$pdo=mysqlAppPdo();$institutionId=mysqlCurrentInstitutionId();
    $students=$pdo->prepare('SELECT COUNT(*) FROM students WHERE institution_id=? AND active=1');$students->execute([$institutionId]);$studentCount=(int)$students->fetchColumn();
    $active=$pdo->prepare("SELECT COUNT(*) FROM course_components WHERE institution_id=? AND status='active' AND start_at<=UTC_TIMESTAMP(6) AND end_at>=UTC_TIMESTAMP(6)");$active->execute([$institutionId]);$activeCount=(int)$active->fetchColumn();
    $today=gmdate('Y-m-d');$completed=$pdo->prepare('SELECT COUNT(*) FROM component_submissions WHERE institution_id=? AND submitted_at>=? AND submitted_at<?');$completed->execute([$institutionId,$today.' 00:00:00',(new DateTimeImmutable($today,new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d 00:00:00')]);$completedToday=(int)$completed->fetchColumn();
    $average=$pdo->prepare('SELECT COALESCE(ROUND(AVG(score),1),0) FROM component_submissions WHERE institution_id=?');$average->execute([$institutionId]);$averageScore=(float)$average->fetchColumn();
    $recent=$pdo->prepare('SELECT cs.id,cs.attempt_id,cs.student_id,cs.component_id,cs.component,cs.status,cs.raw_score,cs.scaled_score,cs.score,cs.submitted_at,cs.course_id,s.full_name,cc.code FROM component_submissions cs INNER JOIN students s ON s.institution_id=cs.institution_id AND s.id=cs.student_id INNER JOIN course_components cc ON cc.institution_id=cs.institution_id AND cc.id=cs.component_id WHERE cs.institution_id=? ORDER BY cs.submitted_at DESC,cs.id DESC LIMIT 8');$recent->execute([$institutionId]);$recentItems=[];foreach($recent->fetchAll() as $row)$recentItems[]=['id'=>$row['id'],'sessionId'=>$row['attempt_id'],'examSessionId'=>$row['attempt_id'],'studentId'=>$row['student_id'],'examId'=>$row['component_id'],'courseId'=>$row['course_id'],'component'=>$row['component'],'rawScore'=>(float)$row['raw_score'],'scaledScore'=>(float)$row['scaled_score'],'score'=>(float)$row['score'],'status'=>$row['status'],'submittedAt'=>mysqlIso($row['submitted_at']),'studentName'=>$row['full_name'],'examCode'=>$row['code']];
    $distribution=$pdo->prepare("SELECT CASE WHEN score<40 THEN '0-39' WHEN score<50 THEN '40-49' WHEN score<60 THEN '50-59' WHEN score<70 THEN '60-69' ELSE '70-100' END label,COUNT(*) count FROM component_submissions WHERE institution_id=? GROUP BY label");$distribution->execute([$institutionId]);$distributionCounts=array_fill_keys(['0-39','40-49','50-59','60-69','70-100'],0);foreach($distribution->fetchAll() as $row)$distributionCounts[$row['label']]=(int)$row['count'];$scoreDistribution=[];foreach($distributionCounts as $label=>$count)$scoreDistribution[]=['label'=>$label,'count'=>$count];
    $pass=$pdo->prepare('SELECT MIN(min_score) FROM grading_scale_bands WHERE institution_id=? AND grade_point>0');$pass->execute([$institutionId]);$passScore=(float)($pass->fetchColumn()?:100);
    $base=" FROM course_components cc LEFT JOIN component_submissions cs ON cs.institution_id=cc.institution_id AND cs.component_id=cc.id WHERE cc.institution_id=? GROUP BY cc.id,cc.code,cc.title,cc.component";
    $outcomeSql='SELECT cc.id exam_id,cc.code,cc.title,cc.component,COUNT(cs.id) attempts,COALESCE(SUM(cs.score>=?),0) passed,COALESCE(ROUND(AVG(cs.score),1),0) average_score'.$base;
    $outcomes=$pdo->prepare($outcomeSql.' ORDER BY attempts DESC,cc.code ASC');$outcomes->execute([$passScore,$institutionId]);$allOutcomes=$outcomes->fetchAll();
    $hidden=$pdo->prepare('SELECT outcome_key FROM dashboard_hidden_outcomes WHERE institution_id=?');$hidden->execute([$institutionId]);$hiddenIds=array_fill_keys(array_map('strval',$hidden->fetchAll(PDO::FETCH_COLUMN)),true);$visible=[];$hiddenRows=[];$popular=[];
    foreach($allOutcomes as $row){$item=['examId'=>$row['exam_id'],'code'=>$row['code'],'title'=>$row['title'],'component'=>$row['component'],'componentLabel'=>ucfirst((string)$row['component']),'attempts'=>(int)$row['attempts'],'passed'=>(int)$row['passed'],'failed'=>(int)$row['attempts']-(int)$row['passed'],'passRate'=>(int)$row['attempts']?round(100*(int)$row['passed']/(int)$row['attempts'],1):0,'averageScore'=>(float)$row['average_score']];$popular[]=['id'=>$row['exam_id'],'code'=>$row['code'],'title'=>$row['title'],'component'=>$row['component'],'componentLabel'=>ucfirst((string)$row['component']),'attempts'=>(int)$row['attempts']];if(isset($hiddenIds[(string)$row['exam_id']]))$hiddenRows[]=$item;else $visible[]=$item;}
    $pageSize=max(3,min(20,(int)($_GET['outcomePageSize']??5)));$total=count($visible);$pages=max(1,(int)ceil($total/$pageSize));$page=max(1,min($pages,(int)($_GET['outcomePage']??1)));$visible=array_slice($visible,($page-1)*$pageSize,$pageSize);$setupMode=(bool)mysqlFastSetting('studentPortalSetupMode',false);
    respond(['stats'=>['students'=>$studentCount,'activeExams'=>$activeCount,'completedToday'=>$completedToday,'averageScore'=>$averageScore],'activity'=>array_slice($recentItems,0,5),'popular'=>array_slice($popular,0,8),'recent'=>$recentItems,'scoreDistribution'=>$scoreDistribution,'courseOutcomes'=>$visible,'courseOutcomesMeta'=>['page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'pages'=>$pages],'hiddenCourseOutcomes'=>array_map(fn($item)=>['examId'=>$item['examId'],'code'=>$item['code'],'title'=>$item['title'],'componentLabel'=>$item['componentLabel']],$hiddenRows),'passScore'=>$passScore,'studentPortalSetupMode'=>$setupMode]);
}
function mysqlFastDashboardPortalMode(): never {
    $session = mysqlFastRequireAdmin();
    $platformManagingTenant = (($session['scope'] ?? '') === 'platform');
    if (!canManageCurrentInstitutionSettings($session)) respond(['error' => 'Select an institution from the Institutions panel before changing student portal availability.'], 403);
    $input = body();
    if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) respond(['error' => 'Choose whether student portal setup mode is on or off.'], 422);

    $enabled = $input['enabled'];
    $institutionId = mysqlCurrentInstitutionId();
    $pdo = mysqlAppPdo();
    $pdo->beginTransaction();
    try {
        $previous = $pdo->prepare("SELECT setting_json FROM institution_settings WHERE institution_id=:institution_id AND setting_key='studentPortalSetupMode' FOR UPDATE");
        $previous->execute(['institution_id' => $institutionId]);
        $wasEnabled = (bool)mysqlJson($previous->fetchColumn(), false);
        $write = $pdo->prepare("INSERT INTO institution_settings (institution_id,setting_key,setting_json) VALUES (:institution_id,'studentPortalSetupMode',:setting_json) ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json)");
        $write->execute(['institution_id' => $institutionId, 'setting_json' => json_encode($enabled, JSON_THROW_ON_ERROR)]);
        mysqlFastAudit($pdo, 'admin', (string)($session['email'] ?? adminActorId()), $enabled ? 'student_portal_setup_mode_enabled' : 'student_portal_setup_mode_disabled', 'student_portal', 'setup_mode', [
            'enabled' => $enabled,
            'beforeAfter' => ['studentPortalSetupMode' => ['before' => $wasEnabled, 'after' => $enabled]],
        ]);
        if ($platformManagingTenant) {
            $institution = mysqlRequestInstitution();
            mysqlInsertPlatformAudit($pdo, (string)($session['email'] ?? adminActorId()), 'tenant_portal_setup_mode_updated', (int)$institution['id'], 'institution_settings', (string)$institution['slug'], 'success', (string)($session['correlationId'] ?? ''), ['enabled' => $enabled, 'institutionSlug' => $institution['slug']], ['studentPortalSetupMode' => ['before' => $wasEnabled, 'after' => $enabled]]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    respond(['enabled' => $enabled]);
}
function mysqlFastAuditEvents(): never {
    mysqlFastRequireAdmin();$from=trim((string)($_GET['from']??''));$to=trim((string)($_GET['to']??''));$actor=strtolower(trim((string)($_GET['actor']??'')));$type=trim((string)($_GET['type']??''));$course=strtolower(trim((string)($_GET['course']??'')));foreach([$from,$to] as $date)if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))respond(['error'=>'Use YYYY-MM-DD for audit dates.'],422);$where=['institution_id=?'];$params=[mysqlCurrentInstitutionId()];if($from!==''){$where[]='timestamp_at>=?';$params[]=$from.' 00:00:00';}if($to!==''){$where[]='timestamp_at<?';$params[]=(new DateTimeImmutable($to,new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d 00:00:00');}if($type!==''){$where[]='action_type=?';$params[]=$type;}if($actor!==''){$where[]="LOWER(CONCAT(COALESCE(actor_id,''),' ',COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.matricNumber')),''),' ',COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.name')),''))) LIKE ?";$params[]='%'.$actor.'%';}if($course!==''){$where[]="LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.course')),'')) LIKE ?";$params[]='%'.$course.'%';}$filter=implode(' AND ',$where);$count=mysqlAppPdo()->prepare("SELECT COUNT(*) FROM audit_events WHERE {$filter}");$count->execute($params);$total=(int)$count->fetchColumn();$pageSize=max(5,min(100,(int)($_GET['pageSize']??20)));$pages=max(1,(int)ceil($total/$pageSize));$page=max(1,min($pages,(int)($_GET['page']??1)));$statement=mysqlAppPdo()->prepare("SELECT id,timestamp_at,actor_type,actor_id,action_type,target_type,target_id,ip_address,user_agent,outcome,correlation_id,before_after_json,metadata_json FROM audit_events WHERE {$filter} ORDER BY timestamp_at DESC,id DESC LIMIT ? OFFSET ?");foreach($params as $index=>$value)$statement->bindValue($index+1,$value);$statement->bindValue(count($params)+1,$pageSize,PDO::PARAM_INT);$statement->bindValue(count($params)+2,($page-1)*$pageSize,PDO::PARAM_INT);$statement->execute();$items=[];foreach($statement->fetchAll() as $row){$meta=(array)mysqlJson($row['metadata_json'],[]);$items[]=['id'=>$row['id'],'timestamp'=>mysqlIso($row['timestamp_at']),'actorType'=>$row['actor_type'],'actorId'=>$row['actor_id'],'actionType'=>$row['action_type'],'targetType'=>$row['target_type'],'targetId'=>$row['target_id'],'ipAddress'=>$row['ip_address'],'userAgent'=>$row['user_agent'],'outcome'=>$row['outcome'],'correlationId'=>$row['correlation_id'],'beforeAfter'=>mysqlJson($row['before_after_json'],[]),'metadata'=>$meta,'actor'=>$row['actor_type']==='student'?($meta['matricNumber']??$row['actor_id']):($row['actor_id']?:'System'),'target'=>!empty($meta['course'])?($meta['course'].(!empty($meta['text'])?' · '.$meta['text']:'')):($meta['matricNumber']??$meta['name']??$row['target_id'])];}respond(['items'=>$items,'meta'=>['page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'pages'=>$pages]]);
}
function mysqlFastResults(): never {
    mysqlFastRequireAdmin();$sessionId=(string)($_GET['sessionId']??'');$semesterId=(string)($_GET['semesterId']??'');$search=trim((string)($_GET['search']??$_GET['q']??''));$aggregate='SELECT student_id,COUNT(*) completed_components,MAX(CONCAT(DATE_FORMAT(submitted_at,\'%Y%m%d%H%i%s%f\'),CHAR(31),id)) latest_key FROM component_submissions WHERE institution_id=?';$params=[mysqlCurrentInstitutionId()];if($sessionId!==''){$aggregate.=' AND academic_session_id=?';$params[]=$sessionId;}if($semesterId!==''){$aggregate.=' AND academic_semester_id=?';$params[]=$semesterId;}$aggregate.=' GROUP BY student_id';$outer=" FROM ({$aggregate}) grouped INNER JOIN component_submissions cs ON cs.institution_id=? AND cs.student_id=grouped.student_id AND CONCAT(DATE_FORMAT(cs.submitted_at,'%Y%m%d%H%i%s%f'),CHAR(31),cs.id)=grouped.latest_key INNER JOIN students s ON s.institution_id=cs.institution_id AND s.id=cs.student_id LEFT JOIN academic_sessions acs ON acs.institution_id=cs.institution_id AND acs.id=cs.academic_session_id LEFT JOIN academic_semesters sem ON sem.institution_id=cs.institution_id AND sem.id=cs.academic_semester_id LEFT JOIN calculated_results cr ON cr.institution_id=cs.institution_id AND cr.student_id=cs.student_id AND cr.session_id=cs.academic_session_id AND cr.semester_id=cs.academic_semester_id";$outerParams=array_merge($params,[mysqlCurrentInstitutionId()]);if($search!==''){$outer.=' WHERE (s.full_name LIKE ? OR s.matric_number LIKE ?)';$outerParams[]='%'.$search.'%';$outerParams[]='%'.$search.'%';}$sort=(string)($_GET['sort']??'');$direction=(($_GET['order']??$_GET['dir']??'asc')==='desc')?'DESC':'ASC';$order=['studentName'=>'s.full_name','matricNumber'=>'s.matric_number','completedComponents'=>'grouped.completed_components','latestSubmittedAt'=>'cs.submitted_at'][$sort]??'s.full_name';$pageSize=max(1,min(100,(int)($_GET['pageSize']??$_GET['limit']??25)));$count=mysqlAppPdo()->prepare('SELECT COUNT(*)'.$outer);$count->execute($outerParams);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$pageSize));$page=max(1,min($pages,(int)($_GET['page']??1)));$statement=mysqlAppPdo()->prepare('SELECT cs.student_id,s.full_name,s.matric_number,grouped.completed_components,cs.submitted_at,cs.academic_session_id,cs.academic_semester_id,acs.label session_label,sem.label semester_label,cr.id calculated_id'.$outer." ORDER BY {$order} {$direction},s.matric_number ASC LIMIT ? OFFSET ?");foreach($outerParams as $index=>$value)$statement->bindValue($index+1,$value);$statement->bindValue(count($outerParams)+1,$pageSize,PDO::PARAM_INT);$statement->bindValue(count($outerParams)+2,($page-1)*$pageSize,PDO::PARAM_INT);$statement->execute();$items=[];foreach($statement->fetchAll() as $row)$items[]=['studentId'=>$row['student_id'],'studentName'=>$row['full_name'],'matricNumber'=>$row['matric_number'],'completedComponents'=>(int)$row['completed_components'],'latestSubmittedAt'=>mysqlIso($row['submitted_at']),'sessionId'=>$row['academic_session_id']??'','semesterId'=>$row['academic_semester_id']??'','sessionLabel'=>$row['session_label']??'Unassigned session','semesterLabel'=>$row['semester_label']??'Unassigned semester','calculated'=>$row['calculated_id']!==null];$periods=mysqlAppPdo()->prepare('SELECT DISTINCT cs.academic_session_id session_id,cs.academic_semester_id semester_id,acs.label session_label,sem.label semester_label FROM component_submissions cs LEFT JOIN academic_sessions acs ON acs.institution_id=cs.institution_id AND acs.id=cs.academic_session_id LEFT JOIN academic_semesters sem ON sem.institution_id=cs.institution_id AND sem.id=cs.academic_semester_id WHERE cs.institution_id=? ORDER BY session_label DESC,semester_label DESC');$periods->execute([mysqlCurrentInstitutionId()]);$periodItems=[];foreach($periods->fetchAll() as $row)$periodItems[]=['sessionId'=>$row['session_id']??'','semesterId'=>$row['semester_id']??'','sessionLabel'=>$row['session_label']??'Unassigned session','semesterLabel'=>$row['semester_label']??'Unassigned semester'];respond(['items'=>$items,'meta'=>['total'=>$total,'page'=>$page,'pageSize'=>$pageSize,'limit'=>$pageSize,'pages'=>$pages,'totalPages'=>$pages],'periods'=>$periodItems]);
}
/** Algebra Stage 1 is deliberately narrower than ordinary tenant permissions:
 * it is available only to the institution's top-level Admin session.  A
 * platform session, an unscoped URL, and a sub-admin role cannot use these
 * data-bearing endpoints. */
function mysqlAlgebraRequireTenantAdmin(): array {
    $session = mysqlFastRequireAdmin();
    $tenantAdmin = ($session['scope'] ?? '') === 'institution' && isInstitutionAdminRole((string)($session['roleId'] ?? ''));
    $platformTenantContext = ($session['scope'] ?? '') === 'platform' && requestHasExplicitInstitutionPath();
    if (!$tenantAdmin && !$platformTenantContext) {
        respond(['error' => 'Resource not found.'], 404);
    }
    return $session;
}
function algebraBeginRequest(array &$session): void {
    $correlationId = 'alg-' . bin2hex(random_bytes(12));
    $_SERVER['CBT_ALGEBRA_CORRELATION_ID'] = $correlationId;
    $session['correlationId'] = $correlationId;
}
function algebraActorColumns(array $session): array {
    $isPlatform = ($session['scope'] ?? '') === 'platform';
    $actorId = (string)($session['userId'] ?? '');
    if ($actorId === '') throw new RuntimeException('Algebra actor identity is unavailable.');
    return [
        'requested_by_admin_id' => $isPlatform ? null : $actorId,
        'requested_by_platform_admin_id' => $isPlatform ? $actorId : null,
        'actorLabel' => $isPlatform ? 'Platform Super Admin' : (string)($session['email'] ?? $actorId),
        'actorScope' => $isPlatform ? 'platform' : 'institution',
    ];
}
function algebraAudit(PDO $pdo, array $session, string $action, string $targetType, string $targetId, array $metadata = []): void {
    $actor = algebraActorColumns($session);
    $metadata['actorScope'] = $actor['actorScope'];
    $metadata['correlationId'] = (string)($session['correlationId'] ?? ($metadata['correlationId'] ?? ''));
    mysqlFastAudit($pdo, 'admin', $actor['actorLabel'], $action, $targetType, $targetId, $metadata);
    if (($actor['actorScope'] ?? '') === 'platform') {
        $institution = mysqlRequestInstitution();
        mysqlInsertPlatformAudit($pdo, $actor['actorLabel'], 'tenant_' . $action, (int)$institution['id'], $targetType, $targetId, auditOutcome($action, $metadata), (string)$metadata['correlationId'], $metadata);
    }
}
function algebraRequestId(): string { return bin2hex(random_bytes(16)); }
function algebraTrimText(mixed $value, string $label, int $maximum): string {
    $text = trim((string)$value);
    if ($text === '') respond(['error' => $label . ' is required.'], 422);
    if (mb_strlen($text) > $maximum) respond(['error' => $label . ' must be at most ' . $maximum . ' characters.'], 422);
    return $text;
}
function algebraRejectPersonalData(string $text, string $label): void {
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text) || preg_match('/(?:\+?\d[\d\s().-]{7,}\d)/', $text) || preg_match('/\b[A-Z]{2,8}[\/-]\d{2,}[A-Z0-9\/-]*\b/i', $text)) {
        respond(['error' => $label . ' must not contain student identifiers, email addresses, or phone numbers.'], 422);
    }
}
function algebraConfigInt(string $key, int $minimum, int $maximum): int {
    $value = filter_var(getenv($key), FILTER_VALIDATE_INT);
    if ($value === false || $value < $minimum || $value > $maximum) throw new AlgebraProviderException('Algebra provider limits are not configured safely.', 503);
    return (int)$value;
}
function algebraPacificDay(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
}
function algebraNextPacificMidnight(): string {
    return (new DateTimeImmutable('tomorrow', new DateTimeZone('America/Los_Angeles')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function algebraBudgetReserve(int $institutionId): void {
    $pdo = mysqlAppPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS algebra_provider_budget (budget_key VARCHAR(80) NOT NULL PRIMARY KEY, quota_day DATE NOT NULL, attempt_count INT UNSIGNED NOT NULL DEFAULT 0, blocked_until DATETIME(6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $day = algebraPacificDay(); $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $global = algebraConfigInt('CBT_ALGEBRA_GLOBAL_DAILY_CAP', 1, 19);
    $tenant = algebraConfigInt('CBT_ALGEBRA_TENANT_DAILY_CAP', 1, $global);
    foreach ([['global', $global], ['tenant:' . $institutionId, $tenant]] as [$key, $limit]) {
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare('SELECT quota_day,attempt_count,blocked_until FROM algebra_provider_budget WHERE budget_key=? FOR UPDATE'); $select->execute([$key]); $row = $select->fetch();
            if (!$row) { $pdo->prepare('INSERT INTO algebra_provider_budget (budget_key,quota_day,attempt_count) VALUES (?, ?, 0)')->execute([$key, $day]); $row = ['quota_day'=>$day,'attempt_count'=>0,'blocked_until'=>null]; }
            if ((string)$row['quota_day'] !== $day) { $pdo->prepare('UPDATE algebra_provider_budget SET quota_day=?,attempt_count=0,blocked_until=NULL WHERE budget_key=?')->execute([$day,$key]); $row=['quota_day'=>$day,'attempt_count'=>0,'blocked_until'=>null]; }
            if ($row['blocked_until'] !== null && strtotime((string)$row['blocked_until']) > $now->getTimestamp()) { $blocked=(string)$row['blocked_until']; $pdo->rollBack(); throw new AlgebraDailyQuotaException($blocked); }
            if ((int)$row['attempt_count'] >= $limit) { $pdo->rollBack(); throw new AlgebraProviderException('Algebra provider allowance has been reached. Please try again later.', 429); }
            $pdo->prepare('UPDATE algebra_provider_budget SET attempt_count=attempt_count+1 WHERE budget_key=?')->execute([$key]); $pdo->commit();
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    }
}
function algebraMarkDailyBlocked(): string {
    $blocked = algebraNextPacificMidnight(); $pdo = mysqlAppPdo(); $pdo->exec("CREATE TABLE IF NOT EXISTS algebra_provider_budget (budget_key VARCHAR(80) NOT NULL PRIMARY KEY, quota_day DATE NOT NULL, attempt_count INT UNSIGNED NOT NULL DEFAULT 0, blocked_until DATETIME(6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $statement = $pdo->prepare('INSERT INTO algebra_provider_budget (budget_key,quota_day,attempt_count,blocked_until) VALUES (?, ?, 0, ?) ON DUPLICATE KEY UPDATE blocked_until=VALUES(blocked_until)');
    $statement->execute(['global', algebraPacificDay(), $blocked]); return $blocked;
}
/** Call Gemini's current Interactions API.  This is intentionally a small
 * shared primitive: the API key never crosses the server boundary and the
 * caller must validate every returned field before it reaches a mutation. */
function algebraGeminiText(string $instruction, int $maximumTokens = 9000): string {
    // Test-only provider seam. PHP_SAPI is checked first so web requests can
    // never enable fixtures through environment, headers, query, or body data.
    if (PHP_SAPI === 'cli' && getenv('CBT_ALGEBRA_MOCK_MODE') !== false) {
        $mode = (string)getenv('CBT_ALGEBRA_MOCK_MODE');
        if (getenv('CBT_ALGEBRA_MOCK_BUDGET') === '1') algebraBudgetReserve(mysqlCurrentInstitutionId());
        $capture = trim((string)getenv('CBT_ALGEBRA_MOCK_CAPTURE'));
        if ($capture !== '') file_put_contents($capture, $instruction, LOCK_EX);
        if ($mode === '429') throw new AlgebraProviderException('Algebra is temporarily rate-limited. Please wait a moment and try again.', 429);
        if ($mode === 'daily429') throw new AlgebraDailyQuotaException(algebraMarkDailyBlocked());
        if ($mode === '503') throw new AlgebraProviderException('Algebra is temporarily unavailable. Please try again in a moment.', 503);
        if ($mode === 'timeout') throw new AlgebraProviderException('Algebra could not reach Gemini after automatic retries. Please try again shortly.', 502);
        if ($mode === 'empty') throw new AlgebraProviderException('Algebra returned no usable response. Please try again.', 422);
        if ($mode === 'garbage') return 'not-json';
        return (string)getenv('CBT_ALGEBRA_MOCK_RESPONSE');
    }
    $apiKey = trim((string)getenv('GEMINI_API_KEY'));
    if ($apiKey === '') throw new AlgebraProviderException('Algebra is not configured yet. Add GEMINI_API_KEY to the server .env file, then try again.', 503);
    if (!function_exists('curl_init')) throw new AlgebraProviderException('Algebra requires the PHP cURL extension, which is not enabled on this server.', 503);
    // Algebra has its own provider setting so a capacity issue cannot alter PDF
    // imports or any other Gemini-backed workflow.
    $payload = ['model' => (string)(getenv('CBT_ALGEBRA_GEMINI_MODEL') ?: getenv('CBT_GEMINI_MODEL') ?: 'gemini-3.1-flash-lite'), 'input' => $instruction, 'generation_config' => ['temperature' => 0.2, 'thinking_level' => 'low', 'max_output_tokens' => $maximumTokens]];
    $raw = false; $error = ''; $status = 0; $response = null;
    foreach ([0, 400000, 1200000] as $delay) {
        if ($delay) usleep($delay);
        algebraBudgetReserve(mysqlCurrentInstitutionId());
        $curl = curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $raw = curl_exec($curl); $error = curl_error($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        $response = json_decode((string)$raw, true);
        if ($raw !== false && $error === '' && $status >= 200 && $status < 300 && is_array($response)) break;
        $message = trim((string)($response['error']['message'] ?? ''));
        if ($status === 429 && preg_match('/per day|daily|requests per day|quota.*day/i', $message)) throw new AlgebraDailyQuotaException(algebraMarkDailyBlocked());
        if (!in_array($status, [0, 429, 500, 502, 503, 504], true)) break;
    }
    if ($raw === false || $error !== '') {
        error_log('Berevion Algebra Gemini connection failed after retries: ' . $error);
        throw new AlgebraProviderException('Algebra could not reach Gemini after automatic retries. Please try again shortly.', 502);
    }
    if ($status < 200 || $status >= 300 || !is_array($response)) {
        $message = trim((string)($response['error']['message'] ?? ''));
        error_log('Berevion Algebra Gemini response failed: HTTP ' . $status . ($message !== '' ? ' --- ' . substr($message, 0, 300) : ''));
        if ($status === 429) throw new AlgebraProviderException('Algebra is temporarily rate-limited. Please wait a moment and try again.', 429);
        if (in_array($status, [500, 502, 503, 504], true)) throw new AlgebraProviderException('Algebra is temporarily unavailable. Please try again in a moment.', 503);
        throw new AlgebraProviderException($message !== '' ? 'Gemini rejected this Algebra request: ' . $message : 'Algebra could not complete this request.', 502);
    }
    if (($response['status'] ?? 'completed') !== 'completed') throw new AlgebraProviderException('Algebra did not complete this request. Please try again.', 422);
    $content = '';
    foreach (($response['steps'] ?? []) as $step) if (($step['type'] ?? '') === 'model_output') foreach (($step['content'] ?? []) as $part) if (($part['type'] ?? '') === 'text') $content .= (string)($part['text'] ?? '');
    if (trim($content) === '') throw new AlgebraProviderException('Algebra returned no usable response. Please try again.', 422);
    return $content;
}
function algebraDecodeJson(string $content): array {
    $clean = trim(preg_replace('/^```(?:json)?\\s*|\\s*```$/i', '', trim($content)) ?? '');
    $decoded = json_decode($clean, true);
    if (!is_array($decoded)) throw new AlgebraProviderException('Algebra returned an invalid structured response. Nothing was saved; please try again.', 422);
    return $decoded;
}
function algebraQuestionItems(array $payload, int $expectedCount): array {
    $rows = $payload['questions'] ?? null;
    if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > ALGEBRA_MAX_DRAFT_QUESTIONS || count($rows) > $expectedCount) throw new AlgebraProviderException('Algebra returned an invalid number of draft questions. Nothing was saved.', 422);
    $items = []; $fingerprints = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) throw new AlgebraProviderException('Algebra returned an invalid question entry. Nothing was saved.', 422);
        $text = trim((string)($row['questionText'] ?? ''));
        $options = is_array($row['options'] ?? null) ? array_values(array_map(fn($value) => trim((string)$value), $row['options'])) : [];
        $correct = is_array($row['correctOptionIndexes'] ?? null) ? array_values(array_unique(array_map('intval', $row['correctOptionIndexes']))) : [];
        if ($text === '' || mb_strlen($text) > 5000 || count($options) < 2 || count($options) > 10 || in_array('', $options, true) || !$correct || count(array_filter($correct, fn($item) => $item < 0 || $item >= count($options))) > 0) throw new AlgebraProviderException('Algebra returned an invalid draft question. Nothing was saved.', 422);
        $type = count($correct) > 1 ? 'multiple' : 'single';
        if ($type === 'single' && count($correct) !== 1) throw new AlgebraProviderException('Algebra returned an invalid single-answer question. Nothing was saved.', 422);
        $fingerprint = questionTextFingerprint($text);
        if (isset($fingerprints[$fingerprint])) throw new AlgebraProviderException('Algebra repeated a draft question. Nothing was saved; try a narrower prompt.', 422);
        $fingerprints[$fingerprint] = true;
        $items[] = ['questionText' => $text, 'options' => $options, 'correctOptionIndexes' => $correct, 'type' => $type, 'confidence' => ($row['confidence'] ?? '') === 'high' ? 'high' : 'low'];
    }
    return $items;
}
function algebraCreateRequest(PDO $pdo, array $session, string $capability, array $input): string {
    $id = algebraRequestId();
    $actor = algebraActorColumns($session);
    $encoded = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $statement = $pdo->prepare("INSERT INTO algebra_requests (institution_id,id,requested_by_admin_id,requested_by_platform_admin_id,capability,status,request_hash,input_json,correlation_id,created_at) VALUES (:institution_id,:id,:admin_id,:platform_admin_id,:capability,'running',:request_hash,:input_json,:correlation_id,UTC_TIMESTAMP(6))");
    $statement->execute(['institution_id' => mysqlCurrentInstitutionId(), 'id' => $id, 'admin_id' => $actor['requested_by_admin_id'], 'platform_admin_id' => $actor['requested_by_platform_admin_id'], 'capability' => $capability, 'request_hash' => hash('sha256', $encoded), 'input_json' => $encoded, 'correlation_id' => (string)($session['correlationId'] ?? '')]);
    return $id;
}
function algebraFinishRequest(PDO $pdo, string $id, string $status, array $response = [], ?string $errorCode = null, ?string $errorMessage = null): void {
    $statement = $pdo->prepare('UPDATE algebra_requests SET status=:status,response_json=:response_json,error_code=:error_code,error_message=:error_message,completed_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id');
    $statement->execute(['institution_id' => mysqlCurrentInstitutionId(), 'id' => $id, 'status' => $status, 'response_json' => $response ? json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null, 'error_code' => $errorCode, 'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 500)]);
}
function algebraCourse(string $courseId): ?array {
    $statement = mysqlAppPdo()->prepare('SELECT id,code,title FROM courses WHERE institution_id=:institution_id AND id=:id LIMIT 1');
    $statement->execute(['institution_id' => mysqlCurrentInstitutionId(), 'id' => $courseId]); return $statement->fetch() ?: null;
}
function mysqlAlgebraQuestionDraft(): never {
    $session = mysqlAlgebraRequireTenantAdmin(); algebraBeginRequest($session); $empty = [];
    enforceRateLimit($empty, 'algebra-question-draft-' . (string)$session['userId'], 1, 15);
    enforceSystemRateLimit($empty, 'algebra-question-draft-daily', 50, 86400, 'Algebra question-drafting');
    $input = body(); $courseId = trim((string)($input['courseId'] ?? '')); $course = algebraCourse($courseId);
    if (!$course) respond(['error' => 'Course not found in this institution.'], 404);
    $topic = algebraTrimText($input['topic'] ?? '', 'Topic', 190); algebraRejectPersonalData($topic, 'Topic'); $difficulty = algebraTrimText($input['difficulty'] ?? '', 'Difficulty', 60);
    $count = (int)($input['count'] ?? 0); $single = (int)($input['singleCount'] ?? 0); $multiple = (int)($input['multipleCount'] ?? 0);
    if ($count < 1 || $count > ALGEBRA_MAX_DRAFT_QUESTIONS || $single < 0 || $multiple < 0 || ($single + $multiple) !== $count) respond(['error' => 'Choose between 1 and ' . ALGEBRA_MAX_DRAFT_QUESTIONS . ' questions with a complete single/multiple-answer mix.'], 422);
    $brief = trim((string)($input['brief'] ?? '')); if (mb_strlen($brief) > ALGEBRA_MAX_PROMPT_LENGTH) respond(['error' => 'Additional instructions must be at most ' . ALGEBRA_MAX_PROMPT_LENGTH . ' characters.'], 422); algebraRejectPersonalData($brief, 'Additional instructions');
    $request = ['courseId'=>$courseId, 'courseCode'=>$course['code'], 'topic'=>$topic, 'difficulty'=>$difficulty, 'count'=>$count, 'singleCount'=>$single, 'multipleCount'=>$multiple, 'brief'=>$brief];
    $pdo = mysqlAppPdo(); $requestId = algebraCreateRequest($pdo, $session, 'question_draft', $request);
    try {
        $instruction = 'You draft assessment questions for an administrator. Return ONLY strict JSON with this exact shape: {"questions":[{"questionText":"...","options":["..."],"correctOptionIndexes":[0],"confidence":"high"}]}. Produce exactly ' . $count . ' distinct questions for course ' . $course['code'] . ' (' . $course['title'] . '), topic "' . $topic . '", difficulty "' . $difficulty . '". Produce exactly ' . $single . ' single-answer questions and ' . $multiple . ' multiple-answer questions. Each question needs 2-10 complete options and zero-based correct indexes. Use only defensible, unambiguous answer keys. Never include publishing targets, grades, student data, prose outside JSON, or invented source citations. Administrator guidance: ' . ($brief !== '' ? $brief : 'None.');
        // Multiple-choice drafts are compact JSON. Reserve output in proportion to
        // the requested count rather than holding a 12k-token slot for every call.
        $items = algebraQuestionItems(algebraDecodeJson(algebraGeminiText($instruction, min(8000, max(3000, $count * 260)))), $count);
        algebraFinishRequest($pdo, $requestId, 'completed', ['items'=>$items]);
        algebraAudit($pdo, $session, 'algebra_question_draft_generated', 'course', $courseId, ['course'=>$course['code'], 'requestId'=>$requestId, 'count'=>count($items), 'status'=>'review_required']);
        respond(['requestId'=>$requestId, 'courseId'=>$courseId, 'items'=>$items, 'reviewRequired'=>true, 'status'=>'draft_only']);
    } catch (Throwable $error) {
        algebraFinishRequest($pdo, $requestId, 'failed', [], 'gemini_or_validation_failed', $error->getMessage());
        algebraAudit($pdo, $session, 'algebra_question_draft_failed', 'course', $courseId, ['course'=>$course['code'], 'requestId'=>$requestId]);
        if ($error instanceof AlgebraProviderException) respond(['error' => $error->getMessage()], $error->httpStatus);
        throw $error;
    }
}
function algebraPerformanceSnapshot(): array {
    $pdo = mysqlAppPdo(); $institutionId = mysqlCurrentInstitutionId();
    $summary = $pdo->prepare("SELECT COUNT(*) submissions, COALESCE(ROUND(AVG(score),1),0) average_score, COALESCE(SUM(score >= (SELECT COALESCE(MIN(min_score),100) FROM grading_scale_bands WHERE institution_id=? AND grade_point>0)),0) passed FROM component_submissions WHERE institution_id=?");
    $summary->execute([$institutionId, $institutionId]); $head = $summary->fetch() ?: ['submissions'=>0,'average_score'=>0,'passed'=>0];
    $bands = $pdo->prepare("SELECT CASE WHEN score<40 THEN '0-39' WHEN score<50 THEN '40-49' WHEN score<60 THEN '50-59' WHEN score<70 THEN '60-69' ELSE '70-100' END band, COUNT(*) count FROM component_submissions WHERE institution_id=? GROUP BY band");
    $bands->execute([$institutionId]); $distribution = array_fill_keys(['0-39','40-49','50-59','60-69','70-100'], 0); foreach ($bands->fetchAll() as $row) $distribution[$row['band']] = (int)$row['count'];
    $components = $pdo->prepare("SELECT cc.code,cc.component,COUNT(cs.id) submissions,COALESCE(ROUND(AVG(cs.score),1),0) average_score FROM course_components cc LEFT JOIN component_submissions cs ON cs.institution_id=cc.institution_id AND cs.component_id=cc.id WHERE cc.institution_id=? GROUP BY cc.id,cc.code,cc.component ORDER BY submissions DESC,cc.code ASC LIMIT 20");
    $components->execute([$institutionId]);
    $rows = $pdo->prepare("SELECT s.question_id,s.question_json,GROUP_CONCAT(a.option_index ORDER BY a.option_index) answer_indexes,MAX(at.submitted_at) submitted_at FROM attempt_question_snapshots s INNER JOIN assessment_attempts at ON at.institution_id=s.institution_id AND at.id=s.attempt_id AND at.status IN ('submitted','auto_submitted') LEFT JOIN attempt_answers a ON a.institution_id=s.institution_id AND a.attempt_id=s.attempt_id AND a.question_id=s.question_id WHERE s.institution_id=? GROUP BY s.attempt_id,s.question_id,s.question_json ORDER BY submitted_at DESC LIMIT 2500");
    $rows->execute([$institutionId]); $missed = [];
    foreach ($rows->fetchAll() as $row) { $question = (array)mysqlJson($row['question_json'], []); $expected = array_values(array_map('intval', (array)($question['correctOptions'] ?? []))); sort($expected); $answers = ($row['answer_indexes'] ?? '') === null || $row['answer_indexes'] === '' ? [] : array_values(array_map('intval', explode(',', (string)$row['answer_indexes']))); sort($answers); $key = (string)$row['question_id']; $missed[$key] ??= ['question'=>mb_substr(trim((string)($question['text'] ?? 'Question unavailable')), 0, 240), 'attempts'=>0, 'missed'=>0]; $missed[$key]['attempts']++; if ($answers !== $expected) $missed[$key]['missed']++; }
    usort($missed, fn($a,$b) => ($b['missed'] <=> $a['missed']) ?: ($b['attempts'] <=> $a['attempts']));
    return ['submissions'=>(int)$head['submissions'], 'averageScore'=>(float)$head['average_score'], 'passed'=>(int)$head['passed'], 'scoreDistribution'=>$distribution, 'components'=>array_map(fn($r)=>['code'=>$r['code'],'component'=>$r['component'],'submissions'=>(int)$r['submissions'],'averageScore'=>(float)$r['average_score']], $components->fetchAll()), 'mostMissed'=>array_slice(array_values($missed), 0, 5)];
}
function mysqlAlgebraPerformanceInsight(): never {
    $session = mysqlAlgebraRequireTenantAdmin(); algebraBeginRequest($session); $empty = [];
    enforceRateLimit($empty, 'algebra-performance-insight-' . (string)$session['userId'], 1, 10);
    enforceSystemRateLimit($empty, 'algebra-performance-insight-daily', 100, 86400, 'Algebra performance-insight');
    $question = algebraTrimText((body()['question'] ?? ''), 'Question', ALGEBRA_MAX_PROMPT_LENGTH); algebraRejectPersonalData($question, 'Question');
    $snapshot = algebraPerformanceSnapshot(); $pdo = mysqlAppPdo(); $requestId = algebraCreateRequest($pdo, $session, 'performance_insight', ['question'=>$question, 'snapshot'=>$snapshot]);
    try {
        $instruction = 'You are Algebra, a careful education-performance assistant. Analyse ONLY this anonymised, single-institution aggregate snapshot. Do not infer student identities, accuse misconduct, recommend automatic restrictions, or claim causation from small samples. Return ONLY JSON: {"summary":"...","findings":[{"title":"...","detail":"...","priority":"info|watch|review"}],"caveats":["..."]}. The administrator asks: ' . $question . '\nSNAPSHOT:\n' . json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result = algebraDecodeJson(algebraGeminiText($instruction, 5000));
        $summary = trim((string)($result['summary'] ?? '')); $findings = $result['findings'] ?? []; $caveats = $result['caveats'] ?? [];
        if ($summary === '' || !is_array($findings) || !array_is_list($findings) || count($findings) > 8 || !is_array($caveats) || !array_is_list($caveats) || count($caveats) > 8) throw new AlgebraProviderException('Algebra returned an invalid insight response.',422);
        $cleanFindings=[]; foreach($findings as $finding){ if(!is_array($finding)) continue; $title=trim((string)($finding['title']??''));$detail=trim((string)($finding['detail']??''));$priority=in_array(($finding['priority']??''),['info','watch','review'],true)?$finding['priority']:'info'; if($title!==''&&$detail!=='')$cleanFindings[]=['title'=>mb_substr($title,0,180),'detail'=>mb_substr($detail,0,1000),'priority'=>$priority]; }
        if (!$cleanFindings) throw new AlgebraProviderException('Algebra returned no usable insight findings.',422);
        $output=['summary'=>mb_substr($summary,0,2000),'findings'=>$cleanFindings,'caveats'=>array_values(array_slice(array_filter(array_map(fn($item)=>mb_substr(trim((string)$item),0,500),$caveats)),0,8)),'snapshot'=>$snapshot];
        algebraFinishRequest($pdo, $requestId, 'completed', $output); algebraAudit($pdo,$session,'algebra_performance_insight_generated','performance_summary',$requestId,['requestId'=>$requestId,'aggregateOnly'=>true]);
        respond(['requestId'=>$requestId] + $output);
    } catch (Throwable $error) { algebraFinishRequest($pdo,$requestId,'failed',[],'gemini_or_validation_failed',$error->getMessage()); algebraAudit($pdo,$session,'algebra_performance_insight_failed','performance_summary',$requestId,['requestId'=>$requestId]); if ($error instanceof AlgebraProviderException) respond(['error'=>$error->getMessage()],$error->httpStatus); throw $error; }
}
function mysqlAlgebraQuestionImport(): never {
    $session = mysqlAlgebraRequireTenantAdmin(); algebraBeginRequest($session); $empty=[]; enforceRateLimit($empty, 'algebra-question-import-' . (string)$session['userId'], 20, 3600);
    $input=body(); $requestId=trim((string)($input['requestId']??'')); $courseId=trim((string)($input['courseId']??'')); $items=$input['items']??null; $course=algebraCourse($courseId);
    if (!preg_match('/^[a-f0-9]{32}$/',$requestId) || !is_array($items) || !array_is_list($items) || !$items || count($items)>ALGEBRA_MAX_DRAFT_QUESTIONS) respond(['error'=>'Select 1 to '.ALGEBRA_MAX_DRAFT_QUESTIONS.' reviewed Algebra questions for a course in this institution.'],422);
    if (!$course) respond(['error'=>'Course not found in this institution.'],404);
    $pdo=mysqlAppPdo(); $pdo->beginTransaction();
    try {
        $actorColumn = (($session['scope'] ?? '') === 'platform') ? 'requested_by_platform_admin_id' : 'requested_by_admin_id';
        $lookup=$pdo->prepare("SELECT status FROM algebra_requests WHERE institution_id=:institution_id AND id=:id AND {$actorColumn}=:admin_id AND capability='question_draft' FOR UPDATE");$lookup->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>$requestId,'admin_id'=>(string)$session['userId']]);$request=$lookup->fetch();if(!$request){ $pdo->rollBack(); respond(['error'=>'Algebra draft request not found in this institution.'],404); }if(!in_array($request['status'],['completed','imported'],true)){ $pdo->rollBack(); respond(['error'=>'This Algebra draft review is no longer available. Generate a new draft.'],409); }
        $clean=[];$fingerprints=[]; foreach($items as $index=>$item){ if(!is_array($item))respond(['error'=>'Reviewed Algebra question '.($index+1).' is invalid.'],422);$text=trim((string)($item['questionText']??''));$options=is_array($item['options']??null)?array_values(array_map(fn($x)=>trim((string)$x),$item['options'])):[];$correct=array_values(array_unique(array_map('intval',(array)($item['correctOptionIndexes']??[]))));if($text===''||count($options)<2||count($options)>10||in_array('',$options,true)||!$correct||count(array_filter($correct,fn($x)=>$x<0||$x>=count($options))))respond(['error'=>'Reviewed Algebra question '.($index+1).' is incomplete.'],422);$fingerprint=questionTextFingerprint($text);if(isset($fingerprints[$fingerprint]))respond(['error'=>'Reviewed Algebra questions must be unique.'],422);$fingerprints[$fingerprint]=true;$clean[]=['id'=>id(),'text'=>$text,'options'=>$options,'correct'=>$correct,'type'=>count($correct)>1?'multiple':'single']; }
        $existing=$pdo->prepare('SELECT text FROM questions WHERE institution_id=:institution_id AND course_id=:course_id FOR UPDATE');$existing->execute(['institution_id'=>mysqlCurrentInstitutionId(),'course_id'=>$courseId]);$existingHashes=[];foreach($existing->fetchAll() as $row)$existingHashes[questionTextFingerprint((string)$row['text'])]=true;$clean=array_values(array_filter($clean,fn($q)=>!isset($existingHashes[questionTextFingerprint($q['text'])])));$skipped=count($items)-count($clean);
        $insertQuestion=$pdo->prepare("INSERT INTO questions (institution_id,id,course_id,legacy_component_id,text,type,topic,difficulty,status) VALUES (:institution_id,:id,:course_id,NULL,:text,:type,NULL,NULL,'draft')");$insertOption=$pdo->prepare('INSERT INTO question_options (institution_id,question_id,option_index,option_text,is_correct) VALUES (:institution_id,:question_id,:option_index,:option_text,:is_correct)');foreach($clean as $question){$insertQuestion->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>$question['id'],'course_id'=>$courseId,'text'=>$question['text'],'type'=>$question['type']]);foreach($question['options'] as $optionIndex=>$option)$insertOption->execute(['institution_id'=>mysqlCurrentInstitutionId(),'question_id'=>$question['id'],'option_index'=>$optionIndex,'option_text'=>$option,'is_correct'=>in_array($optionIndex,$question['correct'],true)?1:0]);}
        $update = $pdo->prepare("UPDATE algebra_requests SET status='imported' WHERE institution_id=:institution_id AND id=:id");
        $update->execute(['institution_id' => mysqlCurrentInstitutionId(), 'id' => $requestId]);
        algebraAudit($pdo, $session, 'algebra_questions_imported_draft', 'course', $courseId, [
            'course' => (string)$course['code'], 'requestId' => $requestId, 'count' => count($clean),
            'skippedExisting' => $skipped, 'status' => 'draft', 'publishedTargets' => []
        ]);
        $pdo->commit();
        respond(['added' => count($clean), 'skipped' => $skipped, 'status' => 'draft', 'publishedTo' => []], $clean ? 201 : 200);
    } catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
/** Stage 2 keeps the same tenant-only guard as question drafting.  These
 * helpers intentionally return suggestions, narratives, or review flags only:
 * no Algebra route calls a course, settings, newsletter, result, or student
 * mutation endpoint. */
function algebraStage2RateLimit(array &$data, array $session, string $capability, int $seconds = 10, int $daily = 100): void {
    enforceRateLimit($data, 'algebra-' . $capability . '-' . (string)$session['userId'], 1, $seconds);
    enforceSystemRateLimit($data, 'algebra-' . $capability . '-daily', $daily, 86400, 'Algebra ' . str_replace('_', ' ', $capability));
}
function algebraBoundedText(mixed $value, int $maximum): string {
    return mb_substr(trim((string)$value), 0, $maximum);
}
function algebraFinishFailure(PDO $pdo, array $session, string $requestId, string $action, string $targetType, string $targetId, Throwable $error): never {
    algebraFinishRequest($pdo, $requestId, 'failed', [], 'gemini_or_validation_failed', $error->getMessage());
    algebraAudit($pdo, $session, $action . '_failed', $targetType, $targetId, ['requestId'=>$requestId]);
    if ($error instanceof AlgebraProviderException) respond(['error'=>$error->getMessage()], $error->httpStatus);
    throw $error;
}
function mysqlAlgebraSetupSuggestion(): never {
    $session = mysqlAlgebraRequireTenantAdmin(); algebraBeginRequest($session); $empty = []; algebraStage2RateLimit($empty, $session, 'setup_suggestion');
    $input = body(); $formType = (string)($input['formType'] ?? '');
    if (!in_array($formType, ['course','component','grading_scale'], true)) respond(['error'=>'Choose Course, component, or grading-scale setup assistance.'], 422);
    $brief = algebraTrimText($input['brief'] ?? '', 'Setup request', ALGEBRA_MAX_PROMPT_LENGTH); algebraRejectPersonalData($brief, 'Setup request');
    $pdo = mysqlAppPdo(); $requestId = algebraCreateRequest($pdo, $session, 'setup_suggestion', ['formType'=>$formType,'brief'=>$brief]);
    try {
        $shapes = [
            'course' => '{"summary":"...","fields":{"code":"...","title":"...","category":"...","courseUnit":3,"sessionLabel":"...","semesterLabel":"Harmattan Semester","description":"...","testMaxMark":30,"testPassThreshold":15,"testDuration":30,"testQuestionCount":20,"examMaxMark":70,"examPassThreshold":35,"examDuration":90,"examQuestionCount":50},"notes":["..."]}',
            'component' => '{"summary":"...","fields":{"maxMark":30,"passThreshold":15,"duration":30,"questionCount":20,"status":"draft"},"notes":["..."]}',
            'grading_scale' => '{"summary":"...","fields":{"bands":[{"minScore":70,"maxScore":100,"grade":"A","gradePoint":5}]},"notes":["..."]}',
        ];
        $instruction = 'You are Algebra, an education setup assistant. Return ONLY JSON matching this shape: ' . $shapes[$formType] . '. Suggest values for the existing ' . $formType . ' form from this administrator brief: ' . $brief . '. Never claim the values were saved, never set an assessment active, never include student data, and never add instructions to submit automatically.';
        $result = algebraDecodeJson(algebraGeminiText($instruction, 3500));
        $summary = algebraBoundedText($result['summary'] ?? '', 1500); $fields = $result['fields'] ?? null; $notes = $result['notes'] ?? [];
        if ($summary === '' || !is_array($fields) || !is_array($notes) || !array_is_list($notes)) throw new AlgebraProviderException('Algebra returned an invalid setup suggestion.', 422);
        $output = ['formType'=>$formType,'summary'=>$summary,'fields'=>$fields,'notes'=>array_values(array_slice(array_filter(array_map(fn($item)=>algebraBoundedText($item,500),$notes)),0,8))];
        algebraFinishRequest($pdo,$requestId,'completed',$output);
        algebraAudit($pdo,$session,'algebra_setup_suggestion_generated','setup_form',$formType,['requestId'=>$requestId,'readOnly'=>true]);
        respond(['requestId'=>$requestId] + $output);
    } catch (Throwable $error) { algebraFinishFailure($pdo,$session,$requestId,'algebra_setup_suggestion','setup_form',$formType,$error); }
}
function mysqlAlgebraAuditDigest(): never {
    $session = mysqlAlgebraRequireTenantAdmin(); algebraBeginRequest($session); $empty = []; algebraStage2RateLimit($empty, $session, 'audit_digest');
    $input = body(); $from = trim((string)($input['from'] ?? '')); $to = trim((string)($input['to'] ?? ''));
    foreach ([$from,$to] as $date) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) respond(['error'=>'Choose a start and end date for the audit digest.'],422);
    if ($from > $to || (strtotime($to) - strtotime($from)) > 366 * 86400) respond(['error'=>'Choose a period of up to 366 days.'],422);
    $pdo=mysqlAppPdo(); $institutionId=mysqlCurrentInstitutionId();
    $counts=$pdo->prepare("SELECT action_type,COALESCE(outcome,'success') outcome,COUNT(*) count FROM audit_events WHERE institution_id=? AND timestamp_at>=? AND timestamp_at<? GROUP BY action_type,outcome ORDER BY count DESC,action_type ASC LIMIT 60");
    $until=(new DateTimeImmutable($to,new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d 00:00:00'); $counts->execute([$institutionId,$from.' 00:00:00',$until]);
    $summaryRows=array_map(fn($row)=>['action'=>algebraBoundedText($row['action_type'],150),'outcome'=>algebraBoundedText($row['outcome'],30),'count'=>(int)$row['count']],$counts->fetchAll());
    $request=['from'=>$from,'to'=>$to,'actionOutcomeCounts'=>$summaryRows]; $requestId=algebraCreateRequest($pdo,$session,'audit_digest',$request);
    try {
        $instruction='You are Algebra, an audit-log summariser. Analyse ONLY this anonymised action/outcome count table for one institution. Do not infer identity, disclose raw audit fields, suggest punishment, or claim causes. Return ONLY JSON: {"summary":"...","highlights":[{"title":"...","detail":"..."}],"caveats":["..."]}. PERIOD: '.$from.' to '.$to."\nCOUNTS:\n".json_encode($summaryRows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $result=algebraDecodeJson(algebraGeminiText($instruction,3500)); $summary=algebraBoundedText($result['summary']??'',2000); $highlights=$result['highlights']??[]; $caveats=$result['caveats']??[];
        if($summary===''||!is_array($highlights)||!array_is_list($highlights)||count($highlights)>8||!is_array($caveats)||!array_is_list($caveats)) throw new AlgebraProviderException('Algebra returned an invalid audit digest.',422);
        $clean=[];foreach($highlights as $item){if(!is_array($item))continue;$title=algebraBoundedText($item['title']??'',180);$detail=algebraBoundedText($item['detail']??'',900);if($title!==''&&$detail!=='')$clean[]=['title'=>$title,'detail'=>$detail];}if(!$clean)throw new AlgebraProviderException('Algebra returned no usable audit-digest highlights.',422);
        $output=['from'=>$from,'to'=>$to,'summary'=>$summary,'highlights'=>$clean,'caveats'=>array_values(array_slice(array_filter(array_map(fn($item)=>algebraBoundedText($item,500),$caveats)),0,8))];algebraFinishRequest($pdo,$requestId,'completed',$output);algebraAudit($pdo,$session,'algebra_audit_digest_generated','audit_period',$from.'_'.$to,['requestId'=>$requestId,'aggregateOnly'=>true]);respond(['requestId'=>$requestId]+$output);
    } catch(Throwable $error){algebraFinishFailure($pdo,$session,$requestId,'algebra_audit_digest','audit_period',$from.'_'.$to,$error);}
}
function mysqlAlgebraAnomalyFlags(): never {
    $session=mysqlAlgebraRequireTenantAdmin();algebraBeginRequest($session);$empty=[];algebraStage2RateLimit($empty,$session,'anomaly_review',15,50);$pdo=mysqlAppPdo();$institutionId=mysqlCurrentInstitutionId();
    /* This is intentionally question-level only. It does not score, name, or
       rank students, and produces no enforcement signal. */
    $query=$pdo->prepare("SELECT s.question_id,s.question_json,COALESCE(a.option_index,-1) option_index,COUNT(DISTINCT s.attempt_id) response_count FROM attempt_question_snapshots s INNER JOIN assessment_attempts at ON at.institution_id=s.institution_id AND at.id=s.attempt_id AND at.status IN ('submitted','auto_submitted') LEFT JOIN attempt_answers a ON a.institution_id=s.institution_id AND a.attempt_id=s.attempt_id AND a.question_id=s.question_id WHERE s.institution_id=? GROUP BY s.question_id,s.question_json,COALESCE(a.option_index,-1) ORDER BY s.question_id ASC");$query->execute([$institutionId]);$groups=[];foreach($query->fetchAll() as $row){$key=(string)$row['question_id'];$groups[$key]['question']=(array)mysqlJson($row['question_json'],[]);$groups[$key]['answers'][(int)$row['option_index']]=(int)$row['response_count'];}
    $flags=[];foreach($groups as $questionId=>$group){$question=$group['question'];$answers=$group['answers'];$total=array_sum($answers);$correct=array_map('intval',(array)($question['correctOptions']??[]));if($total<10)continue;foreach($answers as $option=>$count){if($option<0||in_array($option,$correct,true)||$count<8||($count/$total)<0.70)continue;$options=(array)($question['options']??[]);$snippet=algebraBoundedText($question['text']??'Question',180);$flags[]=['kind'=>'concentrated_incorrect_response','questionId'=>$questionId,'title'=>'Review recommended: concentrated incorrect response','detail'=>sprintf('%d of %d submitted attempts selected one incorrect option%s for “%s”. Review the wording, distractor, and answer key; this is not evidence of misconduct.', $count,$total,isset($options[$option])?' (“'.algebraBoundedText($options[$option],100).'”)':'',$snippet),'sampleSize'=>$total,'responseCount'=>$count];}}
    usort($flags,fn($a,$b)=>($b['responseCount']<=>$a['responseCount'])?:($b['sampleSize']<=>$a['sampleSize']));$flags=array_slice($flags,0,20);$requestId=algebraCreateRequest($pdo,$session,'anomaly_review',['minimumSample'=>10,'flagCount'=>count($flags)]);$output=['reviewRecommended'=>true,'minimumSample'=>10,'flags'=>$flags,'notice'=>'These are question-level review signals only. They do not identify, accuse, restrict, or take action against any student.'];algebraFinishRequest($pdo,$requestId,'completed',$output);algebraAudit($pdo,$session,'algebra_anomaly_review_generated','answer_patterns',$requestId,['requestId'=>$requestId,'readOnly'=>true,'reviewRecommended'=>true]);respond(['requestId'=>$requestId]+$output);
}
function mysqlAlgebraCommunicationDraft(): never {
    $session=mysqlAlgebraRequireTenantAdmin();algebraBeginRequest($session);$empty=[];algebraStage2RateLimit($empty,$session,'communication_draft');$input=body();$purpose=algebraTrimText($input['purpose']??'','Purpose',240);$audience=algebraTrimText($input['audience']??'','Audience',240);$points=algebraTrimText($input['keyPoints']??'','Key points',ALGEBRA_MAX_PROMPT_LENGTH);algebraRejectPersonalData($purpose,'Purpose');algebraRejectPersonalData($audience,'Audience');algebraRejectPersonalData($points,'Key points');$pdo=mysqlAppPdo();$requestId=algebraCreateRequest($pdo,$session,'communication_draft',['purpose'=>$purpose,'audience'=>$audience,'keyPoints'=>$points]);
    try{$instruction='You are Algebra, a communications drafting assistant. Create a clear student-facing newsletter draft. Return ONLY JSON: {"subject":"...","content":"...","reviewNotes":["..."]}. Purpose: '.$purpose.'. Audience: '.$audience.'. Key points: '.$points.'. This is a DRAFT ONLY: do not claim it was sent, do not invent dates or policy, and do not include recipient information.';$result=algebraDecodeJson(algebraGeminiText($instruction,3500));$subject=algebraBoundedText($result['subject']??'',180);$content=algebraBoundedText($result['content']??'',10000);$notes=$result['reviewNotes']??[];if($subject===''||$content===''||!is_array($notes)||!array_is_list($notes))throw new AlgebraProviderException('Algebra returned an invalid communication draft.',422);$output=['subject'=>$subject,'content'=>$content,'reviewNotes'=>array_values(array_slice(array_filter(array_map(fn($item)=>algebraBoundedText($item,500),$notes)),0,8)),'sendRequired'=>true];algebraFinishRequest($pdo,$requestId,'completed',$output);algebraAudit($pdo,$session,'algebra_communication_draft_generated','newsletter_draft',$requestId,['requestId'=>$requestId,'draftOnly'=>true]);respond(['requestId'=>$requestId]+$output);}catch(Throwable $error){algebraFinishFailure($pdo,$session,$requestId,'algebra_communication_draft','newsletter_draft',$requestId,$error);}
}
function mysqlAlgebraResultReport(): never {
    $session=mysqlAlgebraRequireTenantAdmin();algebraBeginRequest($session);$empty=[];algebraStage2RateLimit($empty,$session,'result_report');$input=body();$studentId=trim((string)($input['studentId']??''));$sessionId=trim((string)($input['sessionId']??''));$semesterId=trim((string)($input['semesterId']??''));if($studentId===''||$sessionId===''||$semesterId==='')respond(['error'=>'Choose a student and calculated academic period.'],422);$pdo=mysqlAppPdo();$institutionId=mysqlCurrentInstitutionId();$record=$pdo->prepare('SELECT cr.id,cr.semester_gpa,cr.cgpa,s.full_name,s.matric_number,acs.label session_label,sem.label semester_label FROM calculated_results cr INNER JOIN students s ON s.institution_id=cr.institution_id AND s.id=cr.student_id LEFT JOIN academic_sessions acs ON acs.institution_id=cr.institution_id AND acs.id=cr.session_id LEFT JOIN academic_semesters sem ON sem.institution_id=cr.institution_id AND sem.id=cr.semester_id WHERE cr.institution_id=? AND cr.student_id=? AND cr.session_id=? AND cr.semester_id=? LIMIT 1');$record->execute([$institutionId,$studentId,$sessionId,$semesterId]);$result=$record->fetch();if(!$result)respond(['error'=>'A calculated result for that student and period was not found in this institution.'],404);$items=$pdo->prepare('SELECT item_json FROM calculated_result_items WHERE institution_id=? AND calculated_result_id=? ORDER BY course_id');$items->execute([$institutionId,$result['id']]);$courses=array_map(fn($row)=>(array)mysqlJson($row['item_json'],[]),$items->fetchAll());$requestId=algebraCreateRequest($pdo,$session,'result_report',['student'=>'the student','academicPeriod'=>'the selected academic period','semesterGpa'=>(float)$result['semester_gpa'],'cgpa'=>(float)$result['cgpa'],'courses'=>array_map(static fn(array $course):array=>['course'=>'the selected course','score'=>$course['score']??null,'grade'=>$course['grade']??null,'gradePoint'=>$course['gradePoint']??null,'qualityPoints'=>$course['qualityPoints']??null],$courses)]);
    try{$tokenized=['student'=>'the student','academicPeriod'=>'the selected academic period','semesterGpa'=>(float)$result['semester_gpa'],'cgpa'=>(float)$result['cgpa'],'courses'=>array_map(static fn(array $course):array=>['course'=>'the selected course','score'=>$course['score']??null,'grade'=>$course['grade']??null,'gradePoint'=>$course['gradePoint']??null,'qualityPoints'=>$course['qualityPoints']??null],$courses)];$instruction='You are Algebra, a careful academic-report drafting assistant. Create a neutral printable result-sheet narrative from this already calculated result. Do not recalculate grades, change results, promise outcomes, mention other students, add identity data, or return matric numbers, emails, student IDs, or institution IDs. Return ONLY JSON: {"heading":"...","summary":"...","highlights":["..."],"reviewNote":"..."}. SNAPSHOT:\n'.json_encode($tokenized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$decoded=algebraDecodeJson(algebraGeminiText($instruction,3500));$joined=json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',$joined)||preg_match('/\b[A-Z]{2,8}[\/-]\d{2,}[A-Z0-9\/-]*\b/i',$joined))throw new AlgebraProviderException('Algebra returned identifying data in the result-report draft.',422);$heading=algebraBoundedText($decoded['heading']??'',180);$summary=algebraBoundedText($decoded['summary']??'',2000);$highlights=$decoded['highlights']??[];$note=algebraBoundedText($decoded['reviewNote']??'',500);if($heading===''||$summary===''||!is_array($highlights)||!array_is_list($highlights))throw new AlgebraProviderException('Algebra returned an invalid result-report draft.',422);$output=['heading'=>$heading,'summary'=>$summary,'highlights'=>array_values(array_slice(array_filter(array_map(fn($item)=>algebraBoundedText($item,500),$highlights)),0,8)),'reviewNote'=>$note,'draftOnly'=>true];algebraFinishRequest($pdo,$requestId,'completed',$output);algebraAudit($pdo,$session,'algebra_result_report_drafted','calculated_result',$result['id'],['requestId'=>$requestId,'draftOnly'=>true]);respond(['requestId'=>$requestId]+$output);}catch(Throwable $error){algebraFinishFailure($pdo,$session,$requestId,'algebra_result_report','calculated_result',(string)$result['id'],$error);}
}
/** Plain-text-only validation for human support correspondence. HTML is not
 * interpreted anywhere; the UI will additionally escape the stored text. */
function supportText(mixed $value, string $label, int $maximum, bool $singleLine = false): string {
    if (!is_string($value)) respond(['error' => $label . ' is required.'], 422);
    $text = trim($value);
    if ($singleLine && preg_match('/[\r\n]/', $text)) respond(['error' => $label . ' must be a single line.'], 422);
    if ($text === '' || textLength($text) > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) {
        respond(['error' => $label . ' must be between 1 and ' . $maximum . ' characters.'], 422);
    }
    return $text;
}
function supportThreadId(mixed $value): string {
    $id = is_string($value) ? strtolower(trim($value)) : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) respond(['error' => 'The support thread identifier is invalid.'], 422);
    return $id;
}
function mysqlSupportTenantSession(): array {
    $session = mysqlFastRequireAdmin();
    if (($session['scope'] ?? '') !== 'institution') respond(['error' => 'Resource not found.'], 404);
    $permission = mysqlAppPdo()->prepare("SELECT 1 FROM role_permissions WHERE institution_id=:institution_id AND role_id=:role_id AND permission='messages' LIMIT 1");
    $permission->execute(['institution_id'=>mysqlCurrentInstitutionId(),'role_id'=>(string)($session['roleId'] ?? '')]);
    if (!$permission->fetchColumn()) respond(['error' => 'Your role does not have permission to use support messages.'], 403);
    return $session;
}
function mysqlSupportPlatformSession(): array {
    $session = mysqlFastRequireAdmin();
    if (($session['scope'] ?? '') !== 'platform' || ($session['roleId'] ?? '') !== 'platform_super_admin') respond(['error' => 'Resource not found.'], 404);
    return $session;
}
function mysqlSupportRateLimit(array $session, string $operation, int $maximum, int $windowSeconds): void {
    if (($session['scope'] ?? '') !== 'institution') return;
    $key = hash('sha256', 'support-' . $operation . '|admin:' . (string)($session['userId'] ?? '') . '|' . clientFingerprint());
    if (!mysqlConsumeRateLimit($key, $maximum, $windowSeconds)) respond(['error' => 'Too many support-message requests. Please wait a moment and try again.'], 429);
}
function mysqlSupportTenantAudit(PDO $pdo, int $institutionId, string $actorType, string $actorId, string $action, string $targetId, array $metadata, string $correlationId): void {
    $statement = $pdo->prepare('INSERT INTO audit_events (institution_id,id,timestamp_at,actor_type,actor_id,action_type,target_type,target_id,ip_address,user_agent,outcome,correlation_id,before_after_json,metadata_json) VALUES (:institution_id,:id,UTC_TIMESTAMP(6),:actor_type,:actor_id,:action_type,\'support_thread\',:target_id,:ip_address,:user_agent,\'success\',:correlation_id,JSON_OBJECT(),:metadata_json)');
    $statement->execute(['institution_id'=>$institutionId,'id'=>id(),'actor_type'=>$actorType,'actor_id'=>$actorId,'action_type'=>$action,'target_id'=>$targetId,'ip_address'=>clientFingerprint(),'user_agent'=>auditUserAgent(),'correlation_id'=>$correlationId,'metadata_json'=>json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
}
function mysqlSupportThreadList(bool $platform): array {
    $pdo = mysqlAppPdo();
    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '' && !in_array($status, ['open','resolved'], true)) respond(['error'=>'Choose open or resolved status.'],422);
    $pageSize=max(5,min(100,(int)($_GET['pageSize']??20))); $page=max(1,(int)($_GET['page']??1));
    if (!$platform) {
        $institutionId=mysqlCurrentInstitutionId(); $where=['t.institution_id=:institution_id']; $params=['institution_id'=>$institutionId];
        if($status!==''){ $where[]='t.status=:status'; $params['status']=$status; }
        $filter=implode(' AND ',$where);
        $count=$pdo->prepare("SELECT COUNT(*) FROM support_threads t WHERE {$filter}"); $count->execute($params); $total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/$pageSize)); $page=min($page,$pages);
        $sql="SELECT t.id,t.subject,t.status,t.created_at,t.last_message_at,t.created_by_admin_id,EXISTS(SELECT 1 FROM support_messages m WHERE m.institution_id=t.institution_id AND m.thread_id=t.id AND m.sender_scope='platform_super_admin' AND (t.tenant_last_read_at IS NULL OR m.created_at>t.tenant_last_read_at)) unread FROM support_threads t WHERE {$filter} ORDER BY t.last_message_at DESC,t.id DESC LIMIT :limit OFFSET :offset";
        $statement=$pdo->prepare($sql); foreach($params as $key=>$value)$statement->bindValue(':'.$key,$value); $statement->bindValue(':limit',$pageSize,PDO::PARAM_INT); $statement->bindValue(':offset',($page-1)*$pageSize,PDO::PARAM_INT); $statement->execute();
        $items=[];foreach($statement->fetchAll() as $row)$items[]=['id'=>$row['id'],'subject'=>$row['subject'],'status'=>$row['status'],'createdAt'=>mysqlIso($row['created_at']),'lastMessageAt'=>mysqlIso($row['last_message_at']),'createdByAdminId'=>$row['created_by_admin_id'],'unread'=>mysqlBool($row['unread'])];
        $unread=$pdo->prepare("SELECT COUNT(*) FROM support_threads t WHERE t.institution_id=:institution_id AND EXISTS(SELECT 1 FROM support_messages m WHERE m.institution_id=t.institution_id AND m.thread_id=t.id AND m.sender_scope='platform_super_admin' AND (t.tenant_last_read_at IS NULL OR m.created_at>t.tenant_last_read_at))"); $unread->execute(['institution_id'=>$institutionId]);
        return ['items'=>$items,'unreadCount'=>(int)$unread->fetchColumn(),'meta'=>['page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'pages'=>$pages]];
    }
    $institutionFilter=trim((string)($_GET['institutionId']??''));
    if($institutionFilter!==''&&(!ctype_digit($institutionFilter)||(int)$institutionFilter<1))respond(['error'=>'Choose a valid institution.'],422);
    $where=['i.deleted_at IS NULL'];$params=[];if($status!==''){ $where[]='t.status=:status';$params['status']=$status; }if($institutionFilter!==''){ $where[]='t.institution_id=:support_institution_id';$params['support_institution_id']=(int)$institutionFilter; }$filter=implode(' AND ',$where);
    $count=$pdo->prepare("SELECT COUNT(*) FROM support_threads t INNER JOIN institutions i ON i.id=t.institution_id WHERE {$filter}");$count->execute($params);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);
    $sql="SELECT t.institution_id,t.id,t.subject,t.status,t.created_at,t.last_message_at,i.name,i.slug,b.display_name,EXISTS(SELECT 1 FROM support_messages m WHERE m.institution_id=t.institution_id AND m.thread_id=t.id AND m.sender_scope='institution_admin' AND (t.platform_last_read_at IS NULL OR m.created_at>t.platform_last_read_at)) unread FROM support_threads t INNER JOIN institutions i ON i.id=t.institution_id LEFT JOIN institution_branding b ON b.institution_id=i.id WHERE {$filter} ORDER BY t.last_message_at DESC,t.id DESC LIMIT :limit OFFSET :offset";
    $statement=$pdo->prepare($sql);foreach($params as $key=>$value)$statement->bindValue(':'.$key,$value);$statement->bindValue(':limit',$pageSize,PDO::PARAM_INT);$statement->bindValue(':offset',($page-1)*$pageSize,PDO::PARAM_INT);$statement->execute();$items=[];foreach($statement->fetchAll() as $row)$items[]=['institutionId'=>(int)$row['institution_id'],'institutionName'=>$row['display_name']?:$row['name'],'institutionSlug'=>$row['slug'],'id'=>$row['id'],'subject'=>$row['subject'],'status'=>$row['status'],'createdAt'=>mysqlIso($row['created_at']),'lastMessageAt'=>mysqlIso($row['last_message_at']),'unread'=>mysqlBool($row['unread'])];
    $unread=$pdo->prepare("SELECT COUNT(*) FROM support_threads t INNER JOIN institutions i ON i.id=t.institution_id WHERE i.deleted_at IS NULL AND EXISTS(SELECT 1 FROM support_messages m WHERE m.institution_id=t.institution_id AND m.thread_id=t.id AND m.sender_scope='institution_admin' AND (t.platform_last_read_at IS NULL OR m.created_at>t.platform_last_read_at))");$unread->execute();
    return ['items'=>$items,'unreadCount'=>(int)$unread->fetchColumn(),'meta'=>['page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'pages'=>$pages]];
}
function mysqlSupportThreadDetail(string $threadId, bool $platform): array {
    $pdo=mysqlAppPdo();
    if($platform){$statement=$pdo->prepare("SELECT t.*,i.name institution_name,i.slug,b.display_name FROM support_threads t INNER JOIN institutions i ON i.id=t.institution_id LEFT JOIN institution_branding b ON b.institution_id=i.id WHERE t.id=:id AND i.deleted_at IS NULL LIMIT 1");$statement->execute(['id'=>$threadId]);}
    else {$statement=$pdo->prepare('SELECT * FROM support_threads WHERE institution_id=:institution_id AND id=:id LIMIT 1');$statement->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>$threadId]);}
    $thread=$statement->fetch();if(!$thread)respond(['error'=>'Support thread not found.'],404);
    $readColumn=$platform?'platform_last_read_at':'tenant_last_read_at';$update=$pdo->prepare("UPDATE support_threads SET {$readColumn}=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id");$update->execute(['institution_id'=>$thread['institution_id'],'id'=>$thread['id']]);
    $messages=$pdo->prepare("SELECT m.id,m.sender_scope,m.sender_admin_id,m.sender_platform_admin_id,m.body,m.created_at,ta.name tenant_sender_name FROM support_messages m LEFT JOIN admin_users ta ON ta.institution_id=m.institution_id AND ta.id=m.sender_admin_id WHERE m.institution_id=:institution_id AND m.thread_id=:thread_id ORDER BY m.created_at ASC,m.id ASC");$messages->execute(['institution_id'=>$thread['institution_id'],'thread_id'=>$thread['id']]);$items=[];foreach($messages->fetchAll() as $message){$platformSender=$message['sender_scope']==='platform_super_admin';$items[]=['id'=>$message['id'],'senderScope'=>$message['sender_scope'],'senderLabel'=>$platformSender?'Platform Super Admin':($platform?$message['tenant_sender_name']?:'Tenant Admin':'Tenant Admin'),'body'=>$message['body'],'createdAt'=>mysqlIso($message['created_at'])];}
    return ['thread'=>['id'=>$thread['id'],'institutionId'=>(int)$thread['institution_id'],'institutionName'=>$platform?($thread['display_name']?:$thread['institution_name']):null,'institutionSlug'=>$platform?$thread['slug']:null,'subject'=>$thread['subject'],'status'=>$thread['status'],'createdAt'=>mysqlIso($thread['created_at']),'lastMessageAt'=>mysqlIso($thread['last_message_at']),'resolvedAt'=>mysqlIso($thread['resolved_at']),'canReply'=>$thread['status']==='open'],'messages'=>$items];
}
function mysqlSupportTenantThreads(string $method): never {
    $session=mysqlSupportTenantSession();
    if($method==='GET')respond(mysqlSupportThreadList(false));
    mysqlSupportRateLimit($session,'create',10,3600);$input=body();$subject=supportText($input['subject']??null,'Subject',180,true);$message=supportText($input['message']??null,'Message',5000);$institutionId=mysqlCurrentInstitutionId();$threadId=bin2hex(random_bytes(16));$messageId=bin2hex(random_bytes(16));$pdo=mysqlAppPdo();$correlation='support-'.bin2hex(random_bytes(12));$pdo->beginTransaction();try{$pdo->prepare("INSERT INTO support_threads (institution_id,id,subject,status,created_by_admin_id,created_at,updated_at,last_message_at,tenant_last_read_at) VALUES (:institution_id,:id,:subject,'open',:created_by,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")->execute(['institution_id'=>$institutionId,'id'=>$threadId,'subject'=>$subject,'created_by'=>(string)$session['userId']]);$pdo->prepare("INSERT INTO support_messages (institution_id,id,thread_id,sender_scope,sender_admin_id,body,created_at) VALUES (:institution_id,:id,:thread_id,'institution_admin',:sender_admin_id,:body,UTC_TIMESTAMP(6))")->execute(['institution_id'=>$institutionId,'id'=>$messageId,'thread_id'=>$threadId,'sender_admin_id'=>(string)$session['userId'],'body'=>$message]);$metadata=['messageId'=>$messageId,'messageLength'=>textLength($message),'subjectLength'=>textLength($subject)];mysqlSupportTenantAudit($pdo,$institutionId,'admin',(string)$session['email'],'support_thread_created',$threadId,$metadata,$correlation);mysqlInsertPlatformAudit($pdo,(string)$session['email'],'tenant_support_thread_created',$institutionId,'support_thread',$threadId,'success',$correlation,$metadata);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}respond(['threadId'=>$threadId],201);
}
function mysqlSupportTenantThread(string $method): never {
    $session=mysqlSupportTenantSession();if($method==='GET')respond(mysqlSupportThreadDetail(supportThreadId($_GET['id']??null),false));$input=body();if(($input['operation']??'reply')==='set-status')respond(['error'=>'Only the platform Super Admin can resolve or reopen a support thread.'],403);mysqlSupportRateLimit($session,'reply',60,3600);$threadId=supportThreadId($input['threadId']??null);$message=supportText($input['message']??null,'Message',5000);$institutionId=mysqlCurrentInstitutionId();$check=mysqlAppPdo()->prepare('SELECT status FROM support_threads WHERE institution_id=:institution_id AND id=:id LIMIT 1');$check->execute(['institution_id'=>$institutionId,'id'=>$threadId]);$thread=$check->fetch();if(!$thread)respond(['error'=>'Support thread not found.'],404);if($thread['status']!=='open')respond(['error'=>'This support thread is resolved and can be reopened only by the platform Super Admin.'],409);$messageId=bin2hex(random_bytes(16));$pdo=mysqlAppPdo();$correlation='support-'.bin2hex(random_bytes(12));$pdo->beginTransaction();try{$insert=$pdo->prepare("INSERT INTO support_messages (institution_id,id,thread_id,sender_scope,sender_admin_id,body,created_at) VALUES (:institution_id,:id,:thread_id,'institution_admin',:sender_admin_id,:body,UTC_TIMESTAMP(6))");$insert->execute(['institution_id'=>$institutionId,'id'=>$messageId,'thread_id'=>$threadId,'sender_admin_id'=>(string)$session['userId'],'body'=>$message]);$pdo->prepare("UPDATE support_threads SET last_message_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id AND status='open'")->execute(['institution_id'=>$institutionId,'id'=>$threadId]);$metadata=['messageId'=>$messageId,'messageLength'=>textLength($message)];mysqlSupportTenantAudit($pdo,$institutionId,'admin',(string)$session['email'],'support_thread_replied',$threadId,$metadata,$correlation);mysqlInsertPlatformAudit($pdo,(string)$session['email'],'tenant_support_thread_replied',$institutionId,'support_thread',$threadId,'success',$correlation,$metadata);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}respond(['messageId'=>$messageId],201);
}
function mysqlSupportPlatformThreads(): never { mysqlSupportPlatformSession();respond(mysqlSupportThreadList(true)); }
function mysqlSupportPlatformThread(string $method): never {
    $session=mysqlSupportPlatformSession();if($method==='GET')respond(mysqlSupportThreadDetail(supportThreadId($_GET['id']??null),true));$input=body();$threadId=supportThreadId($input['threadId']??null);$operation=(string)($input['operation']??'reply');$pdo=mysqlAppPdo();$lookup=$pdo->prepare('SELECT t.institution_id,t.status FROM support_threads t INNER JOIN institutions i ON i.id=t.institution_id WHERE t.id=:id AND i.deleted_at IS NULL LIMIT 1');$lookup->execute(['id'=>$threadId]);$thread=$lookup->fetch();if(!$thread)respond(['error'=>'Support thread not found.'],404);$institutionId=(int)$thread['institution_id'];$correlation='support-'.bin2hex(random_bytes(12));
    if($operation==='reply'){$message=supportText($input['message']??null,'Message',5000);if($thread['status']!=='open')respond(['error'=>'Resolve status must be reopened before replying.'],409);$messageId=bin2hex(random_bytes(16));$pdo->beginTransaction();try{$pdo->prepare("INSERT INTO support_messages (institution_id,id,thread_id,sender_scope,sender_platform_admin_id,body,created_at) VALUES (:institution_id,:id,:thread_id,'platform_super_admin',:sender_platform_admin_id,:body,UTC_TIMESTAMP(6))")->execute(['institution_id'=>$institutionId,'id'=>$messageId,'thread_id'=>$threadId,'sender_platform_admin_id'=>(string)$session['userId'],'body'=>$message]);$pdo->prepare("UPDATE support_threads SET last_message_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id AND status='open'")->execute(['institution_id'=>$institutionId,'id'=>$threadId]);$metadata=['messageId'=>$messageId,'messageLength'=>textLength($message)];mysqlSupportTenantAudit($pdo,$institutionId,'platform_admin',(string)$session['email'],'platform_support_reply_received',$threadId,$metadata,$correlation);mysqlInsertPlatformAudit($pdo,(string)$session['email'],'platform_support_thread_replied',$institutionId,'support_thread',$threadId,'success',$correlation,$metadata);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}respond(['messageId'=>$messageId],201);}
    if($operation==='set-status'){$status=(string)($input['status']??'');if(!in_array($status,['open','resolved'],true))respond(['error'=>'Choose open or resolved status.'],422);if($status===$thread['status'])respond(['status'=>$status]);$pdo->beginTransaction();try{$statement=$pdo->prepare("UPDATE support_threads SET status=:status,updated_at=UTC_TIMESTAMP(6),resolved_at=".($status==='resolved'?'UTC_TIMESTAMP(6)':'NULL').",resolved_by_platform_admin_id=".($status==='resolved'?':platform_admin_id':'NULL')." WHERE institution_id=:institution_id AND id=:id");$params=['status'=>$status,'institution_id'=>$institutionId,'id'=>$threadId];if($status==='resolved')$params['platform_admin_id']=(string)$session['userId'];$statement->execute($params);$action=$status==='resolved'?'support_thread_resolved':'support_thread_reopened';$metadata=['beforeStatus'=>$thread['status'],'afterStatus'=>$status];mysqlSupportTenantAudit($pdo,$institutionId,'platform_admin',(string)$session['email'],$action,$threadId,$metadata,$correlation);mysqlInsertPlatformAudit($pdo,(string)$session['email'],'platform_'.$action,$institutionId,'support_thread',$threadId,'success',$correlation,$metadata);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}respond(['status'=>$status]);}
    respond(['error'=>'Unknown support-thread action.'],422);
}
function mysqlFastRoute(string $action, string $method): bool {
    // These requests are safe to process without the compatibility loader.
    if ($action === 'algebra-budget-probe' && $method === 'POST' && PHP_SAPI === 'cli' && getenv('CBT_ALGEBRA_MOCK_BUDGET') === '1') { algebraBudgetReserve((int)getenv('CBT_ALGEBRA_MOCK_BUDGET_ID')); respond(['reserved'=>true]); }
    if ($action === 'health' && $method === 'GET') { $empty = []; enforceGeneralApiRateLimit($empty); respond(['ok' => true]); }
    if ($action === 'platform-branding' && $method === 'GET') { $empty = []; enforceGeneralApiRateLimit($empty); respond(['branding' => platformBranding()]); }
    if ($action === 'branding' && $method === 'GET') { $empty = []; enforceGeneralApiRateLimit($empty); respond(['branding' => institutionBranding()]); }
    if ($action === 'auth-csrf' && $method === 'GET') { $empty = []; enforceGeneralApiRateLimit($empty); $token = secretToken(); setSessionCookie('CBT_CSRF', $token, time() + ADMIN_TOKEN_SECONDS); respond(['csrfToken' => $token]); }
    if ($action === 'student-login' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlFastStudentLogin(); }
    if ($action === 'session-start' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlFastSessionStart(); }
    if ($action === 'session-resume' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlFastSessionResume(); }
    if ($action === 'session-answer' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlFastSessionAnswer(); }
    if ($action === 'session-submit' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlFastSessionSubmit(); }
    if ($action === 'dashboard' && $method === 'GET') { $empty = []; enforceGeneralApiRateLimit($empty); mysqlFastDashboard(); }
    if ($action === 'dashboard-portal-mode' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlFastDashboardPortalMode(); }
    if ($action === 'results' && $method === 'GET') { $empty = []; enforceGeneralApiRateLimit($empty); mysqlFastResults(); }
    if ($action === 'audit-events' && $method === 'GET') { $empty = []; enforceGeneralApiRateLimit($empty); mysqlFastAuditEvents(); }
    if ($action === 'algebra-question-draft' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlAlgebraQuestionDraft(); }
    if ($action === 'algebra-question-import' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlAlgebraQuestionImport(); }
    if ($action === 'algebra-performance-insight' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlAlgebraPerformanceInsight(); }
    if ($action === 'algebra-setup-suggestion' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlAlgebraSetupSuggestion(); }
    if ($action === 'algebra-audit-digest' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlAlgebraAuditDigest(); }
    if ($action === 'algebra-anomaly-flags' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlAlgebraAnomalyFlags(); }
    if ($action === 'algebra-communication-draft' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlAlgebraCommunicationDraft(); }
    if ($action === 'algebra-result-report' && $method === 'POST') { $empty = []; enforceGeneralApiRateLimit($empty); requireCsrf(); mysqlAlgebraResultReport(); }
    if ($action === 'support-threads' && in_array($method, ['GET','POST'], true)) { $empty = []; enforceGeneralApiRateLimit($empty); if ($method === 'POST') requireCsrf(); mysqlSupportTenantThreads($method); }
    if ($action === 'support-thread' && in_array($method, ['GET','POST'], true)) { $empty = []; enforceGeneralApiRateLimit($empty); if ($method === 'POST') requireCsrf(); mysqlSupportTenantThread($method); }
    if ($action === 'platform-support-threads' && $method === 'GET') { $empty = []; enforceGeneralApiRateLimit($empty); mysqlSupportPlatformThreads(); }
    if ($action === 'platform-support-thread' && in_array($method, ['GET','POST'], true)) { $empty = []; enforceGeneralApiRateLimit($empty); if ($method === 'POST') requireCsrf(); mysqlSupportPlatformThread($method); }
    return false;
}
if (mysqlStorageEnabled() && mysqlFastRoute($action, $method)) exit;
$data = loadData();
runScheduledBackup($data);
runScheduledAuditArchival($data);
$beforeExpiry = json_encode($data); expireSessions($data); if ($beforeExpiry !== json_encode($data)) saveData($data);
enforceGeneralApiRateLimit($data);

if ($action === 'health') respond(['ok' => true]);
if ($action === 'platform-branding' && $method === 'GET') respond(['branding' => platformBranding()]);
// Public, read-only tenant identity used before login by the student and admin
// shells. The institution comes solely from the request path, never a client
// supplied ID, so this cannot be used to probe another tenant's branding.
if ($action === 'branding' && $method === 'GET') respond(['branding' => institutionBranding()]);
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
        institutionBrandName() . ' password reset code',
        "Hello " . $account['name'] . ",\n\nYour " . institutionBrandName() . " administrator password reset code is: " . $code . "\n\nThis six-digit code expires in 2 minutes. If you did not request a password reset, contact your Superadmin immediately.",
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
if ($action === 'emergency-recovery' && $method === 'POST') {
    $input = body(); $rateLimitKey = enforceRateLimit($data, 'emergency-recovery', 5, 900);
    $code = normalizeEmergencyRecoveryCode((string)($input['code'] ?? '')); $nextPassword = (string)($input['newPassword'] ?? '');
    // CACSA-prefixed codes remain accepted because existing code hashes are
    // historical recovery material. Newly generated codes use BEREVION.
    if (!preg_match('/^(?:BEREVION|CACSA)-(?:[A-Z2-9]{5}-){3}[A-Z2-9]{5}$/', $code) || strlen($nextPassword) < 12) respond(['error' => 'Enter a valid emergency code and a new password of at least 12 characters.'], 422);
    $codes = is_array($data['emergencyRecoveryCodes']['codes'] ?? null) ? $data['emergencyRecoveryCodes']['codes'] : [];
    $matched = null;
    foreach ($codes as $index => $record) if (is_array($record) && isset($record['hash']) && password_verify($code, (string)$record['hash'])) { $matched = $index; break; }
    if ($matched === null) {
        auditEvent($data, 'system', 'emergency_recovery', 'admin_emergency_recovery_failed', 'institution_admin', 'admin', ['outcome' => 'failure']);
        saveData($data); respond(['error' => 'That emergency code is invalid or has already been used.'], 401);
    }
    $account = institutionRecoveryAdminAccount($data);
    if (!$account) respond(['error' => 'No active institution Admin is available for emergency recovery. Contact the platform Super Admin.'], 409);
    $account['passwordHash'] = passwordHash($nextPassword); unset($account['twoFactor'], $account['twoFactorPending']);
    persistAdminAccount($data, $account);
    // A single successful emergency recovery invalidates the full set and every prior admin session.
    $data['emergencyRecoveryCodes'] = []; $data['admin2faChallenges'] = []; $data['adminSessions'] = [];
    clearRateLimit($data, $rateLimitKey);
    auditEvent($data, 'system', 'emergency_recovery', 'admin_emergency_recovery_used', 'institution_admin', (string)$account['id'], ['outcome' => 'success', 'codePosition' => $matched + 1, 'twoFactorCleared' => true]);
    saveData($data); clearSessionCookie('CBT_ADMIN_SESSION'); clearSessionCookie('CBT_ADMIN_2FA_CHALLENGE'); respond(['ok' => true]);
}
if ($action === 'emergency-codes' && $method === 'GET') {
    auth(true); respond(emergencyRecoverySummary($data));
}
if ($action === 'emergency-codes' && $method === 'POST') {
    auth(true); $input = body();
    if (empty($input['confirm'])) respond(['error' => 'Confirm that you will store the emergency codes securely before generating them.'], 422);
    $codes = [];
    for ($index = 0; $index < 8; $index++) { $code = emergencyRecoveryCode(); $codes[] = $code; }
    $data['emergencyRecoveryCodes'] = ['generatedAt' => date('c'), 'codes' => array_map(fn($code) => ['hash' => passwordHash($code)], $codes)];
    auditEvent($data, 'admin', adminActorId(), 'admin_emergency_codes_generated', 'institution_admin', (string)(institutionRecoveryAdminAccount($data)['id'] ?? 'admin'), ['count' => count($codes)]); saveData($data);
    respond(['codes' => $codes, 'summary' => emergencyRecoverySummary($data)]);
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
    $isPlatformUser = ($user['scope'] ?? '') === 'platform';
    $role = $isPlatformUser ? ['id' => 'platform_super_admin', 'name' => 'Super Admin', 'permissions' => ADMIN_PERMISSIONS] : (findBy($data['roles'], 'id', $user['roleId']) ?? findBy(DEFAULT_ROLES, 'id', 'admin'));
    if (twoFactorEnabled($user)) {
        $challengeToken = secretToken(); $challengeExpiresAt = date('c', time() + 600);
        if ($isPlatformUser) {
            mysqlCreatePlatformTwoFactorChallenge($user, tokenHash($challengeToken), $rateLimitKey, $challengeExpiresAt);
            mysqlPlatformAudit((string)$user['email'], 'platform_admin_two_factor_challenge_issued', null, 'platform_admin', (string)$user['id'], 'success', 'platform-login-' . id());
            setSessionCookie('CBT_ADMIN_2FA_CHALLENGE', $challengeToken, strtotime($challengeExpiresAt));
            respond(['twoFactorRequired' => true]);
        }
        $data['admin2faChallenges'] = array_values(array_filter($data['admin2faChallenges'], fn($item) => ($item['userId'] ?? '') !== $user['id']));
        $data['admin2faChallenges'][] = ['tokenHash' => tokenHash($challengeToken), 'passwordRateLimitKey' => $rateLimitKey, 'userId' => $user['id'], 'email' => $user['email'], 'roleId' => $role['id'], 'expiresAt' => $challengeExpiresAt, 'attempts' => 0];
        auditEvent($data, 'system', $user['email'], 'admin_two_factor_challenge_issued', 'administrator_login', $user['id'], ['outcome' => 'success']);
        saveData($data); setSessionCookie('CBT_ADMIN_2FA_CHALLENGE', $challengeToken, strtotime($challengeExpiresAt));
        respond(['twoFactorRequired' => true]);
    }
    $token = secretToken(); $expiresAt = ADMIN_SESSION_EXPIRES_AT; $correlationId = 'admin-' . id();
    if ($isPlatformUser) {
        clearRateLimit($data, $rateLimitKey);
        mysqlCreatePlatformSession($user, tokenHash($token), $correlationId, date('c'), $expiresAt);
        mysqlPlatformAudit((string)$user['email'], 'platform_admin_login', null, 'platform_admin_session', tokenHash($token), 'success', $correlationId, ['twoFactor' => false]);
        saveData($data); setSessionCookie('CBT_ADMIN_SESSION', $token, strtotime($expiresAt));
        respond(['expiresAt' => $expiresAt, 'user' => ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'roleId' => $role['id'], 'role' => $role['name'], 'permissions' => $role['permissions'], 'mustChangePassword' => !empty($user['mustChangePassword']), 'scope' => 'platform']]);
    }
    clearRateLimit($data, $rateLimitKey);
    $data['adminUserActivity'][$user['id']] = ['lastLoginAt' => date('c')];
    $data['adminSessions'][] = ['tokenHash' => tokenHash($token), 'correlationId' => $correlationId, 'expiresAt' => $expiresAt, 'createdAt' => date('c'), 'lastSeenAt' => date('c'), 'userId' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'roleId' => $role['id'], 'permissions' => $role['permissions']];
    auditEvent($data, 'admin', $user['email'], 'admin_login', 'admin_session', tokenHash($token), ['email' => $user['email'], 'role' => $role['name'], 'twoFactor' => false, 'correlationId' => $correlationId]);
    saveData($data); setSessionCookie('CBT_ADMIN_SESSION', $token, strtotime($expiresAt));
    respond(['expiresAt' => $expiresAt, 'user' => ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'roleId' => $role['id'], 'role' => $role['name'], 'permissions' => $role['permissions'], 'mustChangePassword' => !empty($user['mustChangePassword'])]]);
}
if ($action === 'admin-login-2fa' && $method === 'POST') {
    $input = body(); $rateLimitKey = enforceRateLimit($data, 'admin-login-2fa', 10, 900); $challengeToken = cookieValue('CBT_ADMIN_2FA_CHALLENGE');
    $platformChallenge = mysqlStorageEnabled() && $challengeToken !== '' ? mysqlPlatformTwoFactorChallenge(tokenHash($challengeToken)) : null;
    if ($platformChallenge) {
        if (strtotime((string)$platformChallenge['expiresAt']) <= time()) {
            mysqlDeletePlatformTwoFactorChallenge((string)$platformChallenge['tokenHash']); clearSessionCookie('CBT_ADMIN_2FA_CHALLENGE');
            respond(['error' => 'Your two-factor sign-in request expired. Sign in with your password again.'], 401);
        }
        $user = mysqlPlatformAdminAccount();
        $secret = $user && twoFactorEnabled($user) ? decryptTwoFactorSecret((string)$user['twoFactor']['secretEncrypted']) : null;
        $code = trim((string)($input['code'] ?? ''));
        if (!$user || empty($user['active']) || $secret === null || !verifyTwoFactorCode($secret, $code)) {
            $platformChallenge['attempts'] = (int)$platformChallenge['attempts'] + 1;
            if ($platformChallenge['attempts'] >= 5) { mysqlDeletePlatformTwoFactorChallenge((string)$platformChallenge['tokenHash']); clearSessionCookie('CBT_ADMIN_2FA_CHALLENGE'); }
            else mysqlUpdatePlatformTwoFactorChallenge($platformChallenge);
            mysqlPlatformAudit((string)($platformChallenge['email'] ?? 'unknown'), 'platform_admin_two_factor_failed', null, 'platform_admin', (string)($platformChallenge['userId'] ?? 'unknown'), 'failure', 'platform-login-' . id());
            saveData($data); respond(['error' => 'The authentication code is invalid.'], 401);
        }
        $token = secretToken(); $expiresAt = ADMIN_SESSION_EXPIRES_AT; $correlationId = 'admin-' . id();
        clearRateLimit($data, $rateLimitKey); if (!empty($platformChallenge['passwordRateLimitKey'])) clearRateLimit($data, (string)$platformChallenge['passwordRateLimitKey']);
        mysqlDeletePlatformTwoFactorChallenge((string)$platformChallenge['tokenHash']);
        mysqlCreatePlatformSession($user, tokenHash($token), $correlationId, date('c'), $expiresAt);
        mysqlPlatformAudit((string)$user['email'], 'platform_admin_login', null, 'platform_admin_session', tokenHash($token), 'success', $correlationId, ['twoFactor' => true]);
        saveData($data); clearSessionCookie('CBT_ADMIN_2FA_CHALLENGE'); setSessionCookie('CBT_ADMIN_SESSION', $token, strtotime($expiresAt));
        respond(['expiresAt' => $expiresAt, 'user' => ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'roleId' => 'platform_super_admin', 'role' => 'Super Admin', 'permissions' => ADMIN_PERMISSIONS, 'mustChangePassword' => !empty($user['mustChangePassword']), 'scope' => 'platform']]);
    }
    $challenge = $challengeToken !== '' ? findBy($data['admin2faChallenges'], 'tokenHash', tokenHash($challengeToken)) : null;
    if (!$challenge || strtotime((string)($challenge['expiresAt'] ?? '')) <= time()) {
        clearSessionCookie('CBT_ADMIN_2FA_CHALLENGE');
        $data['admin2faChallenges'] = array_values(array_filter($data['admin2faChallenges'], fn($item) => ($item['tokenHash'] ?? '') !== tokenHash($challengeToken)));
        saveData($data); respond(['error' => 'Your two-factor sign-in request expired. Sign in with your password again.'], 401);
    }
    $user = ($challenge['userId'] ?? '') === 'bootstrap-superadmin' ? bootstrapAdminAccount($data) : findBy($data['adminUsers'], 'id', (string)$challenge['userId']);
    $secret = $user && twoFactorEnabled($user) ? decryptTwoFactorSecret((string)$user['twoFactor']['secretEncrypted']) : null;
    $code = trim((string)($input['code'] ?? ''));
    if (!$user || empty($user['active']) || $secret === null || !verifyTwoFactorCode($secret, $code)) {
        $challenge['attempts'] = (int)($challenge['attempts'] ?? 0) + 1;
        if ($challenge['attempts'] >= 5) $data['admin2faChallenges'] = array_values(array_filter($data['admin2faChallenges'], fn($item) => ($item['tokenHash'] ?? '') !== $challenge['tokenHash']));
        else replaceBy($data['admin2faChallenges'], 'tokenHash', $challenge['tokenHash'], $challenge);
        auditEvent($data, 'system', (string)($challenge['email'] ?? 'unknown'), 'admin_two_factor_failed', 'administrator_login', (string)($challenge['userId'] ?? 'unknown'), ['outcome' => 'failure']);
        saveData($data); if (($challenge['attempts'] ?? 0) >= 5) clearSessionCookie('CBT_ADMIN_2FA_CHALLENGE');
        respond(['error' => 'The authentication code is invalid.'], 401);
    }
    $role = findBy($data['roles'], 'id', $user['roleId']) ?? findBy(DEFAULT_ROLES, 'id', 'superadmin');
    $token = secretToken(); $expiresAt = ADMIN_SESSION_EXPIRES_AT; $correlationId = 'admin-' . id();
    clearRateLimit($data, $rateLimitKey); if (!empty($challenge['passwordRateLimitKey'])) clearRateLimit($data, (string)$challenge['passwordRateLimitKey']);
    $data['admin2faChallenges'] = array_values(array_filter($data['admin2faChallenges'], fn($item) => ($item['tokenHash'] ?? '') !== $challenge['tokenHash']));
    $data['adminUserActivity'][$user['id']] = ['lastLoginAt' => date('c')];
    $data['adminSessions'][] = ['tokenHash' => tokenHash($token), 'correlationId' => $correlationId, 'expiresAt' => $expiresAt, 'createdAt' => date('c'), 'lastSeenAt' => date('c'), 'userId' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'roleId' => $role['id'], 'permissions' => $role['permissions']];
    auditEvent($data, 'admin', $user['email'], 'admin_login', 'admin_session', tokenHash($token), ['email' => $user['email'], 'role' => $role['name'], 'twoFactor' => true, 'correlationId' => $correlationId]);
    saveData($data); clearSessionCookie('CBT_ADMIN_2FA_CHALLENGE'); setSessionCookie('CBT_ADMIN_SESSION', $token, strtotime($expiresAt));
    respond(['expiresAt' => $expiresAt, 'user' => ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'roleId' => $role['id'], 'role' => $role['name'], 'permissions' => $role['permissions'], 'mustChangePassword' => !empty($user['mustChangePassword'])]]);
}
if ($action === 'admin-logout' && $method === 'POST') {
    $record = auth(true); $tokenHash = (string)$record['tokenHash'];
    if (($record['scope'] ?? '') === 'platform') {
        mysqlDeletePlatformSession($tokenHash);
        mysqlPlatformAudit((string)$record['email'], 'platform_admin_logout', null, 'platform_admin_session', $tokenHash, 'success', (string)($record['correlationId'] ?? ''));
        clearSessionCookie('CBT_ADMIN_SESSION'); respond(['ok' => true]);
    }
    $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($item) => $item['tokenHash'] !== $tokenHash));
    auditEvent($data, 'admin', (string)($record['email'] ?? (getenv('CBT_ADMIN_EMAIL') ?: DEV_ADMIN_EMAIL)), 'admin_logout', 'admin_session', $tokenHash);
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
    $beforeAccount = $account; $input = body(); $name = requireText($input['name'] ?? null, 'Full name'); $phone = trim((string)($input['phoneNumber'] ?? ''));
    if ($phone !== '') { $digits = preg_replace('/\D+/', '', $phone); if (!preg_match('/^[0-9+()\-\s.]+$/', $phone) || strlen((string)$digits) < 7 || strlen((string)$digits) > 16) respond(['error' => 'Enter a valid phone number.'], 422); }
    $email = strtolower(requireText($input['email'] ?? null, 'Email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 422);
    $emailChanged = strcasecmp($email, (string)$account['email']) !== 0;
    if (($account['scope'] ?? '') === 'platform' && $emailChanged) respond(['error' => 'The platform Super Admin email is managed through the platform account and cannot be changed from an institution workspace.'], 409);
    if ($emailChanged && !empty($account['emailManagedByEnvironment'])) respond(['error' => 'This Superadmin email is managed in the server environment and cannot be changed here.'], 409);
    foreach ($data['adminUsers'] as $user) if (($user['id'] ?? '') !== $account['id'] && strcasecmp((string)($user['email'] ?? ''), $email) === 0) respond(['error' => 'Another administrator already uses this email.'], 409);
    $bootstrap = bootstrapAdminAccount($data);
    if ($account['id'] !== 'bootstrap-superadmin' && strcasecmp((string)$bootstrap['email'], $email) === 0) respond(['error' => 'That email is reserved for the Superadmin account.'], 409);
    $account['name'] = $name; $account['phoneNumber'] = $phone;
    if ($emailChanged) {
        if (!newsletterSmtpConfig()) respond(['error' => 'Email verification is unavailable until Gmail SMTP is configured.'], 503);
        $code = (string)random_int(100000, 999999);
        $delivery = sendNewsletterMessage($email, 'Confirm your ' . institutionPortalTitle() . ' administrator email', "Hello " . $name . ",\n\nYour email-change verification code is: " . $code . "\n\nThis code expires in 15 minutes. If you did not request this change, ignore this message.", false);
        if (!$delivery['ok']) respond(['error' => 'The verification email was not accepted by the mail server. Your email was not changed.'], 503);
        $data['adminEmailVerifications'] = array_values(array_filter($data['adminEmailVerifications'], fn($item) => ($item['userId'] ?? '') !== $account['id']));
        $data['adminEmailVerifications'][] = ['userId' => $account['id'], 'email' => $email, 'codeHash' => password_hash($code, PASSWORD_DEFAULT), 'expiresAt' => date('c', time() + 900), 'attempts' => 0];
    }
    persistAdminAccount($data, $account);
    foreach ($data['adminSessions'] as &$item) if (($item['userId'] ?? '') === $account['id']) $item['name'] = $account['name']; unset($item);
    auditEvent($data, 'admin', adminActorId(), 'administrator_profile_updated', 'administrator', (string)$account['id'], ['emailChangeRequested' => $emailChanged, 'beforeAfter' => auditBeforeAfter($beforeAccount, $account, ['name', 'phoneNumber', 'email'])]);
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
        persistAdminAccount($data, $account);
        foreach ($data['adminSessions'] as &$item) if (($item['userId'] ?? '') === $account['id']) $item['email'] = $account['email']; unset($item);
        $data['adminEmailVerifications'] = array_values(array_filter($data['adminEmailVerifications'], fn($item) => ($item['userId'] ?? '') !== $account['id']));
        auditEvent($data, 'admin', (string)$oldEmail, 'administrator_email_verified', 'administrator', (string)$account['id'], ['oldEmail' => $oldEmail, 'newEmail' => $account['email']]); saveData($data); respond(['account' => publicAccount($account, $data)]);
    }
    if ($operation === 'two-factor-begin') {
        $currentPassword = (string)($input['currentPassword'] ?? '');
        if (!password_verify($currentPassword, (string)$account['passwordHash'])) respond(['error' => 'Your current password is incorrect.'], 422);
        $secret = base32Encode(random_bytes(20));
        $account['twoFactorPending'] = ['secretEncrypted' => encryptTwoFactorSecret($secret), 'expiresAt' => date('c', time() + 600)];
        persistAdminAccount($data, $account);
        auditEvent($data, 'admin', adminActorId(), 'administrator_two_factor_setup_started', 'administrator', (string)$account['id']); saveData($data);
        $issuer = institutionPortalTitle(); $label = rawurlencode($issuer . ':' . $account['email']);
        respond(['setup' => ['secret' => $secret, 'issuer' => $issuer, 'account' => $account['email'], 'otpauthUri' => 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30']]);
    }
    if ($operation === 'two-factor-confirm') {
        $pending = is_array($account['twoFactorPending'] ?? null) ? $account['twoFactorPending'] : null;
        $secret = $pending && strtotime((string)($pending['expiresAt'] ?? '')) > time() ? decryptTwoFactorSecret((string)($pending['secretEncrypted'] ?? '')) : null;
        $code = trim((string)($input['code'] ?? ''));
        if ($secret === null || !verifyTwoFactorCode($secret, $code)) respond(['error' => 'Enter the current six-digit code from your authenticator app.'], 422);
        $account['twoFactor'] = ['enabled' => true, 'secretEncrypted' => encryptTwoFactorSecret($secret), 'enabledAt' => date('c')]; unset($account['twoFactorPending']);
        persistAdminAccount($data, $account);
        auditEvent($data, 'admin', adminActorId(), 'administrator_two_factor_enabled', 'administrator', (string)$account['id']); saveData($data); respond(['account' => publicAccount($account, $data)]);
    }
    if ($operation === 'two-factor-disable') {
        $currentPassword = (string)($input['currentPassword'] ?? ''); $code = trim((string)($input['code'] ?? ''));
        $secret = twoFactorEnabled($account) ? decryptTwoFactorSecret((string)$account['twoFactor']['secretEncrypted']) : null;
        if (!password_verify($currentPassword, (string)$account['passwordHash'])) respond(['error' => 'Your current password is incorrect.'], 422);
        if ($secret === null || !verifyTwoFactorCode($secret, $code)) respond(['error' => 'Enter the current six-digit code from your authenticator app.'], 422);
        unset($account['twoFactor'], $account['twoFactorPending']); persistAdminAccount($data, $account);
        $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($item) => ($item['userId'] ?? '') !== $account['id'] || ($item['tokenHash'] ?? '') === tokenHash($token)));
        auditEvent($data, 'admin', adminActorId(), 'administrator_two_factor_disabled', 'administrator', (string)$account['id']); saveData($data); respond(['account' => publicAccount($account, $data)]);
    }
    if ($operation === 'change-password') {
        if (!empty($account['passwordManagedByEnvironment'])) respond(['error' => 'This password is managed in the server environment and cannot be changed here.'], 409);
        $current = (string)($input['currentPassword'] ?? ''); $next = (string)($input['newPassword'] ?? '');
        if (!password_verify($current, (string)$account['passwordHash'])) respond(['error' => 'Your current password is incorrect.'], 422);
        if (strlen($next) < 8) respond(['error' => 'Use a new password with at least 8 characters.'], 422);
        $account['passwordHash'] = passwordHash($next); $account['mustChangePassword'] = false;
        persistAdminAccount($data, $account);
        $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($item) => ($item['userId'] ?? '') !== $account['id'] || ($item['tokenHash'] ?? '') === tokenHash($token)));
        auditEvent($data, 'admin', adminActorId(), 'administrator_password_changed', 'administrator', (string)$account['id']); saveData($data); respond(['ok' => true]);
    }
    if ($operation === 'signout-others') {
        $before = count($data['adminSessions']); $data['adminSessions'] = array_values(array_filter($data['adminSessions'], fn($item) => ($item['userId'] ?? '') !== $account['id'] || ($item['tokenHash'] ?? '') === tokenHash($token)));
        auditEvent($data, 'admin', adminActorId(), 'administrator_other_sessions_revoked', 'administrator', (string)$account['id'], ['revoked' => $before - count($data['adminSessions'])]); saveData($data); respond(['ok' => true]);
    }
    respond(['error' => 'Unknown account action.'], 422);
}
if ($action === 'platform-institutions' && $method === 'GET') {
    requirePlatformSuperAdmin();
    respond(['items' => mysqlPlatformInstitutions()]);
}
if ($action === 'platform-institutions' && $method === 'POST') {
    $session = requirePlatformSuperAdmin();
    $operation = (string)($_POST['operation'] ?? 'create');
    if ($operation === 'set-status') {
        $institutionId = filter_var($_POST['institutionId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $activeInput = $_POST['active'] ?? null;
        if (!$institutionId || !in_array((string)$activeInput, ['0', '1'], true)) respond(['error' => 'Choose a valid institution status.'], 422);
        $updated = mysqlSetInstitutionActive((int)$institutionId, (string)$activeInput === '1', (string)$session['email'], (string)($session['correlationId'] ?? ('platform-status-' . id())));
        if (!$updated) respond(['error' => 'Institution not found.'], 404);
        respond(['item' => $updated]);
    }
    if ($operation === 'soft-delete') {
        $institutionId = filter_var($_POST['institutionId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $confirmation = trim((string)($_POST['confirmation'] ?? ''));
        if (!$institutionId || textLength($reason) < 15 || textLength($reason) > 2000) respond(['error' => 'Provide a deletion reason between 15 and 2,000 characters.'], 422);
        $target = mysqlPlatformInstitution((int)$institutionId);
        if (!$target) respond(['error' => 'Institution not found.'], 404);
        if (!hash_equals((string)$target['name'], $confirmation) && !hash_equals((string)$target['slug'], strtolower($confirmation))) respond(['error' => 'Type the exact institution name or slug to confirm this soft deletion.'], 422);
        $deleted = mysqlSoftDeleteInstitution((int)$institutionId, $reason, (string)$session['email'], (string)($session['correlationId'] ?? ('platform-soft-delete-' . id())));
        if (!$deleted) respond(['error' => 'Institution not found.'], 404);
        respond(['item' => $deleted]);
    }
    if ($operation === 'update-branding') {
        $institutionId = filter_var($_POST['institutionId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$institutionId) respond(['error' => 'Choose a valid institution to update.'], 422);
        $current = mysqlPlatformInstitution((int)$institutionId);
        if (!$current) respond(['error' => 'Institution not found.'], 404);
        $name = requireText($_POST['name'] ?? null, 'Institution name', 190);
        $accent = institutionAccentProfile($_POST['accentColor'] ?? null, ($_POST['accentAcknowledged'] ?? '') === '1');
        $logo = optionalInstitutionLogoUpload((string)$current['slug']); $existing = $current['branding'] ?? [];
        $field = static function (string $key, string $fallback, int $maximum = 400): string {
            $value = trim((string)($_POST[$key] ?? ''));
            if ($value === '') return $fallback;
            if (textLength($value) > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) respond(['error' => "$key is too long or contains invalid characters."], 422);
            return $value;
        };
        $supportEmail = trim((string)($_POST['supportEmail'] ?? ($existing['supportEmail'] ?? '')));
        if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid support email address.'], 422);
        try {
            $updated = mysqlUpdateInstitutionBranding((int)$institutionId, [
                'name' => $name, 'displayName' => $field('displayName', $name, 190), 'portalTitle' => $field('portalTitle', $name . ' CBT', 190),
                'logoPath' => $logo['path'] ?? (string)($existing['logoPath'] ?? ''), 'accentSourceColor' => $accent['source'], 'primaryColor' => $accent['primary'], 'accentColor' => $accent['accent'],
                'navLabel' => $field('navLabel', $name . ' CBT', 190), 'assessmentLabel' => $field('assessmentLabel', 'Assessment centre', 190),
                'footerPrimary' => $field('footerPrimary', $name . ' Academic Directorate Certified Node'), 'footerSecondary' => $field('footerSecondary', 'Assessment timing supplied by the CBT service'),
                'footerLegal' => $field('footerLegal', '© {year} ' . $name . '. All rights reserved.'), 'resultSheetTitle' => $field('resultSheetTitle', $name . ' CBT', 190),
                'newsletterSenderName' => $field('newsletterSenderName', $name, 190), 'supportEmail' => $supportEmail
            ], (string)$session['email'], (string)($session['correlationId'] ?? ('platform-branding-' . id())));
        } catch (Throwable $error) {
            if ($logo && is_file($logo['absolutePath'])) @unlink($logo['absolutePath']);
            throw $error;
        }
        if (!$updated) { if ($logo && is_file($logo['absolutePath'])) @unlink($logo['absolutePath']); respond(['error' => 'Institution not found.'], 404); }
        respond(['item' => $updated, 'accent' => $accent]);
    }
    if ($operation !== 'create') respond(['error' => 'Unknown institution action.'], 422);
    $name = requireText($_POST['name'] ?? null, 'Institution name', 190);
    $email = strtolower(requireText($_POST['adminEmail'] ?? null, 'Initial Admin email', 254));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid initial Admin email address.'], 422);
    $slug = institutionProvisionSlug((string)($_POST['slug'] ?? ''));
    $logo = institutionLogoUpload($slug);
    $accent = institutionAccentProfile($_POST['accentColor'] ?? null, ($_POST['accentAcknowledged'] ?? '') === '1');
    $navLabel = trim((string)($_POST['navLabel'] ?? '')) ?: $name . ' CBT';
    $footerPrimary = trim((string)($_POST['footerPrimary'] ?? '')) ?: $name . ' Academic Directorate Certified Node';
    $footerSecondary = trim((string)($_POST['footerSecondary'] ?? '')) ?: 'Assessment timing supplied by the CBT service';
    $footerLegal = trim((string)($_POST['footerLegal'] ?? '')) ?: '© {year} ' . $name . '. All rights reserved.';
    foreach (['Navigation label' => $navLabel, 'Footer line one' => $footerPrimary, 'Footer line two' => $footerSecondary, 'Footer legal text' => $footerLegal] as $label => $value) if (textLength($value) > 400 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) respond(['error' => $label . ' is too long or contains invalid characters.'], 422);
    $password = provisionTemporaryPassword();
    $roles = array_values(array_filter(DEFAULT_ROLES, static fn(array $role): bool => $role['id'] !== 'superadmin'));
    $branding = [
        'displayName' => $name, 'portalTitle' => $name . ' CBT', 'logoPath' => $logo['path'], 'faviconPath' => 'favicon.php',
        'accentSourceColor' => $accent['source'], 'primaryColor' => $accent['primary'], 'accentColor' => $accent['accent'], 'navLabel' => $navLabel, 'assessmentLabel' => 'Assessment centre',
        'footerPrimary' => $footerPrimary, 'footerSecondary' => $footerSecondary,
        'footerLegal' => $footerLegal, 'resultSheetTitle' => $name . ' CBT', 'newsletterSenderName' => $name, 'supportEmail' => $email
    ];
    try {
        $institution = mysqlProvisionInstitution([
            'name' => $name, 'slug' => $slug, 'branding' => $branding, 'roles' => $roles, 'adminId' => 'tenant-admin-' . id(),
            'adminName' => $name . ' Admin', 'adminEmail' => $email, 'adminPasswordHash' => passwordHash($password), 'actorEmail' => (string)$session['email'],
            'correlationId' => (string)($session['correlationId'] ?? ('platform-provision-' . id())), 'gradingScale' => DEFAULT_GRADING_SCALE,
            'integrityPolicy' => DEFAULT_INTEGRITY_POLICY, 'settings' => ['examSecurity' => DEFAULT_EXAM_SECURITY, 'backup' => DEFAULT_BACKUP_SETTINGS, 'auditRetention' => DEFAULT_AUDIT_RETENTION_SETTINGS, 'studentPortalSetupMode' => false, 'resultLogoUrl' => $logo['path']]
        ]);
    } catch (PDOException $error) {
        if (is_file($logo['absolutePath'])) @unlink($logo['absolutePath']);
        if ((string)$error->getCode() === '23000') respond(['error' => 'That institution slug is already in use. Choose a different one.'], 409);
        throw $error;
    } catch (Throwable $error) {
        if (is_file($logo['absolutePath'])) @unlink($logo['absolutePath']);
        throw $error;
    }
    respond(['institution' => $institution, 'initialAdmin' => ['email' => $email, 'temporaryPassword' => $password, 'mustChangePassword' => true]], 201);
}
if ($action === 'roles' && $method === 'GET') {
    auth(true); $items = [];
    foreach ($data['roles'] as $role) {
        $count = count(array_filter($data['adminUsers'], fn($user) => ($user['roleId'] ?? '') === $role['id'] && !empty($user['active'])));
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
    $beforeRole = $role;
    $role['maxUsers'] = $maxUsers; $role['permissions'] = array_values(array_unique(array_merge(array_diff($permissions, ['roles']), ['newsletter'])));
    replaceBy($data['roles'], 'id', $roleId, $role); auditEvent($data, 'admin', adminActorId(), 'role_updated', 'role', $roleId, ['role' => $role['name'], 'permissions' => $role['permissions'], 'beforeAfter' => auditBeforeAfter($beforeRole, $role, ['maxUsers', 'permissions'])]); saveData($data); respond(['item' => $role]);
}
if ($action === 'admin-users' && $method === 'GET') {
    auth(true); $items = [];
    // Platform identities deliberately do not appear in any institution's
    // administrator directory, including the institution currently selected
    // by a platform Super Admin.
    foreach ($data['adminUsers'] as $user) $items[] = publicAdminUser($user, $data['roles'], $data['adminUserActivity']);
    respond(['items' => $items]);
}
if ($action === 'admin-users' && $method === 'POST') {
    auth(true); $input = body(); $values = validateAdminUser($input, $data); $role = findBy($data['roles'], 'id', $values['roleId']);
    $count = count(array_filter($data['adminUsers'], fn($user) => ($user['roleId'] ?? '') === $role['id'] && !empty($user['active'])));
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
    $beforeUser = $user; $input = body(); $values = validateAdminUser(array_merge($user, $input), $data, $userId, false); $newRole = findBy($data['roles'], 'id', $values['roleId']);
    if ($newRole['id'] !== $user['roleId']) { $count = count(array_filter($data['adminUsers'], fn($item) => ($item['roleId'] ?? '') === $newRole['id'] && !empty($item['active']))); if ($count >= (int)$newRole['maxUsers']) respond(['error' => 'This role has reached its maximum number of users.'], 409); }
    $user = array_merge($user, $values);
    if (array_key_exists('active', $input)) {
        $user['active'] = (bool)$input['active'];
        if ($user['active']) { unset($user['suspendedAt'], $user['suspensionReason']); }
    }
    replaceBy($data['adminUsers'], 'id', $userId, $user);
    auditEvent($data, 'admin', adminActorId(), 'administrator_updated', 'administrator', $userId, ['email' => $user['email'], 'role' => $newRole['name'], 'beforeAfter' => auditBeforeAfter($beforeUser, $user, ['name', 'email', 'phoneNumber', 'roleId', 'active'])]); saveData($data); respond(['item' => publicAdminUser($user, $data['roles'], $data['adminUserActivity'])]);
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
    $message = "Hello " . $request['name'] . ",\n\nYour " . institutionPortalTitle() . " administrator account has been approved.\n\nAssigned role: " . $role['name'] . "\nAccess: " . implode(', ', array_map(fn($permission) => ucwords(str_replace('_', ' ', $permission)), $role['permissions'])) . "\n\nYou can now sign in at the administrator portal using the email and password you submitted.\n\nIf you did not request this account, contact " . institutionBrandName() . " immediately.";
    $delivery = sendNewsletterMessage($request['email'], 'Your ' . institutionPortalTitle() . ' administrator access has been approved', $message, false);
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
    $beforeSession = $item; $item = array_merge($item, validateAcademicSession(array_merge($item, $input), $data, $sessionId)); if ($item['isActive']) foreach ($data['academicSessions'] as &$session) $session['isActive'] = false; unset($session);
    replaceBy($data['academicSessions'], 'id', $sessionId, $item); auditEvent($data, 'admin', adminActorId(), 'academic_session_updated', 'academic_session', $sessionId, ['label' => $item['label'], 'beforeAfter' => auditBeforeAfter($beforeSession, $item, ['label', 'isActive'])]); saveData($data); respond(['item' => $item]);
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
    $beforeSemester = $item; $item = array_merge($item, validateSemester(array_merge($item, $input), $data, $semesterId)); if ($item['isActive']) foreach ($data['semesters'] as &$semester) if (($semester['sessionId'] ?? '') === $item['sessionId']) $semester['isActive'] = false; unset($semester);
    replaceBy($data['semesters'], 'id', $semesterId, $item); auditEvent($data, 'admin', adminActorId(), 'semester_updated', 'semester', $semesterId, ['label' => $item['label'], 'beforeAfter' => auditBeforeAfter($beforeSemester, $item, ['label', 'sessionId', 'startDate', 'endDate', 'isActive'])]); saveData($data); respond(['item' => $item]);
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
        // Questions belong to the course pool. Removing one component only removes
        // that publish target; it must never erase questions still usable elsewhere.
        foreach ($data['questions'] as &$question) {
            if (($question['courseId'] ?? '') !== $course['id']) continue;
            $targets = normalizeQuestionPublishTargets($question['publishedTo'] ?? []);
            $question['publishedTo'] = array_values(array_filter($targets, fn($target) => $target !== $kind));
            $question['status'] = $question['publishedTo'] ? 'published' : 'draft';
        }
        unset($question);
        $data['exams'] = array_values(array_filter($data['exams'], fn($exam) => ($exam['id'] ?? '') !== $componentId));
        if ($kind === 'test') $course['testMaxMark'] = 0; else $course['examMaxMark'] = 0;
        replaceBy($data['courses'], 'id', $course['id'], $course);
        auditEvent($data, 'admin', adminActorId(), 'course_component_deleted', 'course_component', $componentId, ['course' => $course['code'], 'component' => $kind]); saveData($data); respond(['ok' => true]);
    }
    $beforeComponent = $component; $values = validateCourseComponent(body(), $course, $component);
    $component = array_merge($component, $values); replaceBy($data['exams'], 'id', $componentId, $component);
    if ($kind === 'test') $course['testMaxMark'] = $values['maxMark']; else $course['examMaxMark'] = $values['maxMark'];
    replaceBy($data['courses'], 'id', $course['id'], $course);
    if ((float)($beforeComponent['maxMark'] ?? 0) !== (float)($component['maxMark'] ?? 0) || (int)($beforeComponent['courseUnit'] ?? 0) !== (int)($component['courseUnit'] ?? 0)) recalculateResults($data);
    auditEvent($data, 'admin', adminActorId(), 'course_component_updated', 'course_component', $componentId, ['course' => $course['code'], 'component' => $kind, 'status' => $component['status'], 'maxMark' => $component['maxMark'], 'beforeAfter' => auditBeforeAfter($beforeComponent, $component, ['maxMark', 'passThreshold', 'duration', 'questionCount', 'status', 'startAt', 'endAt'])]); saveData($data); respond(['item' => publicExam($component)]);
}

if ($action === 'courses' && $method === 'GET') {
    auth(true); $items = $data['courses'];
    $questionCounts = []; $componentQuestionCounts = []; $sharedQuestionCounts = [];
    foreach ($data['questions'] as $question) {
        $courseId = (string)($question['courseId'] ?? '');
        if ($courseId === '') continue;
        $questionCounts[$courseId] = ($questionCounts[$courseId] ?? 0) + 1;
        $targets = normalizeQuestionPublishTargets($question['publishedTo'] ?? []);
        foreach ($targets as $target) $componentQuestionCounts[$courseId][$target] = ($componentQuestionCounts[$courseId][$target] ?? 0) + 1;
        if (count($targets) === 2) $sharedQuestionCounts[$courseId] = ($sharedQuestionCounts[$courseId] ?? 0) + 1;
    }
    foreach ($items as &$course) {
        $session = findBy($data['academicSessions'], 'id', (string)($course['sessionId'] ?? '')); $semester = findBy($data['semesters'], 'id', (string)($course['semesterId'] ?? ''));
        $course['sessionLabel'] = $session['label'] ?? ''; $course['semesterLabel'] = $semester['label'] ?? '';
        $courseId = (string)($course['id'] ?? '');
        $course['questionBankCount'] = (int)($questionCounts[$courseId] ?? 0);
        $course['sharedQuestionCount'] = (int)($sharedQuestionCounts[$courseId] ?? 0);
        $components = array_filter($data['exams'], fn($exam) => ($exam['courseId'] ?? '') === ($course['id'] ?? ''));
        $course['components'] = array_values(array_map(function ($component) use ($courseId, $componentQuestionCounts) {
            $component = publicExam($component);
            $type = strtolower((string)($component['component'] ?? 'exam')) === 'test' ? 'test' : 'exam';
            $component['questionBankCount'] = (int)($componentQuestionCounts[$courseId][$type] ?? 0);
            return $component;
        }, $components));
    }
    unset($course);
    if (($_GET['missingQuestionBank'] ?? '') === 'true') {
        $items = array_values(array_filter($items, function ($course) {
            foreach (($course['components'] ?? []) as $component) {
                if ((int)($component['questionCount'] ?? 0) <= 0 || (int)($component['questionBankCount'] ?? 0) === 0) return true;
            }
            return false;
        }));
    }
    respond(listItems($items, ['code', 'title', 'sessionLabel', 'semesterLabel'], ['code', 'title', 'courseUnit']));
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
        $data['questions'] = array_values(array_filter($data['questions'], fn($question) => ($question['courseId'] ?? '') !== $courseId)); $data['exams'] = array_values(array_filter($data['exams'], fn($exam) => ($exam['courseId'] ?? '') !== $courseId)); $data['courses'] = array_values(array_filter($data['courses'], fn($item) => ($item['id'] ?? '') !== $courseId)); auditEvent($data, 'admin', adminActorId(), 'course_deleted', 'course', $courseId, ['course' => $course['code']]); saveData($data); respond(['ok' => true]);
    }
    $beforeCourse = $course; $input = array_merge($course, $input); ensureCoursePeriod($data, $input);
    $values = validateCourse($input, $data, $courseId); $course = array_merge($course, array_diff_key($values, ['test' => true, 'exam' => true])); replaceBy($data['courses'], 'id', $courseId, $course);
    foreach (['test', 'exam'] as $component) {
        $existing = array_values(array_filter($data['exams'], fn($exam) => ($exam['courseId'] ?? '') === $courseId && ($exam['component'] ?? 'exam') === $component)); $exam = $existing[0] ?? null;
        $componentData = ['courseId' => $courseId, 'component' => $component, 'code' => $course['code'], 'title' => $course['title'], 'courseTitle' => $course['title'], 'description' => $course['description'], 'category' => $course['category'], 'courseUnit' => $course['courseUnit'], 'sessionId' => $course['sessionId'], 'semesterId' => $course['semesterId']] + $values[$component];
        if ($exam) replaceBy($data['exams'], 'id', $exam['id'], array_merge($exam, $componentData)); else $data['exams'][] = ['id' => id()] + $componentData + ['createdAt' => date('c')];
    }
    $resultFields=['courseUnit','sessionId','semesterId','testMaxMark','examMaxMark'];$requiresResultRecalculation=false;foreach($resultFields as $field)if(($beforeCourse[$field]??null)!==($course[$field]??null)){$requiresResultRecalculation=true;break;}if($requiresResultRecalculation)recalculateResults($data); auditEvent($data, 'admin', adminActorId(), 'course_updated', 'course', $courseId, ['course' => $course['code'], 'testMaxMark' => $course['testMaxMark'], 'examMaxMark' => $course['examMaxMark'], 'beforeAfter' => auditBeforeAfter($beforeCourse, $course, ['code', 'title', 'description', 'category', 'courseUnit', 'sessionId', 'semesterId', 'testMaxMark', 'examMaxMark'])]); saveData($data); respond(['item' => $course]);
}

if ($action === 'exams' && $method === 'GET') {
    $items = array_map('publicExam', $data['exams']);
    if (($_GET['active'] ?? '') === 'true') {
        $items = array_values(array_filter($items, fn($exam) => $exam['active']));
        foreach ($items as &$item) {
            $publishedQuestions = questionsPublishedForComponent($data, $item);
            $item['availableQuestionCount'] = count($publishedQuestions);
            $item['questionMode'] = !$publishedQuestions ? 'Questions pending' : (array_filter($publishedQuestions, fn($question) => ($question['type'] ?? 'single') === 'multiple') ? 'Single & multiple choice' : 'Single choice');
        }
        unset($item);
        $now = time();
        $studentsTesting = count(array_filter($data['sessions'], fn($session) => in_array(($session['status'] ?? ''), ['in_progress', 'locked'], true) && strtotime((string)($session['endsAt'] ?? '')) > $now));
        $activeSession = findBy($data['academicSessions'], 'isActive', true);
        $activeSemester = findBy($data['semesters'], 'isActive', true);
        respond(['items' => $items, 'liveStatus' => ['openComponents' => count($items), 'studentsTesting' => $studentsTesting, 'clientIp' => clientFingerprint(), 'singleSessionLockActive' => !empty(examSecurity($data)['concurrentIpBlockEnabled'])], 'activePeriod' => ['sessionLabel' => $activeSession['label'] ?? '', 'semesterLabel' => $activeSemester['label'] ?? ''], 'studentPortalSetupMode' => !empty($data['settings']['studentPortalSetupMode'])]);
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
    $beforeExam = $exam; $exam = array_merge($exam, validateExam(array_merge($exam, $input), $data, $examId));
    replaceBy($data['exams'], 'id', $examId, $exam); if((float)($beforeExam['maxMark']??0)!==(float)($exam['maxMark']??0)||(int)($beforeExam['courseUnit']??0)!==(int)($exam['courseUnit']??0))recalculateResults($data); auditEvent($data, 'admin', adminActorId(), 'exam_updated', 'exam', $examId, ['course' => $exam['code'], 'title' => $exam['title'], 'changedFields' => array_keys($input), 'beforeAfter' => auditBeforeAfter($beforeExam, $exam, ['code', 'title', 'description', 'duration', 'questionCount', 'courseUnit', 'session', 'maxMark', 'passThreshold', 'status', 'startAt', 'endAt'])]); saveData($data); respond(['item' => publicExam($exam)]);
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
    $beforeStudent = $student; $input = body(); $student = array_merge($student, validateStudent(array_merge($student, $input), $data, $studentId)); if (array_key_exists('active', $input)) $student['active'] = (bool)$input['active'];
    replaceBy($data['students'], 'id', $studentId, $student); syncStudentSubscriber($data, $student); auditEvent($data, 'admin', adminActorId(), 'student_updated', 'student', $studentId, ['matricNumber' => $student['matricNumber'], 'name' => $student['fullName'], 'changedFields' => array_keys($input), 'beforeAfter' => auditBeforeAfter($beforeStudent, $student, ['fullName', 'email', 'phoneNumber', 'matricNumber', 'department', 'active'])]); saveData($data); respond(['item' => $student]);
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

if ($action === 'questions' && $method === 'GET') { auth(true); $items = $data['questions']; if (!empty($_GET['courseId'])) $items = array_values(array_filter($items, fn($item) => (string)($item['courseId'] ?? '') === (string)$_GET['courseId'])); elseif (!empty($_GET['examId'])) $items = array_values(array_filter($items, fn($item) => $item['examId'] === $_GET['examId'])); if (!empty($_GET['status'])) $items = array_values(array_filter($items, fn($item) => ($item['status'] ?? 'published') === $_GET['status'])); respond(listItems($items, ['text','topic','difficulty','status'], ['text','topic','difficulty','status'])); }
if ($action === 'questions' && in_array($method, ['POST','PUT','DELETE'], true)) {
    auth(true); $questionId = $_GET['id'] ?? '';
    if ($method === 'DELETE') { $existing = findBy($data['questions'], 'id', $questionId); if (!$existing) respond(['error' => 'Question not found.'], 404); $course = findBy($data['courses'], 'id', (string)($existing['courseId'] ?? '')); $data['questions'] = array_values(array_filter($data['questions'], fn($item) => $item['id'] !== $questionId)); auditEvent($data, 'admin', adminActorId(), 'question_deleted', 'question', $questionId, ['courseId' => $existing['courseId'] ?? '', 'course' => $course['code'] ?? '', 'text' => substr($existing['text'], 0, 120)]); saveData($data); respond(['ok' => true]); }
    $input = body();
    if ($method === 'POST') { $question = ['id' => id(), 'legacyComponentId' => null, 'publishedTo' => [], 'status' => 'draft'] + validateQuestion($input, $data); requireUniqueQuestionBatch([$question], $data, (string)$question['courseId']); $course = findBy($data['courses'], 'id', $question['courseId']); $data['questions'][] = $question; auditEvent($data, 'admin', adminActorId(), 'question_added', 'question', $question['id'], ['courseId' => $question['courseId'], 'course' => $course['code'] ?? '', 'text' => substr($question['text'], 0, 120), 'status' => 'draft']); saveData($data); respond(['item' => $question], 201); }
    $question = findBy($data['questions'], 'id', $questionId); if (!$question) respond(['error' => 'Question not found.'], 404);
    $beforeQuestion = $question; $question = array_merge($question, validateQuestion(array_merge($question, $input), $data)); requireUniqueQuestionBatch([$question], $data, (string)$question['courseId'], (string)$questionId);
    $course = findBy($data['courses'], 'id', $question['courseId']); replaceBy($data['questions'], 'id', $questionId, $question); auditEvent($data, 'admin', adminActorId(), 'question_updated', 'question', $questionId, ['courseId' => $question['courseId'], 'course' => $course['code'] ?? '', 'text' => substr($question['text'], 0, 120), 'changedFields' => array_keys($input), 'beforeAfter' => auditBeforeAfter($beforeQuestion, $question, ['text', 'options', 'correctOptions', 'type'])]); saveData($data); respond(['item' => $question]);
}
if ($action === 'questions-bulk' && $method === 'POST') {
    auth(true); enforceRateLimit($data, 'questions-bulk', 10, 600); $input = body(); $courseId = trim((string)($input['courseId'] ?? '')); $course = findBy($data['courses'], 'id', $courseId); $rows = $input['items'] ?? null;
    if (!$course) respond(['error' => 'Choose an existing course question bank.'], 422);
    if (!is_array($rows) || !$rows || count($rows) > 500 || !array_is_list($rows)) respond(['error' => 'Provide 1 to 500 question rows.'], 422);
    $new = []; foreach ($rows as $index => $row) { if (!is_array($row)) respond(['error' => 'Question row ' . ($index + 1) . ' is invalid.'], 422); $new[] = ['id' => id(), 'legacyComponentId' => null, 'publishedTo' => [], 'status' => 'draft'] + validateQuestion(array_merge($row, ['courseId' => $courseId]), $data); }
    requireUniqueQuestionBatch($new, $data, $courseId);
    array_push($data['questions'], ...$new); auditEvent($data, 'admin', adminActorId(), 'questions_bulk_imported', 'course', $courseId, ['count' => count($new), 'course' => $course['code'] ?? '', 'status' => 'draft']); saveData($data); respond(['added' => count($new), 'status' => 'draft'], 201);
}

if ($action === 'questions-bulk-delete' && $method === 'POST') {
    auth(true); $input = body(); $courseId = trim((string)($input['courseId'] ?? '')); $examId = trim((string)($input['examId'] ?? '')); $ids = $input['ids'] ?? null;
    $course = $courseId !== '' ? findBy($data['courses'], 'id', $courseId) : null;
    $exam = $courseId === '' ? findBy($data['exams'], 'id', $examId) : null;
    if (!$course && !$exam) respond(['error' => 'The selected course question bank no longer exists.'], 422);
    if (!is_array($ids) || !$ids || !array_is_list($ids) || count($ids) > 100) respond(['error' => 'Select between 1 and 100 questions to delete.'], 422);
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn($id) => $id !== '')));
    $selected = array_values(array_filter($data['questions'], fn($question) => in_array((string)($question['id'] ?? ''), $ids, true)));
    if (count($selected) !== count($ids) || ($course ? array_filter($selected, fn($question) => ($question['courseId'] ?? '') !== $courseId) : array_filter($selected, fn($question) => ($question['examId'] ?? '') !== $examId))) respond(['error' => 'One or more selected questions no longer belong to this question bank. Refresh the page and try again.'], 422);
    $selectedIds = array_fill_keys($ids, true); $data['questions'] = array_values(array_filter($data['questions'], fn($question) => !isset($selectedIds[(string)($question['id'] ?? '')])));
    auditEvent($data, 'admin', adminActorId(), 'questions_bulk_deleted', $course ? 'course' : 'exam', $course ? $courseId : $examId, ['course' => $course['code'] ?? $exam['code'] ?? '', 'component' => $course ? 'shared_pool' : ($exam['component'] ?? 'exam'), 'count' => count($ids), 'questionIds' => $ids]); saveData($data);
    respond(['removed' => count($ids)]);
}

if ($action === 'questions-publish-target' && $method === 'POST') {
    auth(true); $input = body(); $courseId = trim((string)($input['courseId'] ?? '')); $target = strtolower(trim((string)($input['target'] ?? ''))); $ids = $input['ids'] ?? null;
    $course = findBy($data['courses'], 'id', $courseId);
    if (!$course) respond(['error' => 'The selected course no longer exists. Refresh the page and try again.'], 422);
    if (!in_array($target, ['test', 'exam'], true)) respond(['error' => 'Choose Test or Exam as the publishing target.'], 422);
    $targetComponent = null;
    foreach ($data['exams'] as $component) if (($component['courseId'] ?? '') === $courseId && strtolower((string)($component['component'] ?? 'exam')) === $target) { $targetComponent = $component; break; }
    if (!$targetComponent) respond(['error' => 'This course does not yet have a ' . ucfirst($target) . ' component to publish questions to.'], 422);
    if (!is_array($ids) || !$ids || !array_is_list($ids) || count($ids) > 100) respond(['error' => 'Select between 1 and 100 questions to publish.'], 422);
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn($id) => $id !== '')));
    $selected = array_values(array_filter($data['questions'], fn($question) => in_array((string)($question['id'] ?? ''), $ids, true)));
    if (count($selected) !== count($ids) || array_filter($selected, fn($question) => ($question['courseId'] ?? '') !== $courseId)) respond(['error' => 'One or more selected questions no longer belong to this shared course pool. Refresh the page and try again.'], 422);
    $otherTarget = $target === 'test' ? 'exam' : 'test';
    $overlap = array_values(array_filter($selected, fn($question) => in_array($otherTarget, normalizeQuestionPublishTargets($question['publishedTo'] ?? []), true)));
    if ($overlap && empty($input['confirmOverlap'])) respond(['error' => count($overlap) . ' selected question(s) are already published to ' . ucfirst($otherTarget) . '. Confirmation is required before they can also be published to ' . ucfirst($target) . '.', 'requiresOverlapConfirmation' => true, 'overlapCount' => count($overlap), 'overlapQuestionIds' => array_values(array_map(fn($question) => (string)$question['id'], $overlap))], 409);
    $selectedIds = array_fill_keys($ids, true); $newlyAssigned = 0;
    foreach ($data['questions'] as &$question) {
        if (!isset($selectedIds[(string)($question['id'] ?? '')])) continue;
        $targets = normalizeQuestionPublishTargets($question['publishedTo'] ?? []);
        if (!in_array($target, $targets, true)) { $targets[] = $target; $newlyAssigned++; }
        $question['publishedTo'] = normalizeQuestionPublishTargets($targets);
        $question['status'] = $question['publishedTo'] ? 'published' : 'draft';
    }
    unset($question);
    auditEvent($data, 'admin', adminActorId(), 'questions_published_to_component', 'course', $courseId, ['course' => $course['code'] ?? '', 'target' => $target, 'targetComponentId' => $targetComponent['id'], 'selectedCount' => count($ids), 'newlyAssignedCount' => $newlyAssigned, 'overlapCount' => count($overlap), 'questionIds' => $ids]);
    saveData($data); respond(['published' => count($ids), 'newlyAssigned' => $newlyAssigned, 'overlapCount' => count($overlap), 'target' => $target]);
}

if ($action === 'questions-unpublish-target' && $method === 'POST') {
    auth(true); $input = body(); $courseId = trim((string)($input['courseId'] ?? '')); $questionId = trim((string)($input['questionId'] ?? '')); $targets = normalizeQuestionPublishTargets($input['targets'] ?? []);
    $course = findBy($data['courses'], 'id', $courseId); $question = findBy($data['questions'], 'id', $questionId);
    if (!$course || !$question || ($question['courseId'] ?? '') !== $courseId) respond(['error' => 'This question is no longer in the selected course pool. Refresh the page and try again.'], 422);
    if (!$targets) respond(['error' => 'Choose at least one component to unpublish from.'], 422);
    $currentTargets = normalizeQuestionPublishTargets($question['publishedTo'] ?? []);
    if (array_diff($targets, $currentTargets)) respond(['error' => 'This question is no longer published to one or more selected components. Refresh the page and try again.'], 409);
    $question['publishedTo'] = array_values(array_filter($currentTargets, fn($target) => !in_array($target, $targets, true)));
    $question['status'] = $question['publishedTo'] ? 'published' : 'draft';
    replaceBy($data['questions'], 'id', $questionId, $question);
    auditEvent($data, 'admin', adminActorId(), 'question_unpublished_from_component', 'question', $questionId, ['courseId' => $courseId, 'course' => $course['code'] ?? '', 'removedTargets' => $targets, 'remainingTargets' => $question['publishedTo'], 'text' => substr((string)($question['text'] ?? ''), 0, 120)]);
    saveData($data); respond(['item' => $question, 'removedTargets' => $targets]);
}

if ($action === 'questions-deduplicate' && $method === 'POST') {
    auth(true); $input = body(); $examId = trim((string)($input['examId'] ?? '')); $exam = findBy($data['exams'], 'id', $examId);
    if (!$exam) respond(['error' => 'The selected Test or Exam component no longer exists.'], 422);
    $usedQuestionIds = [];
    foreach ($data['sessions'] as $session) foreach (($session['questions'] ?? []) as $question) if (!empty($question['id'])) $usedQuestionIds[(string)$question['id']] = true;
    $groups = [];
    foreach ($data['questions'] as $question) if (($question['examId'] ?? '') === $examId) $groups[questionTextFingerprint((string)($question['text'] ?? ''))][] = $question;
    $removeIds = [];
    foreach ($groups as $group) {
        if (count($group) < 2) continue;
        $published = array_values(array_filter($group, fn($question) => ($question['status'] ?? 'published') !== 'draft'));
        $keeperId = (string)(($published[0]['id'] ?? $group[0]['id']) ?? '');
        foreach ($group as $question) {
            $id = (string)($question['id'] ?? '');
            if ($id !== $keeperId && ($question['status'] ?? 'published') === 'draft' && !isset($usedQuestionIds[$id])) $removeIds[$id] = true;
        }
    }
    if ($removeIds) {
        $data['questions'] = array_values(array_filter($data['questions'], fn($question) => !isset($removeIds[(string)($question['id'] ?? '')])));
        auditEvent($data, 'admin', adminActorId(), 'duplicate_draft_questions_removed', 'exam', $examId, ['course' => $exam['code'] ?? '', 'component' => $exam['component'] ?? 'exam', 'removed' => count($removeIds), 'policy' => 'exact text duplicates; published and session-referenced questions preserved']); saveData($data);
    }
    respond(['removed' => count($removeIds)]);
}

if ($action === 'strict-question-parse' && $method === 'POST') {
    auth(true);
    $courseId = trim((string)($_POST['courseId'] ?? '')); $course = findBy($data['courses'], 'id', $courseId);
    if (!$course) respond(['error' => 'Choose a valid course Question Bank before importing questions.'], 422);
    $source = strictQuestionImportSource(); $items = parseStrictQuestionText($source['text']); requireUniqueParsedQuestions($items, 'Strict-format import');
    auditEvent($data, 'admin', adminActorId(), 'strict_questions_parsed', 'course', $courseId, ['course' => $course['code'] ?? '', 'filename' => $source['filename'], 'parsedQuestions' => count($items)]); saveData($data);
    respond(['filename' => $source['filename'], 'items' => $items, 'limits' => ['questions' => STRICT_IMPORT_MAX_QUESTIONS, 'bytes' => STRICT_IMPORT_MAX_BYTES]]);
}

if ($action === 'strict-question-import' && $method === 'POST') {
    auth(true); $input = body();
    $courseId = trim((string)($input['courseId'] ?? '')); $course = findBy($data['courses'], 'id', $courseId); $items = $input['items'] ?? null;
    if (!$course) respond(['error' => 'The selected course Question Bank no longer exists. Reopen it and try again.'], 422);
    if (!is_array($items) || !$items || !array_is_list($items) || count($items) > STRICT_IMPORT_MAX_QUESTIONS) respond(['error' => 'Select between 1 and ' . STRICT_IMPORT_MAX_QUESTIONS . ' reviewed questions to import.'], 422);
    $new = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) respond(['error' => 'Reviewed question ' . ($index + 1) . ' is invalid.'], 422);
        $correctOptions = array_values(array_unique(array_map('intval', (array)($item['correctOptionIndexes'] ?? []))));
        $question = validateQuestion(['courseId' => $courseId, 'text' => $item['questionText'] ?? '', 'options' => $item['options'] ?? [], 'correctOptions' => $correctOptions, 'type' => count($correctOptions) > 1 ? 'multiple' : 'single'], $data);
        $new[] = ['id' => id(), 'legacyComponentId' => null, 'publishedTo' => [], 'status' => 'draft'] + $question;
    }
    $existingIndexes = array_fill_keys(requireUniqueQuestionBatch($new, $data, $courseId, '', true), true); $skipped = count($existingIndexes);
    $new = array_values(array_filter($new, fn($question, $index) => !isset($existingIndexes[$index]), ARRAY_FILTER_USE_BOTH));
    if (!$new) respond(['added' => 0, 'skipped' => $skipped, 'status' => 'draft']);
    array_push($data['questions'], ...$new); auditEvent($data, 'admin', adminActorId(), 'strict_questions_imported', 'course', $courseId, ['course' => $course['code'] ?? '', 'count' => count($new), 'skippedExisting' => $skipped, 'status' => 'draft']); saveData($data);
    respond(['added' => count($new), 'skipped' => $skipped, 'status' => 'draft'], 201);
}

if ($action === 'pdf-import-jobs' && $method === 'POST') {
    if (!mysqlStorageEnabled()) respond(['error' => 'Queued PDF import requires MySQL storage.'], 503);
    $session = auth(true); $requester = pdfImportRequester($session); $provider = strtolower(trim((string)($_POST['provider'] ?? 'gemini')));
    if (!in_array($provider, ['gemini','openrouter'], true)) respond(['error' => 'Choose Gemini or OpenRouter for this PDF import.'], 422);
    if ($provider === 'openrouter') { enforceRateLimit($data, 'openrouter-pdf-parse-' . adminActorId(), 1, 30); enforceSystemRateLimit($data, 'openrouter-pdf-parse-daily', 30, 86400, 'OpenRouter PDF-import'); }
    else { enforceRateLimit($data, 'gemini-pdf-parse-' . adminActorId(), 1, 15); enforceSystemRateLimit($data, 'gemini-pdf-parse-daily', 50, 86400); }
    $courseId = trim((string)($_POST['courseId'] ?? '')); $course = findBy($data['courses'], 'id', $courseId);
    if (!$course) respond(['error' => 'Choose a valid course Question Bank before importing a PDF.'], 422);
    $source = queuePdfImportSource($provider); $pdo = mysqlAppPdo();
    try {
        $pdo->beginTransaction(); mysqlRegisterInstitutionAsset($pdo, mysqlCurrentInstitutionId(), 'pdf_import_source', $source['sourceKey'], (string)($session['email'] ?? ''), ['sourceFilename' => $source['filename'], 'provider' => $provider, 'temporary' => true]);
        $insert = $pdo->prepare("INSERT INTO pdf_import_jobs (institution_id,id,course_id,requested_by_admin_id,requested_by_platform_admin_id,requested_by_email,requested_by_scope,provider,status,source_key,source_filename,source_sha256,source_size_bytes,available_at,created_at,updated_at) VALUES (:institution_id,:id,:course_id,:admin_id,:platform_admin_id,:email,:requester_scope,:provider,'queued',:source_key,:source_filename,:source_sha256,:source_size_bytes,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
        $insert->execute(['institution_id' => mysqlCurrentInstitutionId(), 'id' => $source['id'], 'course_id' => $courseId, 'admin_id' => $requester['scope'] === 'institution' ? $requester['id'] : null, 'platform_admin_id' => $requester['scope'] === 'platform' ? $requester['id'] : null, 'email' => (string)($session['email'] ?? ''), 'requester_scope' => $requester['scope'], 'provider' => $provider, 'source_key' => $source['sourceKey'], 'source_filename' => $source['filename'], 'source_sha256' => $source['checksum'], 'source_size_bytes' => $source['size']]);
        mysqlFastAudit($pdo, 'admin', (string)($session['email'] ?? adminActorId()), $provider . '_pdf_import_queued', 'course', $courseId, ['course' => $course['code'] ?? '', 'jobId' => $source['id'], 'filename' => $source['filename'], 'provider' => $provider, 'status' => 'queued', 'requesterScope' => $requester['scope']]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); $path = mysqlStorageAbsolutePath($source['sourceKey']); if ($path && is_file($path)) @unlink($path); throw $error; }
    respond(['job' => ['id' => $source['id'], 'status' => 'queued', 'filename' => $source['filename'], 'provider' => $provider]], 202);
}
if ($action === 'pdf-import-jobs' && $method === 'GET') {
    if (!mysqlStorageEnabled()) respond(['error' => 'Queued PDF import requires MySQL storage.'], 503);
    $session = auth(true); $jobId = trim((string)($_GET['id'] ?? ''));
    if ($jobId !== '' && !preg_match('/^[a-f0-9]{32}$/', $jobId)) respond(['error' => 'Choose a valid PDF import job.'], 422);
    if ($jobId === '') {
        $courseId = trim((string)($_GET['courseId'] ?? ''));
        if ($courseId === '') respond(['error' => 'Choose a course to resume its PDF import.'], 422);
        $job = latestRecoverablePdfImportJob($courseId, $session);
        respond(['job' => $job ? pdfImportJobPayload($job, true) : null]);
    }
    $job = currentPdfImportJob($jobId, $session); if (!$job) respond(['error' => 'PDF import job not found.'], 404);
    respond(['job' => pdfImportJobPayload($job, true)]);
}
if ($action === 'pdf-import-jobs' && $method === 'DELETE') {
    if (!mysqlStorageEnabled()) respond(['error' => 'Queued PDF import requires MySQL storage.'], 503);
    $session = auth(true); $jobId = trim((string)($_GET['id'] ?? '')); $pdo = mysqlAppPdo(); $pdo->beginTransaction();
    try {
        $job = currentPdfImportJob($jobId, $session, true); if (!$job) { $pdo->rollBack(); respond(['error' => 'PDF import job not found.'], 404); }
        if (in_array((string)$job['status'], ['completed','cancelled'], true)) { $pdo->rollBack(); respond(['error' => 'This PDF import can no longer be cancelled.'], 409); }
        $pdo->prepare("UPDATE pdf_import_jobs SET status='cancelled',error_code='cancelled_by_admin',error_message='Import cancelled by administrator.',source_expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL " . PDF_IMPORT_SOURCE_RETENTION_HOURS . " HOUR),updated_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id")->execute(['institution_id' => mysqlCurrentInstitutionId(), 'id' => $jobId]);
        schedulePdfImportSourceExpiry($pdo, mysqlCurrentInstitutionId(), (string)$job['source_key']); mysqlFastAudit($pdo, 'admin', (string)($session['email'] ?? adminActorId()), 'pdf_import_cancelled', 'course', (string)$job['course_id'], ['jobId' => $jobId, 'provider' => $job['provider']]); $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    respond(['ok' => true, 'status' => 'cancelled']);
}
if ($action === 'pdf-import-job-import' && $method === 'POST') {
    if (!mysqlStorageEnabled()) respond(['error' => 'Queued PDF import requires MySQL storage.'], 503);
    $session = auth(true); enforceRateLimit($data, 'pdf-question-import', 20, 3600); $input = body(); $jobId = trim((string)($input['jobId'] ?? '')); $items = $input['items'] ?? null;
    $job = currentPdfImportJob($jobId, $session, true); if (!$job || (string)$job['status'] !== 'review_ready') respond(['error' => 'This PDF review is no longer available. Reopen the import screen and try again.'], 409);
    $courseId = (string)$job['course_id']; $course = findBy($data['courses'], 'id', $courseId); $maximum = (int)pdfQueueLimits((string)$job['provider'])['questions'];
    if (!$course || !is_array($items) || !$items || !array_is_list($items) || count($items) > $maximum) respond(['error' => 'Select between 1 and ' . $maximum . ' reviewed questions to import.'], 422);
    $new = []; foreach ($items as $index => $item) { if (!is_array($item)) respond(['error' => 'Reviewed question ' . ($index + 1) . ' is invalid.'], 422); $correct = array_values(array_unique(array_map('intval', (array)($item['correctOptionIndexes'] ?? [])))); $question = validateQuestion(['courseId'=>$courseId,'text'=>$item['questionText'] ?? '','options'=>$item['options'] ?? [],'correctOptions'=>$correct,'type'=>count($correct)>1?'multiple':'single'], $data); $new[] = ['id'=>id(),'legacyComponentId'=>null,'publishedTo'=>[],'status'=>'draft'] + $question; }
    $existingIndexes = array_fill_keys(requireUniqueQuestionBatch($new, $data, $courseId, '', true), true); $skipped = count($existingIndexes); $new = array_values(array_filter($new, fn($question, $index) => !isset($existingIndexes[$index]), ARRAY_FILTER_USE_BOTH));
    if ($new) { array_push($data['questions'], ...$new); auditEvent($data, 'admin', adminActorId(), (string)$job['provider'] . '_pdf_questions_imported', 'course', $courseId, ['course'=>$course['code'] ?? '','count'=>count($new),'skippedExisting'=>$skipped,'status'=>'draft','jobId'=>$jobId]); saveData($data); }
    $pdo = mysqlAppPdo(); $pdo->beginTransaction(); try { $pdo->prepare("UPDATE pdf_import_jobs SET status='completed',completed_at=UTC_TIMESTAMP(6),source_expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL " . PDF_IMPORT_SOURCE_RETENTION_HOURS . " HOUR),updated_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id AND status='review_ready'")->execute(['institution_id'=>mysqlCurrentInstitutionId(),'id'=>$jobId]); schedulePdfImportSourceExpiry($pdo, mysqlCurrentInstitutionId(), (string)$job['source_key']); $pdo->commit(); } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    respond(['added'=>count($new),'skipped'=>$skipped,'status'=>'draft'], $new ? 201 : 200);
}

if ($action === 'pdf-question-parse' && $method === 'POST') {
    auth(true); enforceRateLimit($data, 'gemini-pdf-parse-' . adminActorId(), 1, 15); enforceSystemRateLimit($data, 'gemini-pdf-parse-daily', 50, 86400);
    $courseId = trim((string)($_POST['courseId'] ?? '')); $course = findBy($data['courses'], 'id', $courseId);
    if (!$course) respond(['error' => 'Choose a valid course Question Bank before importing a PDF.'], 422);
    $parsed = extractPdfQuestionCandidates(); $lowConfidence = count(array_filter($parsed['items'], fn($item) => ($item['confidence'] ?? 'low') === 'low'));
    auditEvent($data, 'admin', adminActorId(), 'pdf_questions_parsed', 'course', $courseId, ['course' => $course['code'] ?? '', 'filename' => $parsed['filename'], 'pages' => $parsed['pages'], 'parsedQuestions' => count($parsed['items']), 'lowConfidence' => $lowConfidence]); saveData($data);
    respond(['filename' => $parsed['filename'], 'pages' => $parsed['pages'], 'items' => $parsed['items'], 'limits' => $parsed['limits']]);
}

if ($action === 'pdf-question-import' && $method === 'POST') {
    auth(true); enforceRateLimit($data, 'pdf-question-import', 20, 3600); $input = body();
    $courseId = trim((string)($input['courseId'] ?? '')); $course = findBy($data['courses'], 'id', $courseId); $items = $input['items'] ?? null;
    if (!$course) respond(['error' => 'The selected course Question Bank no longer exists. Reopen it and try again.'], 422);
    if (!is_array($items) || !$items || !array_is_list($items) || count($items) > PDF_IMPORT_MAX_QUESTIONS) respond(['error' => 'Select between 1 and ' . PDF_IMPORT_MAX_QUESTIONS . ' reviewed questions to import.'], 422);
    $new = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) respond(['error' => 'Reviewed question ' . ($index + 1) . ' is invalid.'], 422);
        $correctOptions = array_values(array_unique(array_map('intval', (array)($item['correctOptionIndexes'] ?? []))));
        $question = validateQuestion(['courseId' => $courseId, 'text' => $item['questionText'] ?? '', 'options' => $item['options'] ?? [], 'correctOptions' => $correctOptions, 'type' => count($correctOptions) > 1 ? 'multiple' : 'single'], $data);
        $new[] = ['id' => id(), 'legacyComponentId' => null, 'publishedTo' => [], 'status' => 'draft'] + $question;
    }
    $existingIndexes = array_fill_keys(requireUniqueQuestionBatch($new, $data, $courseId, '', true), true); $skipped = count($existingIndexes);
    $new = array_values(array_filter($new, fn($question, $index) => !isset($existingIndexes[$index]), ARRAY_FILTER_USE_BOTH));
    if (!$new) respond(['added' => 0, 'skipped' => $skipped, 'status' => 'draft']);
    array_push($data['questions'], ...$new); auditEvent($data, 'admin', adminActorId(), 'pdf_questions_imported', 'course', $courseId, ['course' => $course['code'] ?? '', 'count' => count($new), 'skippedExisting' => $skipped, 'status' => 'draft']); saveData($data);
    respond(['added' => count($new), 'skipped' => $skipped, 'status' => 'draft'], 201);
}

if ($action === 'openrouter-pdf-question-parse' && $method === 'POST') {
    auth(true); enforceRateLimit($data, 'openrouter-pdf-parse-' . adminActorId(), 1, 30); enforceSystemRateLimit($data, 'openrouter-pdf-parse-daily', 30, 86400, 'OpenRouter PDF-import');
    $courseId = trim((string)($_POST['courseId'] ?? '')); $course = findBy($data['courses'], 'id', $courseId);
    if (!$course) respond(['error' => 'Choose a valid course Question Bank before importing a PDF.'], 422);
    $parsed = extractPdfQuestionCandidates('openrouter'); $lowConfidence = count(array_filter($parsed['items'], fn($item) => ($item['confidence'] ?? 'low') === 'low'));
    auditEvent($data, 'admin', adminActorId(), 'openrouter_pdf_questions_parsed', 'course', $courseId, ['course' => $course['code'] ?? '', 'filename' => $parsed['filename'], 'pages' => $parsed['pages'], 'parsedQuestions' => count($parsed['items']), 'lowConfidence' => $lowConfidence, 'model' => (string)(getenv('CBT_OPENROUTER_MODEL') ?: 'openrouter/free')]); saveData($data);
    respond(['filename' => $parsed['filename'], 'pages' => $parsed['pages'], 'items' => $parsed['items'], 'limits' => $parsed['limits']]);
}

if ($action === 'openrouter-pdf-question-import' && $method === 'POST') {
    auth(true); enforceRateLimit($data, 'openrouter-pdf-question-import', 20, 3600); $input = body();
    $courseId = trim((string)($input['courseId'] ?? '')); $course = findBy($data['courses'], 'id', $courseId); $items = $input['items'] ?? null;
    if (!$course) respond(['error' => 'The selected course Question Bank no longer exists. Reopen it and try again.'], 422);
    if (!is_array($items) || !$items || !array_is_list($items) || count($items) > OPENROUTER_PDF_IMPORT_MAX_QUESTIONS) respond(['error' => 'Select between 1 and ' . OPENROUTER_PDF_IMPORT_MAX_QUESTIONS . ' reviewed questions to import.'], 422);
    $new = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) respond(['error' => 'Reviewed question ' . ($index + 1) . ' is invalid.'], 422);
        $correctOptions = array_values(array_unique(array_map('intval', (array)($item['correctOptionIndexes'] ?? []))));
        $question = validateQuestion(['courseId' => $courseId, 'text' => $item['questionText'] ?? '', 'options' => $item['options'] ?? [], 'correctOptions' => $correctOptions, 'type' => count($correctOptions) > 1 ? 'multiple' : 'single'], $data);
        $new[] = ['id' => id(), 'legacyComponentId' => null, 'publishedTo' => [], 'status' => 'draft'] + $question;
    }
    $existingIndexes = array_fill_keys(requireUniqueQuestionBatch($new, $data, $courseId, '', true), true); $skipped = count($existingIndexes);
    $new = array_values(array_filter($new, fn($question, $index) => !isset($existingIndexes[$index]), ARRAY_FILTER_USE_BOTH));
    if (!$new) respond(['added' => 0, 'skipped' => $skipped, 'status' => 'draft']);
    array_push($data['questions'], ...$new); auditEvent($data, 'admin', adminActorId(), 'openrouter_pdf_questions_imported', 'course', $courseId, ['course' => $course['code'] ?? '', 'count' => count($new), 'skippedExisting' => $skipped, 'status' => 'draft', 'model' => (string)(getenv('CBT_OPENROUTER_MODEL') ?: 'openrouter/free')]); saveData($data);
    respond(['added' => count($new), 'skipped' => $skipped, 'status' => 'draft'], 201);
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
    $token = secretToken(); $expiresAt = min(strtotime($exam['endAt']), time() + LOGIN_TOKEN_SECONDS); $correlationId = 'exam-' . id(); clearExamLoginFailures($data, $matricNumber, $examId); $data['loginTokens'][] = ['tokenHash' => tokenHash($token), 'correlationId' => $correlationId, 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordId' => $passwordRecord['id'], 'expiresAt' => date('c', $expiresAt), 'usedAt' => null, 'deviceFingerprint' => $deviceFingerprint, 'sharedDeviceEvents' => $sharedDeviceEvents]; auditEvent($data, 'student', $student['id'], 'student_exam_login', 'exam', $exam['id'], ['matricNumber' => $student['matricNumber'], 'course' => $exam['code'], 'correlationId' => $correlationId]); saveData($data);
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
        $existing['questions'] ??= questionsPublishedForComponent($data, $exam);
        $existing['integrityEvents'] ??= [];
        replaceBy($data['sessions'], 'id', $existing['id'], $existing); saveData($data);
        clearSessionCookie('CBT_EXAM_LOGIN'); setSessionCookie('CBT_EXAM_SESSION', $accessToken, strtotime($existing['endsAt'])); respond(['session' => sessionPayload($existing), 'questions' => publicSessionQuestions($existing)]);
    }
    $questionsForExam = questionsPublishedForComponent($data, $exam);
    $count = (int)$exam['questionCount'];
    if (count($questionsForExam) < $count) respond(['error' => "This assessment needs $count published questions before it can start; " . count($questionsForExam) . ' are available.'], 422);
    shuffle($questionsForExam);
    $questionsForExam = array_slice($questionsForExam, 0, $count);
    $questionsForExam = array_map(fn($question) => sessionQuestionWithOptionOrder($question, !empty(examSecurity($data)['optionShuffleEnabled'])), $questionsForExam);
    $now = time(); $accessToken = secretToken();
    $session = ['id' => id(), 'studentId' => $student['id'], 'examId' => $exam['id'], 'passwordId' => $passwordRecord['id'], 'accessTokenHash' => tokenHash($accessToken), 'correlationId' => (string)($token['correlationId'] ?? ('exam-' . id())), 'startedAt' => date('c', $now), 'endsAt' => date('c', min($now + ((int)$exam['duration'] * 60), strtotime($exam['endAt']))), 'questions' => $questionsForExam, 'answers' => [], 'flagged' => [], 'integrityEvents' => $token['sharedDeviceEvents'] ?? [], 'ipAddress' => clientFingerprint(), 'deviceFingerprint' => $token['deviceFingerprint'] ?? '', 'status' => 'in_progress'];
    $data['sessions'][] = $session; auditEvent($data, 'student', $student['id'], 'exam_started', 'exam_session', $session['id'], ['matricNumber' => $student['matricNumber'], 'course' => $exam['code'], 'correlationId' => $session['correlationId']]); saveData($data); clearSessionCookie('CBT_EXAM_LOGIN'); setSessionCookie('CBT_EXAM_SESSION', $accessToken, strtotime($session['endsAt'])); respond(['session' => sessionPayload($session), 'questions' => publicSessionQuestions($session)]);
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
if ($action === 'audit-archives' && $method === 'GET') {
    auth(true);
    if (!empty($_GET['download'])) {
        $filename = (string)$_GET['download']; $path = auditArchivePath($filename);
        header('Content-Type: application/json; charset=utf-8'); header('Content-Length: ' . (string)filesize($path)); header('Content-Disposition: attachment; filename="' . $filename . '"');
        readfile($path); exit;
    }
    respond(['items' => listAuditArchives(), 'settings' => auditRetentionSettings($data), 'directory' => 'database/backups/']);
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
if ($action === 'reset-student-result' && $method === 'POST') {
    auth(true); $input = body();
    $studentId = (string)($input['studentId'] ?? ''); $sessionId = (string)($input['sessionId'] ?? ''); $semesterId = (string)($input['semesterId'] ?? '');
    if ($studentId === '' || $sessionId === '' || $semesterId === '') respond(['error' => 'Choose a student, academic session, and semester before resetting a calculated result.'], 422);
    $student = findBy($data['students'], 'id', $studentId);
    if (!$student) respond(['error' => 'Student not found.'], 404);
    $existing = null;
    foreach ($data['calculatedResults'] as $item) {
        if (($item['studentId'] ?? '') === $studentId && ($item['sessionId'] ?? '') === $sessionId && ($item['semesterId'] ?? '') === $semesterId) { $existing = $item; break; }
    }
    if (!$existing) respond(['error' => 'There is no saved calculated result for this student and academic period.'], 404);
    $data['calculatedResults'] = array_values(array_filter($data['calculatedResults'], fn($item) => !(($item['studentId'] ?? '') === $studentId && ($item['sessionId'] ?? '') === $sessionId && ($item['semesterId'] ?? '') === $semesterId)));
    auditEvent($data, 'admin', adminActorId(), 'student_result_calculation_reset', 'student_result', $studentId, [
        'matricNumber' => $student['matricNumber'] ?? '', 'sessionId' => $sessionId, 'semesterId' => $semesterId,
        'beforeAfter' => ['calculationStatus' => ['before' => 'Calculated', 'after' => 'Not calculated'], 'semesterGpa' => ['before' => $existing['semesterGpa'] ?? null, 'after' => null], 'cgpa' => ['before' => $existing['cgpa'] ?? null, 'after' => null]]
    ]);
    saveData($data); respond(['ok' => true, 'status' => 'not_calculated']);
}
if ($action === 'component-submission-delete' && $method === 'POST') {
    auth(true); $input = body();
    $resultId = trim((string)($input['resultId'] ?? '')); $reason = trim((string)($input['reason'] ?? '')); $confirmation = trim((string)($input['confirmation'] ?? ''));
    if ($resultId === '') respond(['error' => 'Choose the Test or Exam submission to delete.'], 422);
    if (strlen($reason) < 15) respond(['error' => 'Provide a deletion reason of at least 15 characters.'], 422);
    $result = findBy($data['results'], 'id', $resultId);
    if (!$result) respond(['error' => 'This component submission no longer exists. Refresh the result page and try again.'], 404);
    $student = findBy($data['students'], 'id', (string)($result['studentId'] ?? ''));
    if (!$student) respond(['error' => 'The student for this component submission was not found.'], 404);
    $matricNumber = (string)($student['matricNumber'] ?? '');
    if ($matricNumber === '' || !hash_equals($matricNumber, $confirmation)) respond(['error' => 'Type the student\'s matric number exactly to confirm this deletion.'], 422);
    $exam = findBy($data['exams'], 'id', (string)($result['examId'] ?? '')) ?? [];
    $sessionId = (string)($result['examSessionId'] ?? $result['sessionId'] ?? '');
    $safetyBackup = createBackup($data, 'safety');
    $data['results'] = array_values(array_filter($data['results'], fn($item) => ($item['id'] ?? '') !== $resultId));
    $sessionStillReferenced = $sessionId !== '' && (bool)array_filter($data['results'], fn($item) => (string)($item['examSessionId'] ?? $item['sessionId'] ?? '') === $sessionId);
    if ($sessionId !== '' && !$sessionStillReferenced) {
        $data['sessions'] = array_values(array_filter($data['sessions'], fn($item) => ($item['id'] ?? '') !== $sessionId));
        $data['examFlags'] = array_values(array_filter($data['examFlags'], fn($item) => ($item['sessionId'] ?? '') !== $sessionId));
    }
    $academicSessionId = (string)($result['academicSessionId'] ?? $exam['sessionId'] ?? '');
    $academicSemesterId = (string)($result['academicSemesterId'] ?? $exam['semesterId'] ?? '');
    $beforeCalculations = count($data['calculatedResults']);
    $data['calculatedResults'] = array_values(array_filter($data['calculatedResults'], fn($item) => !(($item['studentId'] ?? '') === ($result['studentId'] ?? '') && ($item['sessionId'] ?? '') === $academicSessionId && ($item['semesterId'] ?? '') === $academicSemesterId)));
    $calculationInvalidated = count($data['calculatedResults']) !== $beforeCalculations;
    auditEvent($data, 'admin', adminActorId(), 'component_submission_deleted', 'exam_submission', $resultId, [
        'matricNumber' => $matricNumber, 'studentName' => $student['fullName'] ?? '', 'course' => $exam['code'] ?? '',
        'courseTitle' => $exam['title'] ?? '', 'component' => $result['component'] ?? ($exam['component'] ?? 'exam'),
        'submissionId' => $resultId, 'examSessionId' => $sessionId, 'submittedAt' => $result['submittedAt'] ?? '', 'rawScore' => $result['rawScore'] ?? null, 'scaledScore' => $result['scaledScore'] ?? $result['score'] ?? null, 'reason' => $reason,
        'safetyBackup' => $safetyBackup['filename'], 'safetyBackupFilename' => $safetyBackup['filename'], 'calculationInvalidated' => $calculationInvalidated,
        'sessionId' => $academicSessionId, 'semesterId' => $academicSemesterId
    ]);
    saveData($data); respond(['ok' => true, 'safetyBackup' => $safetyBackup['filename'], 'calculationInvalidated' => $calculationInvalidated]);
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
    $adminSession = auth(true);
    $platformManagingTenant = (($adminSession['scope'] ?? '') === 'platform');
    if (!canManageCurrentInstitutionSettings($adminSession)) respond(['error' => 'Select an institution from the Institutions panel before changing tenant settings.'], 403);
    $previousSettings = $data['settings']; $input = body(); $scale = $input['gradingScale'] ?? ($data['settings']['gradingScale'] ?? DEFAULT_GRADING_SCALE);
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
    if (array_key_exists('studentPortalSetupMode', $input)) {
        if (!canManageCurrentInstitutionSettings($adminSession)) respond(['error' => 'Only this institution’s Admin can change student portal availability.'], 403);
        if (!is_bool($input['studentPortalSetupMode'])) respond(['error' => 'Choose whether assessment setup mode is on or off.'], 422);
        $data['settings']['studentPortalSetupMode'] = $input['studentPortalSetupMode'];
    }
    if (array_key_exists('backup', $input)) {
        if (!canManageCurrentInstitutionSettings($adminSession)) respond(['error' => 'Only this institution’s Admin can change automatic backup settings.'], 403);
        if (!is_array($input['backup'])) respond(['error' => 'Provide valid automatic backup settings.'], 422);
        $time = (string)($input['backup']['time'] ?? '');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) respond(['error' => 'Choose a valid daily backup time.'], 422);
        $retention = filter_var($input['backup']['retentionCount'] ?? null, FILTER_VALIDATE_INT);
        if ($retention === false || $retention < 1 || $retention > 365) respond(['error' => 'Backup retention must be between 1 and 365 files.'], 422);
        $backup = backupSettings($data); $backup['enabled'] = !empty($input['backup']['enabled']); $backup['time'] = $time; $backup['retentionCount'] = $retention;
        $data['settings']['backup'] = $backup; applyBackupRetention($data);
    }
    if (array_key_exists('auditRetention', $input)) {
        if (!canManageCurrentInstitutionSettings($adminSession)) respond(['error' => 'Only this institution’s Admin can change audit-log retention.'], 403);
        $months = filter_var($input['auditRetention']['months'] ?? null, FILTER_VALIDATE_INT);
        if ($months === false || $months < 1 || $months > 120) respond(['error' => 'Audit-log retention must be between 1 and 120 months.'], 422);
        $time = (string)($input['auditRetention']['time'] ?? '');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) respond(['error' => 'Choose a valid daily audit archive time.'], 422);
        $auditRetention = auditRetentionSettings($data); $auditRetention['months'] = $months; $auditRetention['time'] = $time; $auditRetention['lastRunDate'] = '';
        $data['settings']['auditRetention'] = $auditRetention;
    }
    $beforeAfter = auditBeforeAfter($previousSettings, $data['settings'], ['gradingScale', 'integrityPolicy', 'examSecurity', 'resultLogoUrl', 'studentPortalSetupMode', 'backup', 'auditRetention']);
    auditEvent($data, 'admin', adminActorId(), 'settings_updated', 'settings', 'integrity_and_grading', ['changed' => array_keys($input), 'beforeAfter' => $beforeAfter]); saveData($data);
    if ($platformManagingTenant && mysqlStorageEnabled()) {
        $institution = mysqlRequestInstitution();
        mysqlPlatformAudit((string)($adminSession['email'] ?? adminActorId()), 'tenant_settings_updated', (int)$institution['id'], 'institution_settings', (string)$institution['slug'], 'success', (string)($adminSession['correlationId'] ?? ''), ['changed' => array_keys($input), 'institutionSlug' => $institution['slug']], $beforeAfter);
    }
    if (array_key_exists('auditRetention', $input)) runScheduledAuditArchival($data, true);
    respond(['settings' => $data['settings']]);
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
    respond(['stats' => ['students' => count(array_filter($data['students'], fn($student) => $student['active'])), 'activeExams' => count(array_filter($data['exams'], fn($exam) => publicExam($exam)['active'])), 'completedToday' => count($completed), 'averageScore' => $average], 'activity' => array_slice($recent, 0, 5), 'popular' => array_slice($popular, 0, 8), 'recent' => $recent, 'scoreDistribution' => $distribution, 'courseOutcomes' => $visibleOutcomePage, 'courseOutcomesMeta' => ['page' => $outcomePage, 'pageSize' => $outcomePageSize, 'total' => $outcomeTotal, 'pages' => $outcomePages], 'hiddenCourseOutcomes' => array_map(fn($outcome) => ['examId' => $outcome['examId'], 'code' => $outcome['code'], 'title' => $outcome['title'], 'componentLabel' => $outcome['componentLabel']], $hiddenOutcomes), 'passScore' => $passScore, 'studentPortalSetupMode' => !empty($data['settings']['studentPortalSetupMode'])]);
}

if ($action === 'dashboard-portal-mode' && $method === 'POST') {
    auth(true); $input = body();
    if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) respond(['error' => 'Choose whether student portal setup mode is on or off.'], 422);
    $enabled = $input['enabled']; $data['settings']['studentPortalSetupMode'] = $enabled;
    auditEvent($data, 'admin', adminActorId(), $enabled ? 'student_portal_setup_mode_enabled' : 'student_portal_setup_mode_disabled', 'student_portal', 'setup_mode', ['enabled' => $enabled]);
    saveData($data); respond(['enabled' => $enabled]);
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
    if (isSafetyBackupFilename($filename)) respond(['error' => 'Safety backups are protected from manual deletion by the retention policy.'], 403);
    if (!unlink($path)) respond(['error' => 'Unable to delete the backup file.'], 500);
    if (mysqlStorageEnabled()) mysqlDeleteBackupRecord(backupInstitutionId(), $filename);
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
