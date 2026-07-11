<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid file reference.');
}
$stmt = $db->prepare('SELECT filename, original_name, mime_type, file_size FROM program_syllabi WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$file) {
    http_response_code(404);
    exit('File not found.');
}
$root = realpath(__DIR__ . '/uploads/syllabi');
$path = $root ? realpath($root . DIRECTORY_SEPARATOR . basename($file['filename'])) : false;
if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Stored file is missing.');
}
$downloadName = str_replace(["\r", "\n", '"'], '', basename($file['original_name']));
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
