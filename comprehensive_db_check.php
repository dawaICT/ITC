<?php
echo "=== COMPREHENSIVE DATABASE CHECK ===\n\n";

// Database connection
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}

echo "✓ Database connection successful\n\n";

// 1. Check table existence and basic counts
$tables = [
    'students' => 'Core student data',
    'student_program' => 'Student program assignments',
    'programs' => 'Program definitions',
    'departments' => 'Department information',
    'courses' => 'Course catalog',
    'course_registration' => 'Modern course registrations',
    'student_courses' => 'Legacy course registrations',
    'semester_registration' => 'Semester registrations',
    'student_payments' => 'Payment records',
    'invoices' => 'Invoice records',
    'academic_periods' => 'Academic session data'
];

echo "=== TABLE EXISTENCE AND COUNTS ===\n";
foreach ($tables as $table => $description) {
    $result = $db->query("SELECT COUNT(*) as count FROM `$table`");
    if ($result) {
        $count = $result->fetch_assoc()['count'];
        echo "✓ $table ($description): $count records\n";
        $result->free();
    } else {
        echo "✗ $table: Table not found or query failed\n";
    }
}

echo "\n=== DATA INTEGRITY CHECKS ===\n";

// 2. Check for orphaned student_program records
echo "Checking orphaned student_program records...\n";
$result = $db->query("
    SELECT COUNT(*) as orphaned
    FROM student_program sp
    LEFT JOIN students s ON sp.Sid = s.SID
    WHERE s.SID IS NULL
");
if ($result) {
    $orphaned = $result->fetch_assoc()['orphaned'];
    echo "Orphaned student_program records: $orphaned\n";
    $result->free();
}

// 3. Check for orphaned course_registration records
echo "Checking orphaned course_registration records...\n";
$result = $db->query("
    SELECT COUNT(*) as orphaned
    FROM course_registration cr
    LEFT JOIN semester_registration sr ON cr.semester_registration_id = sr.id
    WHERE sr.id IS NULL
");
if ($result) {
    $orphaned = $result->fetch_assoc()['orphaned'];
    echo "Orphaned course_registration records: $orphaned\n";
    $result->free();
}

// 4. Check for students without program assignments
echo "Checking students without program assignments...\n";
$result = $db->query("
    SELECT COUNT(*) as no_program
    FROM students s
    LEFT JOIN student_program sp ON s.SID = sp.Sid
    WHERE sp.Sid IS NULL
");
if ($result) {
    $no_program = $result->fetch_assoc()['no_program'];
    echo "Students without program assignments: $no_program\n";
    $result->free();
}

// 5. Check for duplicate student IDs
echo "Checking for duplicate student IDs...\n";
$result = $db->query("
    SELECT SID, COUNT(*) as count
    FROM students
    GROUP BY SID
    HAVING COUNT(*) > 1
");
$duplicates = 0;
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $duplicates++;
        echo "  Duplicate SID: {$row['SID']} (appears {$row['count']} times)\n";
    }
    $result->free();
}
if ($duplicates == 0) {
    echo "No duplicate student IDs found\n";
}

echo "\n=== REGISTRATION DATA ANALYSIS ===\n";

// 6. Check semester registration vs course registration consistency
echo "Checking semester registration vs course registration...\n";
$result = $db->query("
    SELECT
        (SELECT COUNT(*) FROM semester_registration) as semester_regs,
        (SELECT COUNT(*) FROM course_registration) as course_regs,
        (SELECT COUNT(*) FROM student_courses) as legacy_regs
");
if ($result) {
    $row = $result->fetch_assoc();
    echo "Semester registrations: {$row['semester_regs']}\n";
    echo "Modern course registrations: {$row['course_regs']}\n";
    echo "Legacy course registrations: {$row['legacy_regs']}\n";
    $result->free();
}

// 7. Check for current academic session
echo "Checking current academic session...\n";
$result = $db->query("SELECT * FROM academic_periods WHERE is_current = 1");
if ($result && $result->num_rows > 0) {
    $session = $result->fetch_assoc();
    echo "Current session: {$session['academic_year']} Semester {$session['semester_term']}\n";
    $result->free();
} else {
    echo "✗ No current academic session defined\n";
}

echo "\n=== PAYMENT AND FINANCIAL CHECKS ===\n";

// 8. Check payment status distribution
echo "Checking payment status distribution...\n";
$result = $db->query("
    SELECT payment_status, COUNT(*) as count
    FROM student_payments
    GROUP BY payment_status
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "  {$row['payment_status']}: {$row['count']} records\n";
    }
    $result->free();
}

// 9. Check for unpaid invoices
echo "Checking unpaid invoices...\n";
$result = $db->query("
    SELECT COUNT(*) as unpaid
    FROM invoices
    WHERE status != 'paid'
");
if ($result) {
    $unpaid = $result->fetch_assoc()['unpaid'];
    echo "Unpaid invoices: $unpaid\n";
    $result->free();
}

echo "\n=== SCHEMA CONSISTENCY CHECKS ===\n";

// 10. Check for missing foreign key relationships
echo "Checking program references...\n";
$result = $db->query("
    SELECT COUNT(*) as missing_programs
    FROM student_program sp
    LEFT JOIN programs p ON sp.program_code = p.program_code
    WHERE p.program_code IS NULL
");
if ($result) {
    $missing = $result->fetch_assoc()['missing_programs'];
    echo "Student programs with missing program definitions: $missing\n";
    $result->free();
}

// 11. Check course references
echo "Checking course references...\n";
$result = $db->query("
    SELECT COUNT(*) as missing_courses
    FROM course_registration cr
    LEFT JOIN courses c ON cr.course_code = c.course_code
    WHERE c.course_code IS NULL
");
if ($result) {
    $missing = $result->fetch_assoc()['missing_courses'];
    echo "Course registrations with missing course definitions: $missing\n";
    $result->free();
}

echo "\n=== PERFORMANCE CHECKS ===\n";

// 12. Check table sizes
echo "Checking table sizes...\n";
$result = $db->query("
    SELECT table_name, table_rows, data_length, index_length
    FROM information_schema.tables
    WHERE table_schema = 'wucportal'
    AND table_name IN ('students', 'course_registration', 'student_courses', 'semester_registration', 'student_payments')
    ORDER BY data_length DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $size_mb = round(($row['data_length'] + $row['index_length']) / 1024 / 1024, 2);
        echo "  {$row['table_name']}: {$row['table_rows']} rows, {$size_mb} MB\n";
    }
    $result->free();
}

$db->close();

echo "\n=== DATABASE CHECK COMPLETED ===\n";
?>