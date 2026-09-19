<?php
/**
 * Test script to verify period_mode is correctly fetched and displayed
 * Usage: php test_period_mode_display.php
 */

require 'db/connect.php';

echo "=== Testing Period Mode Display for Students ===\n\n";

// Test query (same as in students/index.php)
$query = "SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.profile_image, sp.startYear, sp.endYear,
                   sp.program_code, sp.Sid as sp_sid,
                   p.program_name, p.program_duration,
                   p.period_mode
            FROM students s
            INNER JOIN student_program sp ON s.SID = sp.Sid
            INNER JOIN programs p ON sp.program_code = p.program_code
            LIMIT 10";

$result = $db->query($query);

if ($result) {
    echo "Found " . $result->num_rows . " student(s) with program assignments:\n\n";
    
    while ($r = $result->fetch_object()) {
        $periodMode = $r->period_mode ?? 'semester';
        $isTermBased = ($periodMode === 'term');
        $periodLabel = $isTermBased ? 'Term' : 'Semester';
        $badgeType = $isTermBased ? 'TERM-BASED' : 'SEMESTER-BASED';
        
        echo "Student: " . $r->Fname . " " . $r->Lname . " (" . $r->SID . ")\n";
        echo "  Program: " . $r->program_name . " (" . $r->program_code . ")\n";
        echo "  Period Mode: " . $periodMode . " => Display as [$badgeType]\n";
        echo "  Registration Label: Yr X $periodLabel Y\n";
        echo "\n";
    }
} else {
    echo "Error: " . $db->error . "\n";
}

// Test semester_registration display
echo "\n=== Testing Registration Display ===\n\n";

$regQuery = "SELECT sr.student_id, sr.year_of_study, sr.semester, sr.academic_year, 
                    p.period_mode, p.program_name
             FROM semester_registration sr
             LEFT JOIN student_program sp ON sr.student_id = sp.Sid
             LEFT JOIN programs p ON sp.program_code = p.program_code
             ORDER BY sr.registration_date DESC
             LIMIT 5";

$result = $db->query($regQuery);

if ($result && $result->num_rows > 0) {
    while ($rd = $result->fetch_assoc()) {
        $periodMode = $rd['period_mode'] ?? 'semester';
        $isTermBased = ($periodMode === 'term');
        $periodLabel = $isTermBased ? 'Term' : 'Sem';
        
        echo "Student: " . $rd['student_id'] . "\n";
        echo "  Program: " . ($rd['program_name'] ?? 'N/A') . " (period_mode: $periodMode)\n";
        echo "  Display: Yr " . $rd['year_of_study'] . " $periodLabel " . $rd['semester'] . "\n";
        echo "  Academic Year: " . $rd['academic_year'] . "\n\n";
    }
} else {
    echo "No semester registrations found.\n";
}

echo "=== Test Complete ===\n";
$db->close();
?>
