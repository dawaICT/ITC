<?php
// get_students.php - AJAX handler to fetch students enrolled in a course
header('Content-Type: text/html; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id']) && empty($_SESSION['staff_id'])) {
    http_response_code(403);
    echo "<option value='' data-error='1'>You must be logged in to load students.</option>";
    exit;
}

require_once dirname(__DIR__) . "/db/connect.php";
require_once dirname(__DIR__) . "/includes/result_entry_helpers.php";
global $db;

if (!isset($db) || !$db) {
    echo "<option value=''>DB Connection Error</option>";
    exit;
}

$course = trim((string)($_POST['course_code'] ?? ''));
$semester = trim((string)($_POST['semester'] ?? ''));
$year = trim((string)($_POST['year'] ?? ''));

if ($course === '') {
    echo "<option value=''>No course selected</option>";
    exit;
}

if (!preg_match('/^[1-3]$/', $semester) || !preg_match('/^\d{4}$/', $year)) {
    echo "<option value='' data-error='1'>No registered term found.</option>";
    exit;
}

$students = result_students_for_course_period_status($db, $course, $semester, $year);

if (!empty($students)) {
    echo "<option value='' disabled selected>Select Student...</option>";
    foreach ($students as $row) {
        $sid = htmlspecialchars((string)$row['SID'], ENT_QUOTES, 'UTF-8');
        $fname = htmlspecialchars((string)($row['Fname'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lname = htmlspecialchars((string)($row['Lname'] ?? ''), ENT_QUOTES, 'UTF-8');
        $reason = htmlspecialchars((string)($row['reason'] ?? ''), ENT_QUOTES, 'UTF-8');
        if (empty($row['eligible'])) {
            $label = trim("{$sid} - {$fname} {$lname}") . ($reason !== '' ? " ({$reason})" : ' (Not eligible)');
            echo "<option value='' disabled title='{$reason}'>{$label}</option>";
        } else {
            echo "<option value='{$sid}'>{$sid} - {$fname} {$lname}</option>";
        }
    }
} else {
    echo "<option value='' data-error='1'>Results cannot be entered because no eligible student is registered for this course in the academic year.</option>";
}
?>
