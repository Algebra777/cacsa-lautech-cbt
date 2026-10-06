<?php
declare(strict_types=1);

/** Disposable end-to-end verification for Algebra Stage 1.
 * It creates its own tenant, uses the deployed HTTP API, proves tenant
 * scoping and Draft-only import, writes a safety manifest, then removes only
 * its own tenant rows. Gemini is called for the genuine drafting/insight path.
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

$root = dirname(__DIR__); $pdo = mysqlMigrationPdo($root); $id = 0;
$slug = 'algebra-stage1-test-' . gmdate('Ymdhis'); $tokenA = bin2hex(random_bytes(32)); $tokenB = bin2hex(random_bytes(32));
$base = 'http://localhost/BEREVION/i/' . rawurlencode($slug) . '/api.php?action=';
$tracked = ['students','courses','questions','question_options','question_publish_targets','assessment_attempts','component_submissions','audit_events'];
$hash = static function(PDO $db, string $table): string { $q=$db->query("SELECT * FROM {$table} WHERE institution_id=1 ORDER BY 1"); return hash('sha256', json_encode($q->fetchAll(), JSON_UNESCAPED_SLASHES)); };
$before=[]; foreach ($tracked as $table) $before[$table]=$hash($pdo,$table);
function algebraHttp(string $url, string $token, string $method='GET', ?array $payload=null): array {
    $headers=['Accept: application/json'];
    if ($method === 'GET') $headers[]='Cookie: CBT_ADMIN_SESSION='.$token;
    else { $csrf=algebraCsrf(dirname($url).'/api.php?action=auth-csrf',$token); $headers[]='Cookie: CBT_ADMIN_SESSION='.$token.'; CBT_CSRF='.$csrf; $headers[]='X-CSRF-Token: '.$csrf; $headers[]='Content-Type: application/json'; }
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$payload===null?'':json_encode($payload,JSON_UNESCAPED_SLASHES),'ignore_errors'=>true,'timeout'=>180]]);
    $raw=file_get_contents($url,false,$context); preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);
    return ['status'=>(int)($m[1]??0),'body'=>json_decode((string)$raw,true) ?: []];
}
function algebraCsrf(string $url, string $token): string {
    $context=stream_context_create(['http'=>['method'=>'GET','header'=>'Accept: application/json\r\nCookie: CBT_ADMIN_SESSION='.$token,'ignore_errors'=>true,'timeout'=>30]]);
    $raw=file_get_contents($url,false,$context); $body=json_decode((string)$raw,true); $csrf=(string)($body['csrfToken']??''); if($csrf==='') throw new RuntimeException('Could not create Algebra test CSRF token.'); return $csrf;
}
function deleteAlgebraTenant(PDO $pdo,int $id,string $slug,string $root): void {
    if($id<1)return; $dir=$root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'institution-'.$id; if(!is_dir($dir))mkdir($dir,0700,true); $manifest=$dir.DIRECTORY_SEPARATOR.'algebra-stage1-test-safety-'.gmdate('Ymd-His').'.json'; file_put_contents($manifest,json_encode(['kind'=>'algebra-stage1-disposable-cleanup','institutionId'=>$id,'slug'=>$slug,'reason'=>'Stage 1 Algebra verification completed','createdAt'=>gmdate('c')],JSON_PRETTY_PRINT),LOCK_EX);
    $pdo->beginTransaction(); try {
        foreach(['algebra_requests','audit_events','rate_limit_records','question_publish_targets','question_options','questions','component_submissions','assessment_attempts','course_components','courses','admin_sessions','admin_users','role_permissions','roles','institution_branding'] as $table) $pdo->prepare("DELETE FROM {$table} WHERE institution_id=?")->execute([$id]);
        $pdo->prepare('DELETE FROM institutions WHERE id=? AND slug=?')->execute([$id,$slug]); $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}
try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO institutions (name,slug,active,created_at) VALUES (?,?,1,UTC_TIMESTAMP(6))')->execute(['Algebra Stage 1 Test',$slug]); $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO institution_branding (institution_id,display_name,portal_title,logo_path,favicon_path,accent_source_color,primary_color,accent_color,nav_label,assessment_label,footer_primary,footer_secondary,footer_legal,result_sheet_title,newsletter_sender_name,support_email,updated_at) VALUES (?,'Algebra Stage 1 Test','Algebra Stage 1 Test','CACSA%20Logo.jpeg','favicon.php','#16774d','#16774d','#105839','Algebra Stage 1 Test','Assessment centre','Disposable test','Algebra verification','© test','Algebra Stage 1 Test','Algebra Test','algebra-test@example.invalid',UTC_TIMESTAMP(6))")->execute([$id]);
    $pdo->prepare("INSERT INTO roles (institution_id,id,name,description,max_users,system_locked) VALUES (?,'admin','Admin', 'Disposable Algebra administrator',2,1)")->execute([$id]); foreach(['questions','results'] as $permission)$pdo->prepare('INSERT INTO role_permissions (institution_id,role_id,permission) VALUES (?,\'admin\',?)')->execute([$id,$permission]);
    foreach([['algebra-admin-a',$tokenA],['algebra-admin-b',$tokenB]] as [$admin,$token]) { $email=$admin.'@example.invalid'; $pdo->prepare("INSERT INTO admin_users (institution_id,id,role_id,name,email,password_hash,active,verified,must_change_password,created_at) VALUES (?,?,'admin',?,?, 'not-used',1,1,0,UTC_TIMESTAMP(6))")->execute([$id,$admin,$admin,$email]); $pdo->prepare("INSERT INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at) VALUES (?,?,?,?,?,'admin','[\"questions\",\"results\"]','algebra-stage1-test',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'2099-12-31 23:59:59.000000')")->execute([$id,hash('sha256',$token),$admin,$email,$admin]); }
    $pdo->prepare("INSERT INTO courses (institution_id,id,code,title,course_unit,test_max_mark,exam_max_mark,legacy_exam_only,created_at) VALUES (?,'algebra-course','ALG 101','Algebra Test Course',3,30,70,0,UTC_TIMESTAMP(6))")->execute([$id]);
    $pdo->commit();

    // A CACSA course id guessed from another tenant must fail before Gemini is called.
    $cacsaCourse=(string)$pdo->query('SELECT id FROM courses WHERE institution_id=1 ORDER BY id LIMIT 1')->fetchColumn();
    $cross=algebraHttp($base.'algebra-question-draft',$tokenA,'POST',['courseId'=>$cacsaCourse,'topic'=>'cross tenant probe','difficulty'=>'easy','count'=>1,'singleCount'=>1,'multipleCount'=>0]);
    if($cross['status']!==422)throw new RuntimeException('Cross-tenant course guess was not rejected: '.json_encode($cross));

    $draft=algebraHttp($base.'algebra-question-draft',$tokenB,'POST',['courseId'=>'algebra-course','topic'=>'linear equations','difficulty'=>'easy','count'=>1,'singleCount'=>1,'multipleCount'=>0,'brief'=>'Use a simple solvable equation.']);
    if($draft['status']!==200||empty($draft['body']['requestId'])||count($draft['body']['items']??[])!==1)throw new RuntimeException('Gemini draft flow failed: '.json_encode($draft));
    $item=$draft['body']['items'][0]; $item['publishedTo']=['test','exam']; $item['status']='published'; // hostile client fields must be ignored.
    $import=algebraHttp($base.'algebra-question-import',$tokenB,'POST',['requestId'=>$draft['body']['requestId'],'courseId'=>'algebra-course','items'=>[$item]]);
    if($import['status']!==201||(string)($import['body']['status']??'')!=='draft')throw new RuntimeException('Draft-only import failed: '.json_encode($import));
    $question=$pdo->prepare("SELECT q.status,COUNT(t.question_id) targets FROM questions q LEFT JOIN question_publish_targets t ON t.institution_id=q.institution_id AND t.question_id=q.id WHERE q.institution_id=? AND q.course_id='algebra-course' GROUP BY q.id,q.status");$question->execute([$id]);$question=$question->fetch();if(($question['status']??'')!=='draft'||(int)($question['targets']??-1)!==0)throw new RuntimeException('Manipulated Algebra import created a published or targeted question.');

    $insight=algebraHttp($base.'algebra-performance-insight',$tokenB,'POST',['question'=>'Summarise the available performance data cautiously and state any limitations.']);
    if($insight['status']!==200||empty($insight['body']['summary'])||empty($insight['body']['findings']))throw new RuntimeException('Performance-insight flow failed: '.json_encode($insight));
    $request=$pdo->prepare("SELECT input_json FROM algebra_requests WHERE institution_id=? AND capability='performance_insight' ORDER BY created_at DESC LIMIT 1");$request->execute([$id]);$input=(string)$request->fetchColumn();if($input===''||str_contains($input,'CACSA')||str_contains($input,'cacsalautech'))throw new RuntimeException('Performance insight request contained cross-tenant content.');
    foreach($before as $table=>$value)if(!hash_equals($value,$hash($pdo,$table)))throw new RuntimeException("CACSA {$table} changed during Algebra verification.");
    $result=['pass'=>true,'tenantSlug'=>$slug,'crossTenantCourseGuess'=>'blocked-422','draftReview'=>'generated','draftOnlyImport'=>true,'manipulatedPublishFields'=>'ignored','performanceInsight'=>'aggregate-only','cacsaHashes'=>'unchanged'];
} finally {
    if($pdo->inTransaction())$pdo->rollBack(); deleteAlgebraTenant($pdo,$id,$slug,$root);
}
echo json_encode($result??['pass'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
