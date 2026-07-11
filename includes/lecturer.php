<?php
session_start();
require_once 'config.php';
require_once 'db_connect.php';

// Check if user is logged in and is a lecturer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: /wucportal/staff_login.php");
    exit();
}

// Get lecturer information
$lecturer_id = $_SESSION['user_id'];
$lecturerStmt = $db->prepare("SELECT * FROM staff WHERE staff_id = ?");
$lecturerStmt->bind_param("s", $lecturer_id);
$lecturerStmt->execute();
$lecturer = $lecturerStmt->get_result()->fetch_assoc();

if (!$lecturer) {
    header("Location: /wucportal/staff_login.php");
    exit();
}
?> 
