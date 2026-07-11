<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_handler.php';

// Security check
if (!isAdminAuthenticated()) {
    http_response_code(403);
    die('Unauthorized access');
}

$file = $_GET['file'] ?? '';

if (empty($file) || empty($_SESSION['download_file'])) {
    http_response_code(404);
    die('File not found or session expired');
}

$sessionFile = $_SESSION['download_file'];
$tempDir = sys_get_temp_dir();
$fullPath = $tempDir . DIRECTORY_SEPARATOR . basename($sessionFile);

// Verify that the requested file matches the session file (security against path traversal)
if (basename($sessionFile) !== $file) {
    http_response_code(403);
    die('Invalid file request');
}

// Verify file exists
if (!file_exists($fullPath)) {
    http_response_code(404);
    die('File no longer exists');
}

// Serve file
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);

// Cleanup
@unlink($fullPath);
unset($_SESSION['download_file']);
exit;
