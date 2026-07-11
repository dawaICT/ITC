<?php
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';

$sessionSid = (string)($_SESSION['Sid'] ?? '');
$del = isset($_GET['del']) ? (int)$_GET['del'] : 0;

if ($sessionSid === '' || $del <= 0) {
    $_SESSION['errorWithdraw'] = ' Invalid withdrawal request.';
    header('Location: exempStatus.php');
    exit;
}

// Only the owner may withdraw, and only while the application is pending.
$stmt = $db->prepare('DELETE FROM exemption WHERE CoRegID = ? AND Sid = ? AND status = 0');
$ok = false;
if ($stmt) {
    $stmt->bind_param('is', $del, $sessionSid);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
}

if ($ok) {
    $_SESSION['successWithdraw'] = ' You have successfully withdrawn your exemption application.';
} else {
    $_SESSION['errorWithdraw'] = ' You can not withdraw this exemption application, because it has already been processed.';
}
header('Location: exempStatus.php');
exit;
