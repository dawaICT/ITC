<?php
require "../db/connect.php";
require_once __DIR__ . '/../includes/payment_helpers.php';
error_reporting(0);
session_start();

$Sid = trim($_POST["Sid"]);
$balance = trim($_POST["balance"]);
$invoice = trim($_POST["invoice"]);
$semester2 = trim($_POST["semester2"]);
$semester = trim($_POST["semester"]);
$narration = trim($_POST["narration"]);
$Year = trim($_POST["Year"]);

//To check if student already registered for the current semester.
try {
    $checkStmt = $db->prepare("SELECT id FROM semester_registration WHERE SID = ? AND program_code = ? AND semester = ? AND academic_year = ? LIMIT 1");
    if ($checkStmt) {
        $checkStmt->bind_param('ssss', $_SESSION['Sid'], $_POST['program_code'], $_POST['semester'], $_POST['Year']);
        $checkStmt->execute();
        $res = $checkStmt->get_result();
        if ($res && $res->num_rows > 0) {
            echo "<script>alert('Student ID " . htmlspecialchars($_SESSION['Sid']) . " already registered for Semester $semester Year $Year if this was an error please see the system administrator.')</script>";
            echo "<script>window.open('registration.php','_self')</script>";
            exit;
        }
    }
} catch (Throwable $e) { /* ignore */ }

if (!empty($Sid) && isset($invoice) && !empty($semester) && !empty($narration) && !empty($Year)) {
    $created = payment_create_student_invoice($db, $Sid, (float)$invoice, $Year, $semester, $narration);
    if (empty($created['success']) && empty($created['duplicate'])) {
        echo "<script>alert('Student invoicing failed!')</script>";
        echo "<script>window.open('registration.php','_self')</script>";
        die();
    }
}

?>
