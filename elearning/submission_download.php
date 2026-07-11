<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/assignment_storage.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/portal_access.php';

wuc_apply_security_headers(false);
wuc_configure_session_cookie();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$submissionId = (int)($_GET['submission_id'] ?? 0);
if ($submissionId <= 0) {
    http_response_code(400);
    exit('Invalid submission.');
}

assignmentStorageEnsureSchema($db);

$submission = null;
if ($stmt = $db->prepare("SELECT
        s.*,
        a.course_code,
        a.title AS assignment_title
    FROM el_submissions s
    INNER JOIN el_assignments a ON a.id = s.assignment_id
    WHERE s.id = ?
    LIMIT 1")) {
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$submission) {
    http_response_code(404);
    exit('Submission not found.');
}

$allowed = false;
$sid = (string)($_SESSION['Sid'] ?? '');
$staffId = (string)($_SESSION['staff_id'] ?? '');
$userIdDb = (int)($_SESSION['user_id_db'] ?? 0);
if ($userIdDb <= 0 || !wuc_user_has_portal_access($db, $userIdDb, 'elearning')) {
    http_response_code(403);
    exit('Your account does not have eLearning access.');
}
if ($sid !== '' && hash_equals((string)$submission['Sid'], $sid)) {
    $allowed = true;
}
if (!$allowed && $staffId !== '') {
    $allowed = isLecturerAssignedToCourse($db, $staffId, (string)$submission['course_code']);
}
if (!$allowed) {
    http_response_code(403);
    exit('You are not allowed to download this submission.');
}

$absolute = '';
if (!empty($submission['archive_file_path']) && is_file((string)$submission['archive_file_path'])) {
    $absolute = (string)$submission['archive_file_path'];
} else {
    $candidate = assignmentStorageAbsolutePath($submission['file_path'] ?? '');
    if ($candidate && is_file($candidate)) {
        $absolute = $candidate;
    }
}

if ($absolute === '') {
    http_response_code(404);
    exit('Submission file is not available.');
}

$downloadName = (string)($submission['archive_original_name'] ?: basename($absolute));
$downloadName = str_replace(['"', "\r", "\n"], '', $downloadName);
$mime = assignmentStorageMimeType($absolute);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolute));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
readfile($absolute);
exit;
