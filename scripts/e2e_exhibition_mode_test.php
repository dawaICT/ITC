<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db/connect.php';

$base = 'http://localhost/wucportal';
$password = 'Exhibition@2026';
$pass = 0;
$fail = 0;

function exh_assert(bool $condition, string $label, string $detail = ''): void
{
    global $pass, $fail;
    $condition ? $pass++ : $fail++;
    echo ($condition ? '[OK]   ' : '[FAIL] ') . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
}

/** @return array{code:int,location:string,body:string} */
function exh_http(string $url, string $jar, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $headerSize);
    $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $match) ? trim($match[1]) : '';
    return ['code' => $code, 'location' => $location, 'body' => substr($raw, $headerSize)];
}

function exh_csrf(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $match) ? $match[1] : '';
}

function exh_login(string $base, string $kind, string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'exhibition_login_');
    $page = $kind === 'student' ? '/student_login.php' : ($kind === 'applicant' ? '/applicant_login.php' : '/staff_login.php');
    $form = exh_http($base . $page, $jar);
    $fields = ['csrf_token' => exh_csrf($form['body']), 'password' => $password];
    if ($kind === 'student') {
        $fields['Sid'] = $username;
    } elseif ($kind === 'applicant') {
        $fields['username'] = $username;
    } else {
        $fields['user_id'] = $username;
    }
    $login = exh_http($base . $page, $jar, $fields);
    return [$jar, $login];
}

$mode = $db->query("SELECT setting_value FROM portal_settings WHERE setting_key='exhibition_mode' LIMIT 1")->fetch_assoc();
exh_assert(($mode['setting_value'] ?? '0') === '1', 'Exhibition Mode configuration is enabled');

$checks = [
    ['applicant', 'exh-applicant@exhibition.test', '/admissions/applicant_portal.php', 'Application Status'],
    ['student', 'EXH-STU-001', '/students/index.php', 'Student'],
    ['staff', 'EXH-LEC-001', '/lecturers/index.php', 'Lecturer'],
    ['staff', 'EXH-ADM-001', '/admissions/index.php', 'Admission'],
    ['staff', 'EXH-REG-001', '/registrar/index.php', 'Registrar'],
    ['staff', 'EXH-ADMIN-001', '/admin/index.php', 'Dashboard'],
    ['staff', 'EXH-EMP-001', '/employer/index.php', 'Employer Portal'],
    ['student', 'EXH-ALU-001', '/alumni/index.php', 'Alumni Portal'],
];

foreach ($checks as [$kind, $username, $page, $needle]) {
    [$jar, $login] = exh_login($base, $kind, $username, $password);
    $loggedIn = in_array($login['code'], [302, 303], true)
        && $login['location'] !== ''
        && stripos($login['location'], 'login.php') === false
        && stripos($login['location'], 'change_password.php') === false;
    exh_assert($loggedIn, "{$username} authenticates", $login['location']);
    $dashboard = exh_http($base . $page, $jar);
    exh_assert($dashboard['code'] === 200 && stripos($dashboard['body'], $needle) !== false, "{$username} reaches its scoped workspace", "HTTP {$dashboard['code']}");
    if ($username === 'EXH-STU-001') {
        exh_assert(stripos($dashboard['body'], 'Attendance') !== false, 'student dashboard surfaces attendance summary');
        exh_assert(stripos($dashboard['body'], 'Skills') !== false, 'student dashboard links skills and career');
        exh_assert(stripos($dashboard['body'], 'Recent Results') !== false, 'student dashboard shows published results');
        $attendance = exh_http($base . '/students/attendance.php', $jar);
        exh_assert($attendance['code'] === 200 && stripos($attendance['body'], 'My Attendance') !== false, 'student attendance summary page loads');
        exh_assert(stripos($attendance['body'], 'DCSE-101') !== false, 'student attendance shows seeded course check-ins');
        $skills = exh_http($base . '/students/skill_discovery.php', $jar);
        exh_assert($skills['code'] === 200 && stripos($skills['body'], 'Evidence') !== false, 'student skills are rendered from academic evidence');
        exh_assert(stripos($skills['body'], 'Career relevance') !== false, 'student skills include an evidence-linked career recommendation');
        $elearning = exh_http($base . '/students/elearning/index.php', $jar);
        exh_assert($elearning['code'] === 200, 'student reaches the separate eLearning workspace');
        $materials = exh_http($base . '/students/materials.php', $jar);
        exh_assert($materials['code'] === 200 && stripos($materials['body'], 'Exhibition:') !== false, 'student materials list includes seeded eLearning content');
        $resultsAlias = exh_http($base . '/students/results.php', $jar);
        $resultsOk = in_array($resultsAlias['code'], [302, 303], true)
            && stripos((string)$resultsAlias['location'], 'continuousAssessment.php') !== false;
        exh_assert($resultsOk, 'results.php alias redirects to continuous assessment');
        $assistant = exh_http($base . '/students/learning_assistant.php', $jar);
        exh_assert($assistant['code'] === 200 && stripos($assistant['body'], 'Learning Assistant') !== false, 'permission-aware student AI workspace loads');
    }
    if ($username === 'EXH-ADMIN-001') {
        $analytics = exh_http($base . '/admin/analytics_dashboard.php', $jar);
        exh_assert($analytics['code'] === 200 && stripos($analytics['body'], 'Analytics') !== false, 'management analytics loads from the exhibition database');
    }
    if ($username === 'EXH-ALU-001') {
        exh_assert(stripos($dashboard['body'], 'data:image/svg+xml;base64,') !== false, 'alumni workspace renders an offline employer-verification QR');
    }
    @unlink($jar);
}

