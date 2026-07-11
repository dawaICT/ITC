<?php
// ajax/add_program.php
// This script handles the "Quick Add" functionality from the departments modal.
header('Content-Type: application/json');
session_start();
// Adjust path to admin.php since we are in ajax/ directory
include "../includes/admin.php"; 

// 1. Security & CSRF Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Security token expired. Please reload.']);
    exit;
}

// 2. Data Collection
$deptId      = $_POST['department_id'] ?? '';
$progCode    = strtoupper(trim($_POST['program_code'] ?? ''));
$progName    = trim($_POST['program_name'] ?? '');
$progType    = $_POST['program_type'] ?? ''; // degree, diploma, certificate
$studyMode   = trim($_POST['study_mode'] ?? 'Full Time');   // Full Time / Part Time (attendance)
if ($studyMode === '') {
    $studyMode = 'Full Time';
}
// Registration period model drives Registration & Enrolment (semester vs term).
$academic_structure = trim($_POST['academic_structure'] ?? 'certificate_term');
$examination_type = trim($_POST['examination_type'] ?? 'external');

$uses_terms = 0;
$uses_semesters = 0;
$is_short_course = 0;
$is_transport_exception = 0;
$periodMode = 'semester';

if ($academic_structure === 'short_course') {
    $is_short_course = 1;
    $periodMode = 'short_course';
} elseif ($academic_structure === 'certificate_term' || $academic_structure === 'diploma_term') {
    $uses_terms = 1;
    $periodMode = 'term';
} elseif ($academic_structure === 'semester_exception') {
    $uses_semesters = 1;
    $is_transport_exception = 1;
    $periodMode = 'semester';
}

$duration_value = !empty($_POST['duration_value']) ? (int)$_POST['duration_value'] : null;
$duration_unit = !empty($_POST['duration_unit']) ? trim($_POST['duration_unit']) : null;

$durationRaw = trim((string)($_POST['program_duration'] ?? ''));
$defaultDuration = [
    'degree' => 4.0,
    'diploma' => 2.0,
    'certificate' => 1.0,
][strtolower(trim($progType))] ?? 1.0;
$duration = $durationRaw === '' ? $defaultDuration : filter_var($durationRaw, FILTER_VALIDATE_FLOAT);
$description = trim($_POST['program_description'] ?? '');
$isActive = isset($_POST['is_active']) ? 1 : 0;

// 3. Validation
if (empty($deptId) || empty($progCode) || empty($progName)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}
if ($duration === false || $duration < 0.25 || $duration > 10) {
    echo json_encode(['success' => false, 'message' => 'Program duration must be between 0.25 and 10 years.']);
    exit;
}
$duration = round((float)$duration, 2);

try {
    // 4. Check if Program Code already exists
    $check = $db->prepare("SELECT program_code FROM programs WHERE program_code = ?");
    $check->bind_param("s", $progCode);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'This Program Code is already in use.']);
        exit;
    }

    // 5. Insert Program with new academic structure columns
    $sql = "INSERT INTO programs (
                program_code, program_name, department_id, program_type,
                study_mode, period_mode, program_duration, is_active,
                academic_structure, duration_value, duration_unit,
                uses_terms, uses_semesters, is_short_course,
                is_transport_exception, examination_type
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ssssssdissiiiiis",
        $progCode, $progName, $deptId, $progType,
        $studyMode, $periodMode, $duration, $isActive,
        $academic_structure, $duration_value, $duration_unit,
        $uses_terms, $uses_semesters, $is_short_course,
        $is_transport_exception, $examination_type
    );

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Program added successfully to the department!',
            'status' => 'success' // Matches your frontend check
        ]);
    } else {
        throw new Exception($stmt->error);
    }

} catch (Exception $e) {
    error_log("Program Insert Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to add program. Please try again.']);
}
