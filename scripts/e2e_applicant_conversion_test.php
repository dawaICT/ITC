<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db/connect.php';

$base = 'http://localhost/wucportal';
$email = 'ui.applicant.e2e@example.test';
$nrc = '667788/55/4';
$pass = 0;
$fail = 0;

function e2e_ok(bool $condition, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo '[OK]   ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
    } else {
        $fail++;
        echo '[FAIL] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
    }
}

/** @return array{code:int,location:string,body:string} */
function e2e_request(string $url, ?array $post, string $jar, bool $multipart = false): array
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
    }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $headerSize);
    $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $match) ? trim($match[1]) : '';
    return ['code' => $code, 'location' => $location, 'body' => substr($raw, $headerSize)];
}

function e2e_csrf(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $match) ? $match[1] : '';
}

function e2e_cleanup(mysqli $db, string $email, string $nrc): void
{
    $deleteApplicantUser = $db->prepare("DELETE FROM users WHERE username = ? AND primary_role = 'applicant'");
    $deleteApplicantUser->bind_param('s', $email);
    $deleteApplicantUser->execute();
    $deleteApplicantUser->close();

    $studentIds = [];
    $stmt = $db->prepare('SELECT SID FROM students WHERE email = ? OR nrc_pass = ?');
    $stmt->bind_param('ss', $email, $nrc);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $studentIds[] = (string)$row['SID'];
    }
    $stmt->close();
    foreach ($studentIds as $sid) {
        foreach ([
            ['processed_applicants_added', 'student_id'], ['finance_student_sponsors', 'student_id'],
            ['course_registration', 'Sid'], ['student_courses', 'student_id'],
            ['semester_registration', 'student_id'], ['portal_alerts', 'user_id'],
            ['invoices', 'student_id'], ['student_login', 'Sid'], ['student_program', 'Sid'],
            ['students', 'SID'],
        ] as [$table, $column]) {
            $delete = $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` = ?");
            $delete->bind_param('s', $sid);
            $delete->execute();
            $delete->close();
        }
    }
    foreach (['online_applicants', 'processed_applicants'] as $table) {
        $delete = $db->prepare("DELETE FROM `{$table}` WHERE email = ? OR nrc_pass = ?");
        $delete->bind_param('ss', $email, $nrc);
        $delete->execute();
        $delete->close();
    }
}

e2e_cleanup($db, $email, $nrc);
$jar = tempnam(sys_get_temp_dir(), 'applicant_e2e_');
$pdf = tempnam(sys_get_temp_dir(), 'application_doc_') . '.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

try {
    $form = e2e_request($base . '/online_services/index.php', null, $jar);
    $csrf = e2e_csrf($form['body']);
    e2e_ok($form['code'] === 200, 'public application page loads');
    e2e_ok($csrf !== '', 'public application form includes CSRF protection');

    $year = date('Y');
    $submission = e2e_request($base . '/online_services/index.php', [
        'csrf_token' => $csrf,
        'submit' => '1',
        'title' => 'Ms', 'Fname' => 'Interface', 'Lname' => 'Applicant', 'sex' => 'F',
        'nrc_pass' => $nrc, 'country' => 'Zambia', 'dob' => '2001-06-15',
        'mobile' => '0976554433', 'email' => $email, 'status' => 'pending',
        'account_password' => 'Applicant@2026', 'account_password_confirmation' => 'Applicant@2026',
        'h_addre' => 'Exhibition test address', 'p_addre' => 'Exhibition test address',
        'sponsor' => 'Self', 'next_kin' => 'Exhibition Guardian',
        'next_kin_mobile' => '0976000011', 'relat' => 'Parent', 'program' => 'ICT-001',
        'intake' => 'January ' . $year, 'mode' => 'Full-time', 'year' => $year,
        'results' => new CURLFile($pdf, 'application/pdf', 'results.pdf'),
        'nrc_file' => new CURLFile($pdf, 'application/pdf', 'nrc.pdf'),
        'deposit_slip' => new CURLFile($pdf, 'application/pdf', 'deposit.pdf'),
    ], $jar, true);
    e2e_ok($submission['code'] === 200, 'application submission returns a usable page');
    e2e_ok(stripos($submission['body'], 'submitted successfully') !== false, 'applicant sees submission confirmation');

    $stmt = $db->prepare('SELECT id, results, nrc_file, deposit_slip FROM online_applicants WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $application = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $applicationId = (int)($application['id'] ?? 0);
    e2e_ok($applicationId > 0, 'submitted application is persisted');

    $applicantLoginPage = e2e_request($base . '/applicant_login.php', null, $jar);
    $applicantLogin = e2e_request($base . '/applicant_login.php', [
        'csrf_token' => e2e_csrf($applicantLoginPage['body']),
        'username' => $email,
        'password' => 'Applicant@2026',
    ], $jar);
    e2e_ok(strpos($applicantLogin['location'], 'admissions/applicant_portal.php') !== false, 'applicant signs in to the isolated Applicant Portal');
    $applicantPortal = e2e_request($base . '/admissions/applicant_portal.php', null, $jar);
    e2e_ok($applicantPortal['code'] === 200 && stripos($applicantPortal['body'], '#' . $applicationId) !== false, 'applicant sees the submitted application automatically');

    @unlink($jar);
    $jar = tempnam(sys_get_temp_dir(), 'admissions_e2e_');

    $loginPage = e2e_request($base . '/staff_login.php', null, $jar);
    $staffLogin = e2e_request($base . '/staff_login.php', [
        'csrf_token' => e2e_csrf($loginPage['body']),
        'user_id' => 'ITC902',
        'password' => 'Test@12345',
    ], $jar);
    e2e_ok(in_array($staffLogin['code'], [302, 303], true), 'admissions officer logs in');

    $admissionsPage = e2e_request($base . '/admissions/applicants.php', null, $jar);
    e2e_ok($admissionsPage['code'] === 200, 'admissions review page is authorized');
    $admissionsCsrf = preg_match("/csrfToken:\s*'([0-9a-f]{64})'/", $admissionsPage['body'], $match) ? $match[1] : '';
    e2e_ok($admissionsCsrf !== '', 'admissions review exposes a session token');

    $accept = e2e_request($base . '/admissions/applicants.php', [
        'action' => 'accept',
        'id' => $applicationId,
        'csrf_token' => $admissionsCsrf,
    ], $jar);
    $payload = json_decode($accept['body'], true) ?: [];
    e2e_ok($accept['code'] === 200 && !empty($payload['success']), 'officer accepts and converts through the UI', json_encode($payload));
    $studentId = (string)($payload['data']['student_id'] ?? '');
    e2e_ok($studentId !== '', 'conversion response returns the generated student ID');

    $stmt = $db->prepare(
        'SELECT s.SID, sp.program_code, sl.must_change_password
         FROM students s JOIN student_program sp ON sp.Sid=s.SID JOIN student_login sl ON sl.Sid=s.SID
         WHERE s.SID=? LIMIT 1'
    );
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $converted = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    e2e_ok(($converted['program_code'] ?? '') === 'ICT-001', 'student, programme, and login commit together');

    $studentJar = tempnam(sys_get_temp_dir(), 'converted_student_');
    $studentLoginPage = e2e_request($base . '/student_login.php', null, $studentJar);
    $studentLogin = e2e_request($base . '/student_login.php', [
        'csrf_token' => e2e_csrf($studentLoginPage['body']),
        'login' => '1', 'Sid' => $studentId, 'Password' => $nrc,
    ], $studentJar);
    e2e_ok(strpos($studentLogin['location'], 'students/change_password.php') !== false, 'converted student can log in with the one-time credential');
    @unlink($studentJar);

    foreach (['results', 'nrc_file', 'deposit_slip'] as $column) {
        $name = basename((string)($application[$column] ?? ''));
        if ($name !== '') {
            @unlink(__DIR__ . '/../online_services/uploads/' . $name);
        }
    }
} finally {
    e2e_cleanup($db, $email, $nrc);
    @unlink($pdf);
    @unlink($jar);
}

echo PHP_EOL . "Result: {$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
