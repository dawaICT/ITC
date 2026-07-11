<?php
// Enable detailed error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('error_log', __DIR__ . '/../logs/direct_course_submit_errors.log');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log the request details
error_log("Direct submit request: " . json_encode($_POST) . " - Session ID: " . session_id());

// Check if this is a direct submission
if (!isset($_POST['direct_submission']) || $_POST['direct_submission'] !== '1') {
    error_log("Not a direct submission");
    header('Location: courseReg.php');
    exit;
}

// Include guard to check authentication
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/payment_helpers.php';
require_once __DIR__ . '/../includes/helpers/academic_structure_helpers.php';

// Make sure we have required fields
if (!isset($_POST['Sid']) || !isset($_POST['semester']) || !isset($_POST['Year']) || !isset($_POST['course_code']) || !is_array($_POST['course_code'])) {
    $_SESSION['directSubmitError'] = 'Missing required fields for course registration.';
    error_log("Missing required fields: " . json_encode($_POST));
    header('Location: direct_course_submit.php');
    exit;
}

// Sanitize inputs
$sid = $_POST['Sid'];
$semester = (int)$_POST['semester'];
$year = (int)$_POST['Year'];
$selectedCourses = $_POST['course_code'];

// Make sure we have selected courses
if (empty($selectedCourses)) {
    $_SESSION['directSubmitError'] = 'Please select at least one course.';
    header('Location: direct_course_submit.php');
    exit;
}

$programCode = '';
$programStmt = $db->prepare(
    "SELECT sp.program_code
     FROM student_program sp
     JOIN programs p ON p.program_code = sp.program_code
     WHERE sp.Sid = ? AND COALESCE(p.is_active, 1) = 1
     LIMIT 1"
);
$programStmt->bind_param('s', $sid);
$programStmt->execute();
if ($programRow = $programStmt->get_result()->fetch_assoc()) {
    $programCode = (string)$programRow['program_code'];
}
$programStmt->close();

if ($programCode === '') {
    $_SESSION['directSubmitError'] = 'Active program not found for this student.';
    header('Location: direct_course_submit.php');
    exit;
}

$pcCols = wuc_course_availability_columns($db, 'program_courses');
$where = ['pc.program_code = ?', 'pc.year = ?', 'COALESCE(p.is_active, 1) = 1', "LOWER(COALESCE(c.status, 'active')) = 'active'"];
$types = 'si';
$params = [$programCode, $year];
$periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcCols['semester'] ?? null, $semester);
if ($periodFilter['sql'] !== '1=1') {
    $where[] = $periodFilter['sql'];
    $types .= $periodFilter['types'];
    $params = array_merge($params, $periodFilter['params']);
}

$allowedStmt = $db->prepare(
    "SELECT DISTINCT pc.course_code
     FROM program_courses pc
     JOIN programs p ON p.program_code = pc.program_code
     JOIN courses c ON c.course_code = pc.course_code
      WHERE " . implode(' AND ', $where)
);
$allowedStmt->bind_param($types, ...$params);
$allowedStmt->execute();
$allowedResult = $allowedStmt->get_result();
$allowedCourses = [];
while ($allowedRow = $allowedResult->fetch_assoc()) {
    $allowedCourses[] = strtoupper(trim((string)$allowedRow['course_code']));
}
$allowedStmt->close();

$requestedCourses = array_map(function ($code) {
    return strtoupper(trim((string)$code));
}, $selectedCourses);
$notAllowed = array_diff($requestedCourses, $allowedCourses);
if (!empty($notAllowed)) {
    $_SESSION['directSubmitError'] = 'Course(s) are not assigned to your active program: ' . implode(', ', $notAllowed);
    header('Location: direct_course_submit.php');
    exit;
}
$selectedCourses = $requestedCourses;

$registrationGuard = wuc_legacy_course_registration_guard($db, $sid, $programCode, $year, $semester, $selectedCourses);
if (!$registrationGuard['ok']) {
    $_SESSION['directSubmitError'] = $registrationGuard['reason'];
    header('Location: direct_course_submit.php');
    exit;
}

// Term-level duplicate guard: block when courses already exist for this period.
$termDupStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM course_registration WHERE Sid = ? AND semester = ? AND Year = ?');
if ($termDupStmt) {
    $semStr = (string)$semester;
    $yrStr = (string)$year;
    $termDupStmt->bind_param('sss', $sid, $semStr, $yrStr);
    $termDupStmt->execute();
    $termDupRow = $termDupStmt->get_result()->fetch_assoc();
    $termDupStmt->close();
    if ((int)($termDupRow['cnt'] ?? 0) > 0) {
        $_SESSION['directSubmitError'] = 'You have already registered courses for Year ' . $year . ', period ' . $semester . '. Contact the academic office to make changes.';
        header('Location: courseReg.php');
        exit;
    }
}

