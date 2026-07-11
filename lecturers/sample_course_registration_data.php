<?php
/**
 * Sample Course Registration Data Generator
 * 
 * This script generates sample course registration records with fee tracking
 * to test the CA upload module's 50% payment validation.
 * 
 * Run this via CLI: php sample_course_registration_data.php
 * Or access via browser (admin only): http://localhost/wucportal/lecturers/sample_course_registration_data.php
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    die("Database connection failed.\n");
}

// Check if we have sample students and courses
$hasStudents = false;
$hasCourses = false;

if ($res = $db->query("SELECT COUNT(*) as cnt FROM students LIMIT 1")) {
    $row = $res->fetch_assoc();
    $hasStudents = ($row['cnt'] > 0);
    $res->free();
}

if ($res = $db->query("SELECT COUNT(*) as cnt FROM courses LIMIT 1")) {
    $row = $res->fetch_assoc();
    $hasCourses = ($row['cnt'] > 0);
    $res->free();
}

if (!$hasStudents || !$hasCourses) {
    die("Error: You need to have students and courses in the database first.\n");
}

echo "Generating sample course registration data...\n\n";

// Fetch 10 sample students
$students = [];
if ($res = $db->query("SELECT Sid FROM students LIMIT 10")) {
    while ($row = $res->fetch_assoc()) {
        $students[] = $row['Sid'];
    }
    $res->free();
}

// Fetch 5 sample courses
$courses = [];
if ($res = $db->query("SELECT course_code, course_name FROM courses LIMIT 5")) {
    while ($row = $res->fetch_assoc()) {
        $courses[] = $row;
    }
    $res->free();
}

if (empty($students) || empty($courses)) {
    die("Error: Could not fetch students or courses.\n");
}

// Payment scenarios (for testing 50% rule)
$scenarios = [
    ['percent' => 0,   'label' => 'No payment (0%)'],
    ['percent' => 25,  'label' => 'Partial payment (25%)'],
    ['percent' => 50,  'label' => 'Minimum payment (50%)'],
    ['percent' => 75,  'label' => 'Good payment (75%)'],
    ['percent' => 100, 'label' => 'Full payment (100%)'],
];

$inserted = 0;
$skipped = 0;

foreach ($students as $idx => $sid) {
    $scenario = $scenarios[$idx % count($scenarios)];
    
    // Each student registers for 2-3 courses
    $numCourses = 2 + ($idx % 2);
    
    for ($c = 0; $c < $numCourses && $c < count($courses); $c++) {
        $course = $courses[$c];
        $tuition = 500.00; // $500 per course
        $paid = round($tuition * ($scenario['percent'] / 100), 2);
        
        // Insert registration
        $semester = (($idx % 2) + 1); // 1 or 2
        $year = (($idx % 4) + 1);     // 1, 2, 3, or 4
        $programType = ($idx % 3 == 0) ? 'term' : 'semester';
        $studyMode = ['Full-time', 'Part-time', 'Distance'][$idx % 3];
        
        $stmt = $db->prepare("INSERT INTO course_registration 
            (Sid, course_code, semester, Year, program_type, study_mode, tuition_total, amount_paid, is_active, registration_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE 
                tuition_total = VALUES(tuition_total),
                amount_paid = VALUES(amount_paid),
                updated_at = NOW()
        ");
        
        $stmt->bind_param('ssssssdd', 
            $sid, 
            $course['course_code'], 
            $semester, 
            $year, 
            $programType, 
            $studyMode, 
            $tuition, 
            $paid
        );
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                printf("✓ %s → %s (Y%d/S%d): %s - \$%.2f paid of \$%.2f (%.0f%%)\n", 
                    $sid, 
                    $course['course_code'], 
                    $year, 
                    $semester,
                    $scenario['label'],
                    $paid, 
                    $tuition,
                    $scenario['percent']
                );
                $inserted++;
            } else {
                $skipped++;
            }
        }
        $stmt->close();
    }
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "Summary:\n";
echo "  Inserted/Updated: $inserted records\n";
echo "  Skipped (duplicates): $skipped records\n";
echo str_repeat("=", 70) . "\n";

echo "\nTest Scenarios Created:\n";
foreach ($scenarios as $s) {
    echo "  • {$s['label']}\n";
}

echo "\nNext Steps:\n";
echo "1. Navigate to upload_ca.php in your browser\n";
echo "2. Select a course and try to upload CA marks\n";
echo "3. Students with <50% payment should be blocked\n";
echo "4. Students with ≥50% payment should be allowed\n";

$db->close();
?>
