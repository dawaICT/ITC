<?php
/**
 * Separate STU900's two memberships into two distinct students:
 *   - STU900 stays a DEGREE (semester) student — academic program only.
 *   - STU901 is a dedicated SHORT-COURSE-ONLY student (no academic program)
 *     and receives STU900's short-course enrollments.
 *
 * A student is "short-course-only" precisely when it has NO student_program row
 * but has short_course_enrollments (see includes/short_course_student.php), so
 * STU901 must never get a student_program row.
 *
 * Idempotent. Run from project root:
 *   E:\xampp\php\php.exe scripts\separate_stu900_shortcourses.php
 */

require_once __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DEGREE = 'STU900';
$SHORT  = 'STU901';
$password = 'Student@12345';
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $db->begin_transaction();

    // 1. Create the short-course-only student (no program row, ever).
    $st = $db->prepare(
        "INSERT INTO students (SID, Fname, Lname, sex, email, status, academic_year)
         VALUES (?, 'Test', 'ShortCourse', 'M', 'stu901@test.wuc', 'active', '2026')
         ON DUPLICATE KEY UPDATE Fname = VALUES(Fname), Lname = VALUES(Lname), status = VALUES(status)"
    );
    $st->bind_param('s', $SHORT);
    $st->execute();
    echo "ensured student {$SHORT}\n";

    // 2. Login for STU901.
    $li = $db->prepare(
        "INSERT INTO student_login (Sid, Password, must_change_password)
         VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE Password = VALUES(Password)"
    );
    $li->bind_param('ss', $SHORT, $hash);
    $li->execute();
    echo "ensured login for {$SHORT} (password: {$password})\n";

    // 3. Guard: STU901 must have no academic program.
    $db->query("DELETE FROM student_program WHERE Sid = '" . $db->real_escape_string($SHORT) . "'");

    // 4. Move STU900's short-course enrollments to STU901.
    $mv = $db->prepare("UPDATE short_course_enrollments SET student_id = ? WHERE student_id = ?");
    $mv->bind_param('ss', $SHORT, $DEGREE);
    $mv->execute();
    echo "moved {$db->affected_rows} short-course enrollment(s) {$DEGREE} -> {$SHORT}\n";

    $db->commit();

    // ── Verify the separation ──
    $hasProg = function (string $sid) use ($db): int {
        $r = $db->query("SELECT COUNT(*) c FROM student_program WHERE Sid = '" . $db->real_escape_string($sid) . "'");
        return (int)$r->fetch_object()->c;
    };
    $scCount = function (string $sid) use ($db): int {
        $r = $db->query("SELECT COUNT(*) c FROM short_course_enrollments WHERE student_id = '" . $db->real_escape_string($sid) . "'");
        return (int)$r->fetch_object()->c;
    };
    echo "\nVERIFY:\n";
    echo "  {$DEGREE}: program rows=" . $hasProg($DEGREE) . ", short courses=" . $scCount($DEGREE) . "  (expect program>=1, short=0)\n";
    echo "  {$SHORT}: program rows=" . $hasProg($SHORT) . ", short courses=" . $scCount($SHORT) . "  (expect program=0, short=3)\n";
    echo "DONE\n";
} catch (Throwable $e) {
    $db->rollback();
    echo "FAILED (rolled back): " . $e->getMessage() . "\n";
    exit(1);
}
