<?php
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/nav.php';
require_once dirname(__DIR__) . "/includes/permissions.php";

// Check if user has permission to edit courses
enforcePermission($_SESSION['staff_id'], 'edit_courses');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_code = trim($_POST['course_code']);
    $section = trim($_POST['section']);

    // Verify that the lecturer has access to this course
    if (!hasAccessToCourse($_SESSION['staff_id'], $course_code)) {
        die("Access Denied: You don't have permission to edit this course.");
    }

    // Update the course section
    $query = "UPDATE course_lecturer 
              SET section = ? 
              WHERE staff_id = ? AND course_code = ?";

    $stmt = $db->prepare($query);
    $stmt->bind_param("sss", $section, $_SESSION['staff_id'], $course_code);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Course details updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error updating course details. Please try again.";
    }

    header("Location: manage_courses.php");
    exit();
} else {
    header("Location: manage_courses.php");
    exit();
} 