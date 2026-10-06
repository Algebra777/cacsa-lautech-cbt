<?php
declare(strict_types=1);

/**
 * Stage 1 disposable-tenant HTTP load harness.
 *
 * Usage:
 *   php scripts/stage1_load_test.php <tenant-slug> health <concurrency> [base-url]
 *   php scripts/stage1_load_test.php <tenant-slug> autosave <concurrency> [base-url]
 *   php scripts/stage1_load_test.php <tenant-slug> dashboard|results|audit <concurrency> [base-url]
 *
 * The guard deliberately refuses CACSA and any non-disposable tenant.  It is
 * a verification tool only; it never creates or changes production data.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

[$script, $slug, $mode, $concurrency, $baseUrl] = array_pad($argv, 5, null);
if (!is_string($slug) || !preg_match('/^perf-stage[123]-[a-z0-9-]+$/', $slug)) {
    fwrite(STDERR, "Refusing: use a disposable perf-stage1-*, perf-stage2-*, or perf-stage3-* tenant slug.\n"); exit(2);
}
if (!in_array($mode, ['health', 'autosave', 'dashboard', 'results', 'audit'], true) || !ctype_digit((string)$concurrency) || (int)$concurrency < 1) {
    fwrite(STDERR, "Usage: php scripts/stage1_load_test.php <tenant-slug> <health|autosave|dashboard|results|audit> <concurrency> [base-url]\n"); exit(2);
}
$baseUrl ??= 'http://localhost/BEREVION';
$baseUrl = rtrim($baseUrl, '/');
$sharedIp = (string)getenv('STAGE1_LOAD_SHARED_IP') === '1';
$pdo = mysqlMigrationPdo(dirname(__DIR__));
$tenant = $pdo->prepare('SELECT id FROM institutions WHERE slug = :slug AND id <> 1 AND deleted_at IS NULL');
$tenant->execute(['slug' => $slug]); $institutionId = (int)($tenant->fetchColumn() ?: 0);
if ($institutionId < 2) { fwrite(STDERR, "Refusing: disposable test institution was not found.\n"); exit(2); }

$count = (int)$concurrency;
$requests = [];
if ($mode === 'health') {
    for ($i = 0; $i < $count; $i++) {
        $ip = $sharedIp ? '198.18.255.1' : '198.18.' . intdiv($i, 250) . '.' . (($i % 250) + 1);
        $requests[] = ['url' => $baseUrl . '/i/' . rawurlencode($slug) . '/api.php?action=health', 'headers' => ['X-Forwarded-For: ' . $ip]];
    }
} elseif ($mode === 'autosave') {
    // Use the deliberately reserved burst-attempt-31..60 fixture range.  A
    // lexical LIMIT would otherwise mix 1, 10, 100-like identifiers and make
    // the post-run persistence assertion meaningless.
    $attempts = $pdo->prepare("SELECT a.id, q.question_id FROM assessment_attempts a JOIN attempt_question_snapshots q ON q.institution_id = a.institution_id AND q.attempt_id = a.id WHERE a.institution_id = :institution_id AND a.id REGEXP '^burst-attempt-(3[1-9]|[4-5][0-9]|60)$' AND a.status = 'in_progress' GROUP BY a.id, q.question_id ORDER BY CAST(SUBSTRING(a.id, 15) AS UNSIGNED) LIMIT {$count}");
    $attempts->execute(['institution_id' => $institutionId]); $rows = $attempts->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < $count) { fwrite(STDERR, "Refusing: {$count} valid in-progress disposable attempts with question snapshots are required.\n"); exit(2); }
    $token = 'stage1-burst-token';
    // assessment_attempts intentionally retains the immutable attempt snapshot
    // alongside indexed columns. Keep the disposable fixture coherent in both
    // representations, exactly as session-start does in the application.
    $tokenHash = hash('sha256', $token);
    $setToken = $pdo->prepare("UPDATE assessment_attempts SET access_token_hash = :token_hash, status = 'in_progress', ends_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 MINUTE), snapshot_json = JSON_SET(snapshot_json, '$.accessTokenHash', :snapshot_token_hash, '$.status', 'in_progress', '$.endsAt', DATE_FORMAT(DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 MINUTE), '%Y-%m-%dT%H:%i:%s.000Z')) WHERE institution_id = :institution_id AND id LIKE 'burst-attempt-%'");
    $setToken->execute(['token_hash' => $tokenHash, 'snapshot_token_hash' => $tokenHash, 'institution_id' => $institutionId]);
    foreach ($rows as $index => $row) {
        $requests[] = [
            'url' => $baseUrl . '/i/' . rawurlencode($slug) . '/api.php?action=session-answer',
            'body' => json_encode(['sessionId' => $row['id'], 'questionId' => $row['question_id'], 'answers' => [0]], JSON_THROW_ON_ERROR),
            'headers' => [
                'Content-Type: application/json', 'X-CSRF-Token: stage1-load-csrf',
                'Cookie: CBT_EXAM_SESSION=' . $token . '; CBT_CSRF=stage1-load-csrf',
                'X-Forwarded-For: ' . ($sharedIp ? '198.19.255.1' : '198.19.' . intdiv($index, 250) . '.' . (($index % 250) + 1)),
            ],
        ];
    }
} else {
    $action = $mode === 'dashboard' ? 'dashboard' : ($mode === 'results' ? 'results&page=1&pageSize=25' : 'audit-events&page=1&pageSize=20');
    for ($i = 0; $i < $count; $i++) {
        $requests[] = [
            'url' => $baseUrl . '/i/' . rawurlencode($slug) . '/api.php?action=' . $action,
            'headers' => [
                'Cookie: CBT_ADMIN_SESSION=stage3-admin-token-' . ($i + 1),
                'X-Forwarded-For: 198.20.' . intdiv($i, 250) . '.' . (($i % 250) + 1),
            ],
        ];
    }
}

if (!function_exists('curl_multi_init')) { fwrite(STDERR, "PHP cURL extension is required for this load test.\n"); exit(2); }
$startedAt = microtime(true); $multi = curl_multi_init(); $handles = [];
foreach ($requests as $index => $request) {
    $handle = curl_init($request['url']);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $request['headers']]);
    if (isset($request['body'])) { curl_setopt($handle, CURLOPT_POST, true); curl_setopt($handle, CURLOPT_POSTFIELDS, $request['body']); }
    curl_multi_add_handle($multi, $handle); $handles[$index] = $handle;
}
do { $status = curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 1.0); } while ($running && $status === CURLM_OK);
$results = [];
foreach ($handles as $handle) {
    $results[] = ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'seconds' => (float)curl_getinfo($handle, CURLINFO_TOTAL_TIME), 'error' => curl_error($handle)];
    curl_multi_remove_handle($multi, $handle); curl_close($handle);
}
curl_multi_close($multi); $elapsed = microtime(true) - $startedAt;
$times = array_column($results, 'seconds'); sort($times); $at = static fn(float $percentile): float => $times[(int)floor((count($times) - 1) * $percentile)];
echo json_encode([
    'tenantSlug' => $slug, 'tenantId' => $institutionId, 'mode' => $mode, 'requests' => count($results),
    'ok' => count(array_filter($results, static fn(array $result): bool => $result['status'] === 200)),
    'errors' => count(array_filter($results, static fn(array $result): bool => $result['status'] !== 200)),
    'wallSeconds' => round($elapsed, 3), 'p50Seconds' => round($at(.50), 3), 'p95Seconds' => round($at(.95), 3),
    'p99Seconds' => round($at(.99), 3), 'maxSeconds' => round(max($times), 3),
    'non200' => array_values(array_filter($results, static fn(array $result): bool => $result['status'] !== 200)),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
