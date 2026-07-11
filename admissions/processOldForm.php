<?php
require "includes/nav.php";

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// Function to generate exactly 10-digit Student ID
// Uses shared generator from includes/student_id_generator.php
require_once __DIR__ . '/../includes/student_id_generator.php';
// Shared admissions helpers — provides admissionsEnsureStudentLogin().
require_once __DIR__ . '/includes/registration_handlers.php';


if(isset($_POST['submit']))
{
    // 1. CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['errorMessage'] = "Security validation failed. Please refresh the page and try again.";
        header('Location:regOldStud.php');
        exit();
    }

    // 2. Input Sanitization
    $title = trim($_POST["title"]);
    $Fname = trim($_POST["Fname"]);
    $Lname = trim($_POST["Lname"]);
    $sex = trim($_POST["sex"]);
    $nrc_pass = trim($_POST["nrc_pass"]);
    $country = trim($_POST["country"]);
    $dob = trim($_POST["dob"]);
    $mobile = trim($_POST["mobile"]);
    $email = trim($_POST["email"]);
    // FIX: students.status is the ACCOUNT status checked at login (must be
    // 'active'/'enabled' to sign in). Never store form values like marital
    // status here — new records always start as 'active'.
    $status = 'active';
    $h_addre = trim($_POST["h_addre"]);
    $p_addre = trim($_POST["p_addre"]);
    $sponsor = trim($_POST["sponsor"]);
    $next_kin = trim($_POST["next_kin"]);
    $next_kin_mobile = trim($_POST["next_kin_mobile"]);
    $relat = trim($_POST["relat"]);
    $school = trim($_POST["school"] ?? '');
    $academic_year = trim($_POST["academic_year"] ?? date('Y'));
    
    // Transfer student fields
    $is_transfer = (isset($_POST['is_transfer']) && ($_POST['is_transfer'] === 'on' || $_POST['is_transfer'] == '1')) ? 1 : 0;
    $transfer_from = $is_transfer ? trim($_POST["transfer_from"] ?? '') : '';
    $transfer_credits = $is_transfer ? (int)($_POST["transfer_credits"] ?? 0) : 0;
    $transfer_program = $is_transfer ? trim($_POST["transfer_program"] ?? '') : '';
    $transfer_letter = $is_transfer ? trim($_POST["transfer_letter"] ?? '') : '';

    // 3. Secure File Upload Handling
    $upload_dir = "../uploads/";
    $profile_dir = "../uploads/profile/";
    
    // Check if directories exist
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
    if (!file_exists($profile_dir)) mkdir($profile_dir, 0755, true);

    function processUpload($fileKey, $targetDir, $prefix) {
        if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] != UPLOAD_ERR_OK) {
             // If file missing but required, we could return false. 
             // Logic assumes validation happened on frontend, but for security we should check.
             return false;
        }
        
        $tmp_name = $_FILES[$fileKey]['tmp_name'];
        $name = $_FILES[$fileKey]['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        
        // Allowed formats
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!in_array($ext, $allowed)) return false;
        
        // Generate secure, unique filename to prevent overwrites and directory transversal
        $new_name = $prefix . '_' . md5(uniqid(rand(), true)) . '.' . $ext;
        
        if (move_uploaded_file($tmp_name, $targetDir . $new_name)) {
            return $new_name;
        }
        return false;
    }

    if (isset($_POST['bulk_import'])) {
        // Bulk import: bypass file upload validation
        $profile_image = 'default_profile.png';
        $results_file = 'pending_results.pdf';
        $nrc_file = 'pending_nrc.pdf';
    } else {
        $profile_image = processUpload('profile_image', $profile_dir, 'p');
        $results_file = processUpload('results', $upload_dir, 'r');
        $nrc_file = processUpload('nrc_file', $upload_dir, 'n');

        if ($profile_image === false || $results_file === false || $nrc_file === false) {
            $_SESSION['invalidFormat'] = "Upload failed. Please ensure files are valid (PDF, JPG, PNG) and try again."; 
            header('Location:regOldStud.php');
            exit();
        }
    }

    // 4. Duplicate Check (Server Side) — email, phone, and NRC
    try {
        admissionsAssertIdentityIsUnique($db, $email, $mobile, $nrc_pass);
    } catch (RuntimeException $e) {
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => false, 'message' => 'Registration blocked: ' . $e->getMessage()]);
            exit();
        }
        echo "<script>alert('Registration blocked: " . htmlspecialchars(addslashes($e->getMessage()), ENT_QUOTES) . "'); window.location.href='regOldStud.php';</script>";
        exit();
    }

    // 5. Generate SID
    $program_code = "TRANSFER"; 
    $semester = date('m') >= 7 ? 2 : 1; 
    
    try {
        $SID = generateStudentId($db, $program_code, (string)$semester, $academic_year, $nrc_pass);
    } catch (Exception $e) {
        $_SESSION['errorMessage'] = "Error generating Student ID: " . $e->getMessage();
        header('Location:regOldStud.php');
        exit();
    }

    // 6. Insert Data (Prepared Statement)
    // FIX (FATAL): the old INSERT named columns that do not exist in the live
    // students table (grade, dte1, dte2, transfer_program, transfer_letter) and
    // fataled with an Unknown-column error on every submission. The column list
    // below matches the real schema exactly.
    $insert_query = "INSERT INTO students (
        SID, title, Fname, Lname, sex, nrc_pass, country, dob,
        mobile, email, status, h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat, school,
        profile_image, results, nrc_file, dte_adm, is_transfer, transfer_from, transfer_credits, academic_year
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)";

    $stmt = $db->prepare($insert_query);

    if ($stmt) {
        $stmt->bind_param("sssssssssssssssssssssisis",
            $SID, $title, $Fname, $Lname, $sex, $nrc_pass, $country, $dob,
            $mobile, $email, $status, $h_addre, $p_addre, $sponsor, $next_kin, $next_kin_mobile, $relat, $school,
            $profile_image, $results_file, $nrc_file,
            $is_transfer, $transfer_from, $transfer_credits, $academic_year
        );

        if ($stmt->execute()) {
            // Success
            $transfer_text = $is_transfer ? " (Transfer from: $transfer_from, Credits: $transfer_credits, Program: $transfer_program, Letter: $transfer_letter)" : "";
            error_log("Existing Student Registration: SID=$SID, Name=$Fname $Lname, is_transfer=$is_transfer, year=$academic_year" . $transfer_text);

            // FIX (CRITICAL): create portal credentials immediately (initial
            // password = NRC, forced change on first login) instead of leaving
            // the student without a student_login row.
            admissionsEnsureStudentLogin($db, $SID, $nrc_pass, $email !== '' ? $email : null);
            
            $status_msg = $is_transfer ? 
                "Transfer student registered! SID: $SID (Credits: $transfer_credits). Proceed to admit." :
                "Existing student registered! SID: $SID. Proceed to admit.";
            
            if (isset($_POST['ajax'])) {
                echo json_encode(['success' => true, 'message' => $status_msg, 'sid' => $SID]);
                exit();
            } else {
                echo "<script>alert('$status_msg'); window.location.href='admitStudent.php';</script>";
            }
        } else {
            if (isset($_POST['ajax'])) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => "Registration failed: " . $stmt->error]);
                exit();
            } else {
                $_SESSION['errorMessage'] = "Registration failed: " . $stmt->error;
                header('Location:regOldStud.php');
            }
        }
        $stmt->close();
    } else {
        $error = "Database error: " . $db->error;
        if (isset($_POST['ajax'])) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $error]);
            exit();
        } else {
            $_SESSION['errorMessage'] = $error;
            header('Location:regOldStud.php');
        }
    }
}
?>