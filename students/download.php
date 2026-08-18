<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';

wuc_secure_session_start();

$noteId = isset($_GET['note_id']) ? (int) $_GET['note_id'] : 0;
$legacyCourseCode = isset($_GET['file_id']) ? trim((string) $_GET['file_id']) : '';

if ($noteId <= 0 && $legacyCourseCode === '') {
    http_response_code(400);
    exit('Missing file reference.');
}

if ($noteId > 0) {
    $stmt = $db->prepare('SELECT id, course_code, notes FROM lesson_notes WHERE id = ? LIMIT 1');
    if (!$stmt) {
        http_response_code(500);
        exit('Unable to load file.');
    }
    $stmt->bind_param('i', $noteId);
} else {
    if (!preg_match('/\A[A-Za-z0-9._-]{1,32}\z/', $legacyCourseCode)) {
        http_response_code(400);
        exit('Invalid course reference.');
    }
    $stmt = $db->prepare('SELECT id, course_code, notes FROM lesson_notes WHERE course_code = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) {
        http_response_code(500);
        exit('Unable to load file.');
    }
    $stmt->bind_param('s', $legacyCourseCode);
}

$stmt->execute();
$result = $stmt->get_result();
$file = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$file) {
    http_response_code(404);
    exit('File not found.');
}

$filename = basename((string) ($file['notes'] ?? ''));
if ($filename === '' || !preg_match('/\A[a-zA-Z0-9._-]{1,255}\z/', $filename)) {
    http_response_code(404);
    exit('File not found.');
}

$courseCode = (string) ($file['course_code'] ?? '');
$staffId = isset($_SESSION['staff_id']) ? (string) $_SESSION['staff_id'] : '';
$studentId = isset($_SESSION['Sid']) ? (string) $_SESSION['Sid'] : '';

if ($staffId !== '') {
    enforceLecturerCourseAccess($db, $staffId, $courseCode);
} elseif ($studentId !== '') {
    enforceStudentCourseAccess($db, $studentId, $courseCode);
} else {
    http_response_code(403);
    exit('Please log in to access this file.');
}

$portalRoot = realpath(dirname(__DIR__));
$allowedRoot = $portalRoot !== false
    ? realpath($portalRoot . DIRECTORY_SEPARATOR . 'lecturers' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'materials')
    : false;
$absolute = $allowedRoot !== false
    ? realpath($allowedRoot . DIRECTORY_SEPARATOR . $filename)
    : false;

if (
    $allowedRoot === false
    || $absolute === false
    || strpos($absolute, $allowedRoot . DIRECTORY_SEPARATOR) !== 0
    || !is_file($absolute)
) {
    http_response_code(404);
    exit('File not found.');
}

$mime = mime_content_type($absolute) ?: 'application/octet-stream';
$inlineTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
$disposition = in_array($mime, $inlineTypes, true) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($absolute));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($absolute);
exit;
