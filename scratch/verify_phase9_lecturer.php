<?php
/**
 * Automated Verification Script - Phase 9 Lecturer Workspace
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';

echo "=== Phase 9 Lecturer Workspace Verification Checks ===\n\n";

function verify_assert(bool $condition, string $description) {
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        exit(1);
    }
}

// 1. Check workspace dashboard code integrates correct UI parameters
$index_code = file_get_contents('lecturers/index.php');
verify_assert(stripos($index_code, 'Timetable') !== false, "Timetable container integrated in lecturers/index.php.");
verify_assert(stripos($index_code, 'Assigned Courses') !== false, "Assigned courses container integrated in lecturers/index.php.");

// 2. Verify Timetable Query Logic
$testStaffId = 'ITC900';
$todayDay = date('l');

$sqlTimetable = "SELECT cs.*, ts.slot_name, ts.start_time, ts.end_time, c.course_name 
                 FROM course_schedule cs 
                 LEFT JOIN time_slots ts ON ts.id = cs.time_slot_id 
                 LEFT JOIN courses c ON c.course_code = cs.course_code 
                 WHERE (cs.lecturer_id = (SELECT id FROM staff WHERE staff_id = ? LIMIT 1) 
                        OR cs.course_code IN (SELECT course_code FROM course_lecturer WHERE staff_id = ? AND status <> 'inactive'))
                   AND cs.day_of_week = ?
                 ORDER BY ts.start_time ASC";

$stmt = $db->prepare($sqlTimetable);
$stmt->bind_param('sss', $testStaffId, $testStaffId, $todayDay);
verify_assert($stmt->execute(), "Successfully executed course timetable query for test staff account.");
$res = $stmt->get_result();
echo "[INFO] Found " . $res->num_rows . " scheduled sessions for today ($todayDay).\n";
$stmt->close();

// 3. Verify Assigned Courses Details Query
$sqlCoursesDetails = "SELECT cl.course_code, c.course_name, cl.semester, cl.academic_year,
                             (SELECT COUNT(DISTINCT student_id) FROM student_courses WHERE course_code = cl.course_code) AS enrolled_students 
                      FROM course_lecturer cl
                      INNER JOIN courses c ON c.course_code = cl.course_code
                      WHERE cl.staff_id = ? AND cl.status <> 'inactive'
                      ORDER BY cl.course_code";

$stmt = $db->prepare($sqlCoursesDetails);
$stmt->bind_param('s', $testStaffId);
verify_assert($stmt->execute(), "Successfully executed assigned courses query for test staff account.");
$res = $stmt->get_result();
echo "[INFO] Found " . $res->num_rows . " assigned courses in DB for lecturer $testStaffId.\n";
while ($row = $res->fetch_assoc()) {
    echo "  - Course: " . $row['course_code'] . " (" . $row['course_name'] . ") with " . $row['enrolled_students'] . " enrolled student(s)\n";
}
$stmt->close();

echo "\nVerification Results:\n";
echo "=== [ALL PASS] Phase 9 Lecturer Workspace Verification completed successfully! ===\n";
?>
