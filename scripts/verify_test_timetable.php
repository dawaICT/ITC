<?php
/**
 * Smoke-test Test Timetable activation + conflict + publish guards.
 * Run: C:\xampp\php\php.exe scripts/verify_test_timetable.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/test_timetable.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function tt_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('FAIL: ' . $msg);
    }
    echo "OK: $msg\n";
}

if (!tt_ensure_schema($db)) {
    fwrite(STDERR, "Schema missing\n");
    exit(1);
}

$marker = 'TT-VERIFY-' . date('YmdHis');
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$nextWeek = date('Y-m-d', strtotime('+7 days'));
$nextWeek2 = date('Y-m-d', strtotime('+10 days'));

// Cleanup prior verify rows
$db->query("DELETE FROM assessment_periods WHERE term_label LIKE 'TT-VERIFY-%'");

$create = tt_create_period($db, [
    'academic_year' => '2099/2100',
    'term_label' => $marker,
    'scheduling_start_date' => $yesterday,
    'publication_date' => $nextWeek,
    'test_start_date' => $nextWeek,
    'test_end_date' => $nextWeek2,
], 'verify-script');
tt_assert($create['ok'], 'create assessment period');
$periodId = (int)$create['id'];
$period = tt_get_period($db, $periodId);
tt_assert($period !== null, 'load period');
tt_assert(tt_registrar_can_schedule($period), 'registrar can schedule after scheduling_start');
tt_assert(!tt_student_can_view_period($period), 'students cannot view before publication/publish');

// Need real program/course if available
$prog = $db->query("SELECT program_code FROM programs WHERE COALESCE(is_active,1)=1 LIMIT 1")->fetch_assoc();
$courseA = $db->query("SELECT course_code FROM courses LIMIT 1")->fetch_assoc();
$courseB = $db->query("SELECT course_code FROM courses LIMIT 1 OFFSET 1")->fetch_assoc();
if (!$prog || !$courseA) {
    echo "SKIP: no programs/courses for entry tests\n";
    $db->query('DELETE FROM assessment_periods WHERE id=' . $periodId);
    exit(0);
}
$pc = (string)$prog['program_code'];
$c1 = (string)$courseA['course_code'];
$c2 = (string)(($courseB['course_code'] ?? '') !== '' ? $courseB['course_code'] : $c1 . 'X');
if ($c2 === $c1) {
    $c2 = $c1 . '-B';
}

$room = $db->query("SELECT id FROM classrooms LIMIT 1")->fetch_assoc();
$roomId = $room ? (int)$room['id'] : null;
$lec = $db->query("SELECT staff_id FROM staff LIMIT 1")->fetch_assoc();
$lecId = $lec ? (string)$lec['staff_id'] : '';

$e1 = tt_save_entry($db, [
    'assessment_period_id' => $periodId,
    'test_date' => $nextWeek,
    'start_time' => '09:00',
    'end_time' => '11:00',
    'program_code' => $pc,
    'year_of_study' => 1,
    'course_code' => $c1,
    'classroom_id' => $roomId,
    'lecturer_staff_id' => $lecId,
], 'verify-script');
tt_assert($e1['ok'], 'save first entry: ' . ($e1['error'] ?? ''));

$e2 = tt_save_entry($db, [
    'assessment_period_id' => $periodId,
    'test_date' => $nextWeek,
    'start_time' => '09:30',
    'end_time' => '11:30',
    'program_code' => $pc,
    'year_of_study' => 1,
    'course_code' => $c2,
    'classroom_id' => $roomId,
    'lecturer_staff_id' => $lecId,
], 'verify-script');
tt_assert($e2['ok'], 'save overlapping second entry: ' . ($e2['error'] ?? ''));

$conflicts = tt_detect_conflicts($db, $periodId);
tt_assert(count($conflicts) >= 1, 'detect at least one conflict (' . count($conflicts) . ')');
$types = array_unique(array_column($conflicts, 'type'));
echo '  conflict types: ' . implode(', ', $types) . "\n";

$pub = tt_set_period_status($db, $periodId, 'published');
tt_assert(!$pub['ok'], 'block publish with critical conflicts');

// Resolve by moving second test
$stmt = $db->prepare('UPDATE test_timetable SET start_time=?, end_time=?, classroom_id=NULL, lecturer_staff_id=NULL WHERE id=?');
$s = '14:00:00';
$e = '16:00:00';
$id2 = (int)$e2['id'];
$stmt->bind_param('ssi', $s, $e, $id2);
$stmt->execute();
$stmt->close();

$conflicts2 = tt_detect_conflicts($db, $periodId);
tt_assert($conflicts2 === [], 'no conflicts after reschedule');

$pub2 = tt_set_period_status($db, $periodId, 'published');
tt_assert($pub2['ok'], 'publish when clean: ' . ($pub2['error'] ?? ''));

// Still not student-visible (publication_date in future)
$period = tt_get_period($db, $periodId);
tt_assert(!tt_student_can_view_period($period), 'students still hidden until publication_date');

// Move publication to today for visibility check
$stmt = $db->prepare('UPDATE assessment_periods SET publication_date=?, status=? WHERE id=?');
$st = 'active';
$stmt->bind_param('ssi', $today, $st, $periodId);
$stmt->execute();
$stmt->close();
$period = tt_get_period($db, $periodId);
// test_start may still be next week — student view requires today <= test_end AND today >= publication
// Also requires status published/active — but test_end is nextWeek2 so OK if today >= publication
// Wait: tt_student_can_view also needs today <= test_end. Good.
// But test_start doesn't matter for visibility. publication is today. Good.
tt_assert(tt_student_can_view_period($period), 'students can view when published/active in window');

$visible = tt_current_student_visible_period($db);
tt_assert($visible !== null && (int)$visible['id'] === $periodId, 'current visible period helper');

$close = tt_set_period_status($db, $periodId, 'closed');
tt_assert($close['ok'], 'close period');
$arch = tt_set_period_status($db, $periodId, 'archived');
tt_assert($arch['ok'], 'archive period');

$db->query('DELETE FROM assessment_periods WHERE id=' . $periodId);
echo "DONE — all checks passed\n";
