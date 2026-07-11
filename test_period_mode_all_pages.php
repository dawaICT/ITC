<?php
/**
 * Test script to verify period mode helper works across all student pages
 */
require 'db/connect.php';
require 'students/includes/period_mode_helper.php';

echo "=== Testing Period Mode Helper Functions ===\n\n";

// Test with different students
$testStudents = [];
$result = $db->query("
    SELECT s.SID, s.Fname, s.Lname, sp.program_code, p.program_name, p.period_mode
    FROM students s
    INNER JOIN student_program sp ON s.SID = sp.Sid
    INNER JOIN programs p ON sp.program_code = p.program_code
    LIMIT 10
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $testStudents[] = $row;
    }
}

foreach ($testStudents as $student) {
    $sid = $student['SID'];
    
    echo "Student: " . $student['Fname'] . " " . $student['Lname'] . " (" . $sid . ")\n";
    echo "  Program: " . $student['program_name'] . " (" . $student['program_code'] . ")\n";
    echo "  DB period_mode: " . ($student['period_mode'] ?? 'NULL') . "\n";
    echo "  \n";
    echo "  Helper Function Results:\n";
    echo "  - getStudentProgramPeriodMode(): " . getStudentProgramPeriodMode($db, $sid) . "\n";
    echo "  - isTermBasedProgram(): " . (isTermBasedProgram($db, $sid) ? 'true' : 'false') . "\n";
    echo "  - getPeriodLabel(): " . getPeriodLabel($db, $sid) . "\n";
    echo "  - getPeriodLabelShort(): " . getPeriodLabelShort($db, $sid) . "\n";
    echo "  - getPeriodTypeBadge(): " . strip_tags(getPeriodTypeBadge($db, $sid)) . "\n";
    echo "\n  Expected UI Display:\n";
    echo "  - Registration: \"" . getPeriodLabel($db, $sid) . " 1\" instead of \"Semester 1\"\n";
    echo "  - Timetable Header: \"Academic Year 2025 - " . getPeriodLabel($db, $sid) . " 1\"\n";
    echo "  - Dashboard Stat Card: \"Yr 1 " . getPeriodLabelShort($db, $sid) . " 1\"\n";
    echo "\n" . str_repeat("-", 60) . "\n\n";
}

echo "\n=== Pages Updated ===\n";
echo "1. students/index.php - Dashboard (already done)\n";
echo "2. students/registration.php - Semester/Term Registration\n";
echo "3. students/courseReg.php - Course Registration\n";
echo "4. students/continuousAssessment.php - CA Results\n";
echo "5. students/timetable.php - Timetable View\n";
echo "6. students/examReg.php - Exam Registration\n";

echo "\n=== Test Complete ===\n";
$db->close();
?>
