<?php
/**
 * AJAX student lookup for admin manual-entry forms.
 */
require_once __DIR__ . '/../../db/connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (empty($_SESSION['staff_id']) && empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$sid = trim((string)($_GET['sid'] ?? ''));
if ($sid === '') {
    echo json_encode(['ok' => false, 'message' => 'Student ID required']);
    exit;
}

$stmt = $db->prepare(
    "SELECT s.SID, s.Fname, s.Lname, s.title, sp.program_code, p.program_name
     FROM students s
     LEFT JOIN student_program sp ON sp.Sid = s.SID AND LOWER(COALESCE(sp.status, 'active')) = 'active'
     LEFT JOIN programs p ON p.program_code = sp.program_code
     WHERE s.SID = ?
     ORDER BY sp.id DESC
     LIMIT 1"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Lookup failed']);
    exit;
}
$stmt->bind_param('s', $sid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'Student not found']);
    exit;
}

echo json_encode([
    'ok' => true,
    'sid' => $row['SID'],
    'name' => trim(($row['title'] ?? '') . ' ' . ($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? '')),
    'program_code' => $row['program_code'] ?? '',
    'program_name' => $row['program_name'] ?? '',
]);
