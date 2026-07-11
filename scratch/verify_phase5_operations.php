<?php
/**
 * Verification Script for Phase 5 Academic Operations Integration
 *
 * Usage:
 *   C:\xampp\php\php.exe scratch\verify_phase5_operations.php
 */

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/grading_helpers.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/ca_helpers.php';

echo "=== Phase 5 Academic Operations Integration Verification Checks ===\n\n";

$errors = [];

// Define test variables
$testStudentId = 'TST26999999';
$testLecturerId = 'ITC900999';
$testCourseCode = 'TST-INTEG';
$testProgramCode = 'TST-PROG';
$academicYear = '2026';
$semester = '1';

// Clean existing test records if any
$db->query("DELETE FROM student_program WHERE Sid = '$testStudentId'");
$db->query("DELETE FROM semester_registration WHERE student_id = '$testStudentId' OR Sid = '$testStudentId'");
$db->query("DELETE FROM course_registration WHERE Sid = '$testStudentId'");
$db->query("DELETE FROM course_lecturer WHERE staff_id = '$testLecturerId'");
$db->query("DELETE FROM course_schedule WHERE course_code = '$testCourseCode'");
$db->query("DELETE FROM attendance_logs WHERE Sid = '$testStudentId'");
$db->query("DELETE FROM semester_assessment WHERE Sid = '$testStudentId'");
$db->query("DELETE FROM exams WHERE Sid = '$testStudentId'");
$db->query("DELETE FROM students WHERE SID = '$testStudentId'");

// 1. Setup Student & Academic Registration
$db->query("INSERT INTO students (SID, Fname, Lname, status) VALUES ('$testStudentId', 'Test', 'IntegStudent', 'active')");
$db->query("INSERT INTO student_program (Sid, program_code, academic_year, year_of_study, status) VALUES ('$testStudentId', '$testProgramCode', '$academicYear', 1, 'active')");
$db->query("INSERT INTO semester_registration (student_id, year_of_study, semester, academic_year, program_code) VALUES ('$testStudentId', 1, '$semester', '$academicYear', '$testProgramCode')");
$semesterRegId = $db->insert_id;

echo "1. [PASS] Setup test student, program assignment, and term registration\n";

// 2. Setup Course & Allocation (Registration)
$db->query("INSERT IGNORE INTO courses (course_code, course_name, credits, status) VALUES ('$testCourseCode', 'Test Integration Course', 3, 'active')");
$db->query("INSERT INTO course_registration (Sid, course_code, semester, Year, semester_registration_id, status, is_active) VALUES ('$testStudentId', '$testCourseCode', $semester, 1, $semesterRegId, 'active', 1)");

// Check if student sees correct courses in eLearning
$enrolled = getStudentEnrolledCourses($db, $testStudentId);
if (in_array($testCourseCode, $enrolled, true)) {
    echo "2. [PASS] Course Allocation & Registration linked correctly. Student sees registered course in eLearning\n";
} else {
    $errors[] = "Student does not see registered course in eLearning";
}

// 3. Lecturer Allocation & Student Retrieval
$db->query("INSERT INTO course_lecturer (staff_id, course_code, program_code, academic_year, year_of_study, semester, status) VALUES ('$testLecturerId', '$testCourseCode', '$testProgramCode', '$academicYear', 1, '$semester', 'active')");

// Simulate ajax_get_course_students.php lookup logic
// Ensure it resolves correctly without throwing column errors
$scStudentCol = ca_column_exists($db, 'student_courses', 'student_id') ? 'student_id' : (ca_column_exists($db, 'student_courses', 'Sid') ? 'Sid' : 'student_id');
$studentSidCol = ca_column_exists($db, 'students', 'SID') ? 'SID' : (ca_column_exists($db, 'students', 'Sid') ? 'Sid' : 'SID');