try {
    // Log that we're starting the registration process
    error_log("Starting registration process for SID: $sid, Semester: $semester, Year: $year");
    
    // Begin transaction
    $db->begin_transaction();
    
    // Insert into course_registration
    $insertCount = 0;
    foreach ($selectedCourses as $course) {
        $course = trim((string)$course);
        
        // Check if already registered
        $checkStmt = $db->prepare("SELECT 1 FROM course_registration WHERE Sid = ? AND course_code = ? AND semester = ? AND Year = ? LIMIT 1");
        if (!$checkStmt) {
            throw new Exception("Failed to prepare course registration check: " . $db->error);
        }
        $checkStmt->bind_param('ssii', $sid, $course, $semester, $year);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult && $checkResult->num_rows > 0) {
            $checkStmt->close();
            $sync = wuc_sync_legacy_course_registration_to_canonical($db, $sid, $programCode, $course, $year, $semester);
            if (!$sync['ok']) {
                throw new Exception("Failed to sync canonical registration for $course: " . $sync['reason']);
            }
            continue; // Skip if already registered
        }
        $checkStmt->close();
        
        // Insert the course registration
        $insertStmt = $db->prepare("INSERT INTO course_registration (Sid, course_code, semester, Year) VALUES (?, ?, ?, ?)");
        if (!$insertStmt) {
            throw new Exception("Failed to prepare course registration insert: " . $db->error);
        }
        $insertStmt->bind_param('ssii', $sid, $course, $semester, $year);
        if ($insertStmt->execute()) {
            $insertCount++;
            $sync = wuc_sync_legacy_course_registration_to_canonical($db, $sid, $programCode, $course, $year, $semester);
            if (!$sync['ok']) {
                throw new Exception("Failed to sync canonical registration for $course: " . $sync['reason']);
            }
        } else {
            throw new Exception("Failed to insert course $course: " . $insertStmt->error);
        }
        $insertStmt->close();
    }

    if ($insertCount === 0) {
        throw new Exception('No new courses were enrolled. The selected course(s) are already registered for this period.');
    }
    
    // Calculate fees
    $totalCredits = 0;
    $fees = 0;
    $placeholders = array_fill(0, count($selectedCourses), '?');
    $placeholdersStr = implode(',', $placeholders);
    
    $stmt = $db->prepare("SELECT course_code, credits, course_fee FROM courses WHERE course_code IN ($placeholdersStr) AND LOWER(COALESCE(status, 'active')) = 'active'");
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $db->error);
    }
    
    // Bind parameters for course codes
    $types = str_repeat('s', count($selectedCourses));
    $stmt->bind_param($types, ...$selectedCourses);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $credits = isset($row['credits']) ? intval($row['credits']) : 3; // Default to 3 if not set
        $totalCredits += $credits;
        $fees += isset($row['course_fee']) && (float)$row['course_fee'] > 0 ? (float)$row['course_fee'] : ($credits * 350);
    }
    $stmt->close();
    
    // Add registration fee
    $fees += 150; // Standard registration fee
    
    // Generate invoice number
    $invoiceNumber = 'CR' . date('Ymd') . rand(1000, 9999);
    
    // Insert invoice into the real invoices table (guarded by helper).
    $narration = "Course Registration Fee - $totalCredits credits";
    $createdInvoice = payment_create_student_invoice($db, $sid, (float)$fees, (string)$year, (string)$semester, $narration, $invoiceNumber);
    if (empty($createdInvoice['success']) && empty($createdInvoice['duplicate'])) {
        throw new Exception("Failed to create invoice: " . ($createdInvoice['message'] ?? 'unknown error'));
    }
    if (!empty($createdInvoice['invoice_number'])) {
        $invoiceNumber = (string)$createdInvoice['invoice_number'];
    }
    
    // Commit transaction
    $db->commit();
    
    // Store registration info in session
    $_SESSION['last_course_reg'] = [
        'Sid' => $sid,
        'semester' => $semester, 
        'Year' => $year, 
        'courses' => $selectedCourses,
        'credits' => $totalCredits,
        'fees' => $fees,
        'invoice' => $invoiceNumber,
        'ts' => time()
    ];
    
    // Flag to show invoice on fees page
    $_SESSION['show_course_invoice'] = true;
    
    // Log success
    error_log("Registration successful: $insertCount courses registered, invoice $invoiceNumber created");
    
    // Set success message and redirect to fees page
    $_SESSION['directSubmitSuccess'] = "Successfully registered $insertCount courses. Invoice #$invoiceNumber created.";
    header("Location: fees.php?invoice=$invoiceNumber");
    exit;
    
} catch (Exception $e) {
    // Roll back transaction if an error occurred
    $db->rollback();
    
    // Log the error
    error_log("Registration error: " . $e->getMessage());
    
    // Set error message and redirect back to form
    $_SESSION['directSubmitError'] = 'Registration failed: ' . $e->getMessage();
    header('Location: direct_course_submit.php');
    exit;
}
