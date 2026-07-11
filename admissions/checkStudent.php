<?php
require_once __DIR__ . '/includes/session_handler.php';
require_once dirname(__DIR__) . '/db/connect.php';

header('Content-Type: application/json');

initializeSession();
if (!isAdminAuthenticated()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['sid'])) {
    echo json_encode(['exists' => false]);
    exit;
}

$sid = trim((string)$_GET['sid']);
if ($sid === '') {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = $db->prepare('SELECT SID, status FROM students WHERE SID = ? LIMIT 1');
$stmt->bind_param('s', $sid);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode(['exists' => true, 'status' => $row['status']]);
} else {
    echo json_encode(['exists' => false]);
}
$stmt->close();
