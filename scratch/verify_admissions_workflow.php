<?php
/**
 * Admissions workflow verification — runs handler checks without keeping data.
 * Run: c:\xampp\php\php.exe scratch/verify_admissions_workflow.php
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/admissions/includes/registration_handlers.php';
require dirname(__DIR__) . '/includes/applicant_admission.php';

$pass = 0;
$fail = 0;
function ok(string $m): void { global $pass; $pass++; echo "  [OK]   {$m}\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  [FAIL] {$m}\n"; }

echo "=== Admissions Workflow Verification ===\n\n";

// Schema probes
$tables = ['students','student_program','student_login','users','user_roles','invoices','student_courses','online_applicants','processed_applicants','programs'];
echo "1. Required tables\n";
foreach ($tables as $t) {
    $r = $db->query("SHOW TABLES LIKE '{$t}'");
    ($r->num_rows > 0) ? ok("{$t} exists") : bad("{$t} missing");
}

// academic_year fallback (single registration)
echo "\n2. handleNewStudentRegistration academic_year fallback\n";
$nrc = '111222/33/4';
$email = 'adm.wf.test@example.test';
$db->query("DELETE FROM student_login WHERE Sid IN (SELECT SID FROM students WHERE email='{$email}')");
$db->query("DELETE FROM student_program WHERE Sid IN (SELECT SID FROM students WHERE email='{$email}')");
$db->query("DELETE FROM invoices WHERE student_id IN (SELECT SID FROM students WHERE email='{$email}')");
$db->query("DELETE FROM students WHERE email='{$email}'");

$prog = $db->query("SELECT program_code FROM programs WHERE COALESCE(is_active,1)=1 LIMIT 1")->fetch_assoc()['program_code'] ?? 'CSE';
$input = [
    'fname' => 'Adm', 'lname' => 'Workflow', 'gender' => 'M', 'dob' => '2000-05-01',
    'program' => $prog, 'email' => $email, 'phone' => '+260971234567', 'nrc' => $nrc,
    'semester' => '1', 'entry_year' => (string)date('Y'), 'mode' => 'Full-time', 'sponsor' => 'Self',
    'nok_fname' => 'Next', 'nok_lname' => 'Kin', 'nok_relationship' => 'Parent', 'nok_phone' => '+260977654321',
];
$res = handleNewStudentRegistration($db, $input, []);
$res['success'] ? ok('registration without explicit academic_year: ' . ($res['student_id'] ?? '')) : bad($res['message'] ?? 'registration failed');
$sid = $res['student_id'] ?? '';
if ($sid) {
    $row = $db->query("SELECT academic_year FROM students WHERE SID='{$sid}'")->fetch_assoc();
    ($row && $row['academic_year'] !== '') ? ok('students.academic_year populated') : bad('students.academic_year empty');
    ($db->query("SELECT 1 FROM student_login WHERE Sid='{$sid}'")->num_rows > 0) ? ok('student_login created') : bad('no student_login');
    ($db->query("SELECT 1 FROM student_program WHERE Sid='{$sid}'")->num_rows > 0) ? ok('student_program created') : bad('no student_program');
}

// admissionsEnrollExistingStudent
echo "\n3. admissionsEnrollExistingStudent (existing student)\n";
if ($sid) {
    $enroll = admissionsEnrollExistingStudent($db, $sid, $prog, 'January ' . date('Y'), 'Full-time', (int)date('Y'));
    (!$enroll['success']) ? ok('duplicate programme correctly rejected') : bad('duplicate enrolment allowed');
}

// Cleanup
if ($sid) {
    $db->query("DELETE FROM student_login WHERE Sid='{$sid}'");
    $db->query("DELETE FROM student_program WHERE Sid='{$sid}'");
    $db->query("DELETE FROM invoices WHERE student_id='{$sid}'");
    $db->query("DELETE FROM students WHERE SID='{$sid}'");
    ok('test rows cleaned up');
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
