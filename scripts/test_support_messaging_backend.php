<?php
declare(strict_types=1);

/* Disposable HTTP verification for support-message storage and access scope. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mysql_data_store.php';

$root = dirname(__DIR__); $pdo = mysqlMigrationPdo($root);
$suffix = gmdate('YmdHis');
$slugs = ['support-a-test-' . $suffix, 'support-b-test-' . $suffix];
$ids = []; $platformToken = bin2hex(random_bytes(32)); $platformHash = hash('sha256', $platformToken);
$tenantTokens = [];

function supportBackendHash(PDO $pdo, string $table): string {
    $statement = $pdo->query("SELECT institution_id,id FROM {$table} WHERE institution_id=1 ORDER BY id");
    return hash('sha256', json_encode($statement->fetchAll(), JSON_UNESCAPED_SLASHES));
}
function supportHttp(string $url, string $method = 'GET', string $token = '', ?array $body = null, string $csrf = '', string $secondaryToken = ''): array {
    $curl = curl_init($url); $headers = ['Accept: application/json'];
    if ($token !== '') $headers[] = 'Cookie: CBT_ADMIN_SESSION=' . rawurlencode($token) . ($secondaryToken !== '' ? '; CBT_ADMIN_SESSION=' . rawurlencode($secondaryToken) : '') . ($csrf !== '' ? '; CBT_CSRF=' . rawurlencode($csrf) : '');
    if ($csrf !== '') $headers[] = 'X-CSRF-Token: ' . $csrf;
    if ($body !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25]);
    $raw = curl_exec($curl); if ($raw === false) throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $headerBytes = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE); curl_close($curl);
    $content = substr($raw, $headerBytes); return ['status'=>$status,'body'=>json_decode($content, true) ?: [],'raw'=>$content];
}
function supportPost(string $base, string $action, string $token, array $body): array {
    $csrf = supportHttp($base . 'auth-csrf', 'GET', $token);
    if ($csrf['status'] !== 200 || empty($csrf['body']['csrfToken'])) throw new RuntimeException('Could not acquire a CSRF token.');
    return supportHttp($base . $action, 'POST', $token, $body, (string)$csrf['body']['csrfToken']);
}
function supportCleanup(PDO $pdo, string $root, array $ids, array $slugs): void {
    foreach ($ids as $index => $institutionId) {
        if ($institutionId < 2 || !str_starts_with($slugs[$index], 'support-')) throw new RuntimeException('Refusing unsafe support-test cleanup target.');
        mysqlInstitutionSafetyBackup($pdo, $institutionId, $slugs[$index], 'Disposable support-messaging verification cleanup', 'support-backend-test');
        $directory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'institution-' . $institutionId;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Could not create test cleanup directory.');
        $snapshot = ['kind'=>'support-messaging-backend-test-safety','institutionId'=>$institutionId,'slug'=>$slugs[$index],'createdAt'=>gmdate('c'),'support_threads'=>[],'support_messages'=>[]];
        foreach (['support_threads','support_messages'] as $table) { $statement=$pdo->prepare("SELECT * FROM {$table} WHERE institution_id=:institution_id");$statement->execute(['institution_id'=>$institutionId]);$snapshot[$table]=$statement->fetchAll(); }
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'support-backend-test-safety-' . gmdate('Ymd-His') . '.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE platform_audit_events SET target_institution_id=NULL,target_type='deleted_support_test_institution' WHERE target_institution_id=:institution_id")->execute(['institution_id'=>$institutionId]);
            foreach (['backup_records','support_messages','support_threads','audit_events','rate_limit_records','admin_sessions','admin_users','role_permissions','roles','institution_settings','institution_branding'] as $table) { $delete=$pdo->prepare("DELETE FROM {$table} WHERE institution_id=:institution_id");$delete->execute(['institution_id'=>$institutionId]); }
            $delete=$pdo->prepare('DELETE FROM institutions WHERE id=:id AND slug=:slug');$delete->execute(['id'=>$institutionId,'slug'=>$slugs[$index]]);if($delete->rowCount()!==1)throw new RuntimeException('Support-test cleanup target disappeared.');
            $pdo->commit();
        } catch (Throwable $error) { if($pdo->inTransaction())$pdo->rollBack();throw $error; }
    }
}

$cleanupArgument = (string)($argv[1] ?? '');
if (preg_match('/^--cleanup-ids=([0-9]+(?:,[0-9]+)*)$/', $cleanupArgument, $match)) {
    $requestedIds = array_values(array_unique(array_map('intval', explode(',', $match[1]))));
    $placeholders = implode(',', array_fill(0, count($requestedIds), '?'));
    $statement = $pdo->prepare("SELECT id,slug FROM institutions WHERE id IN ({$placeholders}) ORDER BY id");
    $statement->execute($requestedIds); $rows = $statement->fetchAll();
    if (count($rows) !== count($requestedIds)) throw new RuntimeException('A requested disposable cleanup target does not exist.');
    $cleanupIds = []; $cleanupSlugs = [];
    foreach ($rows as $row) {
        if ((int)$row['id'] < 2 || !preg_match('/^support-(?:a|b)-test-[0-9]{14}$/', (string)$row['slug'])) throw new RuntimeException('Refusing non-disposable institution cleanup target.');
        $cleanupIds[] = (int)$row['id']; $cleanupSlugs[] = (string)$row['slug'];
    }
    supportCleanup($pdo, $root, $cleanupIds, $cleanupSlugs);
    echo json_encode(['cleaned' => $cleanupIds, 'slugs' => $cleanupSlugs], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$cacsaBefore=[]; foreach(['students','courses','questions','component_submissions','audit_events'] as $table)$cacsaBefore[$table]=supportBackendHash($pdo,$table);
try {
    $platform=$pdo->query("SELECT id,email,name FROM platform_admin_users WHERE id='platform-superadmin' AND active=1 LIMIT 1")->fetch();
    if(!$platform)throw new RuntimeException('Active platform Super Admin is unavailable.');
    $now=gmdate('Y-m-d H:i:s.u');
    $pdo->prepare('INSERT INTO platform_admin_sessions (token_hash,user_id,email,name,correlation_id,created_at,last_seen_at,expires_at) VALUES (:token_hash,:user_id,:email,:name,\'support-backend-test\',:created_at,:last_seen_at,\'2099-12-31 23:59:59.000000\')')->execute(['token_hash'=>$platformHash,'user_id'=>$platform['id'],'email'=>$platform['email'],'name'=>$platform['name'],'created_at'=>$now,'last_seen_at'=>$now]);
    foreach($slugs as $index=>$slug){
        $pdo->beginTransaction(); try {
            $pdo->prepare('INSERT INTO institutions (name,slug,active,created_at) VALUES (:name,:slug,1,:created_at)')->execute(['name'=>'Support Test '.($index+1),'slug'=>$slug,'created_at'=>$now]);$institutionId=(int)$pdo->lastInsertId();
            if (false) {
            $pdo->prepare('INSERT INTO institution_branding (institution_id,display_name,portal_title,logo_path,favicon_path,accent_source_color,primary_color,accent_color,nav_label,assessment_label,footer_primary,footer_secondary,footer_legal,result_sheet_title,newsletter_sender_name,support_email,updated_at) VALUES (:institution_id,:display_name,:portal_title,\'\',\'\',\'#2563eb\',\'#2563eb\',\'#1d4ed8\',:display_name,\'Assessment centre\',:display_name,\'Support test\',\'© {year} Support test\',:display_name,:display_name,\'\',UTC_TIMESTAMP(6))')->execute(['institution_id'=>$institutionId,'display_name'=>'Support Test '.($index+1),'portal_title'=>'Support Test '.($index+1)]);
            }
            $label='Support Test '.($index+1);
            $pdo->prepare('INSERT INTO institution_branding (institution_id,display_name,portal_title,logo_path,favicon_path,accent_source_color,primary_color,accent_color,nav_label,assessment_label,footer_primary,footer_secondary,footer_legal,result_sheet_title,newsletter_sender_name,support_email,updated_at) VALUES (:institution_id,:display_name,:portal_title,\'\',\'\',\'#2563eb\',\'#2563eb\',\'#1d4ed8\',:nav_label,\'Assessment centre\',:footer_primary,\'Support test\',\'Copyright {year} Support test\',:result_sheet_title,:newsletter_sender_name,\'\',UTC_TIMESTAMP(6))')->execute(['institution_id'=>$institutionId,'display_name'=>$label,'portal_title'=>$label,'nav_label'=>$label,'footer_primary'=>$label,'result_sheet_title'=>$label,'newsletter_sender_name'=>$label]);
            $pdo->prepare("INSERT INTO roles (institution_id,id,name,description,max_users,system_locked) VALUES (:institution_id,'admin','Admin','Support test admin',1,1)")->execute(['institution_id'=>$institutionId]);
            $pdo->prepare("INSERT INTO role_permissions (institution_id,role_id,permission) VALUES (:institution_id,'admin','messages')")->execute(['institution_id'=>$institutionId]);
            $adminId='support-admin-'.($index+1);$email='support-admin-'.($index+1).'@example.invalid';$pdo->prepare('INSERT INTO admin_users (institution_id,id,role_id,name,email,password_hash,active,verified,must_change_password,created_at) VALUES (:institution_id,:id,\'admin\',:name,:email,:password_hash,1,1,0,:created_at)')->execute(['institution_id'=>$institutionId,'id'=>$adminId,'name'=>'Support Admin '.($index+1),'email'=>$email,'password_hash'=>password_hash('Support-Test-Only!',PASSWORD_DEFAULT),'created_at'=>$now]);
            if (false) {
            $token=bin2hex(random_bytes(32));$tenantTokens[$index]=$token;$pdo->prepare('INSERT INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at) VALUES (:institution_id,:token_hash,:user_id,:email,:name,\'admin\',JSON_ARRAY(\'messages\'),\'support-backend-test\',:created_at,:created_at,\'2099-12-31 23:59:59.000000\')')->execute(['institution_id'=>$institutionId,'token_hash'=>hash('sha256',$token),'user_id'=>$adminId,'email'=>$email,'name'=>'Support Admin '.($index+1),'created_at'=>$now]);
            }
            $token=bin2hex(random_bytes(32));$tenantTokens[$index]=$token;$pdo->prepare('INSERT INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at) VALUES (:institution_id,:token_hash,:user_id,:email,:name,\'admin\',JSON_ARRAY(\'messages\'),\'support-backend-test\',:created_at,:last_seen_at,\'2099-12-31 23:59:59.000000\')')->execute(['institution_id'=>$institutionId,'token_hash'=>hash('sha256',$token),'user_id'=>$adminId,'email'=>$email,'name'=>'Support Admin '.($index+1),'created_at'=>$now,'last_seen_at'=>$now]);
            $pdo->commit();$ids[$index]=$institutionId;
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }
    $baseA='http://localhost/BEREVION/i/'.$slugs[0].'/api.php?action=';$baseB='http://localhost/BEREVION/i/'.$slugs[1].'/api.php?action=';$platformBase='http://localhost/BEREVION/api.php?action=';
    $tenantPreferred=supportHttp($baseA.'admin-account','GET',$tenantTokens[0],null,'',$platformToken);if($tenantPreferred['status']!==200||($tenantPreferred['body']['account']['id']??'')!=='support-admin-1')throw new RuntimeException('Tenant route did not prefer its tenant Admin session when a platform session was also present.');
    $createA=supportPost($baseA,'support-threads',$tenantTokens[0],['subject'=>'Tenant A support request','message'=>'Please help Tenant A. <script>must remain text</script>']);if($createA['status']!==201)throw new RuntimeException('Tenant A thread creation failed: '.$createA['raw']);$threadA=(string)$createA['body']['threadId'];
    $createB=supportPost($baseB,'support-threads',$tenantTokens[1],['subject'=>'Tenant B support request','message'=>'Please help Tenant B.']);if($createB['status']!==201)throw new RuntimeException('Tenant B thread creation failed: '.$createB['raw']);$threadB=(string)$createB['body']['threadId'];
    $bList=supportHttp($baseB.'support-threads','GET',$tenantTokens[1]);if($bList['status']!==200||count($bList['body']['items']??[])!==1||($bList['body']['items'][0]['id']??'')!==$threadB)throw new RuntimeException('Tenant B could see a foreign thread or could not see its own.');
    $foreignRead=supportHttp($baseB.'support-thread&id='.$threadA,'GET',$tenantTokens[1]);$foreignReply=supportPost($baseB,'support-thread',$tenantTokens[1],['threadId'=>$threadA,'message'=>'Cross-tenant attempt']);if($foreignRead['status']!==404||$foreignReply['status']!==404)throw new RuntimeException('Cross-tenant support-thread access was not blocked with 404.');
    $platformList=supportHttp($platformBase.'platform-support-threads','GET',$platformToken);if($platformList['status']!==200||count(array_filter($platformList['body']['items']??[],fn(array $item):bool=>in_array($item['id']??'',[$threadA,$threadB],true)))!==2)throw new RuntimeException('Platform Super Admin did not receive both tenant support threads.');
    $platformFiltered=supportHttp($platformBase.'platform-support-threads&institutionId='.$ids[0],'GET',$platformToken);if($platformFiltered['status']!==200||count($platformFiltered['body']['items']??[])!==1||($platformFiltered['body']['items'][0]['id']??'')!==$threadA)throw new RuntimeException('Platform institution filter did not return only the selected tenant thread.');
    foreach([[$threadA,'Platform reply for Tenant A'],[$threadB,'Platform reply for Tenant B']] as [$threadId,$message]){ $reply=supportPost($platformBase,'platform-support-thread',$platformToken,['operation'=>'reply','threadId'=>$threadId,'message'=>$message]);if($reply['status']!==201)throw new RuntimeException('Platform reply failed: '.$reply['raw']); }
    $aUnread=supportHttp($baseA.'support-threads','GET',$tenantTokens[0]);$bUnread=supportHttp($baseB.'support-threads','GET',$tenantTokens[1]);if(($aUnread['body']['unreadCount']??0)!==1||($bUnread['body']['unreadCount']??0)!==1)throw new RuntimeException('Tenant unread counts did not reflect the platform replies.');
    $aDetail=supportHttp($baseA.'support-thread&id='.$threadA,'GET',$tenantTokens[0]);$bDetail=supportHttp($baseB.'support-thread&id='.$threadB,'GET',$tenantTokens[1]);$aBodies=json_encode($aDetail['body']['messages']??[]);$bBodies=json_encode($bDetail['body']['messages']??[]);if($aDetail['status']!==200||$bDetail['status']!==200||!str_contains((string)$aBodies,'Platform reply for Tenant A')||str_contains((string)$aBodies,'Platform reply for Tenant B')||!str_contains((string)$bBodies,'Platform reply for Tenant B')||str_contains((string)$bBodies,'Platform reply for Tenant A'))throw new RuntimeException('Platform replies were not delivered to the correct tenant thread.');
    $resolve=supportPost($platformBase,'platform-support-thread',$platformToken,['operation'=>'set-status','threadId'=>$threadA,'status'=>'resolved']);if($resolve['status']!==200||($resolve['body']['status']??'')!=='resolved')throw new RuntimeException('Platform resolve failed.');
    $tenantResolve=supportPost($baseA,'support-thread',$tenantTokens[0],['operation'=>'set-status','threadId'=>$threadA,'status'=>'open']);$resolvedReply=supportPost($baseA,'support-thread',$tenantTokens[0],['threadId'=>$threadA,'message'=>'Must not send while resolved']);if($tenantResolve['status']!==403||$resolvedReply['status']!==409)throw new RuntimeException('Resolve/reopen restriction was not enforced.');
    $reopen=supportPost($platformBase,'platform-support-thread',$platformToken,['operation'=>'set-status','threadId'=>$threadA,'status'=>'open']);$tenantReply=supportPost($baseA,'support-thread',$tenantTokens[0],['threadId'=>$threadA,'message'=>'Thank you; tenant reply after reopen.']);if($reopen['status']!==200||$tenantReply['status']!==201)throw new RuntimeException('Platform reopen or subsequent tenant reply failed.');
    $audit=$pdo->prepare("SELECT metadata_json FROM audit_events WHERE institution_id=:institution_id AND target_id=:thread_id AND action_type='support_thread_created' LIMIT 1");$audit->execute(['institution_id'=>$ids[0],'thread_id'=>$threadA]);$metadata=(string)$audit->fetchColumn();if($metadata===''||str_contains($metadata,'Please help Tenant A'))throw new RuntimeException('Support message text was copied into tenant audit metadata.');
    foreach($cacsaBefore as $table=>$hash)if(!hash_equals($hash,supportBackendHash($pdo,$table)))throw new RuntimeException("CACSA {$table} changed during support backend verification.");
    echo json_encode(['pass'=>true,'checks'=>['tenant-route-session-precedence'=>'tenant-session-wins-over-platform-cookie','tenant-isolation'=>'foreign-read-and-reply-404','platform-cross-tenant-replies'=>'delivered-to-correct-thread','platform-institution-filter'=>'selected-tenant-only','resolve-reopen'=>'platform-only','unread-counts'=>'correct','audit-message-body'=>'excluded','cacsa-hashes'=>'unchanged'],'testInstitutions'=>$ids],JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    $pdo->prepare('DELETE FROM platform_admin_sessions WHERE token_hash=:hash')->execute(['hash'=>$platformHash]);
    if($ids)supportCleanup($pdo,$root,$ids,$slugs);
}