$verificationJar = tempnam(sys_get_temp_dir(), 'cert_verify_');
$verification = exh_http($base . '/verify_certificate.php?cert=EXH-CERT-2025-001', $verificationJar);
exh_assert($verification['code'] === 200 && stripos($verification['body'], 'OFFICIALLY VERIFIED CREDENTIAL') !== false, 'public employer certificate verification succeeds');
@unlink($verificationJar);

$counts = [
    'pending application' => "SELECT COUNT(*) total FROM online_applicants WHERE email='exh-applicant@exhibition.test' AND status='pending'",
    'student course registrations' => "SELECT COUNT(*) total FROM course_registration WHERE Sid='EXH-STU-001' AND is_active=1",
    'student invoice' => "SELECT COUNT(*) total FROM invoices WHERE student_id='EXH-STU-001' AND balance=3500.00",
    'published results' => "SELECT COUNT(*) total FROM semester_assessment WHERE Sid='EXH-STU-001' AND LOWER(status)='published'",
    'lecturer assignments' => "SELECT COUNT(*) total FROM course_lecturer WHERE staff_id='EXH-LEC-001' AND status='active'",
    'employer placement' => "SELECT COUNT(*) total FROM employer_internships WHERE student_id='EXH-STU-001'",
    'graduate clearance' => "SELECT COUNT(*) total FROM student_clearance WHERE student_id='EXH-ALU-001' AND graduation_status='Graduated'",
    'alumni certificate' => "SELECT COUNT(*) total FROM alumni_certificates WHERE student_id='EXH-ALU-001' AND status='Approved'",
    'attendance logs' => "SELECT COUNT(*) total FROM attendance_logs WHERE Sid='EXH-STU-001'",
    'eLearning material' => "SELECT COUNT(*) total FROM lesson_notes WHERE notes='EXH_DCSE-101_network_intro.pdf'",
];
foreach ($counts as $label => $sql) {
    $row = $db->query($sql)->fetch_assoc();
    exh_assert((int)($row['total'] ?? 0) > 0, "Seed contains {$label}");
}

$offlineCss = dirname(__DIR__) . '/assets/vendor/bootstrap/5.3.2/bootstrap.min.css';
$offlineJs = dirname(__DIR__) . '/assets/vendor/bootstrap/5.3.2/bootstrap.bundle.min.js';
$offlineFa = dirname(__DIR__) . '/assets/vendor/fontawesome/6.4.0/css/all.min.css';
exh_assert(is_file($offlineCss) && filesize($offlineCss) > 10000, 'local Bootstrap CSS is available for offline exhibition');
exh_assert(is_file($offlineJs) && filesize($offlineJs) > 10000, 'local Bootstrap JS is available for offline exhibition');
exh_assert(is_file($offlineFa) && filesize($offlineFa) > 10000, 'local Font Awesome CSS is available for offline exhibition');
exh_assert(stripos($verification['body'], 'cdn.jsdelivr.net') === false && stripos($verification['body'], 'placehold.co') === false, 'certificate verification page does not depend on remote CDN assets');

echo PHP_EOL . "Result: {$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
