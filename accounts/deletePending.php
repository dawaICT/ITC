<?php
declare(strict_types=1);
require dirname(__DIR__) . '/admin/includes/admin.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request.');
}
$id = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
if ($id && $id > 0) {
    $stmt = $db->prepare("DELETE FROM payments WHERE id = ? AND LOWER(status) = 'pending'");
    $stmt->bind_param('i', $id); $stmt->execute();
    $_SESSION[$stmt->affected_rows ? 'successMssg' : 'errorMssg'] = $stmt->affected_rows ? 'Pending payment removed.' : 'Pending payment was not found.';
    $stmt->close();
}
header('Location: pendingPayments.php', true, 303); exit;
