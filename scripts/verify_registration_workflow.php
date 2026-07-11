<?php
/**
 * End-to-end verification of the student registration workflow.
 * Replays every stage's query sequence with strict mysqli reporting,
 * inside a transaction that is rolled back (no data is kept).
 *
 * Run: E:\xampp\php\php.exe scripts\verify_registration_workflow.php
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require dirname(__DIR__) . '/db/connect.php';
require dirname(__DIR__) . '/admissions/includes/registration_handlers.php';
require dirname(__DIR__) . '/students/includes/FeeGuard.php';

$sid = 'TESTWF' . substr((string)time(), -5);
$nrc = '123456/78/9';
$fail = 0;
function ok(string $stage, string $msg) { echo "  [OK]   {$stage}: {$msg}\n"; }
function bad(string $stage, string $msg) { global $fail; $fail++; echo "  [FAIL] {$stage}: {$msg}\n"; }

$db->begin_transaction();
try {
    // ── Stage 1: Enrollment (processForm.php query shape) ──────────────────
    $ins = $db->prepare('INSERT INTO students (SID, title, Fname, Lname, sex, dob, country, nrc_pass, mobile, email, status, h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat, school, results, nrc_file, certificate_file, profile_image, program, intake, mode, academic_year, year, dte_adm, enrollment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())');
    $v = [$sid,'Mr','Work','Flow','M','2000-01-01','Zambia',$nrc,'0970009999','wf999@test.local','active','addr','addr','Self','Kin','0970000001','Parent','School','cert.pdf','nrc.pdf','cert.pdf','prof.jpg','BSCS','January 2026','Full-time','2026'];
    $ins->bind_param(str_repeat('s', 26), ...$v);
    $ins->execute(); $ins->close();
    ok('enrollment', 'students row created (status=active)');

    // ── Stage 2: Admission confirmation (processAdmit_student.php shape) ───
    $p = $db->prepare("INSERT INTO student_program (Sid, program_code, intake, mode, startYear, endYear, term_start_date, term_end_date, status) VALUES (?,?,?,?,?,?,?,?, 'active')");
    $pc='BSCS'; $in='January 2026'; $mo='Full-time'; $sy='2026'; $ey='2030'; $ts='2026-01-01'; $te='2026-06-30';
    $p->bind_param('ssssssss', $sid, $pc, $in, $mo, $sy, $ey, $ts, $te);
    $p->execute(); $p->close();
    $u = $db->prepare("UPDATE students SET status = 'active', enrollment_date = NOW() WHERE SID = ?");
    $u->bind_param('s', $sid); $u->execute(); $u->close();
    ok('admission', 'student_program assigned, status confirmed active');

    // Portal credentials created at admission
    admissionsEnsureStudentLogin($db, $sid, $nrc, 'wf999@test.local');
    $r = $db->prepare('SELECT Password, must_change_password FROM student_login WHERE Sid = ?');
    $r->bind_param('s', $sid); $r->execute();
    $login = $r->get_result()->fetch_assoc(); $r->close();
    if ($login && password_verify($nrc, $login['Password']) && (int)$login['must_change_password'] === 1) {
        ok('portal access', 'login row exists, NRC verifies, forced password change set');
    } else {
        bad('portal access', 'login row missing or password/flag wrong');
    }

    // ── Stage 3: Accounts clearance (FeeGuard) ──────────────────────────────
    $fg = fg_check_fee_threshold($db, $sid, 1, 1, 50.0);
    if ($fg['ok']) { ok('accounts clearance', $fg['message']); } else { bad('accounts clearance', $fg['message']); }

    // ── Stage 4: Academic registration (fixed legacy insert shapes) ────────
    $reg = $db->prepare("INSERT INTO semester_registration (program_code, student_id, semester, year_of_study, academic_year, financial_status, date_registered, created_at) VALUE(?,?,?,?,YEAR(CURDATE()),'Pending',NOW(),NOW())");
    $sem='1'; $yos='1';
    $reg->bind_param('ssss', $pc, $sid, $sem, $yos);
    $reg->execute();
    $semRegId = $db->insert_id; $reg->close();
    ok('semester registration', "row {$semRegId} created (financial_status=Pending)");

    $chk = $db->prepare('SELECT id FROM semester_registration WHERE student_id = ? AND year_of_study = ? AND semester = ? LIMIT 1');
    $chk->bind_param('sss', $sid, $yos, $sem);
    $chk->execute(); $chk->store_result();
    $chk->num_rows === 1 ? ok('semester registration', 'duplicate-check query resolves') : bad('semester registration', 'duplicate-check query found nothing');
    $chk->close();

    $cr = $db->prepare('INSERT INTO course_registration (Sid, course_code, semester, Year, semester_registration_id, created_at) VALUES (?,?,?,?,?,NOW())');
    $cc = 'CS101'; $crSem = 1; $crYear = 1;
    $cr->bind_param('ssiii', $sid, $cc, $crSem, $crYear, $semRegId);
    $cr->execute(); $cr->close();
    ok('course registration', 'course_registration row linked to semester registration');

    // ── Stage 6: Lecturer visibility (myStudent.php query shape) ───────────
    $lec = $db->query("SELECT COUNT(*) AS c FROM course_registration cr INNER JOIN course_lecturer lec ON cr.course_code = lec.course_code WHERE cr.Sid = '" . $db->real_escape_string($sid) . "'");
    $cnt = (int)$lec->fetch_assoc()['c'];
    ok('lecturer access', "scoped join executes; student visible to {$cnt} lecturer assignment(s) of {$cc}");

    $db->rollback();
    echo "\nAll stages replayed — transaction rolled back, no data kept.\n";
} catch (Throwable $e) {
    $db->rollback();
    bad('fatal', $e->getMessage());
}
exit($fail > 0 ? 1 : 0);
