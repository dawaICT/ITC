<?php
require "../db/connect.php";
require_once __DIR__ . '/../includes/payment_helpers.php';
error_reporting(0);
session_start();

if(!empty($_POST)){

      if(isset($_POST["Sid"], $_POST["program_code"], $_POST["invoice"], $_POST["balance"], $_POST["narration"], $_POST["semester"], $_POST["Year"])) {

        $Sid = trim($_POST["Sid"]);
        $program_code = trim($_POST["program_code"]);
        $invoice = trim($_POST["invoice"]);
        $balance = trim($_POST["balance"]);
        $narration = trim($_POST["narration"]);
        $semester = trim($_POST["semester"]);
        $Year = trim($_POST["Year"]);     

    if (!empty($Sid)) {
            // Prevent duplicate semester registration.
            $checkStmt = $db->prepare("SELECT id FROM semester_registration WHERE SID = ? AND program_code = ? AND semester = ? AND academic_year = ? LIMIT 1");
            if ($checkStmt) {
                $checkStmt->bind_param('ssss', $Sid, $program_code, $semester, $Year);
                $checkStmt->execute();
                $res = $checkStmt->get_result();
                if ($res && $res->num_rows > 0) {
                    echo "<script>alert('You are already registered for Semester $semester Year $Year.')</script>";
                    echo "<script>window.open('registration.php','_self')</script>";
                    exit;
                }
            }

            if ((float)$invoice > 0) {
                $created = payment_create_student_invoice($db, $Sid, (float)$invoice, $Year, $semester, $narration ?: 'Semester registration invoice');
                if (empty($created['success']) && empty($created['duplicate'])) {
                    echo "<script>alert('Semester registration failed! Could not create invoice.')</script>";
                    echo "<script>window.open('registration.php','_self')</script>";
                    exit;
                }
            }

            // Now insert into semester_registration table.
            $insertReg = $db->prepare("INSERT INTO semester_registration (program_code, SID, semester, academic_year, registration_date, created_at) VALUE(?,?,?,?,NOW(),NOW())");
            $insertReg->bind_param("ssss", $program_code, $Sid, $semester, $Year);

            if ($insertReg->execute()) {
                echo "<script>alert('Semester registration successful!')</script>";
                echo "<script>window.open('registration.php','_self')</script>";
            } else {
                echo "<script>alert('Failed to register for semester! Error: " . $db->error . "')</script>";
                echo "<script>window.open('registration.php','_self')</script>";
            }
        }
        else {
            echo "<script>alert('Invalid data provided!')</script>";
            echo "<script>window.open('registration.php','_self')</script>";
        }
    }
}

?>
