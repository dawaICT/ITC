<?php
require_once __DIR__ . '/db/connect.php';

echo "=== FIXING SEMESTER YEAR ISSUES ===\n\n";

// 1. Fix student_program table - change CS101 to BSCS for Computer Science programs
echo "1. Fixing student_program table...\n";
$db->query("UPDATE student_program SET program_code = 'BSCS' WHERE program_code = 'CS101'");
echo "   Updated program_code from CS101 to BSCS in student_program\n";

// 2. Clean up semester_registration duplicates and keep only the latest valid one per student
echo "\n2. Cleaning up semester_registration duplicates...\n";

// Get all students with multiple semester_registrations
$studentsWithDuplicates = [];
$result = $db->query("
    SELECT student_id, COUNT(*) as count
    FROM semester_registration
    GROUP BY student_id
    HAVING count > 1
");

while ($row = $result->fetch_assoc()) {
    $studentsWithDuplicates[] = $row['student_id'];
}

foreach ($studentsWithDuplicates as $studentId) {
    // Get the latest semester_registration for this student
    $result = $db->query("SELECT id FROM semester_registration WHERE student_id = '$studentId' ORDER BY id DESC LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $keepId = $row['id'];

        // Update academic_year to current
        $currentYear = date('Y');
        $nextYear = $currentYear + 1;
        $academicYear = "$currentYear-$nextYear";
        $db->query("UPDATE semester_registration SET academic_year = '$academicYear' WHERE id = $keepId");

        // Delete older records (this will cascade delete course_registration)
        $db->query("DELETE FROM semester_registration WHERE student_id = '$studentId' AND id != $keepId");
        echo "   Cleaned up duplicates for $studentId, kept ID $keepId\n";
    }
}

// 3. Recreate course_registration records for affected students
echo "\n3. Recreating course_registration records...\n";

foreach ($studentsWithDuplicates as $studentId) {
    // Get student's program and semester info
    $studentInfo = $db->query("
        SELECT sp.program_code, sr.id as sr_id, sr.semester, sr.year_of_study
        FROM student_program sp
        JOIN semester_registration sr ON sp.Sid = sr.student_id
        WHERE sp.Sid = '$studentId'
        ORDER BY sr.id DESC LIMIT 1
    ");

    if ($studentInfo && $info = $studentInfo->fetch_assoc()) {
        $program = $info['program_code'];
        $srId = $info['sr_id'];
        $semester = $info['semester'];
        $yearOfStudy = $info['year_of_study'];

        // Get courses for this program/semester/year
        $coursesResult = $db->query("SELECT course_code FROM course_levels WHERE program_code = '$program' AND semester = $semester AND year = $yearOfStudy");
        $courses = [];
        while ($courseRow = $coursesResult->fetch_assoc()) {
            $courses[] = $courseRow['course_code'];
        }

        // Insert course registrations
        foreach ($courses as $courseCode) {
            $db->query("INSERT INTO course_registration (Sid, course_code, semester, Year, semester_registration_id, registration_date)
                       VALUES ('$studentId', '$courseCode', $semester, $yearOfStudy, $srId, NOW())");
        }
        echo "   Recreated " . count($courses) . " course registrations for $studentId ($program S$semester Y$yearOfStudy)\n";
    }
}

// 4. Ensure all semester_registration records have proper academic_year
echo "\n4. Ensuring academic_year is set for all records...\n";
$currentYear = date('Y');
$nextYear = $currentYear + 1;
$academicYear = "$currentYear-$nextYear";

$result = $db->query("UPDATE semester_registration SET academic_year = '$academicYear' WHERE academic_year IS NULL OR academic_year = ''");
echo "   Updated academic_year for " . $db->affected_rows . " records\n";

echo "\n=== FIX COMPLETE ===\n";
echo "Run check_semester_data.php again to verify fixes.\n";
?>