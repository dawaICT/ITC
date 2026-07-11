<?php
/**
 * Secure Document Viewer
 * Allows authorized admin users to view uploaded student documents
 */

require_once __DIR__ . '/includes/admin.php';

// Check if user is authorized
if (!isset($_SESSION['staff_id'])) {
    http_response_code(403);
    die('Unauthorized access');
}

// Get parameters
$type = $_GET['type'] ?? ''; // nrc, results, or profile
$file = $_GET['file'] ?? '';

// Validate type
$allowed_types = ['nrc', 'results', 'profile'];
if (!in_array($type, $allowed_types)) {
    http_response_code(400);
    die('Invalid document type');
}

// Sanitize filename (prevent directory traversal)
$file = basename($file);
if (empty($file) || strpos($file, '..') !== false) {
    http_response_code(400);
    die('Invalid filename');
}

// Determine file path
$base_dir = __DIR__ . '/uploads/';
switch ($type) {
    case 'nrc':
        $file_path = $base_dir . 'nrc/' . $file;
        break;
    case 'results':
        $file_path = $base_dir . 'results/' . $file;
        break;
    case 'profile':
        $file_path = $base_dir . 'profile_images/' . $file;
        break;
    default:
        http_response_code(400);
        die('Invalid type');
}

// Check if file exists
if (!file_exists($file_path)) {
    http_response_code(404);
    die('File not found');
}

// Get file extension and set content type
$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$content_types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

$content_type = $content_types[$ext] ?? 'application/octet-stream';

// Set headers
header('Content-Type: ' . $content_type);
header('Content-Length: ' . filesize($file_path));

// For PDFs, show inline; for images, show inline too
if ($ext === 'pdf' || in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
    header('Content-Disposition: inline; filename="' . $file . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $file . '"');
}

// Disable caching for sensitive documents
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Output file
readfile($file_path);
exit;
