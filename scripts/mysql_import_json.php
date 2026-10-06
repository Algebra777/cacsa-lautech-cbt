<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_connection.php';

const MIGRATION_KEY = 'json_to_mysql_phase_1';
const DEFAULT_IMPORT_INSTITUTION_ID = 1;
function importInstitutionId(): int {
    $institutionId = defined('MYSQL_IMPORT_INSTITUTION_ID') ? (int)MYSQL_IMPORT_INSTITUTION_ID : DEFAULT_IMPORT_INSTITUTION_ID;
    if ($institutionId < 1) throw new RuntimeException('A valid institution context is required for MySQL persistence.');
    return $institutionId;
}

function dbTime(mixed $value): ?string {
    if ($value === null || $value === '') return null;
    try { return (new DateTimeImmutable((string)$value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    catch (Throwable) { return null; }
}
function unixTime(mixed $value): ?string { return is_numeric($value) && (int)$value > 0 ? gmdate('Y-m-d H:i:s', (int)$value) . '.000000' : null; }
function jsonValue(mixed $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
function boolValue(mixed $value): int { return !empty($value) ? 1 : 0; }
function stringValue(array $row, string $key, string $default = ''): string { return isset($row[$key]) ? (string)$row[$key] : $default; }

function statements(): array {
    return [
        'academic_sessions' => 'INSERT IGNORE INTO academic_sessions (institution_id,id,label,is_active,created_at) VALUES (:institution_id,:id,:label,:is_active,:created_at)',
        'academic_semesters' => 'INSERT IGNORE INTO academic_semesters (institution_id,id,session_id,label,is_active,start_date,end_date,created_at) VALUES (:institution_id,:id,:session_id,:label,:is_active,:start_date,:end_date,:created_at)',
        'courses' => 'INSERT IGNORE INTO courses (institution_id,id,session_id,semester_id,code,title,description,category,course_unit,test_max_mark,exam_max_mark,legacy_exam_only,created_at) VALUES (:institution_id,:id,:session_id,:semester_id,:code,:title,:description,:category,:course_unit,:test_max_mark,:exam_max_mark,:legacy_exam_only,:created_at)',
        'components' => 'INSERT IGNORE INTO course_components (institution_id,id,course_id,component,code,title,description,category,duration_minutes,max_mark,pass_threshold,question_count,start_at,end_at,status,legacy_exam_only,created_at) VALUES (:institution_id,:id,:course_id,:component,:code,:title,:description,:category,:duration_minutes,:max_mark,:pass_threshold,:question_count,:start_at,:end_at,:status,:legacy_exam_only,:created_at)',
        'students' => 'INSERT IGNORE INTO students (institution_id,id,full_name,matric_number,email,phone_number,department,active,created_at) VALUES (:institution_id,:id,:full_name,:matric_number,:email,:phone_number,:department,:active,:created_at)',
        'questions' => 'INSERT IGNORE INTO questions (institution_id,id,course_id,legacy_component_id,text,type,topic,difficulty,status) VALUES (:institution_id,:id,:course_id,:legacy_component_id,:text,:type,:topic,:difficulty,:status)',
        'question_options' => 'INSERT IGNORE INTO question_options (institution_id,question_id,option_index,option_text,is_correct) VALUES (:institution_id,:question_id,:option_index,:option_text,:is_correct)',
        'question_targets' => 'INSERT IGNORE INTO question_publish_targets (institution_id,question_id,target_component) VALUES (:institution_id,:question_id,:target_component)',
        'passwords' => 'INSERT IGNORE INTO assessment_passwords (institution_id,id,student_id,component_id,password_hash,created_at,expires_at,used_at) VALUES (:institution_id,:id,:student_id,:component_id,:password_hash,:created_at,:expires_at,:used_at)',
        'login_tokens' => 'INSERT IGNORE INTO assessment_login_tokens (institution_id,token_hash,student_id,component_id,password_id,correlation_id,device_fingerprint,expires_at,used_at,created_at) VALUES (:institution_id,:token_hash,:student_id,:component_id,:password_id,:correlation_id,:device_fingerprint,:expires_at,:used_at,:created_at)',
        'attempts' => 'INSERT IGNORE INTO assessment_attempts (institution_id,id,student_id,component_id,password_id,status,started_at,ends_at,submitted_at,raw_score,scaled_score,access_token_hash,correlation_id,device_fingerprint,ip_address,locked_at,locked_reason,snapshot_json) VALUES (:institution_id,:id,:student_id,:component_id,:password_id,:status,:started_at,:ends_at,:submitted_at,:raw_score,:scaled_score,:access_token_hash,:correlation_id,:device_fingerprint,:ip_address,:locked_at,:locked_reason,:snapshot_json)',
        'attempt_questions' => 'INSERT IGNORE INTO attempt_question_snapshots (institution_id,attempt_id,question_id,ordinal,question_json) VALUES (:institution_id,:attempt_id,:question_id,:ordinal,:question_json)',
        'attempt_answers' => 'INSERT IGNORE INTO attempt_answers (institution_id,attempt_id,question_id,option_index) VALUES (:institution_id,:attempt_id,:question_id,:option_index)',
        'attempt_flags' => 'INSERT IGNORE INTO attempt_flags (institution_id,attempt_id,question_id) VALUES (:institution_id,:attempt_id,:question_id)',
        'attempt_integrity' => 'INSERT INTO attempt_integrity_events (institution_id,attempt_id,event_json) VALUES (:institution_id,:attempt_id,:event_json)',
        'submissions' => 'INSERT IGNORE INTO component_submissions (institution_id,id,attempt_id,student_id,course_id,component_id,academic_session_id,academic_semester_id,component,status,raw_score,scaled_score,score,submitted_at,grade,grade_point,quality_points,course_unit,review_snapshot_json) VALUES (:institution_id,:id,:attempt_id,:student_id,:course_id,:component_id,:academic_session_id,:academic_semester_id,:component,:status,:raw_score,:scaled_score,:score,:submitted_at,:grade,:grade_point,:quality_points,:course_unit,:review_snapshot_json)',
        'calculated_results' => 'INSERT IGNORE INTO calculated_results (institution_id,id,student_id,session_id,semester_id,source_hash,semester_gpa,cgpa,calculated_at) VALUES (:institution_id,:id,:student_id,:session_id,:semester_id,:source_hash,:semester_gpa,:cgpa,:calculated_at)',
        'calculated_items' => 'INSERT IGNORE INTO calculated_result_items (institution_id,calculated_result_id,course_id,item_json) VALUES (:institution_id,:calculated_result_id,:course_id,:item_json)',
        'roles' => 'INSERT IGNORE INTO roles (institution_id,id,name,description,max_users,system_locked) VALUES (:institution_id,:id,:name,:description,:max_users,:system_locked)',
        'role_permissions' => 'INSERT IGNORE INTO role_permissions (institution_id,role_id,permission) VALUES (:institution_id,:role_id,:permission)',
        'admin_users' => 'INSERT IGNORE INTO admin_users (institution_id,id,role_id,name,email,phone_number,password_hash,active,verified,must_change_password,created_at,approved_at,approved_by) VALUES (:institution_id,:id,:role_id,:name,:email,:phone_number,:password_hash,:active,:verified,:must_change_password,:created_at,:approved_at,:approved_by)',
        'admin_sessions' => 'INSERT IGNORE INTO admin_sessions (institution_id,token_hash,user_id,email,name,role_id,permissions_json,correlation_id,created_at,last_seen_at,expires_at) VALUES (:institution_id,:token_hash,:user_id,:email,:name,:role_id,:permissions_json,:correlation_id,:created_at,:last_seen_at,:expires_at)',
        'profile_overrides' => 'INSERT IGNORE INTO admin_profile_overrides (institution_id,profile_key,payload_json) VALUES (:institution_id,:profile_key,:payload_json)',
        'admin_activity' => 'INSERT IGNORE INTO admin_user_activity (institution_id,user_id,last_login_at,payload_json) VALUES (:institution_id,:user_id,:last_login_at,:payload_json)',
        'pending_requests' => 'INSERT IGNORE INTO pending_admin_requests (institution_id,id,name,email,phone_number,role_id,status,ip_address,email_delivery,created_at,resolved_at,resolved_by) VALUES (:institution_id,:id,:name,:email,:phone_number,:role_id,:status,:ip_address,:email_delivery,:created_at,:resolved_at,:resolved_by)',
        'email_verifications' => 'INSERT IGNORE INTO admin_email_verifications (institution_id,user_id,email,code_hash,attempts,expires_at) VALUES (:institution_id,:user_id,:email,:code_hash,:attempts,:expires_at)',
        'password_resets' => 'INSERT IGNORE INTO admin_password_resets (institution_id,id,user_id,email,code_hash,attempts,created_at,expires_at) VALUES (:institution_id,:id,:user_id,:email,:code_hash,:attempts,:created_at,:expires_at)',
        'two_factor' => 'INSERT IGNORE INTO admin_two_factor_challenges (institution_id,token_hash,user_id,email,role_id,password_rate_limit_key,attempts,expires_at) VALUES (:institution_id,:token_hash,:user_id,:email,:role_id,:password_rate_limit_key,:attempts,:expires_at)',
        'emergency_codes' => 'INSERT IGNORE INTO emergency_recovery_codes (institution_id,code_position,code_hash,generated_at) VALUES (:institution_id,:code_position,:code_hash,:generated_at)',
        'audit_events' => 'INSERT IGNORE INTO audit_events (institution_id,id,timestamp_at,actor_type,actor_id,action_type,target_type,target_id,ip_address,user_agent,outcome,correlation_id,before_after_json,metadata_json) VALUES (:institution_id,:id,:timestamp_at,:actor_type,:actor_id,:action_type,:target_type,:target_id,:ip_address,:user_agent,:outcome,:correlation_id,:before_after_json,:metadata_json)',
        'rate_limits' => 'INSERT IGNORE INTO rate_limit_records (institution_id,legacy_key,scope,subject_hash,count_value,reset_at,payload_json) VALUES (:institution_id,:legacy_key,:scope,:subject_hash,:count_value,:reset_at,:payload_json)',
        'login_failures' => 'INSERT IGNORE INTO exam_login_failures (institution_id,legacy_key,failed_at_json,locked_until) VALUES (:institution_id,:legacy_key,:failed_at_json,:locked_until)',
        'login_ip_attempts' => 'INSERT IGNORE INTO exam_login_ip_attempts (institution_id,legacy_key,attempted_at_json) VALUES (:institution_id,:legacy_key,:attempted_at_json)',
        'exam_flags' => 'INSERT IGNORE INTO exam_flags (institution_id,id,attempt_id,flag_type,ip_address,resulting_action,timestamp_at) VALUES (:institution_id,:id,:attempt_id,:flag_type,:ip_address,:resulting_action,:timestamp_at)',
        'subscribers' => 'INSERT IGNORE INTO newsletter_subscribers (institution_id,id,student_id,name,email,source,status,subscribed_at,updated_at,verified_at) VALUES (:institution_id,:id,:student_id,:name,:email,:source,:status,:subscribed_at,:updated_at,:verified_at)',
        'newsletters' => 'INSERT IGNORE INTO newsletters (institution_id,id,subject,content,sender,created_by,created_at,recipient_count,accepted_count,failed_count,status) VALUES (:institution_id,:id,:subject,:content,:sender,:created_by,:created_at,:recipient_count,:accepted_count,:failed_count,:status)',
        'newsletter_deliveries' => 'INSERT IGNORE INTO newsletter_deliveries (institution_id,newsletter_id,recipient_email,delivery_json) VALUES (:institution_id,:newsletter_id,:recipient_email,:delivery_json)',
        'grading_bands' => 'INSERT IGNORE INTO grading_scale_bands (institution_id,ordinal,min_score,max_score,grade,grade_point) VALUES (:institution_id,:ordinal,:min_score,:max_score,:grade,:grade_point)',
        'integrity_policies' => 'INSERT IGNORE INTO integrity_policies (institution_id,event_type,mode,lock_after) VALUES (:institution_id,:event_type,:mode,:lock_after)',
        'settings' => 'INSERT INTO institution_settings (institution_id,setting_key,setting_json) VALUES (:institution_id,:setting_key,:setting_json) ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json)',
        'hidden_outcomes' => 'INSERT IGNORE INTO dashboard_hidden_outcomes (institution_id,outcome_key) VALUES (:institution_id,:outcome_key)',
        'backup_records' => 'INSERT IGNORE INTO backup_records (institution_id,filename,backup_type,created_at,created_by,size_bytes,checksum_sha256,metadata_json) VALUES (:institution_id,:filename,:backup_type,:created_at,:created_by,:size_bytes,:checksum_sha256,:metadata_json)',
        'audit_archives' => 'INSERT IGNORE INTO audit_archives (institution_id,filename,event_count,range_from,range_to,created_at,checksum_sha256) VALUES (:institution_id,:filename,:event_count,:range_from,:range_to,:created_at,:checksum_sha256)',
    ];
}

function importer(PDO $pdo, string $key, array $params): void {
    static $prepared = []; static $sql = null;
    $sql ??= statements();
    if (!isset($sql[$key])) throw new LogicException("Unknown migration statement {$key}.");
    $statementKey = $key;
    $statementSql = $sql[$key];
    // The runtime compatibility layer reuses these mappers for a deliberately
    // small changed-record payload.  Migrations retain INSERT IGNORE semantics;
    // runtime persistence must update an existing changed row instead of
    // deleting and rebuilding an entire institution.
    if (defined('MYSQL_IMPORT_TARGETED') && MYSQL_IMPORT_TARGETED) {
        $primaryColumns = targetedPrimaryColumns($key);
        if ($primaryColumns) {
            if (preg_match('/^INSERT IGNORE INTO ([a-z_]+) \(([^)]+)\) VALUES \(([^)]+)\)$/', $statementSql, $match)) {
                $columns = array_map('trim', explode(',', $match[2]));
                $updates = array_values(array_filter($columns, fn(string $column): bool => !in_array($column, $primaryColumns, true)));
                // Do not retain INSERT IGNORE at runtime.  It hides failures
                // (including foreign-key failures in attempt answers) after an
                // HTTP handler has reported success.  A primary-key-only row
                // still needs an idempotent duplicate branch.
                $duplicateUpdate = $updates
                    ? implode(',', array_map(fn(string $column): string => $column . '=VALUES(' . $column . ')', $updates))
                    : $primaryColumns[0] . '=VALUES(' . $primaryColumns[0] . ')';
                $statementSql = 'INSERT INTO ' . $match[1] . ' (' . $match[2] . ') VALUES (' . $match[3] . ') ON DUPLICATE KEY UPDATE ' . $duplicateUpdate;
                $statementKey .= ':targeted';
            }
        }
    }
    $prepared[$statementKey] ??= $pdo->prepare($statementSql);
    $prepared[$statementKey]->execute($params);
}

/** @return list<string> */
function targetedPrimaryColumns(string $key): array {
    return match ($key) {
        'academic_sessions','academic_semesters','courses','components','students','questions','passwords','attempts','submissions','calculated_results','roles','admin_users','pending_requests','password_resets','exam_flags','subscribers','newsletters','audit_events' => ['institution_id','id'],
        'question_options' => ['institution_id','question_id','option_index'],
        'question_targets' => ['institution_id','question_id','target_component'],
        'login_tokens','admin_sessions','two_factor' => ['institution_id','token_hash'],
        'attempt_questions' => ['institution_id','attempt_id','question_id'],
        'attempt_answers' => ['institution_id','attempt_id','question_id','option_index'],
        'attempt_flags' => ['institution_id','attempt_id','question_id'],
        'calculated_items' => ['institution_id','calculated_result_id','course_id'],
        'role_permissions' => ['institution_id','role_id','permission'],
        'profile_overrides' => ['institution_id','profile_key'],
        'admin_activity','email_verifications' => ['institution_id','user_id'],
        'emergency_codes' => ['institution_id','code_position'],
        'rate_limits','login_failures','login_ip_attempts' => ['institution_id','legacy_key'],
        'newsletter_deliveries' => ['institution_id','newsletter_id','recipient_email'],
        'grading_bands' => ['institution_id','ordinal'],
        'integrity_policies' => ['institution_id','event_type'],
        'settings' => ['institution_id','setting_key'],
        'hidden_outcomes' => ['institution_id','outcome_key'],
        'backup_records','audit_archives' => ['institution_id','filename'],
        default => [],
    };
}

$root = defined('MYSQL_IMPORT_ROOT') ? MYSQL_IMPORT_ROOT : dirname(__DIR__);
$inMemory = defined('MYSQL_IMPORT_IN_MEMORY');
$sourcePath = $root . DIRECTORY_SEPARATOR . 'cbt-data.json';
$manifestPath = $argv[1] ?? '';
$replaceCurrent = ($inMemory && !(defined('MYSQL_IMPORT_TARGETED') && MYSQL_IMPORT_TARGETED)) || in_array('--replace-current', $argv ?? [], true);
if ($inMemory) {
    $data = MYSQL_IMPORT_IN_MEMORY;
    if (!is_array($data)) throw new RuntimeException('The in-memory MySQL payload must be an array.');
    $raw = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    $manifest = MYSQL_IMPORT_MANIFEST;
} else {
    if (!is_file($sourcePath) || !is_file($manifestPath)) throw new RuntimeException('Pass the current migration-preparation record as the first argument.');
    $raw = file_get_contents($sourcePath);
    $data = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
}
$sourceHash = hash('sha256', (string)$raw);
if (!$inMemory && ($manifest['metadata']['legacySourceSha256'] ?? '') !== $sourceHash) throw new RuntimeException('The legacy JSON changed after preflight. Take a new preflight backup before importing.');

$pdo = $GLOBALS['cacsa_mysql_import_pdo'] ?? mysqlMigrationPdo($root);
$existing = $pdo->prepare('SELECT status, source_sha256 FROM storage_migrations WHERE institution_id = :institution_id AND migration_key = :migration_key');
$existing->execute(['institution_id' => importInstitutionId(), 'migration_key' => MIGRATION_KEY]);
$recordedSourceHash = $sourceHash;
if ($row = $existing->fetch()) {
    if ($inMemory && !empty($row['source_sha256'])) $recordedSourceHash = (string)$row['source_sha256'];
    // A request-scoped changed-row payload is not a migration source and must
    // never be compared with the immutable JSON-migration checksum.
    if (defined('MYSQL_IMPORT_TARGETED') && MYSQL_IMPORT_TARGETED) {
        // Keep the migration record untouched; normal targeted processing below
        // owns its own short transaction.
    } else {
    if ($row['status'] === 'completed' && hash_equals((string)$row['source_sha256'], $sourceHash) && !$replaceCurrent) { echo json_encode(['status' => 'already_completed', 'sourceSha256' => $sourceHash]) . PHP_EOL; exit; }
    if (!$replaceCurrent) throw new RuntimeException('A migration record already exists with a different source hash or incomplete status. Resolve it before retrying.');
    $maintenanceFlag = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '.maintenance.json';
    $maintenance = is_file($maintenanceFlag) ? json_decode((string)file_get_contents($maintenanceFlag), true) : null;
    if (!$inMemory && (!is_array($maintenance) || empty($maintenance['enabled']))) throw new RuntimeException('Refreshing an existing migration is allowed only while maintenance mode is active.');
    }
}

$counts = [];
$count = static function (string $name) use (&$counts): void { $counts[$name] = ($counts[$name] ?? 0) + 1; };
$targetedTransactionAttempt = 0;
targeted_import_retry:
$pdo->beginTransaction();
try {
    // Runtime targeted persistence supplies only rows that changed since this
    // request loaded its tenant snapshot.  Apply child-first deletes inside
    // this same transaction, never a tenant-wide table clear.
    if (defined('MYSQL_IMPORT_TARGETED') && MYSQL_IMPORT_TARGETED) {
        foreach ((defined('MYSQL_IMPORT_TARGETED_DELETES') ? MYSQL_IMPORT_TARGETED_DELETES : []) as $plan) {
            if (!is_array($plan) || !isset($plan['table'], $plan['column'])) throw new RuntimeException('Invalid targeted deletion plan.');
            $table = (string)$plan['table']; $column = (string)$plan['column']; $additionalWhere = (array)($plan['additionalWhere'] ?? []);
            if (!preg_match('/^[a-z_]+$/', $table) || !preg_match('/^[a-z_]+$/', $column)) throw new RuntimeException('Invalid targeted deletion identifier.');
            $where = 'institution_id = :institution_id'; $params = ['institution_id'=>importInstitutionId()];
            if (array_key_exists('value', $plan) && $plan['value'] !== null) { $where .= " AND {$column} = :value"; $params['value'] = (string)$plan['value']; }
            foreach ($additionalWhere as $extraColumn => $extraValue) {
                if (!is_string($extraColumn) || !preg_match('/^[a-z_]+$/', $extraColumn)) throw new RuntimeException('Invalid targeted deletion condition.');
                $parameter = 'extra_' . $extraColumn; $where .= " AND {$extraColumn} = :{$parameter}"; $params[$parameter] = (string)$extraValue;
            }
            $delete = $pdo->prepare("DELETE FROM {$table} WHERE {$where}");
            $delete->execute($params);
        }
    }
    if ($replaceCurrent) {
        // Child-to-parent ordering honours the schema's RESTRICT constraints. This
        // branch is only for the final, maintenance-window refresh before cutover.
        foreach ([
            'calculated_result_items', 'calculated_results', 'newsletter_deliveries', 'attempt_answers', 'attempt_flags',
            'attempt_question_snapshots', 'attempt_integrity_events', 'component_submissions', 'exam_flags',
            'assessment_attempts', 'assessment_login_tokens', 'assessment_passwords', 'question_publish_targets',
            'question_options', 'questions', 'role_permissions', 'admin_sessions', 'admin_profile_overrides',
            'admin_user_activity', 'admin_email_verifications', 'admin_password_resets', 'admin_two_factor_challenges',
            'emergency_recovery_codes', 'pending_admin_requests', 'admin_users', 'audit_events', 'audit_archives',
            'rate_limit_records', 'exam_login_failures', 'exam_login_ip_attempts', 'newsletter_subscribers',
            'newsletters', 'grading_scale_bands', 'integrity_policies', 'institution_settings',
            'dashboard_hidden_outcomes', 'backup_records', 'students', 'course_components', 'courses',
            'academic_semesters', 'academic_sessions', 'roles'
        ] as $table) {
            $clear = $pdo->prepare("DELETE FROM {$table} WHERE institution_id = :institution_id");
            $clear->execute(['institution_id' => importInstitutionId()]);
        }
        // Runtime writes are not migrations.  Keep the one-time migration
        // record immutable so ordinary application activity never rewrites
        // its timestamps or provenance.
        if (!$inMemory) {
            $reset = $pdo->prepare('UPDATE storage_migrations SET source_sha256=:source_sha256,status=:status,details_json=:details_json,started_at=:started_at,completed_at=NULL WHERE institution_id=:institution_id AND migration_key=:migration_key');
            $reset->execute(['source_sha256'=>$recordedSourceHash,'status'=>'importing','details_json'=>jsonValue(['source'=>'cbt-data.json','mode'=>'maintenance_refresh']),'started_at'=>dbTime(date('c')),'institution_id'=>importInstitutionId(),'migration_key'=>MIGRATION_KEY]);
        }
    }
    if (!$inMemory) {
        $start = $pdo->prepare('INSERT IGNORE INTO storage_migrations (institution_id,migration_key,source_sha256,status,details_json,started_at) VALUES (:institution_id,:migration_key,:source_sha256,:status,:details_json,:started_at)');
        $start->execute(['institution_id'=>importInstitutionId(),'migration_key'=>MIGRATION_KEY,'source_sha256'=>$sourceHash,'status'=>'importing','details_json'=>jsonValue(['source'=>'cbt-data.json']),'started_at'=>dbTime(date('c'))]);
    }
    foreach ($data['academicSessions'] ?? [] as $r) { importer($pdo,'academic_sessions',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'label'=>stringValue($r,'label'),'is_active'=>boolValue($r['isActive']??false),'created_at'=>dbTime($r['createdAt']??null)]); $count('academicSessions'); }
    foreach ($data['semesters'] ?? [] as $r) { importer($pdo,'academic_semesters',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'session_id'=>stringValue($r,'sessionId'),'label'=>stringValue($r,'label'),'is_active'=>boolValue($r['isActive']??false),'start_date'=>($r['startDate']??null)?:null,'end_date'=>($r['endDate']??null)?:null,'created_at'=>dbTime($r['createdAt']??null)]); $count('semesters'); }
    foreach ($data['courses'] ?? [] as $r) { importer($pdo,'courses',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'session_id'=>($r['sessionId']??null)?:null,'semester_id'=>($r['semesterId']??null)?:null,'code'=>stringValue($r,'code'),'title'=>stringValue($r,'title'),'description'=>($r['description']??null)?:null,'category'=>($r['category']??null)?:null,'course_unit'=>(int)($r['courseUnit']??3),'test_max_mark'=>(float)($r['testMaxMark']??0),'exam_max_mark'=>(float)($r['examMaxMark']??0),'legacy_exam_only'=>boolValue($r['legacyExamOnly']??false),'created_at'=>dbTime($r['createdAt']??null)]); $count('courses'); }
    foreach ($data['exams'] ?? [] as $r) { importer($pdo,'components',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'course_id'=>stringValue($r,'courseId'),'component'=>strtolower(stringValue($r,'component','exam')) === 'test' ? 'test' : 'exam','code'=>stringValue($r,'code'),'title'=>stringValue($r,'title'),'description'=>($r['description']??null)?:null,'category'=>($r['category']??null)?:null,'duration_minutes'=>(int)($r['duration']??0),'max_mark'=>(float)($r['maxMark']??0),'pass_threshold'=>isset($r['passThreshold'])?(float)$r['passThreshold']:null,'question_count'=>(int)($r['questionCount']??0),'start_at'=>dbTime($r['startAt']??null),'end_at'=>dbTime($r['endAt']??null),'status'=>stringValue($r,'status'),'legacy_exam_only'=>boolValue($r['legacyExamOnly']??false),'created_at'=>dbTime($r['createdAt']??null)]); $count('exams'); }
    foreach ($data['students'] ?? [] as $r) { importer($pdo,'students',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'full_name'=>stringValue($r,'fullName'),'matric_number'=>stringValue($r,'matricNumber'),'email'=>stringValue($r,'email'),'phone_number'=>($r['phoneNumber']??null)?:null,'department'=>stringValue($r,'department'),'active'=>boolValue($r['active']??false),'created_at'=>dbTime($r['createdAt']??null)]); $count('students'); }
    foreach ($data['questions'] ?? [] as $r) { $id=stringValue($r,'id'); importer($pdo,'questions',['institution_id'=>importInstitutionId(),'id'=>$id,'course_id'=>stringValue($r,'courseId'),'legacy_component_id'=>($r['legacyComponentId']??$r['examId']??null)?:null,'text'=>stringValue($r,'text'),'type'=>stringValue($r,'type','single'),'topic'=>($r['topic']??null)?:null,'difficulty'=>($r['difficulty']??null)?:null,'status'=>stringValue($r,'status','draft')]); foreach (($r['options']??[]) as $i=>$option) importer($pdo,'question_options',['institution_id'=>importInstitutionId(),'question_id'=>$id,'option_index'=>(int)$i,'option_text'=>(string)$option,'is_correct'=>in_array($i,$r['correctOptions']??[],true)?1:0]); foreach (array_unique(array_filter($r['publishedTo']??[],fn($v)=>in_array($v,['test','exam'],true))) as $target) importer($pdo,'question_targets',['institution_id'=>importInstitutionId(),'question_id'=>$id,'target_component'=>$target]); $count('questions'); }
    foreach ($data['roles'] ?? [] as $r) { importer($pdo,'roles',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'name'=>stringValue($r,'name'),'description'=>($r['description']??null)?:null,'max_users'=>(int)($r['maxUsers']??1),'system_locked'=>boolValue($r['systemLocked']??false)]); foreach (($r['permissions']??[]) as $permission) importer($pdo,'role_permissions',['institution_id'=>importInstitutionId(),'role_id'=>stringValue($r,'id'),'permission'=>(string)$permission]); $count('roles'); }
    foreach ($data['adminUsers'] ?? [] as $r) { importer($pdo,'admin_users',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'role_id'=>stringValue($r,'roleId'),'name'=>stringValue($r,'name'),'email'=>stringValue($r,'email'),'phone_number'=>($r['phoneNumber']??null)?:null,'password_hash'=>($r['passwordHash']??null)?:null,'active'=>boolValue($r['active']??false),'verified'=>boolValue($r['verified']??false),'must_change_password'=>boolValue($r['mustChangePassword']??false),'created_at'=>dbTime($r['createdAt']??null),'approved_at'=>dbTime($r['approvedAt']??null),'approved_by'=>($r['approvedBy']??null)?:null]); $count('adminUsers'); }
    foreach ($data['passwords'] ?? [] as $r) { importer($pdo,'passwords',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'student_id'=>stringValue($r,'studentId'),'component_id'=>stringValue($r,'examId'),'password_hash'=>stringValue($r,'passwordHash'),'created_at'=>dbTime($r['createdAt']??null),'expires_at'=>dbTime($r['expiresAt']??null),'used_at'=>dbTime($r['usedAt']??null)]); $count('passwords'); }
    foreach ($data['loginTokens'] ?? [] as $r) { importer($pdo,'login_tokens',['institution_id'=>importInstitutionId(),'token_hash'=>stringValue($r,'tokenHash'),'student_id'=>stringValue($r,'studentId'),'component_id'=>stringValue($r,'examId'),'password_id'=>($r['passwordId']??null)?:null,'correlation_id'=>($r['correlationId']??null)?:null,'device_fingerprint'=>($r['deviceFingerprint']??null)?:null,'expires_at'=>dbTime($r['expiresAt']??null),'used_at'=>dbTime($r['usedAt']??null),'created_at'=>dbTime($r['createdAt']??null)]); $count('loginTokens'); }
    $questionLibraryById = [];
    foreach ($data['questions'] ?? [] as $libraryQuestion) $questionLibraryById[(string)($libraryQuestion['id'] ?? '')] = $libraryQuestion;
    $reviewQuestionsByAttempt = [];
    foreach ($data['results'] ?? [] as $resultSnapshot) {
        $linkedAttemptId = (string)($resultSnapshot['examSessionId'] ?? $resultSnapshot['sessionId'] ?? '');
        if ($linkedAttemptId !== '' && !empty($resultSnapshot['questions']) && is_array($resultSnapshot['questions'])) $reviewQuestionsByAttempt[$linkedAttemptId] = $resultSnapshot['questions'];
    }
    $targetedSessionChildren = defined('MYSQL_IMPORT_TARGETED_SESSION_CHILDREN') ? MYSQL_IMPORT_TARGETED_SESSION_CHILDREN : [];
    foreach ($data['sessions'] ?? [] as $r) {
        $attemptId=stringValue($r,'id');
        importer($pdo,'attempts',['institution_id'=>importInstitutionId(),'id'=>$attemptId,'student_id'=>stringValue($r,'studentId'),'component_id'=>stringValue($r,'examId'),'password_id'=>($r['passwordId']??null)?:null,'status'=>stringValue($r,'status'),'started_at'=>dbTime($r['startedAt']??null),'ends_at'=>dbTime($r['endsAt']??null),'submitted_at'=>dbTime($r['submittedAt']??null),'raw_score'=>isset($r['rawScore'])?(float)$r['rawScore']:null,'scaled_score'=>isset($r['score'])?(float)$r['score']:null,'access_token_hash'=>($r['accessTokenHash']??null)?:null,'correlation_id'=>($r['correlationId']??null)?:null,'device_fingerprint'=>($r['deviceFingerprint']??null)?:null,'ip_address'=>($r['ipAddress']??null)?:null,'locked_at'=>dbTime($r['lockedAt']??null),'locked_reason'=>($r['lockedReason']??null)?:null,'snapshot_json'=>jsonValue($r)]);
        $childPlan = $targetedSessionChildren[$attemptId] ?? null;
        $snapshotIds=[]; $snapshotQuestions=$r['questions']??[];
        if (!$snapshotQuestions && isset($reviewQuestionsByAttempt[$attemptId])) $snapshotQuestions=$reviewQuestionsByAttempt[$attemptId];
        if (!$snapshotQuestions && !empty($r['answers'])) { foreach (array_keys($r['answers']) as $questionId) { if (!isset($questionLibraryById[$questionId])) throw new RuntimeException("Attempt {$attemptId} refers to question {$questionId}, which is absent from every retained source snapshot."); $snapshotQuestions[]=$questionLibraryById[$questionId]; } $count('attemptSnapshotRowsDerivedFromQuestionBank'); }
        foreach ($snapshotQuestions as $ordinal=>$question) { $qid=stringValue($question,'id'); $snapshotIds[$qid]=true; if ($childPlan === null || !empty($childPlan['questions'])) importer($pdo,'attempt_questions',['institution_id'=>importInstitutionId(),'attempt_id'=>$attemptId,'question_id'=>$qid,'ordinal'=>(int)$ordinal,'question_json'=>jsonValue($question)]); }
        $answerFilter = $childPlan === null ? null : array_fill_keys(array_map('strval', (array)($childPlan['answers'] ?? [])), true);
        foreach (($r['answers']??[]) as $qid=>$answers) { if (!isset($snapshotIds[$qid])) throw new RuntimeException("Attempt {$attemptId} has answers for a question missing from its session, linked submission, or retained question-bank snapshot."); if ($answerFilter !== null && !isset($answerFilter[(string)$qid])) continue; foreach ((array)$answers as $answer) importer($pdo,'attempt_answers',['institution_id'=>importInstitutionId(),'attempt_id'=>$attemptId,'question_id'=>(string)$qid,'option_index'=>(int)$answer]); }
        if ($childPlan === null || !empty($childPlan['flags'])) foreach (($r['flagged']??[]) as $qid) { if (!isset($snapshotIds[$qid])) throw new RuntimeException("Attempt {$attemptId} flags a question missing from its session, linked submission, or retained question-bank snapshot."); importer($pdo,'attempt_flags',['institution_id'=>importInstitutionId(),'attempt_id'=>$attemptId,'question_id'=>(string)$qid]); }
        if ($childPlan === null || !empty($childPlan['integrity'])) foreach (($r['integrityEvents']??[]) as $event) importer($pdo,'attempt_integrity',['institution_id'=>importInstitutionId(),'attempt_id'=>$attemptId,'event_json'=>jsonValue($event)]);
        $count('sessions');
    }
    foreach ($data['results'] ?? [] as $r) { importer($pdo,'submissions',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'attempt_id'=>($r['examSessionId']??$r['sessionId']??null)?:null,'student_id'=>stringValue($r,'studentId'),'course_id'=>stringValue($r,'courseId'),'component_id'=>stringValue($r,'examId'),'academic_session_id'=>($r['academicSessionId']??null)?:null,'academic_semester_id'=>($r['academicSemesterId']??null)?:null,'component'=>strtolower(stringValue($r,'component','exam')) === 'test'?'test':'exam','status'=>stringValue($r,'status'),'raw_score'=>isset($r['rawScore'])?(float)$r['rawScore']:null,'scaled_score'=>isset($r['scaledScore'])?(float)$r['scaledScore']:null,'score'=>isset($r['score'])?(float)$r['score']:null,'submitted_at'=>dbTime($r['submittedAt']??null),'grade'=>($r['grade']??null)?:null,'grade_point'=>isset($r['gradePoint'])?(float)$r['gradePoint']:null,'quality_points'=>isset($r['qualityPoints'])?(float)$r['qualityPoints']:null,'course_unit'=>isset($r['courseUnit'])?(int)$r['courseUnit']:null,'review_snapshot_json'=>jsonValue(['questions'=>$r['questions']??[],'integrityEvents'=>$r['integrityEvents']??[]])]); $count('results'); }
    foreach ($data['calculatedResults'] ?? [] as $r) { $id=stringValue($r,'id'); importer($pdo,'calculated_results',['institution_id'=>importInstitutionId(),'id'=>$id,'student_id'=>stringValue($r,'studentId'),'session_id'=>stringValue($r,'sessionId'),'semester_id'=>stringValue($r,'semesterId'),'source_hash'=>stringValue($r,'sourceHash'),'semester_gpa'=>(float)($r['semesterGpa']??0),'cgpa'=>(float)($r['cgpa']??0),'calculated_at'=>dbTime($r['calculatedAt']??null)]); foreach (($r['items']??[]) as $item) importer($pdo,'calculated_items',['institution_id'=>importInstitutionId(),'calculated_result_id'=>$id,'course_id'=>stringValue($item,'courseId'),'item_json'=>jsonValue($item)]); $count('calculatedResults'); }
    foreach ($data['adminSessions'] ?? [] as $r) { importer($pdo,'admin_sessions',['institution_id'=>importInstitutionId(),'token_hash'=>stringValue($r,'tokenHash'),'user_id'=>stringValue($r,'userId'),'email'=>stringValue($r,'email'),'name'=>stringValue($r,'name'),'role_id'=>stringValue($r,'roleId'),'permissions_json'=>jsonValue($r['permissions']??[]),'correlation_id'=>($r['correlationId']??null)?:null,'created_at'=>dbTime($r['createdAt']??null),'last_seen_at'=>dbTime($r['lastSeenAt']??null),'expires_at'=>dbTime($r['expiresAt']??null)]); $count('adminSessions'); }
    foreach ($data['adminProfileOverrides'] ?? [] as $key=>$r) { importer($pdo,'profile_overrides',['institution_id'=>importInstitutionId(),'profile_key'=>(string)$key,'payload_json'=>jsonValue($r)]); $count('adminProfileOverrides'); }
    foreach ($data['adminUserActivity'] ?? [] as $key=>$r) { importer($pdo,'admin_activity',['institution_id'=>importInstitutionId(),'user_id'=>(string)$key,'last_login_at'=>dbTime($r['lastLoginAt']??null),'payload_json'=>jsonValue($r)]); $count('adminUserActivity'); }
    foreach ($data['pendingAdminRequests'] ?? [] as $r) { importer($pdo,'pending_requests',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'name'=>stringValue($r,'name'),'email'=>stringValue($r,'email'),'phone_number'=>($r['phoneNumber']??null)?:null,'role_id'=>($r['roleId']??null)?:null,'status'=>stringValue($r,'status'),'ip_address'=>($r['ipAddress']??null)?:null,'email_delivery'=>($r['emailDelivery']??null)?:null,'created_at'=>dbTime($r['createdAt']??null),'resolved_at'=>dbTime($r['resolvedAt']??null),'resolved_by'=>($r['resolvedBy']??null)?:null]); $count('pendingAdminRequests'); }
    foreach ($data['adminEmailVerifications'] ?? [] as $r) { importer($pdo,'email_verifications',['institution_id'=>importInstitutionId(),'user_id'=>stringValue($r,'userId'),'email'=>stringValue($r,'email'),'code_hash'=>stringValue($r,'codeHash'),'attempts'=>(int)($r['attempts']??0),'expires_at'=>dbTime($r['expiresAt']??null)]); $count('adminEmailVerifications'); }
    foreach ($data['adminPasswordResets'] ?? [] as $r) { importer($pdo,'password_resets',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'user_id'=>stringValue($r,'userId'),'email'=>stringValue($r,'email'),'code_hash'=>stringValue($r,'codeHash'),'attempts'=>(int)($r['attempts']??0),'created_at'=>dbTime($r['createdAt']??null),'expires_at'=>dbTime($r['expiresAt']??null)]); $count('adminPasswordResets'); }
    foreach ($data['admin2faChallenges'] ?? [] as $r) { importer($pdo,'two_factor',['institution_id'=>importInstitutionId(),'token_hash'=>stringValue($r,'tokenHash'),'user_id'=>stringValue($r,'userId'),'email'=>stringValue($r,'email'),'role_id'=>stringValue($r,'roleId'),'password_rate_limit_key'=>($r['passwordRateLimitKey']??null)?:null,'attempts'=>(int)($r['attempts']??0),'expires_at'=>dbTime($r['expiresAt']??null)]); $count('admin2faChallenges'); }
    foreach (($data['emergencyRecoveryCodes']['codes']??[]) as $position=>$r) { importer($pdo,'emergency_codes',['institution_id'=>importInstitutionId(),'code_position'=>$position+1,'code_hash'=>stringValue($r,'hash'),'generated_at'=>dbTime($data['emergencyRecoveryCodes']['generatedAt']??null)]); $count('emergencyRecoveryCodes'); }
    foreach ($data['rateLimits'] ?? [] as $key=>$r) { importer($pdo,'rate_limits',['institution_id'=>importInstitutionId(),'legacy_key'=>(string)$key,'scope'=>null,'subject_hash'=>null,'count_value'=>(int)($r['count']??0),'reset_at'=>unixTime($r['resetAt']??null),'payload_json'=>jsonValue($r)]); $count('rateLimits'); }
    foreach ($data['examLoginFailures'] ?? [] as $key=>$r) { importer($pdo,'login_failures',['institution_id'=>importInstitutionId(),'legacy_key'=>(string)$key,'failed_at_json'=>jsonValue($r['failedAt']??[]),'locked_until'=>unixTime($r['lockedUntil']??null)]); $count('examLoginFailures'); }
    foreach ($data['examLoginIpAttempts'] ?? [] as $key=>$r) { importer($pdo,'login_ip_attempts',['institution_id'=>importInstitutionId(),'legacy_key'=>(string)$key,'attempted_at_json'=>jsonValue($r['attemptedAt']??[])]); $count('examLoginIpAttempts'); }
    foreach ($data['examFlags'] ?? [] as $r) { importer($pdo,'exam_flags',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'attempt_id'=>stringValue($r,'sessionId'),'flag_type'=>stringValue($r,'flagType'),'ip_address'=>($r['ipAddress']??null)?:null,'resulting_action'=>($r['resultingAction']??null)?:null,'timestamp_at'=>dbTime($r['timestamp']??null)]); $count('examFlags'); }
    foreach ($data['newsletterSubscribers'] ?? [] as $r) { importer($pdo,'subscribers',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'student_id'=>($r['studentId']??null)?:null,'name'=>stringValue($r,'name'),'email'=>stringValue($r,'email'),'source'=>($r['source']??null)?:null,'status'=>stringValue($r,'status'),'subscribed_at'=>dbTime($r['subscribedAt']??null),'updated_at'=>dbTime($r['updatedAt']??null),'verified_at'=>dbTime($r['verifiedAt']??null)]); $count('newsletterSubscribers'); }
    foreach ($data['newsletters'] ?? [] as $r) { $id=stringValue($r,'id'); importer($pdo,'newsletters',['institution_id'=>importInstitutionId(),'id'=>$id,'subject'=>stringValue($r,'subject'),'content'=>stringValue($r,'content'),'sender'=>($r['sender']??null)?:null,'created_by'=>($r['createdBy']??null)?:null,'created_at'=>dbTime($r['createdAt']??null),'recipient_count'=>(int)($r['recipientCount']??0),'accepted_count'=>(int)($r['acceptedCount']??0),'failed_count'=>(int)($r['failedCount']??0),'status'=>stringValue($r,'status')]); foreach (($r['deliveries']??[]) as $delivery) { $email=(string)($delivery['email']??$delivery['recipient']??''); if($email!=='') importer($pdo,'newsletter_deliveries',['institution_id'=>importInstitutionId(),'newsletter_id'=>$id,'recipient_email'=>$email,'delivery_json'=>jsonValue($delivery)]); } $count('newsletters'); }
    foreach (($data['settings']['gradingScale']??[]) as $ordinal=>$r) importer($pdo,'grading_bands',['institution_id'=>importInstitutionId(),'ordinal'=>$ordinal,'min_score'=>(float)($r['minScore']??0),'max_score'=>(float)($r['maxScore']??0),'grade'=>stringValue($r,'grade'),'grade_point'=>(float)($r['gradePoint']??0)]);
    foreach (($data['settings']['integrityPolicy']??[]) as $event=>$r) importer($pdo,'integrity_policies',['institution_id'=>importInstitutionId(),'event_type'=>(string)$event,'mode'=>stringValue($r,'mode'),'lock_after'=>(int)($r['lockAfter']??0)]);
    foreach (['examSecurity','backup','auditRetention','resultLogoUrl','studentPortalSetupMode'] as $key) if (!(defined('MYSQL_IMPORT_TARGETED') && MYSQL_IMPORT_TARGETED) || array_key_exists($key, $data['settings'] ?? [])) importer($pdo,'settings',['institution_id'=>importInstitutionId(),'setting_key'=>$key,'setting_json'=>jsonValue($data['settings'][$key]??null)]);
    if (!(defined('MYSQL_IMPORT_TARGETED') && MYSQL_IMPORT_TARGETED) || array_key_exists('migrations', $data)) importer($pdo,'settings',['institution_id'=>importInstitutionId(),'setting_key'=>'legacy_migrations','setting_json'=>jsonValue($data['migrations']??[])]);
    foreach ($data['dashboardHiddenOutcomes'] ?? [] as $value) { importer($pdo,'hidden_outcomes',['institution_id'=>importInstitutionId(),'outcome_key'=>(string)$value]); $count('dashboardHiddenOutcomes'); }
    foreach ($data['auditEvents'] ?? [] as $r) { importer($pdo,'audit_events',['institution_id'=>importInstitutionId(),'id'=>stringValue($r,'id'),'timestamp_at'=>dbTime($r['timestamp']??null),'actor_type'=>stringValue($r,'actorType'),'actor_id'=>($r['actorId']??null)?:null,'action_type'=>stringValue($r,'actionType'),'target_type'=>stringValue($r,'targetType'),'target_id'=>($r['targetId']??null)?:null,'ip_address'=>($r['ipAddress']??null)?:null,'user_agent'=>($r['userAgent']??null)?:null,'outcome'=>($r['outcome']??null)?:null,'correlation_id'=>($r['correlationId']??null)?:null,'before_after_json'=>jsonValue($r['beforeAfter']??[]),'metadata_json'=>jsonValue($r['metadata']??[])]); $count('auditEvents'); }
    if (!$inMemory) {
        $preparedId = 'mysql-migration-prepared-' . substr($sourceHash,0,16);
        importer($pdo,'audit_events',['institution_id'=>importInstitutionId(),'id'=>$preparedId,'timestamp_at'=>dbTime($manifest['timestamp']??date('c')),'actor_type'=>stringValue($manifest,'actorType','system'),'actor_id'=>stringValue($manifest,'actorId','storage-migration-cli'),'action_type'=>stringValue($manifest,'actionType'),'target_type'=>stringValue($manifest,'targetType'),'target_id'=>stringValue($manifest,'targetId'),'ip_address'=>null,'user_agent'=>null,'outcome'=>stringValue($manifest,'outcome','success'),'correlation_id'=>'storage-migration-' . substr($sourceHash,0,16),'before_after_json'=>jsonValue([]),'metadata_json'=>jsonValue($manifest['metadata']??[])]);
        $count('migrationAuditEvent');
    }
    if (!(defined('MYSQL_IMPORT_TARGETED') && MYSQL_IMPORT_TARGETED)) {
        $backupDir=$root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'backups';
        if (importInstitutionId() !== 1) $backupDir .= DIRECTORY_SEPARATOR . 'institution-' . importInstitutionId();
        foreach (glob($backupDir.DIRECTORY_SEPARATOR.'cacsa-cbt-*.json') ?: [] as $path) { $filename=basename($path); if (str_starts_with($filename,'cacsa-cbt-audit-archive_')) { $archive=json_decode((string)file_get_contents($path),true); importer($pdo,'audit_archives',['institution_id'=>importInstitutionId(),'filename'=>$filename,'event_count'=>is_array($archive['events']??null)?count($archive['events']):null,'range_from'=>dbTime($archive['_auditArchive']['from']??null),'range_to'=>dbTime($archive['_auditArchive']['to']??null),'created_at'=>dbTime(date('c',filemtime($path))),'checksum_sha256'=>hash_file('sha256',$path)]); } else { importer($pdo,'backup_records',['institution_id'=>importInstitutionId(),'filename'=>$filename,'backup_type'=>str_contains($filename,'safety')?'safety':'backup','created_at'=>dbTime(date('c',filemtime($path))),'created_by'=>null,'size_bytes'=>filesize($path),'checksum_sha256'=>hash_file('sha256',$path),'metadata_json'=>jsonValue([])]); } }
    }
    if (!$inMemory) {
        $finish=$pdo->prepare('UPDATE storage_migrations SET status=:status,details_json=:details_json,completed_at=:completed_at WHERE institution_id=:institution_id AND migration_key=:migration_key');
        $finish->execute(['status'=>'completed','details_json'=>jsonValue(['sourceCounts'=>$counts]),'completed_at'=>dbTime(date('c')),'institution_id'=>importInstitutionId(),'migration_key'=>MIGRATION_KEY]);
    }
    $pdo->commit();
    $result = ['status'=>'completed','sourceSha256'=>$sourceHash,'counts'=>$counts];
    if ($inMemory) return $result;
    echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // A short retry is safe here because the whole changed-row payload is
    // inside this transaction.  This replaces the former application-wide
    // file lock while preserving atomicity under normal InnoDB deadlocks.
    $retryable = in_array((string)$error->getCode(), ['1205', '1213', '40001'], true)
        || str_contains(strtolower($error->getMessage()), 'deadlock')
        || str_contains(strtolower($error->getMessage()), 'lock wait timeout');
    if ($inMemory && defined('MYSQL_IMPORT_TARGETED') && MYSQL_IMPORT_TARGETED && $retryable && $targetedTransactionAttempt < 4) {
        usleep((int)(2000 * (1 << $targetedTransactionAttempt) + random_int(0, 2000)));
        $targetedTransactionAttempt++;
        goto targeted_import_retry;
    }
    if ($inMemory) throw $error;
    fwrite(STDERR, 'Migration rolled back: '.$error->getMessage().PHP_EOL); exit(1);
}
