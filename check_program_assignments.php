<?php
/**
 * Comprehensive Program Assignment Check for Registration
 * Validates that students showing courses have proper program assignments
 */

require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/students/includes/StudentDataService.php';

echo "=== PROGRAM ASSIGNMENT VERIFICATION CHECK ===\n\n";

$pdo = new PDO('mysql:host=127.0.0.1;dbname=wucportal;charset=utf8mb4', 'root', '');

// 1. Check students WITHOUT program assignments
echo "1. Students WITHOUT Program Assignments:\n";
echo "----------------------------------------\n";
$stmt = $pdo->query("
    SELECT s.SID, CONCAT(s.Fname, ' ', s.Lname) as name, COUNT(sr.id) as sem_regs
    FROM students s
    LEFT JOIN student_program sp ON s.SID = sp.Sid
    LEFT JOIN semester_registration sr ON s.SID = sr.student_id
    WHERE sp.Sid IS NULL
    GROUP BY s.SID
    LIMIT 10
");

$unprogrammed = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $unprogrammed++;
    echo "  • {$row['SID']} ({$row['name']}) - Semester registrations: {$row['sem_regs']}\n";
}
if ($unprogrammed == 0) {
    echo "  ✓ All students have program assignments\n";
} else {
    echo "\n  ⚠️  Found {$unprogrammed} students without program assignments\n";
}

// 2. Check students with courses but no semester registration
echo "\n2. Students With Courses But No Semester Registration:\n";
echo "------------------------------------------------------\n";
$stmt = $pdo->query("
    SELECT DISTINCT 
        cr.Sid,
        COALESCE(s.Fname, 'Unknown') as fname,
        COUNT(cr.id) as course_count
    FROM course_registration cr
    LEFT JOIN students s ON cr.Sid = s.SID
    LEFT JOIN semester_registration sr ON sr.student_id = cr.Sid 
        AND sr.academic_year = (SELECT academic_year FROM academic_periods WHERE is_current = 1)
        AND sr.semester = cr.semester
    WHERE sr.id IS NULL
    GROUP BY cr.Sid
    LIMIT 10
");

$unregistered_with_courses = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $unregistered_with_courses++;
    echo "  • {$row['Sid']} ({$row['fname']}) - Courses: {$row['course_count']}\n";
}
if ($unregistered_with_courses == 0) {
    echo "  ✓ All students with courses have semester registration\n";
} else {
    echo "\n  ⚠️  Found {$unregistered_with_courses} students with courses but no semester registration\n";
}

// 3. Check legacy student_courses without program assignment
echo "\n3. Legacy Student_Courses Without Program Assignment:\n";
echo "----------------------------------------------------\n";
$stmt = $pdo->query("
    SELECT DISTINCT
        sc.student_id,
        COALESCE(s.Fname, 'Unknown') as fname,
        COUNT(sc.id) as course_count,
        COALESCE(sp.program_code, 'NOT ASSIGNED') as program_code
    FROM student_courses sc
    LEFT JOIN students s ON sc.student_id = s.SID
    LEFT JOIN student_program sp ON sc.student_id = sp.Sid
    WHERE sp.Sid IS NULL
    GROUP BY sc.student_id
    LIMIT 10
");

$legacy_no_program = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $legacy_no_program++;
    echo "  • {$row['student_id']} ({$row['fname']}) - Courses: {$row['course_count']}, Program: {$row['program_code']}\n";
}
if ($legacy_no_program == 0) {
    echo "  ✓ All students in legacy courses table have program assignments\n";
} else {
    echo "\n  ⚠️  Found {$legacy_no_program} students in legacy table without program assignments\n";
}

// 4. Check course_levels available for student's program
echo "\n4. Program-Course Alignment Check:\n";
echo "-----------------------------------\n";
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT sp.program_code) as programs_with_students,
        COUNT(DISTINCT cl.program_code) as programs_with_courses
    FROM student_program sp
    LEFT JOIN course_levels cl ON sp.program_code = cl.program_code
