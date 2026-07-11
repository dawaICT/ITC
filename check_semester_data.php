<?php
require_once __DIR__ . '/db/connect.php';

echo "=== SEMESTER_REGISTRATION ANALYSIS ===\n";

// Count total records
$result = $db->query('SELECT COUNT(*) as total FROM semester_registration');
$row = $result->fetch_assoc();
echo "Total semester_registration records: " . $row['total'] . "\n";

// Check for invalid program_codes (course codes in program_code field)
$result = $db->query("SELECT student_id, program_code, academic_year, semester, year_of_study FROM semester_registration WHERE program_code NOT IN (SELECT program_code FROM programs)");
echo "\nRecords with invalid program_code (not in programs table):\n";
while($row = $result->fetch_assoc()) {
    echo "  " . implode(' | ', $row) . "\n";
}

// Check for missing academic_year
$result = $db->query("SELECT COUNT(*) as count FROM semester_registration WHERE academic_year IS NULL OR academic_year = ''");
$row = $result->fetch_assoc();
echo "\nRecords with missing academic_year: " . $row['count'] . "\n";

// Check semester values
$result = $db->query("SELECT DISTINCT semester FROM semester_registration ORDER BY semester");
echo "\nDistinct semester values: ";
while($row = $result->fetch_assoc()) {
    echo $row['semester'] . " ";
}
echo "\n";

// Check year_of_study values
$result = $db->query("SELECT DISTINCT year_of_study FROM semester_registration ORDER BY year_of_study");
echo "\nDistinct year_of_study values: ";
while($row = $result->fetch_assoc()) {
    echo $row['year_of_study'] . " ";
}
echo "\n";

echo "\n=== STUDENT_PROGRAM TABLE ===\n";
$result = $db->query('SELECT * FROM student_program');
echo "Student program assignments:\n";
while($row = $result->fetch_assoc()) {
    echo "  " . implode(' | ', $row) . "\n";
}

echo "\n=== CHECKING FOR INCONSISTENCIES ===\n";
// Check if semester_registration program_codes match student_program
$result = $db->query("
    SELECT sr.student_id, sr.program_code as sr_program, sp.program_code as sp_program, sr.id
    FROM semester_registration sr
    LEFT JOIN student_program sp ON sr.student_id = sp.Sid
    WHERE sr.program_code != sp.program_code OR sp.program_code IS NULL
");
echo "Semester registrations with program_code not matching student_program:\n";
while($row = $result->fetch_assoc()) {
    echo "  Student: {$row['student_id']}, SR program: {$row['sr_program']}, SP program: " . ($row['sp_program'] ?? 'NULL') . ", SR ID: {$row['id']}\n";
}

// Count total records
$result = $db->query('SELECT COUNT(*) as total FROM course_registration');
$row = $result->fetch_assoc();
echo "Total course_registration records: " . $row['total'] . "\n";

// Check semester values
$result = $db->query("SELECT DISTINCT semester FROM course_registration ORDER BY semester");
echo "\nDistinct semester values: ";
while($row = $result->fetch_assoc()) {
    echo $row['semester'] . " ";
}
echo "\n";

// Check Year values
$result = $db->query("SELECT DISTINCT Year FROM course_registration ORDER BY Year");
echo "\nDistinct Year values: ";
while($row = $result->fetch_assoc()) {
    echo $row['Year'] . " ";
}
echo "\n";

// Check for orphaned course_registration records (no matching semester_registration)
$result = $db->query("
    SELECT COUNT(*) as orphaned 
    FROM course_registration cr 
    LEFT JOIN semester_registration sr ON cr.semester_registration_id = sr.id 
    WHERE sr.id IS NULL
");
$row = $result->fetch_assoc();
echo "\nOrphaned course_registration records (no semester_registration_id match): " . $row['orphaned'] . "\n";

echo "\n=== COURSE_LEVELS BY PROGRAM ===\n";
$result = $db->query('SELECT program_code, COUNT(*) as courses FROM course_levels GROUP BY program_code ORDER BY program_code');
echo "Courses per program:\n";
while($row = $result->fetch_assoc()) {
    echo "  {$row['program_code']}: {$row['courses']} courses\n";
}