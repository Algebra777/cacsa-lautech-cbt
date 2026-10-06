<?php
declare(strict_types=1);

/** One-shot worker. Run it repeatedly through Task Scheduler/systemd, never via Apache. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';
$root = dirname(__DIR__); $environment = mysqlMigrationEnv($root);
foreach ($environment as $key => $value) if (preg_match('/^(?:GEMINI_|OPENROUTER_|CBT_)/', $key)) putenv($key . '=' . $value);
defined('PDF_IMPORT_MAX_BYTES') || define('PDF_IMPORT_MAX_BYTES', 10 * 1024 * 1024);
defined('PDF_IMPORT_MAX_PAGES') || define('PDF_IMPORT_MAX_PAGES', 150);
defined('PDF_IMPORT_MAX_QUESTIONS') || define('PDF_IMPORT_MAX_QUESTIONS', 100);
defined('OPENROUTER_PDF_IMPORT_MAX_BYTES') || define('OPENROUTER_PDF_IMPORT_MAX_BYTES', 10 * 1024 * 1024);
defined('OPENROUTER_PDF_IMPORT_MAX_PAGES') || define('OPENROUTER_PDF_IMPORT_MAX_PAGES', 150);
defined('OPENROUTER_PDF_IMPORT_MAX_QUESTIONS') || define('OPENROUTER_PDF_IMPORT_MAX_QUESTIONS', 100);
if (is_file($root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) require_once $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once $root . DIRECTORY_SEPARATOR . 'pdf_import_queue.php';

function workerAudit(PDO $pdo, array $job, string $action, string $outcome, array $metadata): void {
    $statement = $pdo->prepare('INSERT INTO audit_events (institution_id,id,timestamp_at,actor_type,actor_id,action_type,target_type,target_id,outcome,correlation_id,metadata_json) VALUES (:institution_id,:id,UTC_TIMESTAMP(6),\'system\',\'pdf-import-worker\',:action,\'course\',:course_id,:outcome,:correlation_id,:metadata)');
    $statement->execute(['institution_id'=>(int)$job['institution_id'],'id'=>'pdf-job-' . bin2hex(random_bytes(10)),'action'=>$action,'course_id'=>(string)$job['course_id'],'outcome'=>$outcome,'correlation_id'=>'pdf-import-' . (string)$job['id'],'metadata'=>json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
}
function workerSourcePath(array $job, string $root): ?string {
    $key = str_replace('\\', '/', ltrim((string)$job['source_key'], '/'));
    if (!str_starts_with($key, 'uploads/pdf-imports/') || str_contains($key, '..')) return null;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key); $base = realpath($root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pdf-imports'); $parent = realpath(dirname($path));
    return $base !== false && $parent !== false && str_starts_with($parent . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR) ? $path : null;
}
function workerExpireSource(PDO $pdo, array $job): void {
    $pdo->prepare("UPDATE institution_assets SET asset_state='unreferenced',unreferenced_at=UTC_TIMESTAMP(6),expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 24 HOUR) WHERE institution_id=:institution_id AND storage_key=:storage_key AND asset_state='active'")->execute(['institution_id'=>$job['institution_id'],'storage_key'=>$job['source_key']]);
}

$pdo = mysqlMigrationPdo($root); $lock = $pdo->query("SELECT GET_LOCK('berevion_pdf_import_worker', 0)")->fetchColumn();
if ((int)$lock !== 1) { echo json_encode(['pass'=>true,'idle'=>true,'reason'=>'another_worker_is_running']) . PHP_EOL; exit; }
try {
    $pdo->beginTransaction();
    $expired = $pdo->query("SELECT * FROM pdf_import_jobs WHERE status='review_ready' AND review_expires_at <= UTC_TIMESTAMP(6) FOR UPDATE")->fetchAll();
    foreach ($expired as $job) { $pdo->prepare("UPDATE pdf_import_jobs SET status='cancelled',error_code='review_expired',error_message='The review window expired before import.',source_expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 24 HOUR),updated_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id")->execute(['institution_id'=>$job['institution_id'],'id'=>$job['id']]); workerExpireSource($pdo,$job); workerAudit($pdo,$job,'pdf_import_review_expired','success',['jobId'=>$job['id']]); }
    $pdo->exec("UPDATE pdf_import_jobs SET status='queued',available_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE status='running' AND started_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 20 MINUTE)");
    $claim = $pdo->query("SELECT * FROM pdf_import_jobs WHERE status='queued' AND available_at <= UTC_TIMESTAMP(6) ORDER BY created_at ASC LIMIT 1 FOR UPDATE")->fetch();
    if (!$claim) { $pdo->commit(); echo json_encode(['pass'=>true,'idle'=>true,'expiredReviews'=>count($expired)]) . PHP_EOL; exit; }
    $pdo->prepare("UPDATE pdf_import_jobs SET status='running',attempt_count=attempt_count+1,started_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id")->execute(['institution_id'=>$claim['institution_id'],'id'=>$claim['id']]);
    $claim['attempt_count'] = (int)$claim['attempt_count'] + 1; $pdo->commit();

    $portal = $pdo->prepare('SELECT portal_title FROM institution_branding WHERE institution_id=:institution_id'); $portal->execute(['institution_id'=>$claim['institution_id']]); $portalTitle = (string)($portal->fetchColumn() ?: 'CBT Question Import');
    $path = workerSourcePath($claim, $root); if ($path === null || !is_file($path) || !hash_equals((string)$claim['source_sha256'], hash_file('sha256', $path))) throw new PdfImportJobFailure('source_missing_or_changed', 'The uploaded PDF source is missing or no longer matches the queued file.', false);
    $testMode = (string)getenv('CBT_APP_ENV') === 'testing' ? (string)getenv('CBT_PDF_IMPORT_TEST_MODE') : '';
    if ($testMode === 'page_limit') throw new PdfImportJobFailure('page_limit', 'This PDF has more than 150 pages. Split it by topic or chapter, keeping questions and answer keys together.', false);
    if ($testMode === 'retryable') throw new PdfImportJobFailure('provider_unreachable', 'Gemini could not be reached. The queue will retry automatically.', true);
    $parsed = $testMode === 'review_ready'
        ? ['pages' => 1, 'items' => [['questionText' => 'Which option is the test answer?', 'options' => ['First option', 'Second option'], 'correctOptionIndexes' => [1], 'confidence' => 'high'], ['questionText' => 'Which answer key is uncertain?', 'options' => ['A', 'B'], 'correctOptionIndexes' => [], 'confidence' => 'low']], 'limits' => pdfQueueLimits((string)$claim['provider'])]
        : pdfQueueExtract($path, (string)$claim['provider'], $portalTitle);
    $low = count(array_filter($parsed['items'], static fn(array $item): bool => ($item['confidence'] ?? 'low') === 'low'));
    $pdo->beginTransaction();
    $update = $pdo->prepare("UPDATE pdf_import_jobs SET status='review_ready',page_count=:page_count,parsed_items_json=:items,low_confidence_count=:low_confidence,completed_at=UTC_TIMESTAMP(6),review_expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 7 DAY),updated_at=UTC_TIMESTAMP(6),error_code=NULL,error_message=NULL WHERE institution_id=:institution_id AND id=:id AND status='running'");
    $update->execute(['page_count'=>$parsed['pages'],'items'=>json_encode($parsed['items'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),'low_confidence'=>$low,'institution_id'=>$claim['institution_id'],'id'=>$claim['id']]);
    if ($update->rowCount()) workerAudit($pdo,$claim,(string)$claim['provider'].'_pdf_import_review_ready','success',['jobId'=>$claim['id'],'pages'=>$parsed['pages'],'parsedQuestions'=>count($parsed['items']),'lowConfidence'=>$low]);
    $pdo->commit(); echo json_encode(['pass'=>true,'jobId'=>$claim['id'],'status'=>'review_ready','pages'=>$parsed['pages'],'questions'=>count($parsed['items']),'lowConfidence'=>$low]) . PHP_EOL;
} catch (PdfImportJobFailure $error) {
    if (isset($claim) && is_array($claim)) {
        if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->beginTransaction();
        $retry = $error->retryable && (int)$claim['attempt_count'] < (int)$claim['max_attempts']; $delay = min(900, 30 * (2 ** max(0, (int)$claim['attempt_count'] - 1)));
        if ($retry) $pdo->prepare("UPDATE pdf_import_jobs SET status='queued',available_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL {$delay} SECOND),error_code=:code,error_message=:message,updated_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id AND status='running'")->execute(['code'=>$error->safeCode,'message'=>$error->getMessage(),'institution_id'=>$claim['institution_id'],'id'=>$claim['id']]);
        else { $pdo->prepare("UPDATE pdf_import_jobs SET status='failed',completed_at=UTC_TIMESTAMP(6),source_expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 24 HOUR),error_code=:code,error_message=:message,updated_at=UTC_TIMESTAMP(6) WHERE institution_id=:institution_id AND id=:id AND status='running'")->execute(['code'=>$error->safeCode,'message'=>$error->getMessage(),'institution_id'=>$claim['institution_id'],'id'=>$claim['id']]); workerExpireSource($pdo,$claim); }
        workerAudit($pdo,$claim,(string)$claim['provider'].'_pdf_import_' . ($retry ? 'retry_scheduled' : 'failed'),$retry?'failure':'failure',['jobId'=>$claim['id'],'errorCode'=>$error->safeCode,'attempt'=>(int)$claim['attempt_count'],'retry'=>$retry]); $pdo->commit();
        echo json_encode(['pass'=>false,'jobId'=>$claim['id'],'status'=>$retry?'queued':'failed','error'=>$error->getMessage(),'retryScheduled'=>$retry]) . PHP_EOL;
    } else throw $error;
} finally { $pdo->query("DO RELEASE_LOCK('berevion_pdf_import_worker')"); }
