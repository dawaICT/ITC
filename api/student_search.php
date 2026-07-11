<?php
// Simple student search API
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/json_response.php';

$response = ['success' => false];
if (!isset($_GET['SID']) || empty(trim((string)$_GET['SID']))) {
    wuc_json_error('Missing SID parameter.', 422);
}

$sid = trim((string)$_GET['SID']);

$query = "SELECT SID, Fname, Lname, email, mobile FROM students WHERE SID = ? LIMIT 1";
if ($stmt = $db->prepare($query)) {
    $stmt->bind_param('s', $sid);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $response['success'] = true;
            $response['student'] = $row;
        } else {
            wuc_json_error('Student not found.', 404);
        }
    } else {
        error_log('Student search execute failed: ' . $stmt->error);
        wuc_json_error('Student search failed.', 500);
    }
    $stmt->close();
} else {
    error_log('Student search prepare failed: ' . $db->error);
    wuc_json_error('Student search failed.', 500);
}

wuc_json_response($response);
?>
