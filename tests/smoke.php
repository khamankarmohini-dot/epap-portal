<?php
/**
 * EPAP End-to-End Production & Security Test Suite
 * Self-contained: creates temporary test data via API/DB and cleans up after itself.
 *
 * Usage:
 *   php tests/smoke.php http://localhost:3000 [admin_id] [admin_password] [smtp_capture_log]
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$base = rtrim($argv[1] ?? 'http://localhost:3000', '/');
$adminId = $argv[2] ?? 'TEST-HOD-ADMIN';
$adminPw = $argv[3] ?? 'TestAdminPass123';
$smtpLog = $argv[4] ?? null;

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
}

function httpReq(string $method, string $url, ?array $json = null, ?string $cookieFile = null, ?string $csrf = null, ?array $multipart = null): array
{
    $ch = curl_init($url);
    $headers = [];
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    } elseif ($multipart !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
    }
    $respHeaders = '';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$respHeaders) {
            $respHeaders .= $h;
            return strlen($h);
        },
    ]);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $body, true) ?? (string) $body, $respHeaders];
}

function extractLatestOtpFromSmtpLog(string $smtpLog): ?string
{
    if (!is_file($smtpLog)) {
        return null;
    }
    $content = file_get_contents($smtpLog) ?: '';
    $parts = explode('---END_MSG---', $content);
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        if (preg_match('/Verification Code:\s*(\d{6})/', $parts[$i], $m)) {
            return $m[1];
        }
    }
    return null;
}

function loginViaRealOtp(string $base, string $id, string $pw, string $role, ?string $smtpLog): array
{
    $cookie = tempnam(sys_get_temp_dir(), 'epap_ck_');
    [, $d0] = httpReq('GET', "{$base}/api/auth/csrf", null, $cookie);
    $csrf = $d0['csrf_token'] ?? '';
    [$c1, $d1, $h1] = httpReq('POST', "{$base}/api/auth/login", ['identifier' => $id, 'password' => $pw, 'role' => $role], $cookie, $csrf);

    assertTrue($c1 === 200, "Login accepted for {$id} ({$role})");
    assertTrue(!isset($d1['devOtp']) && !str_contains(json_encode($d1), 'devOtp'), "Production login response never exposes devOtp for {$id}");
    assertTrue(!str_contains($h1, 'devOtp'), "Production login headers never expose devOtp for {$id}");

    // Check otp-session ignores ?devOtp= and never returns devOtp in production
    [, $sessData] = httpReq('GET', "{$base}/api/auth/otp-session?devOtp=999999", null, $cookie);
    assertTrue(($sessData['active'] ?? false) === true && ($sessData['isLocal'] ?? true) === false && !isset($sessData['devOtp']), "otp-session enforces production mode & ignores ?devOtp=");

    $otp = $smtpLog ? extractLatestOtpFromSmtpLog($smtpLog) : ($d1['devOtp'] ?? null);
    assertTrue($otp !== null && strlen($otp) === 6, "6-digit OTP captured from SMTP delivery for {$id}");

    // Test wrong OTP first
    [$cw] = httpReq('POST', "{$base}/api/auth/verify-otp", ['otp' => '000000'], $cookie, $csrf);
    assertTrue($cw === 401, "Wrong OTP rejected with 401");

    // Test valid OTP
    [$c2, $d2] = httpReq('POST', "{$base}/api/auth/verify-otp", ['otp' => $otp], $cookie, $csrf);
    assertTrue($c2 === 200 && ($d2['success'] ?? false) === true, "Valid OTP verified for {$id}");

    // Test reused OTP rejected
    [$cr] = httpReq('POST', "{$base}/api/auth/verify-otp", ['otp' => $otp], $cookie, $csrf);
    assertTrue($cr === 400 || $cr === 401, "Reused OTP rejected");

    [, $d3] = httpReq('GET', "{$base}/api/auth/me", null, $cookie);
    return [$cookie, $d3['csrf_token'] ?? $csrf];
}

// 1. Health & Sensitive-File Blocking
[$hc, $hd, $hh] = httpReq('GET', "{$base}/api/health");
assertTrue($hc === 200 && $hd === ['status' => 'ok'], 'GET /api/health returns only {status:"ok"}');
assertTrue(stripos($hh, 'Cache-Control: no-store') !== false, 'API responses include Cache-Control: no-store');

$sensitivePaths = [
    '/.env', '/app/bootstrap.php', '/storage/logs/app.log', '/database/schema.sql',
    '/docs/SETUP.md', '/vendor/autoload.php', '/tests/smoke.php', '/setup.php', '/bin/backup.php', '/epap_backup.sql',
    '/docker/entrypoint.sh', '/Dockerfile', '/render.yaml'
];
foreach ($sensitivePaths as $sp) {
    [$sc] = httpReq('GET', "{$base}{$sp}");
    assertTrue($sc === 403, "Sensitive path blocked (403): {$sp}");
}

[$hdc] = httpReq('GET', "{$base}/api/health/deep");
assertTrue($hdc === 403, 'GET /api/health/deep without HEALTH_KEY returns 403');

// 2. HTML pages load (200)
foreach (['/index.html', '/login.html', '/verify-otp.html', '/admin-dashboard.html', '/admin-management.html', '/faculty-dashboard.html', '/student-dashboard.html', '/profile.html'] as $page) {
    [$pc] = httpReq('GET', "{$base}{$page}");
    assertTrue($pc === 200, "Page loads (200): {$page}");
}

// 3. Unauthenticated & CSRF checks
[$uc] = httpReq('GET', "{$base}/api/student/profile");
assertTrue($uc === 401, 'Unauthenticated /api/student/profile returns 401');

[$cc] = httpReq('POST', "{$base}/api/auth/login", ['identifier' => 'x', 'password' => 'y', 'role' => 'student']);
assertTrue($cc === 403, 'POST without CSRF token rejected with 403');

// 4. Admin login + Empty-DB checks + Create Subject, Faculty, Student, Allocation, Exam
[$admCookie, $admCsrf] = loginViaRealOtp($base, $adminId, $adminPw, 'admin', $smtpLog);

[$es, $esData] = httpReq('GET', "{$base}/api/admin/stats", null, $admCookie);
assertTrue($es === 200 && isset($esData['total_students']), 'Admin stats loads on empty/clean DB');

$suffix = (string) random_int(1000, 9999);
$subCode = 'TST' . $suffix;
[$csC, $csD] = httpReq('POST', "{$base}/api/admin/subjects", [
    'code' => $subCode, 'name' => 'Smoke Test Subject', 'semester' => 5, 'credits' => 4
], $admCookie, $admCsrf);
assertTrue($csC === 201 && !empty($csD['id']), 'Admin creates Subject');
$subId = (int) $csD['id'];

$facIdent = 'FAC-TST-' . $suffix;
$facEmail = "fac.tst.{$suffix}@example.edu";
[$cfC, $cfD] = httpReq('POST', "{$base}/api/admin/faculty", [
    'faculty_id' => $facIdent, 'full_name' => 'Test Faculty', 'email' => $facEmail
], $admCookie, $admCsrf);
assertTrue($cfC === 201 && !empty($cfD['temp_password']), 'Admin creates Faculty with generated temp password');
$facId = (int) $cfD['id'];
$facTempPw = $cfD['temp_password'];

$stuIdent = 'STU-TST-' . $suffix;
$stuEmail = "stu.tst.{$suffix}@example.edu";
[$cstC, $cstD] = httpReq('POST', "{$base}/api/admin/students", [
    'zprn' => $stuIdent, 'full_name' => 'Test Student', 'email' => $stuEmail, 'year' => 'TE-IT', 'division' => 'A', 'roll_no' => 1, 'semester' => 5
], $admCookie, $admCsrf);
assertTrue($cstC === 201 && !empty($cstD['temp_password']), 'Admin creates Student with generated temp password');
$stuTempPw = $cstD['temp_password'];

// Year/semester validation check
[$badStuC] = httpReq('POST', "{$base}/api/admin/students", [
    'zprn' => 'BAD-' . $suffix, 'full_name' => 'Bad Student', 'email' => "bad.{$suffix}@example.edu", 'year' => 'SE-IT', 'division' => 'A', 'roll_no' => 2, 'semester' => 5
], $admCookie, $admCsrf);
assertTrue($badStuC === 422, 'Admin student creation rejects inconsistent year/semester (SE-IT + Sem 5) with 422');

[$admSumC, $admSumD] = httpReq('GET', "{$base}/api/admin/summary", null, $admCookie);
assertTrue($admSumC === 200 && isset($admSumD['total_students']) && isset($admSumD['inconsistent_students']), 'GET /api/admin/summary returns expected counters');

[$incC, $incD] = httpReq('GET', "{$base}/api/admin/students/inconsistent", null, $admCookie);
assertTrue($incC === 200 && is_array($incD), 'GET /api/admin/students/inconsistent returns array');

[$alC, $alD] = httpReq('POST', "{$base}/api/admin/allocations", [
    'faculty_id' => $facId, 'subject_id' => $subId, 'year' => 'TE-IT', 'division' => 'A'
], $admCookie, $admCsrf);
assertTrue($alC === 201, 'Admin creates Faculty Allocation');

// 5. Faculty login, forced password change, create activity
[$facCookie, $facCsrf] = loginViaRealOtp($base, $facIdent, $facTempPw, 'faculty', $smtpLog);
$facNewPw = 'FacultyNewPass' . $suffix;
[$fpwC] = httpReq('POST', "{$base}/api/auth/change-password", ['current_password' => $facTempPw, 'new_password' => $facNewPw], $facCookie, $facCsrf);
assertTrue($fpwC === 200, 'Faculty completes forced password change');

[$actC, $actD] = httpReq('POST', "{$base}/api/faculty/activities", [
    'subject_id' => $subId, 'title' => 'Smoke Activity ' . $suffix, 'activity_type' => 'Lab Practical Journal',
    'max_marks' => 25, 'deadline' => date('Y-m-d H:i:s', time() + 86400), 'year' => 'TE-IT', 'division' => 'A'
], $facCookie, $facCsrf);
assertTrue($actC === 201 && !empty($actD['id']), 'Faculty creates Activity');
$actId = (int) $actD['id'];

// 6. Student login, forced password change, role isolation, upload validation, analytics empty state
[$stuCookie, $stuCsrf] = loginViaRealOtp($base, $stuIdent, $stuTempPw, 'student', $smtpLog);
$stuNewPw = 'StudentNewPass' . $suffix;
[$spwC] = httpReq('POST', "{$base}/api/auth/change-password", ['current_password' => $stuTempPw, 'new_password' => $stuNewPw], $stuCookie, $stuCsrf);
assertTrue($spwC === 200, 'Student completes forced password change');

[$rc1] = httpReq('GET', "{$base}/api/faculty/stats", null, $stuCookie);
[$rc2] = httpReq('GET', "{$base}/api/admin/stats", null, $stuCookie);
assertTrue($rc1 === 403 && $rc2 === 403, 'Role access control: Student blocked from faculty/admin (403)');

// Summary counter consistency before submission
[$stuSum1C, $stuSum1D] = httpReq('GET', "{$base}/api/student/summary", null, $stuCookie);
[$stuSt1C, $stuSt1D] = httpReq('GET', "{$base}/api/student/stats", null, $stuCookie);
assertTrue(
    $stuSum1C === 200 && $stuSt1C === 200 && (int) ($stuSum1D['pending_activities'] ?? -1) === (int) ($stuSt1D['pending_activities'] ?? -2) && (int) $stuSum1D['pending_activities'] >= 1,
    'Student summary & stats pending_activities agree before submission'
);

[$facSum1C, $facSum1D] = httpReq('GET', "{$base}/api/faculty/summary", null, $facCookie);
assertTrue($facSum1C === 200 && isset($facSum1D['pending_evaluations']) && is_array($facSum1D['allocations'] ?? null), 'GET /api/faculty/summary returns counters & allocations');

$badFile = tempnam(sys_get_temp_dir(), 'bad_') . '.php';
file_put_contents($badFile, '<?php echo 1; ?>');
[$upBad] = httpReq('POST', "{$base}/api/student/submit", null, $stuCookie, $stuCsrf, [
    'activity_id' => $actId, 'file' => new CURLFile($badFile, 'application/x-php', 'evil.php')
]);
@unlink($badFile);
assertTrue($upBad === 422, 'Upload rejects .php file with 422');

$goodFile = tempnam(sys_get_temp_dir(), 'ok_') . '.txt';
file_put_contents($goodFile, 'Smoke test submission');
[$upOk] = httpReq('POST', "{$base}/api/student/submit", null, $stuCookie, $stuCsrf, [
    'activity_id' => $actId, 'file' => new CURLFile($goodFile, 'text/plain', 'solution.txt')
]);
@unlink($goodFile);
assertTrue($upOk === 200, 'Student submits valid .txt file');

// Summary counter consistency after submission
[$stuSum2C, $stuSum2D] = httpReq('GET', "{$base}/api/student/summary", null, $stuCookie);
[$stuSt2C, $stuSt2D] = httpReq('GET', "{$base}/api/student/stats", null, $stuCookie);
assertTrue(
    $stuSum2C === 200 && $stuSt2C === 200 &&
    (int) ($stuSum2D['pending_activities'] ?? -1) === (int) ($stuSt2D['pending_activities'] ?? -2) &&
    (int) $stuSum2D['pending_activities'] === (int) $stuSum1D['pending_activities'] - 1,
    'Student summary & stats pending_activities both decrement by 1 after submission'
);

[$anC, $anD] = httpReq('GET', "{$base}/api/student/analytics", null, $stuCookie);
assertTrue($anC === 200 && ($anD['prediction']['enough_data'] ?? true) === false, 'Empty-DB student analytics returns enough_data: false');

// 7. Faculty grades student submission
[, $fSubs] = httpReq('GET', "{$base}/api/faculty/submissions?activity_id={$actId}", null, $facCookie);
$submId = (int) ($fSubs[0]['id'] ?? 0);
[$grC] = httpReq('POST', "{$base}/api/faculty/grade", ['submission_id' => $submId, 'marks' => 23, 'feedback' => 'Well done'], $facCookie, $facCsrf);
assertTrue($grC === 200, 'Faculty grades submission');

// 8. Admin creates Exam, imports marks, publishes result, Student sees result
[$exC, $exD] = httpReq('POST', "{$base}/api/admin/exams", [
    'exam_name' => 'Smoke Exam ' . $suffix, 'year' => 'TE-IT', 'semester' => 5, 'exam_type' => 'combined', 'academic_year' => '2025-26'
], $admCookie, $admCsrf);
assertTrue($exC === 201 && !empty($exD['id']), 'Admin creates Exam Session');
$examId = (int) $exD['id'];

$marksCsv = tempnam(sys_get_temp_dir(), 'mks_') . '.csv';
file_put_contents($marksCsv, "ZPRN,SubjectCode,Semester,InSem,EndSem,Practical\n{$stuIdent},{$subCode},5,25,58,44\n");
[$mkC] = httpReq('POST', "{$base}/api/admin/marks/import", null, $admCookie, $admCsrf, ['file' => new CURLFile($marksCsv, 'text/csv', 'marks.csv')]);
@unlink($marksCsv);
assertTrue($mkC === 200, 'Admin imports marks CSV');

[$pubC] = httpReq('POST', "{$base}/api/admin/exams/{$examId}/status", ['status' => 'published'], $admCookie, $admCsrf);
assertTrue($pubC === 200, 'Admin publishes Exam Results');

[$sResC, $sResD] = httpReq('GET', "{$base}/api/student/results", null, $stuCookie);
assertTrue($sResC === 200 && count($sResD) > 0, 'Student sees published exam result');

// 9. Resend cooldown & Login throttling
$thCookie = tempnam(sys_get_temp_dir(), 'th_');
[, $thCsrfRes] = httpReq('GET', "{$base}/api/auth/csrf", null, $thCookie);
$thCsrf = $thCsrfRes['csrf_token'] ?? '';
for ($i = 0; $i < 5; $i++) {
    httpReq('POST', "{$base}/api/auth/login", ['identifier' => 'THROTTLE-' . $suffix, 'password' => 'wrong', 'role' => 'student'], $thCookie, $thCsrf);
}
[$thC] = httpReq('POST', "{$base}/api/auth/login", ['identifier' => 'THROTTLE-' . $suffix, 'password' => 'wrong', 'role' => 'student'], $thCookie, $thCsrf);
assertTrue($thC === 429, 'Login throttling returns 429 after 5 failed attempts');

// 10. Cleanup temporary test records
$pdo = Database::getConnection();
$pdo->prepare('DELETE FROM exam_results_release WHERE id = ?')->execute([$examId]);
$pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute([$subId]);
$pdo->prepare('DELETE FROM users WHERE identifier IN (?, ?)')->execute([$facIdent, $stuIdent]);
$pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute(['THROTTLE-' . $suffix]);

echo "\n=== Production Smoke Test Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
