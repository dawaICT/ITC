<?php
session_start();
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/student_id_generator.php';
// Shared upload rules (allowed types, 5MB cap, real-content MIME check).
require_once __DIR__ . '/../includes/upload_validator.php';

// FIX: this handler creates student records and portal credentials — it must
// only be reachable by a logged-in staff member. Previously it had NO auth
// check at all, so an anonymous POST could insert students.
if (!isset($_SESSION['staff_id']) && !isset($_SESSION['user_id'])) {
    header('Location: /wucportal/staff_login.php');
    exit();
}

// Log errors instead of displaying them (this endpoint redirects on failure).
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

function debugLog($message) {
    error_log(print_r($message, true));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    debugLog("Form submitted");

    try {
        // Validate required fields
        $required_fields = [
            'title', 'Fname', 'Lname', 'sex', 'dob', 'nrc', 
            'semester', 'bursary_percentage', 'mobile', 'h_addre',
            'country', 'next_kin', 'relat', 'next_kin_mobile'
        ];

        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (!empty($missing_fields)) {
            throw new Exception("Required fields missing: " . implode(", ", $missing_fields));
        }

        // Sanitize input — using actual students table column names
        $title = $db->real_escape_string($_POST['title']);
        $Fname = $db->real_escape_string($_POST['Fname']);
        $Lname = $db->real_escape_string($_POST['Lname']);
        $sex = $db->real_escape_string($_POST['sex']);
        $dob = $db->real_escape_string($_POST['dob']);
        $nrc_pass = $db->real_escape_string($_POST['nrc']);
        $semester = $db->real_escape_string($_POST['semester']);
        $bursary = (int)$_POST['bursary_percentage'];
        $mobile = $db->real_escape_string($_POST['mobile']);
        $email = $db->real_escape_string($_POST['email'] ?? '');
        $h_addre = $db->real_escape_string($_POST['h_addre']);
        $p_addre = $db->real_escape_string($_POST['p_addre'] ?? '');
        $country = $db->real_escape_string($_POST['country']);
        $sponsor = $db->real_escape_string($_POST['sponsor'] ?? 'Self');
        // TEVETA and CDF sponsorship implies a full (100%) bursary.
        if (in_array(strtoupper($sponsor), ['TEVETA', 'CDF'], true)) {
            $bursary = 100;
        }
        $next_kin = $db->real_escape_string($_POST['next_kin']);
        $relat = $db->real_escape_string($_POST['relat']);
        $next_kin_mobile = $db->real_escape_string($_POST['next_kin_mobile']);
        $school = $db->real_escape_string($_POST['sec_school'] ?? '');
        $status = $db->real_escape_string($_POST['status'] ?? 'Active');

        debugLog("Data sanitized");

        // Generate student number using shared generator
        $program_code = $_POST['program'] ?? 'GENERAL';
        $academic_year = date('Y');
        $SID = generateStudentId($db, $program_code, $semester, $academic_year, $nrc_pass);
        debugLog("Generated student ID: " . $SID);

        // Handle file uploads
        $uploadDir = __DIR__ . '/../uploads/students/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $profile_image = 'default.jpg';
        $nrc_file = null;
        $results_file = null;

        if (isset($_FILES['profile_pic']) && ($_FILES['profile_pic']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $ext = wucValidateUpload($_FILES['profile_pic'], 'image');
            } catch (RuntimeException $e) {
                throw new Exception('Profile photo: ' . $e->getMessage());
            }
            $profile_image = 'profile_' . $SID . '.' . $ext;
            if (!move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $profile_image)) {
                throw new Exception('Failed to store the profile photo.');
            }
        }

        if (isset($_FILES['nrc_scan']) && ($_FILES['nrc_scan']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $ext = wucValidateUpload($_FILES['nrc_scan'], 'document');
            } catch (RuntimeException $e) {
                throw new Exception('NRC scan: ' . $e->getMessage());
            }
            $nrc_file = 'nrc_' . $SID . '.' . $ext;
            if (!move_uploaded_file($_FILES['nrc_scan']['tmp_name'], $uploadDir . $nrc_file)) {
                throw new Exception('Failed to store the NRC scan.');
            }
        }

        if (isset($_FILES['results_scan']) && ($_FILES['results_scan']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $ext = wucValidateUpload($_FILES['results_scan'], 'document');
            } catch (RuntimeException $e) {
                throw new Exception('Results scan: ' . $e->getMessage());
            }
            $results_file = 'results_' . $SID . '.' . $ext;
            if (!move_uploaded_file($_FILES['results_scan']['tmp_name'], $uploadDir . $results_file)) {
                throw new Exception('Failed to store the results scan.');
            }
        }

        // Begin transaction
        $db->begin_transaction();

        // Insert using ACTUAL students table column names
        $stmt = $db->prepare("INSERT INTO students (
            SID, title, Fname, Lname, sex, dob, nrc_pass,
            mobile, email, status, h_addre, p_addre, country, sponsor,
            next_kin, relat, next_kin_mobile, school,
            profile_image, nrc_file, results, dte_adm
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, NOW()
        )");

        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }

        $stmt->bind_param("sssssssssssssssssssss",
            $SID, $title, $Fname, $Lname, $sex, $dob, $nrc_pass,
            $mobile, $email, $status, $h_addre, $p_addre, $country, $sponsor,
            $next_kin, $relat, $next_kin_mobile, $school,
            $profile_image, $nrc_file, $results_file
        );

        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $stmt->close();

        // Create portal credentials right away (initial password = NRC,
        // forced change on first login) so the student printed on the success
        // screen can actually sign in. Idempotent — never touches an existing
        // student_login row.
        require_once __DIR__ . '/../admissions/includes/registration_handlers.php';
        admissionsEnsureStudentLogin($db, $SID, $nrc_pass, $email !== '' ? $email : null);

        // Commit transaction
        $db->commit();

        $student_details = "Student ID: " . $SID . "\n" .
                         "Name: " . $title . " " . $Fname . " " . $Lname . "\n" .
                         "NRC: " . $nrc_pass;

        $_SESSION['success_message'] = "Student registered successfully!\n" . $student_details;
        $_SESSION['student_number'] = $SID;

        debugLog("Registration successful: " . $student_details);

        header("Location: register_student.php?status=success");
        exit();

    } catch (Exception $e) {
        if (isset($db) && $db->ping()) {
            $db->rollback();
        }

        $error_message = $e->getMessage();
        debugLog("Error: " . $error_message);

        if (strpos($error_message, 'Duplicate entry') !== false) {
            if (strpos($error_message, 'SID') !== false || strpos($error_message, 'PRIMARY') !== false) {
                $error_message = "This student ID already exists. Please try again.";
            } elseif (strpos($error_message, 'nrc') !== false) {
                $error_message = "This NRC/Passport number is already registered.";
            }
        }

        $_SESSION['error_message'] = "Registration failed: " . $error_message;
        header("Location: register_student.php?status=error");
        exit();
    }
} else {
    header("Location: register_student.php");
    exit();
}
?>