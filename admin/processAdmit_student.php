<?php
session_start();
require "../db/connect.php";

function redirect(string $path) {
    header("Location: " . $path);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admitEnrolled_student.php');
}

$required = ['Sid','program_code','intake','mode','startYear','endYear'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        $_SESSION['error_msg'] = 'Missing required field: ' . $key;
        redirect('admitEnrolled_student.php');
    }
}

$Sid = trim($_POST['Sid']);
$program_code = trim($_POST['program_code']);
$intake = trim($_POST['intake']);
$mode = trim($_POST['mode']);
// startYear/endYear arrive from the form; only the start YEAR matters for the
// smallint startYear column (the end year is derived from programme duration).
$entryYear = (int)substr(trim($_POST['startYear']), 0, 4) ?: (int)date('Y');

// Single canonical admission path: full student_program enrolment + account
// activation + login + course/invoice. Identical result to the online-applicant
// admission, so the two flows can never drift apart again.
require_once __DIR__ . '/../includes/applicant_admission.php';
$result = admissionsEnrollExistingStudent($db, $Sid, $program_code, $intake, $mode, $entryYear);

if ($result['success']) {
    $_SESSION['success_msg'] = $result['message'];
    redirect('students_by_admin.php');
}

$_SESSION['error_msg'] = $result['message'];
redirect('admitEnrolled_student.php');
