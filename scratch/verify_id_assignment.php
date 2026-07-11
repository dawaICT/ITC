<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/student_id_generator.php';
require_once __DIR__ . '/../includes/id_helpers.php';

echo "=== Student ID generation test ===\n";
try {
    $sid = generateStudentId($db, 'CSE', '1', '2026', '123456/78/9');
    echo "generateStudentId(CSE, 2026, NRC): would be format check only\n";
    echo "Validation regex: " . (wuc_validate_student_number('CSE26456789') ? 'PASS' : 'FAIL') . " for CSE26456789\n";
    echo "Next staff ID: " . generateNextStaffId($db) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Staff count ===\n";
echo (int)$db->query("SELECT COUNT(*) AS c FROM staff")->fetch_assoc()['c'] . " staff rows\n";
echo (int)$db->query("SELECT COUNT(*) AS c FROM staff WHERE staff_id LIKE 'WUC%'")->fetch_assoc()['c'] . " WUC staff rows\n";
echo (int)$db->query("SELECT COUNT(*) AS c FROM students WHERE SID LIKE '%WUC%'")->fetch_assoc()['c'] . " WUC student rows\n";
