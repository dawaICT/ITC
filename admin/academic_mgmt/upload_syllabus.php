<?php
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/auth_helpers.php';

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'Method Not Allowed';
  exit;
}

if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
  http_response_code(403);
  echo 'Invalid security token';
  exit;
}

$program = trim($_POST['program_code'] ?? '');
if ($program === '' || !isset($_FILES['syllabus_file'])) {
  http_response_code(400);
  echo 'Missing parameters';
  exit;
}

// Ensure uploads dir exists
$uploadDir = __DIR__ . '/uploads/syllabi';
if (!is_dir($uploadDir)) {
  @mkdir($uploadDir, 0775, true);
}

$file = $_FILES['syllabus_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo 'Upload failed';
  exit;
}

if ((int)$file['size'] <= 0 || (int)$file['size'] > 10 * 1024 * 1024) {
  http_response_code(400);
  echo 'File must be between 1 byte and 10 MB';
  exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['pdf','doc','docx'])) {
  http_response_code(400);
  echo 'Unsupported file type';
  exit;
}

$allowedMime = [
  'pdf' => ['application/pdf'],
  'doc' => ['application/msword', 'application/octet-stream'],
  'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
if (!in_array($mime, $allowedMime[$ext], true)) {
  http_response_code(400);
  echo 'File content does not match the selected type';
  exit;
}

$finalName = bin2hex(random_bytes(16)) . '.' . $ext;
$destPath = $uploadDir . '/' . $finalName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
  http_response_code(500);
  echo 'Failed to save file';
  exit;
}

// Next version for program
$nextVersion = 1;
if ($res = $db->prepare("SELECT IFNULL(MAX(version),0)+1 AS v FROM program_syllabi WHERE program_code=?")) {
  $res->bind_param('s', $program);
  if ($res->execute()) {
    $row = $res->get_result()->fetch_assoc();
    $nextVersion = (int)($row['v'] ?? 1);
  }
  $res->close();
}

$originalName = basename((string)$file['name']);
$fileSize = (int)$file['size'];
$uploadedBy = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'unknown');
try {
  $ins = $db->prepare("INSERT INTO program_syllabi(program_code, filename, original_name, mime_type, file_size, version, uploaded_by) VALUES(?,?,?,?,?,?,?)");
  $ins->bind_param('ssssiis', $program, $finalName, $originalName, $mime, $fileSize, $nextVersion, $uploadedBy);
  $ins->execute();
  $ins->close();
} catch (Throwable $e) {
  @unlink($destPath);
  error_log('Syllabus metadata insert failed: ' . $e->getMessage());
  http_response_code(500);
  echo 'Unable to save syllabus metadata';
  exit;
}

header('Location: curriculum.php');
exit;


