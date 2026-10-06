$ErrorActionPreference = 'Stop'

$base = 'http://localhost/BEREVION/api.php'
$tokenPath = Join-Path (Split-Path $PSScriptRoot -Parent) 'database\.cutover-smoke-admin-token'
$adminToken = (Get-Content $tokenPath -Raw).Trim()
$adminWeb = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$adminWeb.Cookies.Add((New-Object System.Net.Cookie('CBT_ADMIN_SESSION', $adminToken, '/BEREVION', 'localhost')))
$adminCsrf = (Invoke-RestMethod -WebSession $adminWeb -Uri ($base + '?action=auth-csrf')).csrfToken

function Invoke-AdminApi([string]$Action, [string]$Method = 'GET', $Payload = $null, [string]$Query = '') {
    $uri = $base + '?action=' + $Action + $Query
    $args = @{ Uri = $uri; Method = $Method; WebSession = $adminWeb; Headers = @{ 'X-CSRF-Token' = $adminCsrf } }
    if ($null -ne $Payload) { $args.ContentType = 'application/json'; $args.Body = ($Payload | ConvertTo-Json -Depth 12 -Compress) }
    Invoke-RestMethod @args
}
function Invoke-StudentApi([string]$Action, [string]$Method = 'POST', $Payload = $null) {
    $uri = $base + '?action=' + $Action
    $args = @{ Uri = $uri; Method = $Method; WebSession = $studentWeb; Headers = @{ 'X-CSRF-Token' = $studentCsrf } }
    if ($null -ne $Payload) { $args.ContentType = 'application/json'; $args.Body = ($Payload | ConvertTo-Json -Depth 12 -Compress) }
    Invoke-RestMethod @args
}

$componentId = 'e926e66d2eb63630' # CSC 105 --- Test
$courseId = 'legacy-course-cs105'
$student = @{ id = 'ddde2e5403430513'; matric = '2023007387' }
$original = $null; $questionIds = @(); $resultId = $null; $safetyBackup = $null

try {
    $allComponents = (Invoke-AdminApi 'exams').items
    $original = $allComponents | Where-Object { $_.id -eq $componentId } | Select-Object -First 1
    if ($null -eq $original) { throw 'CSC 105 Test component was not found.' }
    $questionIds = @((Invoke-AdminApi 'questions' 'GET' $null ('&courseId=' + $courseId)).items | Where-Object { $_.text.StartsWith('[CUTOVER SMOKE]') } | ForEach-Object { $_.id })

    $temporaryQuestions = @(
        @{ text='[CUTOVER SMOKE] Which gate outputs 1 only when both inputs are 1?'; options=@('OR','AND','NOT','XOR'); correctOptions=@(1); type='single'; topic='Logic gates'; difficulty='easy' },
        @{ text='[CUTOVER SMOKE] In binary, what is decimal 2?'; options=@('01','10','11','100'); correctOptions=@(1); type='single'; topic='Binary'; difficulty='easy' },
        @{ text='[CUTOVER SMOKE] Which expression represents logical negation of A?'; options=@('A AND B','A OR B','NOT A','A XOR B'); correctOptions=@(2); type='single'; topic='Boolean algebra'; difficulty='easy' }
    )
    if ($questionIds.Count -eq 0) {
        foreach ($item in $temporaryQuestions) {
            $created = Invoke-AdminApi 'questions' 'POST' ($item + @{ courseId = $courseId })
            $questionIds += $created.item.id
        }
    }
    Invoke-AdminApi 'questions-publish-target' 'POST' @{ courseId=$courseId; target='test'; ids=@($questionIds) } | Out-Null

    $start = [DateTime]::UtcNow.AddMinutes(-1).ToString('o')
    $end = [DateTime]::UtcNow.AddMinutes(15).ToString('o')
    Invoke-AdminApi 'course-components' 'PUT' @{ duration=10; maxMark=30; passThreshold=15; questionCount=3; startAt=$start; endAt=$end; status='active' } ('&id=' + $componentId) | Out-Null
    $passwordResponse = Invoke-AdminApi 'exam-password' 'POST' @{ matricNumber=$student.matric; examId=$componentId }

    $studentWeb = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $studentCsrf = (Invoke-RestMethod -WebSession $studentWeb -Uri ($base + '?action=auth-csrf')).csrfToken
    Invoke-StudentApi 'student-login' 'POST' @{ matricNumber=$student.matric; examId=$componentId; password=$passwordResponse.password; deviceFingerprint='mysql-cutover-e2e-smoke' } | Out-Null
    $started = Invoke-StudentApi 'session-start' 'POST' @{}
    $firstQuestion = $started.questions | Select-Object -First 1
    Invoke-StudentApi 'session-answer' 'POST' @{ sessionId=$started.session.id; questionId=$firstQuestion.id; answers=@(0) } | Out-Null
    $submitted = Invoke-StudentApi 'session-submit' 'POST' @{ sessionId=$started.session.id }
    $resultId = $submitted.result.id
    if ([string]::IsNullOrWhiteSpace($resultId)) { throw 'The submitted Test did not return a result ID.' }

    $review = Invoke-AdminApi 'result-review' 'GET' $null ('&id=' + $resultId)
    if ($review.result.id -ne $resultId) { throw 'The administrator result-review endpoint did not return the new submission.' }
    $calculation = Invoke-AdminApi 'calculate-student-result' 'POST' @{ studentId=$student.id; sessionId='session-2026-2027'; semesterId='semester-harmattan-2026-2027' }
    if ($null -eq $calculation.calculatedResult) { throw 'Calculate Result did not return a calculated result.' }

    $deleted = Invoke-AdminApi 'component-submission-delete' 'POST' @{ resultId=$resultId; reason='Temporary MySQL cutover end-to-end verification submission.'; confirmation=$student.matric }
    $safetyBackup = $deleted.safetyBackup
    Invoke-AdminApi 'questions-bulk-delete' 'POST' @{ courseId=$courseId; ids=@($questionIds) } | Out-Null
    Invoke-AdminApi 'calculate-student-result' 'POST' @{ studentId=$student.id; sessionId='session-2026-2027'; semesterId='semester-harmattan-2026-2027' } | Out-Null

    [pscustomobject]@{ pass=$true; component='CSC 105 Test'; submissionId=$resultId; reviewRead=$true; calculated=$true; cleanupBackup=$safetyBackup; temporaryQuestionsRemoved=$questionIds.Count; componentRestored=$false } | ConvertTo-Json -Compress
}
finally {
    if ($null -ne $original) {
        Invoke-AdminApi 'course-components' 'PUT' @{ duration=[int]$original.duration; maxMark=[double]$original.maxMark; passThreshold=[double]$original.passThreshold; questionCount=[int]$original.questionCount; startAt=$original.startAt; endAt=$original.endAt; status=$original.status } ('&id=' + $componentId) | Out-Null
    }
    try { Invoke-AdminApi 'admin-logout' 'POST' @{} | Out-Null } catch {}
    Remove-Item -LiteralPath $tokenPath -Force -ErrorAction SilentlyContinue
}
