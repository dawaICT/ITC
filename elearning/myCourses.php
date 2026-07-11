<?php
/**
 * eLearning My Courses - Smart Routing
 * Routes users to the appropriate courses page based on their role
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/portal_access.php';

if (!wuc_user_has_portal_access($db, (int)($_SESSION['user_id_db'] ?? 0), 'elearning')) {
    if (isset($_SESSION['Sid']) && !empty($_SESSION['Sid'])) {
        $_SESSION['errorMssg'] = 'Your account is active, but eLearning access has not been assigned. Please contact the Registrar or eLearning Administrator.';
        header('Location: ../elearning_login.php');
        exit;
    }
    $_SESSION['errorMessage'] = 'Your account is active, but eLearning access has not been assigned. Please contact the Registrar or eLearning Administrator.';
    header('Location: ../portal_selection.php');
    exit;
}

// Check for student session
if (isset($_SESSION['Sid']) && !empty($_SESSION['Sid'])) {
    // Redirect to student courses page
    header('Location: ../students/myCourses.php');
    exit;
}

// Check for staff/lecturer session
if (isset($_SESSION['staff_id']) && !empty($_SESSION['staff_id'])) {
    // Redirect to elearning courses management
    header('Location: courses.php');
    exit;
}

// No valid session found - redirect to login
// Check for the most likely login page
header('Location: ../student_login.php?redirect=' . urlencode('students/myCourses.php'));
exit;
?>