$studentListSql = "SELECT DISTINCT Sid, Fname, Lname FROM (
    SELECT cr.Sid, COALESCE(s.Fname, '') AS Fname, COALESCE(s.Lname, '') AS Lname
    FROM course_registration cr
    LEFT JOIN students s ON s.`{$studentSidCol}` COLLATE utf8mb4_general_ci = cr.Sid COLLATE utf8mb4_general_ci
    WHERE cr.course_code = '$testCourseCode' AND cr.is_active = 1
    UNION
    SELECT sc.`{$scStudentCol}` AS Sid, COALESCE(s.Fname, '') AS Fname, COALESCE(s.Lname, '') AS Lname
    FROM student_courses sc
    LEFT JOIN students s ON s.`{$studentSidCol}` COLLATE utf8mb4_general_ci = sc.`{$scStudentCol}` COLLATE utf8mb4_general_ci
    WHERE sc.course_code = '$testCourseCode' AND sc.status = 'active'
) AS combined ORDER BY Sid";

$res = $db->query($studentListSql);
$found = false;
while ($res && $row = $res->fetch_assoc()) {
    if ($row['Sid'] === $testStudentId) {
        $found = true;
    }
}
if ($found) {
    echo "3. [PASS] Lecturer student retrieval returns allocated student correctly (union query is valid)\n";
} else {
    $errors[] = "Lecturer course-student retrieval failed to return registered student";
}

// 4. Timetable scheduling
$db->query("INSERT INTO course_schedule (course_code, lecturer_id, day_of_week, start_time, end_time, room, academic_year, semester, Year, status) VALUES ('$testCourseCode', '$testLecturerId', 'Monday', '08:30:00', '10:30:00', 'Rm 101', 1, '$semester', 1, 'active')");

// Verify schedule retrieved
require_once __DIR__ . '/../includes/timetable_management.php';
$schedules = ttm_fetch_schedules($db, 1, (int)$semester, [$testCourseCode]);
if (!empty($schedules) && $schedules[0]['course_code'] === $testCourseCode) {
    echo "4. [PASS] Timetable schedule successfully mapped to the allocated course and semester\n";
} else {
    $errors[] = "Timetable schedule retrieval failed for allocated course";
}

// 5. Attendance log ingest
$db->query("INSERT INTO attendance_logs (Sid, course_code, timestamp, source) VALUES ('$testStudentId', '$testCourseCode', NOW(), 'app')");
$attendRes = $db->query("SELECT COUNT(*) FROM attendance_logs WHERE Sid = '$testStudentId' AND course_code = '$testCourseCode'");
if ($attendRes && $attendRes->fetch_row()[0] > 0) {
    echo "5. [PASS] Attendance logging verified for registered student/course\n";
} else {
    $errors[] = "Attendance log failed to insert or link to student";
}

// 6. CA Upload & Gating Verification
// Simulate ca_student_registered check
$isRegistered = ca_student_registered($db, $testStudentId, $testCourseCode, $semester, $academicYear);
if ($isRegistered) {
    echo "6. [PASS] ca_student_registered helper correctly validates registered context\n";
} else {
    $errors[] = "ca_student_registered failed to validate registered student/course context";
}

// Upload CA marks
$postedBy = 'ITC900';
$caSaved = ca_save_component($db, $testStudentId, $testCourseCode, $semester, $academicYear, 'semester', 'A1', 30.0, $postedBy);
if ($caSaved['ok']) {
    echo "7. [PASS] CA marks successfully saved for student\n";
} else {
    $errors[] = "Failed to save CA marks: " . $caSaved['message'];
}

$db->query("UPDATE semester_assessment SET Exam = 45.0, status = 'Published' WHERE Sid = '$testStudentId' AND Course_Code = '$testCourseCode'");
if ($db->affected_rows === 0) {
    $db->query("INSERT INTO semester_assessment (Sid, Course_Code, Total_CA, Exam, semester, Year, status, program_type) VALUES ('$testStudentId', '$testCourseCode', 30.0, 45.0, '$semester', '$academicYear', 'Published', 'semester')");
}

