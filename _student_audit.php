<?php
require __DIR__ . '/db/connect.php';

echo "=== STUDENT MODULE DATA STRUCTURE AUDIT ===\n\n";

// 1. Check all relevant tables exist
$tables = ['students', 'student_program', 'student_login', 'semester_registration', 
           'course_registration', 'student_payments', 'student_courses', 'fee_structure',
           'invoices', 'announcement', 'programs'];

echo "TABLE CHECK:\n";
foreach ($tables as $t) {
    $r = $db->query("SHOW TABLES LIKE '$t'");
    $exists = ($r && $r->num_rows > 0);
    echo "  $t: " . ($exists ? 'EXISTS' : 'MISSING') . "\n";
    if ($r) $r->free();
}

// 2. Check student_login structure
echo "\nSTUDENT_LOGIN TABLE:\n";
$r = $db->query('DESCRIBE student_login');
if ($r) {
    while($row = $r->fetch_assoc()) {
        echo "  " . $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Key'] . "\n";
    }
} else {
    echo "  TABLE DOES NOT EXIST\n";
}

// 3. Check semester_registration structure  
echo "\nSEMESTER_REGISTRATION TABLE:\n";
$r = $db->query('DESCRIBE semester_registration');
if ($r) {
    while($row = $r->fetch_assoc()) {
        echo "  " . $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Key'] . "\n";
    }
} else {
    echo "  TABLE DOES NOT EXIST\n";
}

// 4. Check course_registration structure
echo "\nCOURSE_REGISTRATION TABLE:\n";
$r = $db->query('DESCRIBE course_registration');
if ($r) {
    while($row = $r->fetch_assoc()) {
        echo "  " . $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Key'] . "\n";
    }
} else {
    echo "  TABLE DOES NOT EXIST\n";
}

// 5. Check student_payments
echo "\nSTUDENT_PAYMENTS TABLE:\n";
$r = $db->query('DESCRIBE student_payments');
if ($r) {
    while($row = $r->fetch_assoc()) {
        echo "  " . $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Key'] . "\n";
    }
} else {
    echo "  TABLE DOES NOT EXIST\n";
}

// 6. Check fee_structure
echo "\nFEE_STRUCTURE TABLE:\n";
$r = $db->query('DESCRIBE fee_structure');
if ($r) {
    while($row = $r->fetch_assoc()) {
        echo "  " . $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Key'] . "\n";
    }
} else {
    echo "  TABLE DOES NOT EXIST\n";
}

// 7. Check programs 
echo "\nPROGRAMS TABLE (key columns):\n";
$r = $db->query('DESCRIBE programs');
if ($r) {
    while($row = $r->fetch_assoc()) {
        echo "  " . $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Key'] . "\n";
    }
}

// 8. Check login records
echo "\nSTUDENT_LOGIN RECORDS:\n";
$r = $db->query('SELECT * FROM student_login LIMIT 10');
if ($r) {
    echo "  Count: " . $r->num_rows . "\n";
    while($row = $r->fetch_assoc()) {
        echo "  SID=" . $row['Sid'] . " | HasPassword=" . (!empty($row['Password']) ? 'YES' : 'NO') . "\n";
    }
} else {
    echo "  QUERY FAILED\n";
}

// 9. Check announcement table 
echo "\nANNOUNCEMENT TABLE:\n";
$r = $db->query('DESCRIBE announcement');
if ($r) {
    while($row = $r->fetch_assoc()) {
        echo "  " . $row['Field'] . ' | ' . $row['Type'] . "\n";
    }
} else {
    echo "  TABLE DOES NOT EXIST\n";
}

echo "\n=== AUDIT COMPLETE ===\n";
