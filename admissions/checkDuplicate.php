<?php
require_once "includes/session_handler.php";
require_once "../includes/db_connect.php";

header('Content-Type: application/json');

if (!isAdminAuthenticated()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$response = ['exists' => false, 'sid' => null, 'message' => ''];

function wuc_check_student_field(mysqli $db, string $column, string $value, bool $caseInsensitive = false): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if ($caseInsensitive) {
        $stmt = $db->prepare("SELECT SID FROM students WHERE LOWER({$column}) = LOWER(?) LIMIT 1");
    } else {
        $stmt = $db->prepare("SELECT SID FROM students WHERE {$column} = ? LIMIT 1");
    }
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['SID'] ?? null;
}

if (isset($_GET['nrc'])) {
    $sid = wuc_check_student_field($db, 'nrc_pass', (string)$_GET['nrc']);
    if ($sid) {
        $response['exists'] = true;
        $response['sid'] = $sid;
        $response['message'] = 'This NRC/ID is already registered to a student.';
    }
} elseif (isset($_GET['email'])) {
    $sid = wuc_check_student_field($db, 'email', (string)$_GET['email'], true);
    if ($sid) {
        $response['exists'] = true;
        $response['sid'] = $sid;
        $response['message'] = 'This email address is already in use.';
    }
} elseif (isset($_GET['phone']) || isset($_GET['mobile'])) {
    $phone = trim((string)($_GET['phone'] ?? $_GET['mobile'] ?? ''));
    $sid = wuc_check_student_field($db, 'mobile', $phone);
    if ($sid) {
        $response['exists'] = true;
        $response['sid'] = $sid;
        $response['message'] = 'This phone number is already registered to a student.';
    }
}

echo json_encode($response);
