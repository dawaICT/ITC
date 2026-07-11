<?php
/**
 * Add Sample Exam and Assessment Data
 * This script adds proper test data for the transcript functionality
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once 'db/connect.php';

echo "=== ADDING SAMPLE EXAM DATA ===\n\n";

// First, let's check what students and courses exist
echo "1. Checking existing data...\n";
$students = $db->query("SELECT SID, Fname, Lname FROM students LIMIT 5");
echo "   Students in database:\n";
$student_ids = [];
if ($students && $students->num_rows > 0) {
    while ($row = $students->fetch_assoc()) {
        echo "   - {$row['SID']}: {$row['Fname']} {$row['Lname']}\n";
        $student_ids[] = $row['SID'];
    }
} else {
    echo "   ❌ No students found! Add students first.\n";
    exit;
}

$courses = $db->query("SELECT course_code, course_name FROM courses LIMIT 5");
echo "\n   Courses in database:\n";
$course_codes = [];
if ($courses && $courses->num_rows > 0) {
    while ($row = $courses->fetch_assoc()) {
        echo "   - {$row['course_code']}: {$row['course_name']}\n";
        $course_codes[] = $row['course_code'];
    }
} else {
    echo "   ❌ No courses found! Add courses first.\n";
    exit;
}

// Clear existing test exam data
echo "\n2. Clearing old test data...\n";
$db->query("DELETE FROM exams WHERE Sid IN ('" . implode("','", $student_ids) . "')");
$db->query("DELETE FROM semester_assessment WHERE Sid IN ('" . implode("','", $student_ids) . "')");
echo "   ✅ Cleared old exam data for test students\n";

// Add sample exam data
echo "\n3. Adding sample exam data...\n";
$exam_data = [];
$assessment_data = [];

// For each student, add exams for first 3 courses
$current_year = date('Y');
$semester = 1;

foreach ($student_ids as $sid) {
    foreach (array_slice($course_codes, 0, 3) as $course_code) {
        // Random marks
        $exam_marks = rand(30, 50);
        $total_marks = $exam_marks; // Exam out of 60
        
        // CA marks
        $a1 = rand(5, 10);
        $a2 = rand(5, 10);
        $t1 = rand(5, 10);
        $t2 = rand(5, 10);
        $total_ca = $a1 + $a2 + $t1 + $t2; // CA out of 40
        
        // Insert exam
        $stmt = $db->prepare("INSERT INTO exams (Sid, Course_Code, Exam_marks, Total_marks, semester, Year) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiiii", $sid, $course_code, $exam_marks, $total_marks, $semester, $current_year);
        if ($stmt->execute()) {
            echo "   ✅ Added exam: Student $sid, Course $course_code, Exam: $total_marks\n";
        }
        
        // Insert assessment
        $stmt = $db->prepare("INSERT INTO semester_assessment (Sid, Course_Code, A1, A2, T1, T2, Total_CA, semester, Year, posted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $posted_by = "admin";
        $stmt->bind_param("ssiiiiiiis", $sid, $course_code, $a1, $a2, $t1, $t2, $total_ca, $semester, $current_year, $posted_by);
        if ($stmt->execute()) {
            echo "   ✅ Added assessment: Student $sid, Course $course_code, CA: $total_ca\n";
        }
    }
}

// Verify data
echo "\n4. Verifying data added...\n";
$exam_count = $db->query("SELECT COUNT(*) as count FROM exams WHERE Sid IN ('" . implode("','", $student_ids) . "')")->fetch_assoc()['count'];
$assess_count = $db->query("SELECT COUNT(*) as count FROM semester_assessment WHERE Sid IN ('" . implode("','", $student_ids) . "')")->fetch_assoc()['count'];

echo "   Exams added: $exam_count\n";
echo "   Assessments added: $assess_count\n";

// Test the transcript query
echo "\n5. Testing transcript query...\n";
$test_sid = $student_ids[0];
$test_query = "SELECT s.SID, s.Fname, s.Lname, e.Course_Code, c.course_name, 
               e.Total_marks, sa.Total_CA, e.semester, e.Year, p.program_name,
               (e.Total_marks + sa.Total_CA) as final_grade
               FROM students s
               INNER JOIN exams e ON s.SID = e.Sid
               INNER JOIN courses c ON e.Course_Code = c.course_code
               INNER JOIN semester_assessment sa ON c.course_code = sa.Course_Code AND s.SID = sa.Sid AND e.semester = sa.semester AND e.Year = sa.Year
               INNER JOIN student_program sp ON s.SID = sp.Sid
               INNER JOIN programs p ON sp.program_code = p.program_code
               WHERE e.Sid = '$test_sid' 
               AND e.semester = $semester
               AND e.Year = $current_year";

$result = $db->query($test_query);
if ($result && $result->num_rows > 0) {
    echo "   ✅ Transcript query successful! Found {$result->num_rows} records\n";
    echo "\n   Sample transcript data for student $test_sid:\n";
    echo "   " . str_repeat("-", 80) . "\n";
    printf("   %-15s %-30s %-10s %-10s %-10s\n", "Course Code", "Course Name", "Exam", "CA", "Total");
    echo "   " . str_repeat("-", 80) . "\n";
    
    while ($row = $result->fetch_assoc()) {
        printf("   %-15s %-30s %-10s %-10s %-10s\n", 
            $row['Course_Code'], 
            substr($row['course_name'], 0, 30),
            $row['Total_marks'],
            $row['Total_CA'],
            $row['final_grade']
        );
    }
    echo "   " . str_repeat("-", 80) . "\n";
} else {
    echo "   ❌ Query failed or returned no results\n";
    if ($db->error) {
        echo "   Error: " . $db->error . "\n";
    }
}

echo "\n=== SAMPLE DATA ADDED SUCCESSFULLY ===\n";
echo "\nYou can now test the transcript at: admin/exams.php\n";
echo "Use Student ID: $test_sid, Semester: $semester, Year: $current_year\n";
