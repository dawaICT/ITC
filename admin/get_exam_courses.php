<?php
// get_exam_courses.php - AJAX handler to fetch courses with enrolled students
header('Content-Type: text/html; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id']) && empty($_SESSION['staff_id'])) {
    http_response_code(403);
    echo "<option value='' data-error='1'>You must be logged in to load courses.</option>";
    exit;
}

require_once dirname(__DIR__) . "/db/connect.php";
require_once dirname(__DIR__) . "/includes/result_entry_helpers.php";
global $db;

if (!$db) {
    echo "<option value=''>DB Connection Error</option>";
    exit;
}

$semester = trim((string)($_POST['semester'] ?? ''));
$year = trim((string)($_POST['year'] ?? ''));

if (!preg_match('/^[1-3]$/', $semester) || !preg_match('/^\d{4}$/', $year)) {
    echo "<option value='' data-error='1'>No registered term found.</option>";
    exit;
}

$courses = result_exam_courses_for_period($db, $semester, $year);

if (!empty($courses)) {
    echo "<option value='' disabled selected>Select Course...</option>";
    foreach ($courses as $row) {
        $code = htmlspecialchars((string)$row['course_code'], ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string)$row['course_name'], ENT_QUOTES, 'UTF-8');
        echo "<option value='{$code}'>{$code} - {$name}</option>";
    }
} else {
    echo "<option value='' data-error='1'>No assessment setup found for the selected term.</option>";
}
?>
