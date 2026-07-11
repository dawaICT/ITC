<?php
// Return period_type (semester|term) for a given program_code
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/../logs/error.log');

$root_path = dirname(dirname(dirname(__FILE__)));
require_once $root_path . '/db/connect.php';
require_once $root_path . '/includes/helpers/academic_period_helpers.php';

header('Content-Type: application/json');

try {
    $programCode = trim((string)($_GET['program_code'] ?? ''));
    if ($programCode === '') {
        echo json_encode(['success' => false, 'message' => 'Missing program_code']);
        exit;
    }

    $meta = getProgramAcademicStructure($db, $programCode);
    echo json_encode([
        'success' => true,
        'period_type' => $meta['period_mode'],
        'program_structure' => $meta['period_mode'],
        'period_label' => $meta['period_label'],
        'max_periods' => $meta['max_periods'],
    ]);
} catch (Throwable $e) {
    error_log('[get_program_period_type] ' . $e->getMessage());
    echo json_encode(['success' => true, 'period_type' => 'semester', 'program_structure' => 'semester', 'period_label' => 'Semester', 'max_periods' => 2]);
}
