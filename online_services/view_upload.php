<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admissions/includes/session_handler.php';

if (!checkSessionTimeout(30) || !isAdminAuthenticated()) {
    http_response_code(403);
    exit('Unauthorized access');
}

$file = basename((string) ($_GET['file'] ?? ''));
if (!preg_match('/\A(?:results|nrc|nrc_file|deposit|deposit_slip)_[0-9]+_[0-9]+\.(?:pdf|jpe?g|png)\z/i', $file)) {
    http_response_code(400);
    exit('Invalid file request');
}

$baseDir = realpath(__DIR__ . '/uploads');
$filePath = $baseDir !== false ? realpath($baseDir . DIRECTORY_SEPARATOR . $file) : false;
if ($baseDir === false || $filePath === false || strpos($filePath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
    http_response_code(404);
    exit('File not found');
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$contentTypes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
];

header('Content-Type: ' . ($contentTypes[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $file) . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
