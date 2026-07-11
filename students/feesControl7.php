<?php
//To restrict access to only the students who have paid upto 50%
error_reporting(0);
require "../db/connect.php";
require_once __DIR__ . '/includes/FeeGuard.php';
session_start();

$sid = isset($_SESSION['Sid']) ? (string)$_SESSION['Sid'] : '';
$yr = isset($Year) ? (int)$Year : (int)($_POST['Year'] ?? 0);
$sem = isset($semester) ? (int)$semester : (int)($_POST['semester'] ?? 0);
$check = fg_check_fee_threshold($db, $sid, $yr, $sem, 50.0);
if (!$check['ok']) {
    echo "<script>alert('" . addslashes($check['message']) . "')</script>";
    echo"<script>window.open('courseReg.php','_self')</script>";
    die();
}

?>