<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';

echo "1. Registering student CSE26456789 for COM101, CSC101, CSC102, MAT101...\n";
$legacyCourses = [
    ['code' => 'COM101', 'semester' => 1],
    ['code' => 'CSC101', 'semester' => 1],
    ['code' => 'CSC102', 'semester' => 1],
    ['code' => 'MAT101', 'semester' => 1]
];

foreach ($legacyCourses as $lc) {
    $stmt = $db->prepare("
        INSERT INTO course_registration 
        (Sid, course_code, semester, Year, academic_year, semester_registration_id, status, tuition_total, amount_paid, is_active)
        VALUES ('CSE26456789', ?, ?, 1, 2026, 21, 'registered', 1000.00, 1000.00, 1)
        ON DUPLICATE KEY UPDATE 
            tuition_total = VALUES(tuition_total),
            amount_paid = VALUES(amount_paid),
            is_active = 1
    ");
    $stmt->bind_param('si', $lc['code'], $lc['semester']);
    if ($stmt->execute()) {
        echo "  Registered CSE26456789 for " . $lc['code'] . " (Sem " . $lc['semester'] . ")\n";
    } else {
        echo "  Failed registering for " . $lc['code'] . ": " . $db->error . "\n";
    }
    $stmt->close();
}

echo "\n2. Assigning admin ITC900 to DCSE-101 through DCSE-109 in course_lecturer...\n";
$dcseCourses = ['DCSE-101', 'DCSE-102', 'DCSE-103', 'DCSE-104', 'DCSE-105', 'DCSE-106', 'DCSE-107', 'DCSE-108', 'DCSE-109'];

foreach ($dcseCourses as $code) {
    $stmt = $db->prepare("
        INSERT INTO course_lecturer 
        (staff_id, course_code, program_code, academic_year, year_of_study, semester, status)
        VALUES ('ITC900', ?, 'CSE', '2026', 1, '2', 'active')
        ON DUPLICATE KEY UPDATE 
            status = 'active',
            semester = '2',
            academic_year = '2026'
    ");
    $stmt->bind_param('s', $code);
    if ($stmt->execute()) {
        echo "  Assigned ITC900 to " . $code . "\n";
    } else {
        echo "  Failed assigning ITC900 to " . $code . ": " . $db->error . "\n";
    }
    $stmt->close();
}

echo "\nFix complete!\n";
