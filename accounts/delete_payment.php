<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/production_guards.php';

wuc_require_post();
wuc_require_finance_staff();
wuc_require_csrf();

require_once __DIR__ . '/../db/connect.php';

$paymentId = (int)($_POST['del'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(400);
    exit('Invalid payment ID');
}

$stmt = $db->prepare('DELETE FROM payments WHERE id = ? LIMIT 1');
if (!$stmt) {
    error_log('delete_payment prepare failed: ' . $db->error);
    http_response_code(500);
    exit('Database error');
}

$stmt->bind_param('i', $paymentId);
$deleted = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

$actor = (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? '');
error_log(sprintf(
    'payment_delete actor=%s payment_id=%d deleted=%d ip=%s',
    $actor,
    $paymentId,
    $affected,
    $_SERVER['REMOTE_ADDR'] ?? ''
));

if ($deleted && $affected > 0) {
    echo "<script>alert('Payment/Invoice record was successfully deleted permanently.')</script>";
    echo "<script>window.open('unpaidBalance.php','_self')</script>";
    exit;
}

http_response_code(500);
echo "<script>alert('Payment/Invoice record could not be deleted!')</script>";
echo "<script>window.open('unpaidBalance.php','_self')</script>";
