<?php
require_once 'db/connect.php';

$sid = 'test123'; // Replace with actual student ID if different

echo "=== Checking Registration Links ===\n\n";

// Check semester_registration records
echo "1. Semester Registrations for student: $sid\n";
$sql = "SELECT id, student_id, program_code, academic_year, semester, year_of_study, created_at 
        FROM semester_registration 
        WHERE student_id = '$sid' 
        ORDER BY id DESC";
$result = $db->query($sql);
while ($row = $result->fetch_assoc()) {
    echo "  ID={$row['id']} | program={$row['program_code']} | acad_year={$row['academic_year']} | semester={$row['semester']} | year={$row['year_of_study']} | created={$row['created_at']}\n";
}

// Check course_registration records
echo "\n2. Course Registrations for student: $sid\n";
$sql = "SELECT id, Sid, course_code, semester, Year, semester_registration_id, registration_date 
        FROM course_registration 
        WHERE Sid = '$sid' 
        ORDER BY id DESC";
$result = $db->query($sql);
if ($result->num_rows == 0) {
    echo "  No course registrations found.\n";
} else {
    while ($row = $result->fetch_assoc()) {
        echo "  ID={$row['id']} | course={$row['course_code']} | sem={$row['semester']} | year={$row['Year']} | sem_reg_id={$row['semester_registration_id']} | date={$row['registration_date']}\n";
    }
}

// Check for orphaned registrations
echo "\n3. Mismatch Analysis:\n";
$sql = "SELECT sr.id AS latest_sr_id 
        FROM semester_registration sr 
        WHERE sr.student_id = '$sid' 
        ORDER BY sr.id DESC LIMIT 1";
$result = $db->query($sql);
$latestSrId = $result->fetch_assoc()['latest_sr_id'] ?? null;

if ($latestSrId) {
    echo "  Latest semester_registration.id = $latestSrId\n";
    
    $sql = "SELECT COUNT(*) AS count 
            FROM course_registration 
            WHERE Sid = '$sid' AND semester_registration_id = $latestSrId";
    $result = $db->query($sql);
    $linkedCount = $result->fetch_assoc()['count'];
    echo "  Course registrations linked to latest semester_registration: $linkedCount\n";
    
    if ($linkedCount == 0) {
        echo "  ⚠ PROBLEM: No courses linked to latest semester registration!\n";
        echo "  This means courses were registered under an old semester_registration record.\n";
    } else {
        echo "  ✓ OK: Courses are correctly linked to latest semester registration.\n";
    }
}

echo "\n=== End Check ===\n";
