<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
wuc_secure_session_start();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/enterprise_portal/bootstrap.php';

$stmt = $db->prepare(
    'SELECT m.*, o.status AS opp_status, o.enterprise_profile_id, p.owner_user_id, p.membership_id
     FROM enterprise_media m
     JOIN enterprise_opportunities o ON o.id = m.enterprise_opportunity_id
     JOIN enterprise_member_profiles p ON p.id = o.enterprise_profile_id
     WHERE m.id = ? LIMIT 1'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$media = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$media) {
    http_response_code(404);
    exit('Not found');
}

$published = (string)$media['opp_status'] === 'published';
$ownerOk = ep_current_user_id() > 0 && (int)$media['owner_user_id'] === ep_current_user_id();
$memberOk = false;
if (!empty($_SESSION['logged_in']) && !$ownerOk) {
    $mem = ep_get_membership_for_user($db, ep_current_user_id(), ep_current_student_id());
    $memberOk = $mem && (int)$mem['id'] === (int)$media['membership_id'];
}

if (!$published && !$ownerOk && !$memberOk && !ep_can($db, 'enterprise.review.access') && !ep_can($db, 'enterprise.approve')) {
    http_response_code(403);
    exit('Forbidden');
}

$root = realpath(dirname(__DIR__));
if ($root === false) {
    http_response_code(500);
    exit('Storage unavailable');
}
$rel = str_replace(['\\', '..'], ['/', ''], (string)$media['file_path']);
$full = realpath($root . '/' . ltrim($rel, '/'));
if ($full === false || !is_file($full) || strpos($full, $root) !== 0) {
    http_response_code(404);
    exit('File not found');
}

$mime = (string)($media['mime_type'] ?? 'image/jpeg');
if (!preg_match('#^image/#', $mime)) {
    $mime = 'application/octet-stream';
}
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($full);
exit;
