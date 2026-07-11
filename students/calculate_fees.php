<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/StudentRegistrationSystem.php';

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Keep the student session alive during AJAX operations
$_SESSION['last_activity'] = time();

try {
    // Log request details
    $input = file_get_contents('php://input');
    error_log("calculate_fees.php - Request received: " . $input);

    // Get JSON input
    $data = json_decode($input, true);

    if (!isset($data['courses']) || !is_array($data['courses'])) {
        throw new Exception('Invalid course data provided');
    }

    $isTransfer = isset($data['is_transfer']) ? (bool)$data['is_transfer'] : false;
    $programCode = trim((string)($data['program_code'] ?? ''));
    $yearOfStudy = isset($data['year_of_study']) ? (int)$data['year_of_study'] : null;
    $semester = isset($data['semester']) ? (int)$data['semester'] : null;

    // Log processed parameters
    error_log("calculate_fees.php - Parameters: courses=" . implode(',', $data['courses']) . ", isTransfer=$isTransfer");

    // Initialize registration system
    $db = new Database();
    $registrationSystem = new StudentRegistrationSystem($db);

    // Calculate fees
    $fees = $registrationSystem->calculateRegistrationFees(
        $data['courses'],
        $isTransfer,
        $programCode !== '' ? $programCode : null,
        $yearOfStudy,
        $semester
    );

    // Log response
    error_log("calculate_fees.php - Calculated fees: " . json_encode($fees));

    // Return success response
    echo json_encode([
        'success' => true,
        'fees' => [
            'base_tuition' => $fees['base_tuition'],
            'registration_fee' => $fees['registration_fee'],
            'additional_fees' => $fees['additional_fees'],
            'total' => $fees['total'],
            'breakdown' => $fees['breakdown'] ?? []
        ]
    ]);

} catch (Exception $e) {
    // Log error
    error_log("calculate_fees.php - Error: " . $e->getMessage());

    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
