<?php
/**
 * Debug script for semester/term registration
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/db/connect.php';

echo "=== semester_registration table structure ===\n";
$result = mysqli_query($db, 'DESCRIBE semester_registration');
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . "\n";
    }
} else {
    echo 'Error: ' . mysqli_error($db) . "\n";
}

echo "\n=== Sample data from semester_registration ===\n";
$result = mysqli_query($db, 'SELECT * FROM semester_registration LIMIT 5');
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        print_r($row);
    }
} else {
    echo 'Error: ' . mysqli_error($db) . "\n";
}

echo "\n=== course_registration table structure ===\n";
$result = mysqli_query($db, 'DESCRIBE course_registration');
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . "\n";
    }
} else {
    echo 'Error: ' . mysqli_error($db) . "\n";
}

echo "\n=== academic_periods table ===\n";
$result = @mysqli_query($db, 'SELECT * FROM academic_periods ORDER BY id DESC LIMIT 3');
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        print_r($row);
    }
} else {
    echo 'Table may not exist. Checking alternatives...' . "\n";
    $tables_result = mysqli_query($db, 'SHOW TABLES');
    echo "Tables containing 'period' or 'session' or 'academic':\n";
    while ($row = mysqli_fetch_row($tables_result)) {
        if (stripos($row[0], 'period') !== false || stripos($row[0], 'session') !== false || stripos($row[0], 'academic') !== false || stripos($row[0], 'semester') !== false) {
            echo "  - " . $row[0] . "\n";
        }
    }
}

echo "\n=== Check invoices table ===\n";
$result = @mysqli_query($db, 'DESCRIBE invoices');
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . "\n";
    }
} else {
    echo 'Error (invoices may not exist): ' . mysqli_error($db) . "\n";
}

echo "\n=== CRITICAL: Check for current academic period ===\n";
$result = mysqli_query($db, 'SELECT * FROM academic_periods WHERE is_current = 1');
if ($result && mysqli_num_rows($result) > 0) {
    echo "Current period found:\n";
    while ($row = mysqli_fetch_assoc($result)) {
        print_r($row);
    }
} else {
    echo "*** ISSUE: No academic period is marked as current (is_current = 1) ***\n";
    echo "This causes getCurrentSession() to return null, breaking semester registration.\n\n";
    
    echo "All academic periods:\n";
    $all = mysqli_query($db, 'SELECT id, academic_year, semester_term, is_current, status FROM academic_periods ORDER BY id DESC');
    while ($row = mysqli_fetch_assoc($all)) {
        echo json_encode($row) . "\n";
    }
}

echo "\n=== Check recent semester registrations ===\n";
$result = mysqli_query($db, 'SELECT id, student_id, program_code, semester, year_of_study, academic_year, financial_status, created_at FROM semester_registration ORDER BY id DESC LIMIT 10');
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo json_encode($row) . "\n";
    }
} else {
    echo 'Error: ' . mysqli_error($db) . "\n";
}

mysqli_close($db);
echo "\n=== Done ===\n";
