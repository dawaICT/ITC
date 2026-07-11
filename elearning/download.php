<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_files.php';
require_once __DIR__ . '/../includes/portal_access.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$contentId = isset($_GET['content_id']) ? (int) $_GET['content_id'] : 0;
$versionId = isset($_GET['version_id']) ? (int) $_GET['version_id'] : 0;

if ($contentId <= 0 && $versionId <= 0) {
    http_response_code(400);
    exit('Missing file reference.');
}

$sql = $versionId > 0
    ? "SELECT c.id AS content_id, c.content_type, c.title, c.mime_type, m.course_code, v.id AS version_id, v.file_path, v.file_size
       FROM el_content_versions v
       INNER JOIN el_contents c ON c.id = v.content_id
       INNER JOIN el_course_modules m ON m.id = c.module_id
       WHERE v.id = ? LIMIT 1"
    : "SELECT c.id AS content_id, c.content_type, c.title, c.mime_type, m.course_code, v.id AS version_id, v.file_path, v.file_size
       FROM el_contents c
       INNER JOIN el_course_modules m ON m.id = c.module_id
       LEFT JOIN el_content_versions v ON v.id = c.current_version_id
       WHERE c.id = ? LIMIT 1";

$id = $versionId > 0 ? $versionId : $contentId;
$stmt = $db->prepare($sql);
if (!$stmt) {
    error_log('elearning/download.php prepare failed: ' . $db->error);
    http_response_code(500);
    exit('Unable to load file.');
}
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$file = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$file) {
    http_response_code(404);
    exit('File not found.');
}

$courseCode = (string) $file['course_code'];
$staffId = $_SESSION['staff_id'] ?? null;
$studentId = $_SESSION['Sid'] ?? ($_SESSION['student_id'] ?? null);
$userIdDb = (int)($_SESSION['user_id_db'] ?? 0);

if ($userIdDb <= 0 || !wuc_user_has_portal_access($db, $userIdDb, 'elearning')) {
    http_response_code(403);
    exit('Your account does not have eLearning access.');
}

if ($staffId) {
    enforceLecturerCourseAccess($db, $staffId, $courseCode);
} elseif ($studentId) {
    enforceStudentCourseAccess($db, $studentId, $courseCode);
} else {
    http_response_code(403);
    exit('Please log in to access this file.');
}

$filePath = trim((string) ($file['file_path'] ?? ''));
if ($filePath === '') {
    http_response_code(404);
    exit('No file has been uploaded for this content.');
}

if (($file['content_type'] ?? '') === 'link') {
    if (elearningValidateExternalUrl($filePath)) {
        header('Location: ' . $filePath);
        exit;
    }
    http_response_code(400);
    exit('Invalid external link.');
}

$normalized = str_replace('\\', '/', $filePath);
$normalized = ltrim($normalized, '/');
if (strpos($normalized, 'uploads/elearning/') !== 0) {
    http_response_code(403);
    exit('Invalid file path.');
}

$portalRoot = realpath(__DIR__ . '/..');
$absolute = realpath($portalRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized));
$allowedRoot = realpath($portalRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'elearning');

if (!$absolute || !$allowedRoot || strpos($absolute, $allowedRoot) !== 0 || !is_file($absolute)) {
    http_response_code(404);
    exit('Uploaded file is missing.');
}

$downloadName = basename($absolute);
$mime = $file['mime_type'] ?: (mime_content_type($absolute) ?: 'application/octet-stream');
$inlineTypes = ['application/pdf', 'video/mp4', 'video/webm', 'image/png', 'image/jpeg'];
$disposition = in_array($mime, $inlineTypes, true) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolute));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $downloadName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($absolute);
exit;
