<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) {
    echo "Connection error\n";
    exit(1);
}

echo "=== FEE STRUCTURE TABLE ===\n";
$res = $db->query('SHOW COLUMNS FROM fee_structure');
if ($res) {
    while ($r = $res->fetch_object()) {
        echo $r->Field . ' (' . $r->Type . ")\n";
    }
} else {
    echo "Table not found\n";
}

echo "\n=== STUDENT PAYMENTS TABLE ===\n";
$res2 = $db->query('SHOW COLUMNS FROM student_payments');
if ($res2) {
    while ($r = $res2->fetch_object()) {
        echo $r->Field . ' (' . $r->Type . ")\n";
    }
} else {
    echo "Table not found\n";
}

echo "\n=== SAMPLE DATA ===\n";
$sid = 'BSCS-TEST-001';
echo "Student: $sid\n\n";

// Check fee structure for this student's program
$prog = $db->query("SELECT program_code FROM student_program WHERE Sid = '$sid' LIMIT 1");
if ($prog && $p = $prog->fetch_object()) {
    $progCode = $p->program_code;
    echo "Program: $progCode\n\n";
    
    echo "Fee Structure:\n";
    $fees = $db->query("SELECT * FROM fee_structure WHERE program_code = '$progCode' LIMIT 3");
    if ($fees) {
        while ($f = $fees->fetch_object()) {
            echo "  - " . ($f->fee_description ?? 'N/A') . ": " . ($f->amount ?? '0') . "\n";
        }
    }
}

echo "\nPayments:\n";
$pays = $db->query("SELECT * FROM student_payments WHERE Sid = '$sid' LIMIT 5");
if ($pays && $pays->num_rows > 0) {
    while ($p = $pays->fetch_object()) {
        echo "  - " . ($p->payment_date ?? 'N/A') . ": " . ($p->amount_paid ?? '0') . "\n";
    }
} else {
    echo "  No payments found\n";
}

$db->close();
