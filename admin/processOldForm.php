<?php
require "includes/admin.php";
// Shared upload rules (allowed types, 5MB cap, real-content MIME check).
require_once __DIR__ . '/../includes/upload_validator.php';
// System-authoritative Student ID generator (single source of truth).
require_once __DIR__ . '/../includes/student_id_generator.php';
error_reporting(0);

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic sanitization — note: SID is NOT read from the client; it is generated below.
    $title = trim($_POST["title"] ?? '');
    $Fname = trim($_POST["Fname"] ?? '');
    $Lname = trim($_POST["Lname"] ?? '');
    $sex = trim($_POST["sex"] ?? '');
    $nrc_pass = trim($_POST["nrc_pass"] ?? '');
    $dob = trim($_POST["dob"] ?? '');
    $mobile = trim($_POST["mobile"] ?? '');
    $email = trim($_POST["email"] ?? '');
    $status = trim($_POST["status"] ?? '');
    $h_addre = trim($_POST["h_addre"] ?? '');
    $p_addre = trim($_POST["p_addre"] ?? '');
    $sponsor = trim($_POST["sponsor"] ?? '');
    $next_kin = trim($_POST["next_kin"] ?? '');
    $next_kin_mobile = trim($_POST["next_kin_mobile"] ?? '');
    $relat = trim($_POST["relat"] ?? '');
    $school = trim($_POST["school"] ?? '');
    $completion_year = trim($_POST["completion_year"] ?? '');
    $semester = trim($_POST["semester"] ?? '');

    // Validate required fields (server-side)
    $required = [$title, $Fname, $Lname, $sex, $nrc_pass, $dob, $mobile, $status, $h_addre, $sponsor, $next_kin, $next_kin_mobile, $relat, $school, $completion_year, $semester];
    if (in_array('', $required, true)) {
        $_SESSION['errorMessage'] = "Please fill in all required fields.";
        header('Location:regOldStud.php');
        exit();
    }

    // Generate the Student ID on the server — the system is the only authority for SIDs.
    $semesterNum = is_numeric($semester) ? (int)$semester : (date('m') >= 7 ? 2 : 1);
    try {
        $SID = generateStudentId($db, 'GENERAL', (string)$semesterNum, date('Y'), $nrc_pass);
    } catch (Exception $e) {
        $_SESSION['errorMessage'] = "Error generating Student ID: " . $e->getMessage();
        header('Location:regOldStud.php');
        exit();
    }

    // Helper to check if a column exists on students table
    $colExists = function(mysqli $db, string $col): bool {
        $sql = "SELECT COUNT(*) cnt FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='students' AND column_name=?";
        $st = $db->prepare($sql);
        $st->bind_param('s', $col);
        $st->execute();
        $res = $st->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        return $row && (int)$row['cnt'] > 0;
    };

    // Duplicate check using prepared statement with flexible columns
    $studentIdCol = $colExists($db, 'SID') ? 'SID' : ($colExists($db, 'student_number') ? 'student_number' : 'SID');
    $nrcCol = $colExists($db, 'nrc_pass') ? 'nrc_pass' : ($colExists($db, 'nrc') ? 'nrc' : null);

    if ($nrcCol) {
        $stmt = $db->prepare("SELECT 1 FROM students WHERE $studentIdCol = ? OR $nrcCol = ? LIMIT 1");
        $stmt->bind_param('ss', $SID, $nrc_pass);
    } else {
        $stmt = $db->prepare("SELECT 1 FROM students WHERE $studentIdCol = ? LIMIT 1");
        $stmt->bind_param('s', $SID);
    }
    $stmt->execute();
    $dup = $stmt->get_result();
    if ($dup && $dup->num_rows > 0) {
        echo "<script>alert('This student number or national ID already exist in the system. Please use existing student')</script>";
        echo "<script>window.open('regOldStud.php','_self')</script>";
        exit();
    }

    // File uploads with validation and unique names
    $uploadErrors = [];
    $uploaded_profile = null;
    $uploaded_results = null;
    $uploaded_nrc = null;

    $uploadDirProfile = realpath(__DIR__ . '/../uploads/profile');
    $uploadDirFiles = realpath(__DIR__ . '/../uploads');

    if (!is_dir($uploadDirProfile)) { @mkdir($uploadDirProfile, 0775, true); }
    if (!is_dir($uploadDirFiles)) { @mkdir($uploadDirFiles, 0775, true); }

    // Helper to move a file safely (delegates to the shared validator).
    $moveSafe = function(array $file, string $targetDir, string $kind) use (&$uploadErrors) {
        try {
            $ext = wucValidateUpload($file, $kind);
        } catch (RuntimeException $e) {
            $uploadErrors[] = $e->getMessage();
            return null;
        }
        $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
        $target = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $uploadErrors[] = 'Failed to move uploaded file.';
            return null;
        }
        return $safeName;
    };

    if (!empty($_FILES['profile_image']['name'])) {
        $uploaded_profile = $moveSafe(
            $_FILES['profile_image'],
            $uploadDirProfile ?: (__DIR__ . '/../uploads/profile'),
            'image'
        );
    }
    if (!empty($_FILES['results']['name'])) {
        $uploaded_results = $moveSafe(
            $_FILES['results'],
            $uploadDirFiles ?: (__DIR__ . '/../uploads'),
            'document'
        );
    }
    if (!empty($_FILES['nrc_file']['name'])) {
        $uploaded_nrc = $moveSafe(
            $_FILES['nrc_file'],
            $uploadDirFiles ?: (__DIR__ . '/../uploads'),
            'document'
        );
    }

    if (!empty($uploadErrors)) {
        $_SESSION['invalidFormat'] = 'Upload error: ' . implode(' ', $uploadErrors);
            header('Location:regOldStud.php');
        exit();
    }

    // Dynamic insert using column detection and mappings
    $pickColumn = function(array $candidates) use ($colExists, $db) {
        foreach ($candidates as $c) {
            if ($colExists($db, $c)) return $c;
        }
        return null;
    };

    $columns = [];
    $params = [];
    $types = '';

    // Student ID (SID or student_number)
    $idCol = $pickColumn(['SID', 'student_number']);
    if ($idCol) { $columns[] = $idCol; $params[] = $SID; $types .= 's'; }

    // Title
    $col = $pickColumn(['title']); if ($col) { $columns[] = $col; $params[] = $title; $types .= 's'; }

    // First/Last name
    $col = $pickColumn(['Fname', 'first_name']); if ($col) { $columns[] = $col; $params[] = $Fname; $types .= 's'; }
    $col = $pickColumn(['Lname', 'last_name']); if ($col) { $columns[] = $col; $params[] = $Lname; $types .= 's'; }

    // Gender
    $col = $pickColumn(['sex', 'gender']); if ($col) { $columns[] = $col; $params[] = $sex; $types .= 's'; }

    // National ID
    $col = $pickColumn(['nrc_pass', 'nrc']); if ($col) { $columns[] = $col; $params[] = $nrc_pass; $types .= 's'; }

    // DOB
    $col = $pickColumn(['DOB', 'dob', 'date_of_birth']); if ($col) { $columns[] = $col; $params[] = $dob; $types .= 's'; }

    // Phone
    $col = $pickColumn(['mobile', 'phone']); if ($col) { $columns[] = $col; $params[] = $mobile; $types .= 's'; }

    // Email
    $col = $pickColumn(['email']); if ($col) { $columns[] = $col; $params[] = $email; $types .= 's'; }

    // Status
    $col = $pickColumn(['status']); if ($col) { $columns[] = $col; $params[] = $status; $types .= 's'; }

    // Addresses
    $col = $pickColumn(['h_addre', 'home_address', 'address']); if ($col) { $columns[] = $col; $params[] = $h_addre; $types .= 's'; }
    $col = $pickColumn(['p_addre', 'postal_address']); if ($col) { $columns[] = $col; $params[] = $p_addre; $types .= 's'; }

    // Sponsor
    $col = $pickColumn(['sponsor']); if ($col) { $columns[] = $col; $params[] = $sponsor; $types .= 's'; }

    // Next of kin + relationship
    $col = $pickColumn(['next_kin', 'next_of_kin']); if ($col) { $columns[] = $col; $params[] = $next_kin; $types .= 's'; }
    $col = $pickColumn(['relat', 'relationship']); if ($col) { $columns[] = $col; $params[] = $relat; $types .= 's'; }
    $col = $pickColumn(['next_kin_mobile', 'next_of_kin_mobile']); if ($col) { $columns[] = $col; $params[] = $next_kin_mobile; $types .= 's'; }

    // School and completion year / legacy placeholders
    $col = $pickColumn(['school', 'secondary_school', 'sec_school']); if ($col) { $columns[] = $col; $params[] = $school; $types .= 's'; }
    $compCol = $pickColumn(['completion_year']);
    if ($compCol) {
        $columns[] = $compCol; $params[] = $completion_year; $types .= 's';
    } else {
        // Legacy: grade, dte1, dte2
        $gradeCol = $pickColumn(['grade']); if ($gradeCol) { $columns[] = $gradeCol; $params[] = ''; $types .= 's'; }
        $dte1Col = $pickColumn(['dte1']); if ($dte1Col) { $columns[] = $dte1Col; $params[] = ''; $types .= 's'; }
        $dte2Col = $pickColumn(['dte2']); if ($dte2Col) { $columns[] = $dte2Col; $params[] = ''; $types .= 's'; }
    }

    // Semester if exists on students table
    $col = $pickColumn(['semester']); if ($col) { $columns[] = $col; $params[] = $semester; $types .= 's'; }

    // Files
    $col = $pickColumn(['profile_image', 'profile_pic_path', 'profile_picture', 'profile_pic']); if ($col) { $columns[] = $col; $params[] = $uploaded_profile; $types .= 's'; }
    $col = $pickColumn(['results', 'results_scan_path', 'academic_documents']); if ($col) { $columns[] = $col; $params[] = $uploaded_results; $types .= 's'; }
    $col = $pickColumn(['nrc_file', 'nrc_scan_path', 'nrc_scan', 'id_document']); if ($col) { $columns[] = $col; $params[] = $uploaded_nrc; $types .= 's'; }

    // Admission/registration timestamp
    $now = date('Y-m-d H:i:s');
    $col = $pickColumn(['dte_adm', 'registration_date', 'created_at']); if ($col) { $columns[] = $col; $params[] = $now; $types .= 's'; }

    // Build and execute insert
    if (empty($columns)) {
        $_SESSION['errorMessage'] = 'Could not register student: no compatible columns found.';
        header('Location:regOldStud.php');
        exit();
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO students (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        echo "<script>alert('Student registered successfully. Proceed to admit.')</script>";
        echo "<script>window.open('admitStudent.php','_self')</script>";
    } else {
        $_SESSION['errorMessage'] = "Could not register student.";
        header('Location:regOldStud.php');
    }
}
?>