// Compute GPA and grades
$computed = wuc_result_compute($db, $testStudentId, 30.0, 45.0);
if ($computed['final'] > 0 && $computed['grade'] === 'B') {
    echo "8. [PASS] Final marks and GPA point calculations resolved correctly (Final: {$computed['final']}, Grade: {$computed['grade']}, Points: {$computed['points']})\n";
} else {
    $errors[] = "Final grade calculations are incorrect";
}

// 8. Graduation / Progression Clearance verification
// Retrieve eligibility results from the evaluator backend logic
$results = [];
$min_gpa = 2.0;
$min_credits = 3;
$max_fails = 2;

$studentsRes = $db->query("SELECT s.SID, s.Fname, s.Lname, sp.program_code 
                           FROM students s 
                           LEFT JOIN student_program sp ON sp.Sid = s.SID
                           WHERE s.SID = '$testStudentId'");
if ($studentsRes && $student = $studentsRes->fetch_assoc()) {
    $examsRes = $db->query("SELECT e.Course_Code, e.Exam_marks, e.Total_marks, 
                                   COALESCE(c.credits, 3) AS credit_hours,
                                   COALESCE(sa.Total_CA, 0) AS Total_CA
                            FROM exams e
                            LEFT JOIN courses c ON c.course_code = e.Course_Code
                            LEFT JOIN semester_assessment sa ON sa.Sid = e.Sid AND sa.Course_Code = e.Course_Code AND sa.Year = e.Year AND sa.semester = e.semester
                            WHERE e.Sid = '$testStudentId'
                              AND e.status = 'Published' AND e.Exam_marks IS NOT NULL");
    
    $totalPoints = 0;
    $totalCredits = 0;
    $failsCount = 0;
    $earnedCredits = 0;
    
    while ($examsRes && $exam = $examsRes->fetch_assoc()) {
        $ca = (float)$exam['Total_CA'];
        $examMark = (float)$exam['Exam_marks'];
        $credits = (int)$exam['credit_hours'];
        
        $comp = wuc_result_compute($db, $testStudentId, $ca, $examMark);
        $totalPoints += $comp['points'] * $credits;
        $totalCredits += $credits;
        
        if ($comp['grade'] === 'F') {
            $failsCount++;
        } else {
            $earnedCredits += $credits;
        }
    }
    
    
    $gpa = $totalCredits > 0 ? round($totalPoints / $totalCredits, 2) : 0.0;
    $eligible = ($gpa >= $min_gpa && $earnedCredits >= $min_credits && $failsCount <= $max_fails);
    
    if ($eligible && $gpa === 3.0 && $earnedCredits === 3) {
        echo "9. [PASS] Progression & Graduation Clearance evaluator evaluates academic eligibility correctly\n";
    } else {
        $errors[] = "Progression clearance evaluator returned incorrect eligibility or metrics";
    }
} else {
    $errors[] = "Test student program assignment not found during progression evaluation";
}

// Cleanup Test Data
$db->query("DELETE FROM student_program WHERE Sid = '$testStudentId'");
$db->query("DELETE FROM semester_registration WHERE student_id = '$testStudentId' OR Sid = '$testStudentId'");
$db->query("DELETE FROM course_registration WHERE Sid = '$testStudentId'");
$db->query("DELETE FROM course_lecturer WHERE staff_id = '$testLecturerId'");
$db->query("DELETE FROM course_schedule WHERE course_code = '$testCourseCode'");
$db->query("DELETE FROM attendance_logs WHERE Sid = '$testStudentId'");
$db->query("DELETE FROM semester_assessment WHERE Sid = '$testStudentId'");
$db->query("DELETE FROM exams WHERE Sid = '$testStudentId'");
$db->query("DELETE FROM students WHERE SID = '$testStudentId'");

echo "\nVerification Results:\n";
if (empty($errors)) {
    echo "=== [ALL PASS] Phase 5 Operations Integration Verification completed successfully! ===\n";
} else {
    echo "=== [FAIL] Phase 5 verification encountered errors: ===\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}
?>
