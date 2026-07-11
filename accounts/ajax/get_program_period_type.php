<?php
// Get program period type (semester vs term)
error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();

$root_path = dirname(dirname(dirname(__FILE__)));
require_once $root_path . '/db/connect.php';
require_once $root_path . '/includes/helpers/academic_period_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_POST['program_code']) || empty($_POST['program_code'])) {
    echo json_encode(['success' => false, 'message' => 'Program code is required']);
    exit;
}

$program_code = trim((string)$_POST['program_code']);

try {
    $meta = getProgramAcademicStructure($db, $program_code);
    echo json_encode([
        'success' => true,
        'period_type' => $meta['period_mode'],
        'program_structure' => $meta['period_mode'],
        'period_label' => $meta['period_label'],
        'max_period' => $meta['max_periods'],
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching program details',
    ]);
}
