<?php
// admin/get_student_program_info.php
require_once dirname(__DIR__) . '/db/connect.php';

header('Content-Type: application/json');

$sid = $_GET['sid'] ?? '';

if (empty($sid)) {
    echo json_encode(['error' => 'Student ID is required']);
    exit;
}

$response = ['study_mode' => 'semester']; // Default to semester

try {
    $stmt = $db->prepare("
        SELECT p.study_mode, p.term_based
        FROM student_program sp
        JOIN programs p ON sp.program_code = p.program_code
        WHERE sp.Sid = ?
    ");
    $stmt->bind_param("s", $sid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if ($row['study_mode'] === 'term' || $row['term_based'] == 1) {
            $response['study_mode'] = 'term';
        }
    }
    $stmt->close();
} catch (Exception $e) {
    // Log error but still return default to avoid breaking the form
    error_log("Error fetching student program info: " . $e->getMessage());
}

echo json_encode($response);
