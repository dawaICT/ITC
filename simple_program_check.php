<?php
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "=== PROGRAM ASSIGNMENT CHECK ===\n\n";

// 1. Students with semester registration but no program
echo "1. Semester registrations WITHOUT program assignment:\n";
$result = $db->query("
    SELECT sr.student_id, sr.program_code, sp.Sid
    FROM semester_registration sr
    LEFT JOIN student_program sp ON sr.student_id = sp.Sid
    WHERE sp.Sid IS NULL
    LIMIT 5
");
if ($result && $result->num_rows > 0) {
    echo "   Found " . $result->num_rows . " records:\n";
    while ($row = $result->fetch_assoc()) {
        echo "   - {$row['student_id']}: program={$row['program_code']}, has assignment: NO\n";
    }
} else {
    echo "   ✓ All semester registrations have program assignments\n";
}

// 2. Course registrations analysis
echo "\n2. Course registration program coverage:\n";
$result = $db->query("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN sr.id IS NOT NULL THEN 1 END) as linked_to_semester_reg,
        COUNT(CASE WHEN sp.Sid IS NOT NULL THEN 1 END) as student_has_program
    FROM course_registration cr
    LEFT JOIN semester_registration sr ON cr.semester_registration_id = sr.id
    LEFT JOIN student_program sp ON cr.Sid = sp.Sid
");
$row = $result->fetch_assoc();
echo "   Total course registrations: {$row['total']}\n";
echo "   Linked to semester_registration: {$row['linked_to_semester_reg']}\n";
echo "   Student has program assignment: {$row['student_has_program']}\n";

// 3. Legacy courses check
echo "\n3. Legacy student_courses check:\n";
$result = $db->query("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN sp.Sid IS NOT NULL THEN 1 END) as with_program
    FROM student_courses sc
    LEFT JOIN student_program sp ON sc.student_id = sp.Sid
");
$row = $result->fetch_assoc();
echo "   Total legacy courses: {$row['total']}\n";
echo "   Students with program: {$row['with_program']}\n";
if ($row['total'] > 0 && $row['with_program'] < $row['total']) {
    echo "   ⚠️  " . ($row['total'] - $row['with_program']) . " legacy courses without program assignment\n";
}

// 4. Program assignment statistics
echo "\n4. Overall program assignment statistics:\n";
$result = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM students) as total_students,
        (SELECT COUNT(*) FROM student_program) as with_program,
        (SELECT COUNT(DISTINCT student_id) FROM semester_registration) as registered_students
");
$row = $result->fetch_assoc();
echo "   Total students: {$row['total_students']}\n";
echo "   With program assignment: {$row['with_program']}\n";
echo "   Currently registered: {$row['registered_students']}\n";

if ($row['total_students'] > 0) {
    $percent = round(($row['with_program'] / $row['total_students']) * 100, 2);
    echo "   Coverage: {$percent}%\n";
}

// 5. Check current session
echo "\n5. Current academic session registrations:\n";
$result = $db->query("
    SELECT 
        sr.academic_year, sr.semester,
        COUNT(DISTINCT sr.student_id) as registrations,
        COUNT(DISTINCT CASE WHEN sp.Sid IS NOT NULL THEN sr.student_id END) as with_program,
        COUNT(DISTINCT CASE WHEN sp.Sid IS NULL THEN sr.student_id END) as without_program
    FROM semester_registration sr
    LEFT JOIN student_program sp ON sr.student_id = sp.Sid
    WHERE sr.academic_year = '2023-2024' AND sr.semester = '1'
    GROUP BY sr.academic_year, sr.semester
");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "   Academic year: {$row['academic_year']}, Semester: {$row['semester']}\n";
    echo "   Total registrations: {$row['registrations']}\n";
    echo "   With program: {$row['with_program']}\n";
    if ($row['without_program'] > 0) {
        echo "   ⚠️  WITHOUT program: {$row['without_program']}\n";
    }
} else {
    echo "   No registrations in current session\n";
}

$db->close();
echo "\n=== DONE ===\n";
?>
