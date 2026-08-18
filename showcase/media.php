<?php
declare(strict_types=1);

/**
 * Secure media streamer for Skills-to-Trade showcase images.
 * Supports ?thumb=1 for thumbnails. Blocks path traversal.
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/bootstrap.php';

wuc_secure_session_start();
if (function_exists('wuc_security_headers')) {
    wuc_security_headers();
}

$id = (int)($_GET['id'] ?? 0);
$wantThumb = isset($_GET['thumb']) && (string)$_GET['thumb'] !== '' && (string)$_GET['thumb'] !== '0';

if ($id <= 0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$stmt = $db->prepare('SELECT * FROM enterprise_item_media WHERE id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unavailable';
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$media = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$media) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$item = eh_get_item($db, (int)$media['enterprise_item_id']);
if (!$item || !eh_can_view_media($db, $item)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$root = realpath(eh_media_root());
if ($root === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Storage unavailable';
    exit;
}

$candidate = eh_media_absolute_path($media, $wantThumb);
$real = realpath($candidate);

// Fallback: if thumb missing, try full file
if (($real === false || !is_file($real)) && $wantThumb) {
    $candidate = eh_media_absolute_path($media, false);
    $real = realpath($candidate);
}

if ($real === false || !is_file($real)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found';
    exit;
}

// Path traversal / jail check
$rootPrefix = $root . DIRECTORY_SEPARATOR;
if (strpos($real, $rootPrefix) !== 0 && $real !== $root) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$mime = (string)($media['mime_type'] ?? '');
if ($mime === '' || !preg_match('#^image/(jpeg|png|webp|gif)$#i', $mime)) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($real);
    $mime = is_string($detected) ? $detected : 'application/octet-stream';
    if (!preg_match('#^image/(jpeg|png|webp|gif)$#i', $mime)) {
        http_response_code(415);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unsupported media type';
        exit;
    }
}

$size = filesize($real);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
header('Content-Disposition: inline; filename="' . basename((string)$media['stored_filename']) . '"');
if ($size !== false) {
    header('Content-Length: ' . (string)$size);
}

$fp = fopen($real, 'rb');
if ($fp === false) {
    http_response_code(500);
    echo 'Read error';
    exit;
}
fpassthru($fp);
fclose($fp);
exit;
