<?php
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/StudentRegistrationSystem.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    $srs = new StudentRegistrationSystem($db);

    $sid = 'test123';
    $year = '1';
    $semester = 1;
    $academic_year = '1';

    $id = $srs->ensureSemesterRegistration($conn, $sid, $year, $semester, $academic_year);
    echo "ensureSemesterRegistration returned id: " . $id . PHP_EOL;
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
