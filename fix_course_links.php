<?php
require_once 'db/connect.php';

echo "=== Fixing Course Registration Links ===\n\n";

$sid = 'test123';

// Get the latest semester_registration.id
$sql = "SELECT id, program_code, academic_year, semester, year_of_study 
        FROM semester_registration 
        WHERE student_id = '$sid' 
        ORDER BY id DESC LIMIT 1";
$result = $db->query($sql);
$latestReg = $result->fetch_assoc();

if (!$latestReg) {
    echo "ERROR: No semester registration found for student $sid\n";
    exit;
}

$latestSrId = (int)$latestReg['id'];
$semester = (int)$latestReg['semester'];
$year = (int)$latestReg['year_of_study'];

echo "Latest semester_registration:\n";
echo "  ID: $latestSrId\n";
echo "  Program: {$latestReg['program_code']}\n";
echo "  Academic Year: {$latestReg['academic_year']}\n";
echo "  Semester: $semester\n";
echo "  Year of Study: $year\n\n";

// Find orphaned course_registration records for this student/term
$sql = "SELECT id, course_code, semester_registration_id 
        FROM course_registration 
        WHERE Sid = '$sid' 
          AND semester = $semester 
          AND Year = $year 
          AND (semester_registration_id IS NULL OR semester_registration_id != $latestSrId)";
$result = $db->query($sql);
$orphaned = [];
while ($row = $result->fetch_assoc()) {
    $orphaned[] = $row;
}

if (empty($orphaned)) {
    echo "✓ All course registrations are already correctly linked.\n";
} else {
    echo "Found " . count($orphaned) . " course registration(s) that need to be linked:\n";
    foreach ($orphaned as $cr) {
        echo "  - Course: {$cr['course_code']} (id={$cr['id']}, old sem_reg_id={$cr['semester_registration_id']})\n";
    }
    
    echo "\nUpdating course_registration records to link to semester_registration.id=$latestSrId...\n";
    $sql = "UPDATE course_registration 
            SET semester_registration_id = $latestSrId 
            WHERE Sid = '$sid' 
              AND semester = $semester 
              AND Year = $year";
    
    if ($db->query($sql)) {
        $affected = $db->affected_rows;
        echo "✓ SUCCESS: Updated $affected course registration(s).\n";
    } else {
        echo "✗ ERROR: " . $db->error . "\n";
    }
}

echo "\n=== Verification ===\n";
$sql = "SELECT id, course_code, semester_registration_id 
        FROM course_registration 
        WHERE Sid = '$sid' AND semester = $semester AND Year = $year";
$result = $db->query($sql);
echo "Current course registrations:\n";
while ($row = $result->fetch_assoc()) {
    echo "  Course: {$row['course_code']} → semester_registration_id={$row['semester_registration_id']}\n";
}

echo "\n=== Done ===\n";
