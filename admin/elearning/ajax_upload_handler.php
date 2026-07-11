<?php
/**
 * AJAX Video Upload Handler for E-Learning Sessions
 * Handles asynchronous video uploads with progress tracking
 */

session_start();
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
require_once __DIR__ . '/../../db/connect.php';

// Always return JSON
header('Content-Type: application/json');

// Get current user info
$currentUserId = $_SESSION['staff_id'] ?? null;
$userRole = $_SESSION['role'] ?? '';
$isUserAdmin = ($userRole === 'systems_admin');

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token. Please refresh the page and try again.']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['video_file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file was uploaded.']);
    exit;
}

// Check for upload errors
$uploadError = $_FILES['video_file']['error'];
if ($uploadError !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds server upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE limit.',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by PHP extension.',
    ];
    $message = $errorMessages[$uploadError] ?? 'Unknown upload error.';
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit;
}

// Validate session ID
$sessionId = (int)($_POST['session_id'] ?? 0);
if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid session ID.']);
    exit;
}

// Check session exists and user has permission
$stmt = $db->prepare("SELECT id, course_code, topic, created_by, video_file_path FROM lms_sessions WHERE id = ?");
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();

if (!$session) {
    http_response_code(404);
    echo json_encode(['error' => 'Session not found.']);
    exit;
}

// Check permission (owner or admin)
if ($session['created_by'] !== $currentUserId && !$isUserAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to upload to this session.']);
    exit;
}

// Check if video already exists
if (!empty($session['video_file_path'])) {
    http_response_code(400);
    echo json_encode(['error' => 'A video already exists for this session. Delete it first.']);
    exit;
}

// Validate file type
$file = $_FILES['video_file'];
$allowedTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];
$allowedExtensions = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];

$fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$mimeType = $file['type'];

// Check extension
if (!in_array($fileExtension, $allowedExtensions)) {
    http_response_code(400);
    echo json_encode(['error' => "Invalid file extension: .$fileExtension. Allowed: " . implode(', ', $allowedExtensions)]);
    exit;
}

// Also verify MIME type (but be lenient as browsers can report differently)
if (!empty($mimeType) && !str_starts_with($mimeType, 'video/') && !in_array($mimeType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['error' => "Invalid file type: $mimeType"]);
    exit;
}

// Create upload directory
$uploadDir = __DIR__ . '/../../uploads/live_sessions/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create upload directory.']);
        exit;
    }
}

// Generate unique filename
$safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
$safeFilename = substr($safeFilename, 0, 50); // Limit length
$uniqueFilename = 'session_' . $sessionId . '_' . time() . '_' . $safeFilename . '.' . $fileExtension;
$destPath = $uploadDir . $uniqueFilename;
$webPath = 'uploads/live_sessions/' . $uniqueFilename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save uploaded file. Check directory permissions.']);
    exit;
}

// Get file info
$fileSize = filesize($destPath);

// Update database with upload timestamp for auto-cleanup
$update = $db->prepare("UPDATE lms_sessions SET 
    video_file_path = ?, 
    video_file_size = ?, 
    video_format = ?,
    video_uploaded_at = NOW(),
    status = 'available',
    updated_at = NOW()
    WHERE id = ?");
$update->bind_param('sisi', $webPath, $fileSize, $fileExtension, $sessionId);

if (!$update->execute()) {
    // Rollback: delete the uploaded file
    unlink($destPath);
    http_response_code(500);
    echo json_encode(['error' => 'Database update failed: ' . $db->error]);
    exit;
}

// Regenerate CSRF token for security
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Success response
echo json_encode([
    'success' => true,
    'message' => 'Video uploaded successfully.',
    'session_id' => $sessionId,
    'file_size' => $fileSize,
    'file_path' => $webPath
]);
