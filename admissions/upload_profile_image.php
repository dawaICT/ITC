<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../db/connect.php';

if (!isset($_SESSION['staff_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (empty($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
$file = $_FILES['profile_image'];
if (!isset($allowedTypes[$file['type']])) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type']);
    exit;
}

if ($file['size'] > 2 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File too large (max 2MB)']);
    exit;
}

$ext = $allowedTypes[$file['type']];
$uploadsDir = __DIR__ . '/../uploads/profile';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

$filename = 'profile_' . $_SESSION['staff_id'] . '_' . time() . '.' . $ext;
$target = $uploadsDir . '/' . $filename;
if (!move_uploaded_file($file['tmp_name'], $target)) {
    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file']);
    exit;
}

// Update staff record with new filename
$staff_id = $_SESSION['staff_id'];
$stmt = $db->prepare("UPDATE staff SET profile_image = ? WHERE staff_id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB error', 'error' => $db->error]);
    exit;
}
$stmt->bind_param('ss', $filename, $staff_id);
if ($stmt->execute()) {
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Uploaded', 'filename' => $filename]);
    exit;
} else {
    $err = $stmt->error;
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'DB update failed', 'error' => $err]);
    exit;
}
