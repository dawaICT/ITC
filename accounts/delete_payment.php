<?php
require "../db/connect.php";
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
$requestToken = (string)($_POST['csrf_token'] ?? '');

if ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$paymentId = (int)($_POST['del'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(400);
    exit('Invalid payment ID');
}

$stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    exit('Database error');
}

$stmt->bind_param('i', $paymentId);
$deleted = $stmt->execute();
$stmt->close();

if ($deleted) {
    echo "<script>alert('Payment/Invoice record was successfully deleted permanently.')</script>";
    echo "<script>window.open('unpaidBalance.php','_self')</script>";
    exit;
}

http_response_code(500);
echo "<script>alert('Payment/Invoice record could not be deleted!')</script>";
echo "<script>window.open('unpaidBalance.php','_self')</script>";
