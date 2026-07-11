<?php
/**
 * registrar/MyCourses.php - Legacy redirect
 * This file was a legacy prototype with hardcoded data and no backend logic.
 * All student course viewing is now handled by students/myCourses.php.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to the proper student courses page
if (isset($_SESSION['Sid']) && !empty($_SESSION['Sid'])) {
    header('Location: ../students/myCourses.php');
    exit;
}

// No valid student session - redirect to login
header('Location: ../student_login.php');
exit;