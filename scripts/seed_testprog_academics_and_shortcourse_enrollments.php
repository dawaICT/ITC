<?php
/**
 * Seed test data so both academic and short-course flows are demonstrable:
 *
 *  A) Genuine ACADEMIC courses for TEST-PROG (a 1-year Certificate in the
 *     General Department, GEN01): 6 GEN1xx unit-courses across 2 semesters,
 *     defined in `courses` and mapped via `program_courses`. STU900 is enrolled
 *     in the semester-1 courses (course_registration) so courseReg + e-learning
 *     have data.
 *  B) SHORT-COURSE enrollments: STU900 enrolled into 3 ITC short courses with
 *     varied statuses (short_course_enrollments).
 *
 * Idempotent. Run from project root:
 *   E:\xampp\php\php.exe scripts\seed_testprog_academics_and_shortcourse_enrollments.php
 */

require_once __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$PROGRAM = 'TEST-PROG';
$STUDENT = 'STU900';
$STAFF   = 'WUC900';

// [code, name, semester]
$academic = [
    ['GEN101', 'Communication Skills',     1],
    ['GEN102', 'Computer Literacy',        1],
    ['GEN103', 'Business Mathematics',     1],
    ['GEN104', 'Entrepreneurship',         2],
    ['GEN105', 'Principles of Management', 2],
    ['GEN106', 'Professional Ethics',      2],
];

// [short_course code, enrollment status]
$shortEnroll = [
    ['ITC-ICT-01', 'active'],
    ['ITC-ICT-05', 'enrolled'],
    ['ITC-SIC-08', 'completed'],
];

try {
    $db->begin_transaction();

    // ── A. Academic courses for TEST-PROG ───────────────────────────────
    $cins = $db->prepare(
        "INSERT INTO courses (course_code, course_name, credits, status)
         VALUES (?, ?, 3, 'active')
         ON DUPLICATE KEY UPDATE course_name = VALUES(course_name), credits = VALUES(credits), status = VALUES(status)"
    );
    foreach ($academic as [$code, $name, $sem]) {
        $cins->bind_param('ss', $code, $name);
        $cins->execute();
    }

    $db->query("DELETE FROM program_courses WHERE program_code = '{$PROGRAM}' AND course_code LIKE 'GEN1%'");
    $pins = $db->prepare(
        "INSERT INTO program_courses (program_code, course_code, course_name, semester, credits, year_of_study)
         VALUES (?, ?, ?, ?, 3, 1)"
    );
    foreach ($academic as [$code, $name, $sem]) {
        $pins->bind_param('sssi', $PROGRAM, $code, $name, $sem);
        $pins->execute();
    }
    echo "  A) defined " . count($academic) . " academic courses and mapped them to {$PROGRAM}\n";

    // Enroll STU900 in the semester-1 academic courses.
    $db->query("DELETE FROM course_registration WHERE Sid = '{$STUDENT}' AND course_code LIKE 'GEN1%'");
    $reg = $db->prepare(
        "INSERT INTO course_registration (Sid, course_code, semester, Year, created_at) VALUES (?, ?, 1, 1, NOW())"
    );
    $enrolledAcademic = 0;
    foreach ($academic as [$code, $name, $sem]) {
        if ($sem === 1) {
            $reg->bind_param('ss', $STUDENT, $code);
            $reg->execute();
            $enrolledAcademic++;
        }
    }
    echo "     enrolled {$STUDENT} in {$enrolledAcademic} semester-1 academic courses\n";

    // ── B. Short-course enrollments for STU900 ──────────────────────────
    $getId = $db->prepare("SELECT id FROM short_courses WHERE course_code = ?");
    $insE = $db->prepare(
        "INSERT INTO short_course_enrollments (short_course_id, student_id, status, enrolled_by, enrollment_date)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE status = VALUES(status), enrolled_by = VALUES(enrolled_by)"
    );
    $enrolledShort = 0;
    foreach ($shortEnroll as [$code, $status]) {
        $getId->bind_param('s', $code);
        $getId->execute();
        $row = $getId->get_result()->fetch_assoc();
        if (!$row) {
            echo "     WARN: short course {$code} not found, skipping\n";
            continue;
        }
        $scId = (int) $row['id'];
        $insE->bind_param('isss', $scId, $STUDENT, $status, $STAFF);
        $insE->execute();
        echo "  B) short-course enroll {$STUDENT} -> {$code} [{$status}]\n";
        $enrolledShort++;
    }

    $db->commit();
    echo "DONE (academic: {$enrolledAcademic} enrolled, short: {$enrolledShort} enrolled)\n";
} catch (Throwable $e) {
    $db->rollback();
    echo "FAILED (rolled back): " . $e->getMessage() . "\n";
    exit(1);
}
