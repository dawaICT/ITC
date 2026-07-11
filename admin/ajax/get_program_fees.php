<?php
require_once __DIR__ . '/../../includes/db_connect.php';

// Verify database connection
if (!isset($db) || !$db) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

// Get program fees
$sql = "SELECT 
            fs.*, 
            p.program_name,
            CONCAT('Year ', fs.year_of_study, ' - Semester ', fs.semester) as period
        FROM fee_structure fs
        JOIN programs p ON fs.program_code = p.program_code
        WHERE fs.status = 'active'
        ORDER BY p.program_code, fs.year_of_study, fs.semester, fs.fee_description";

$program_fees = [];
if($result = $db->query($sql)) {
    while($row = $result->fetch_object()) {
        if(!isset($program_fees[$row->program_code])) {
            $program_fees[$row->program_code] = [
                'program_name' => $row->program_name,
                'fees' => []
            ];
        }
        $program_fees[$row->program_code]['fees'][] = $row;
    }
    $result->free();
}

echo json_encode([
    'success' => true,
    'data' => $program_fees
]); 