");
$alignment = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  Programs with student assignments: {$alignment['programs_with_students']}\n";
echo "  Programs with defined courses: {$alignment['programs_with_courses']}\n";

// 5. Detailed validation for current registration session
echo "\n5. Current Registration Session Validation:\n";
echo "------------------------------------------\n";
$session = $pdo->query("SELECT * FROM academic_periods WHERE is_current = 1")->fetch(PDO::FETCH_ASSOC);
if ($session) {
    echo "  Current session: {$session['academic_year']} Semester {$session['semester_term']}\n";
    
    // Check students in current term registration
    $stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT sr.student_id) as total_registered,
            COUNT(DISTINCT CASE WHEN sp.Sid IS NOT NULL THEN sr.student_id END) as with_program,
            COUNT(DISTINCT CASE WHEN sp.Sid IS NULL THEN sr.student_id END) as without_program
        FROM semester_registration sr
        LEFT JOIN student_program sp ON sr.student_id = sp.Sid
        WHERE sr.academic_year = '{$session['academic_year']}'
        AND sr.semester = '{$session['semester_term']}'
    ");
    
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  Total semester registrations: {$stats['total_registered']}\n";
    echo "  With program assignment: {$stats['with_program']}\n";
    
    if ($stats['without_program'] > 0) {
        echo "  ⚠️  Without program assignment: {$stats['without_program']}\n";
    } else {
        echo "  ✓ All current registrations have program assignments\n";
    }
} else {
    echo "  No current academic session defined\n";
}

// 6. Check for mismatched program codes
echo "\n6. Program Code Mismatch Detection:\n";
echo "----------------------------------\n";
$stmt = $pdo->query("
    SELECT 
        sr.student_id,
        sr.program_code as reg_program,
        sp.program_code as student_program,
        COUNT(DISTINCT cr.course_code) as courses
    FROM semester_registration sr
    LEFT JOIN student_program sp ON sr.student_id = sp.Sid
    LEFT JOIN course_registration cr ON sr.id = cr.semester_registration_id
    WHERE sr.program_code != COALESCE(sp.program_code, sr.program_code)
    AND sr.program_code IS NOT NULL
    AND sp.program_code IS NOT NULL
    LIMIT 5
");

$mismatches = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mismatches++;
    echo "  • {$row['student_id']}: Registered as {$row['reg_program']}, Student program is {$row['student_program']}, Courses: {$row['courses']}\n";
}
if ($mismatches == 0) {
    echo "  ✓ No program code mismatches found\n";
} else {
    echo "\n  ⚠️  Found {$mismatches} students with program code mismatches\n";
}

// 7. Summary Statistics
echo "\n7. SUMMARY STATISTICS:\n";
echo "=====================\n";
$stmt = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM students) as total_students,
        (SELECT COUNT(*) FROM student_program) as student_program_links,
        (SELECT COUNT(*) FROM semester_registration) as semester_regs,
        (SELECT COUNT(*) FROM course_registration) as course_regs,
        (SELECT COUNT(*) FROM student_courses) as legacy_courses,
        (SELECT COUNT(*) FROM course_levels) as course_levels
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
echo "  Total students: {$stats['total_students']}\n";
echo "  Program assignments: {$stats['student_program_links']}\n";
echo "  Semester registrations: {$stats['semester_regs']}\n";
echo "  Modern course registrations: {$stats['course_regs']}\n";
echo "  Legacy course registrations: {$stats['legacy_courses']}\n";
echo "  Available course definitions: {$stats['course_levels']}\n";

$coverage = ($stats['total_students'] > 0) ? round(($stats['student_program_links'] / $stats['total_students']) * 100, 2) : 0;
echo "\n  Program assignment coverage: {$coverage}%\n";

echo "\n=== CHECK COMPLETED ===\n";
?>
