<?php
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
// System-authoritative Student ID generator (single source of truth).
require_once dirname(__DIR__) . '/includes/student_id_generator.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';
require_once dirname(__DIR__) . '/includes/cse_progression.php';
// Shared admissions helpers (portal login creation, schema-aware inserts).
require_once __DIR__ . '/includes/registration_handlers.php';

// Check session timeout
if (!checkSessionTimeout(30)) {
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: index.php');
    exit;
}

if (!isset($_POST['submit'])) {
  header('Location: processedApp.php');
  exit;
}

// CSRF validation
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
  $_SESSION['errorMessage'] = 'Invalid request.';
  header('Location: processedApp.php');
  exit;
}

// Helper: sanitize input
function in($key) {
  return isset($_POST[$key]) ? trim($_POST[$key]) : '';
}

$_SESSION['intake'] = in('intake');
$SID = in('SID');
$title = in('title');
$Fname = in('Fname');
$Lname = in('Lname');
$sex = in('gender'); // Updated field name
$nrc_pass = in('nrc_pass');
$country = in('country');
$dob = in('dob');
$mobile = in('mobile');
$email = in('email');
$status = 'active'; // Default status (lowercase, matching the students.status convention)
$h_addre = in('address'); // Updated field name
$p_addre = in('p_addre');
$sponsor = in('sponsor_type'); // Updated field name
$next_kin = in('next_kin_name'); // Updated field name
$next_kin_mobile = in('next_kin_phone'); // Updated field name
$relat = in('relationship'); // Updated field name
$school = in('school');
$grade = in('grade');
$dte1 = in('completion_year'); // Start year - using completion year for now
$dte2 = in('completion_year'); // Completion year
$english_grade = in('english_grade');
$math_grade = in('math_grade');
$bursary_percentage = in('bursary_percentage');
$program_code = in('program_code');
if ($stageError = wuc_cse_direct_assignment_error($program_code)) {
  $_SESSION['errorMessage'] = $stageError;
  header('Location: processedApp.php');
  exit;
}
$mode = in('mode');

// Basic required checks
$required = ['Fname','Lname','sex','nrc_pass','dob','mobile','email','school','grade','completion_year','program_code','intake'];
foreach ($required as $r) {
  if (empty(in($r))) {
    $_SESSION['errorMessage'] = 'Please complete all required fields.';
    header('Location: processedApp.php');
    exit;
  }
}

// File handling helper
function handle_upload($field, $dest_dir, &$error_msg) {
  if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
    $error_msg = 'Missing or invalid file upload for ' . $field;
    return false;
  }
  $file = $_FILES[$field];
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $allowed = ['jpg','jpeg','png','pdf'];
  if (!in_array($ext, $allowed, true)) {
    $error_msg = 'Invalid file format for ' . $field . '. Allowed: jpg, png, pdf';
    return false;
  }
  if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
    $error_msg = 'File too large for ' . $field;
    return false;
  }
  if (!is_dir($dest_dir)) {
    @mkdir($dest_dir, 0755, true);
  }
  $base = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($file['name']));
  $newName = time() . '_' . bin2hex(random_bytes(6)) . '_' . $base;
  $target = rtrim($dest_dir, '/') . '/' . $newName;
  if (!move_uploaded_file($file['tmp_name'], $target)) {
    $error_msg = 'Failed to move uploaded file for ' . $field;
    return false;
  }
  return $newName;
}

$err = '';
$profile_name = '';
if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
  $profile_name = handle_upload('profile_image', dirname(__DIR__) . '/uploads/profile', $err);
  if ($profile_name === false) {
    $_SESSION['invalidFormat'] = $err;
    header('Location: processedApp.php');
    exit;
  }
}

$certificate_name = handle_upload('certificate_file', dirname(__DIR__) . '/uploads', $err);
if ($certificate_name === false) {
  $_SESSION['invalidFormat'] = $err;
  header('Location: processedApp.php');
  exit;
}

$nrc_name = handle_upload('nrc_file', dirname(__DIR__) . '/uploads', $err);
if ($nrc_name === false) {
  $_SESSION['invalidFormat'] = $err;
  header('Location: processedApp.php');
  exit;
}

// Check duplicate identity (email, phone, NRC) before generating SID.
try {
  admissionsAssertIdentityIsUnique($db, $email, $mobile, $nrc_pass);
} catch (RuntimeException $e) {
  $_SESSION['errorMessage'] = 'Registration blocked: ' . $e->getMessage();
  header('Location: processedApp.php');
  exit;
}

// Generate the Student ID on the server — never trust any client-supplied SID.
$program_for_sid = $program_code !== '' ? $program_code : 'GENERAL';
$semester_for_sid = (date('m') >= 7) ? 2 : 1;
try {
  $newSID = generateStudentId($db, $program_for_sid, (string)$semester_for_sid, date('Y'), $nrc_pass);
} catch (Exception $e) {
  $_SESSION['errorMessage'] = 'Error generating Student ID: ' . $e->getMessage();
  header('Location: processedApp.php');
  exit;
}

// Check duplicate SID collision after generation (race safety).
$stmt = $db->prepare('SELECT SID FROM students WHERE SID = ? LIMIT 1');
if ($stmt) {
  $stmt->bind_param('s', $newSID);
  $stmt->execute();
  $stmt->store_result();
  if ($stmt->num_rows > 0) {
    $_SESSION['errorMessage'] = 'This student number already exists. Please try again.';
    header('Location: processedApp.php');
    exit;
  }
  $stmt->close();
} else {
  error_log('Prepare failed: ' . $db->error);
}

// All workflow rows (student, programme assignment, portal login, applicant
// tracking) are created atomically so a half-registered student can never exist.
$applicant_id = (int) in('applicant_id');
$academic_year = date('Y');
$startYear = (int) date('Y');
$endYear = $startYear + 4;
$intake = $_SESSION['intake'];

try {
  $db->begin_transaction();

  $insert_sql = 'INSERT INTO students (SID, title, Fname, Lname, sex, dob, country, nrc_pass, mobile, email, status, h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat, school, results, nrc_file, certificate_file, profile_image, program, intake, mode, academic_year, year, dte_adm, enrollment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())';
  $ins = $db->prepare($insert_sql);
  $ins->bind_param('ssssssssssssssssssssssssss', $newSID, $title, $Fname, $Lname, $sex, $dob, $country, $nrc_pass, $mobile, $email, $status, $h_addre, $p_addre, $sponsor, $next_kin, $next_kin_mobile, $relat, $school, $certificate_name, $nrc_name, $certificate_name, $profile_name, $program_code, $intake, $mode, $academic_year);
  $ins->execute();
  $ins->close();

  // Programme assignment — same stage as the regNewStud flow.
  $periodPayload = wuc_student_program_period_payload($db, $program_code, wuc_student_program_period_input($db, $program_code, $_POST, 1));
  if (!$periodPayload['ok']) {
    throw new RuntimeException($periodPayload['reason']);
  }
  $periodFields = $periodPayload['fields'];

  $insert_prog = $db->prepare("INSERT INTO student_program (Sid, program_code, intake, mode, term, semester, current_term_number, current_semester_number, current_level_number, startYear, endYear, status, academic_year) VALUES (?,?,?,?,?,?,?,?,?,?,?,'active',?)");
  $insert_prog->bind_param('sssssssssiis', $newSID, $program_code, $intake, $mode, $periodFields['term'], $periodFields['semester'], $periodFields['current_term_number'], $periodFields['current_semester_number'], $periodFields['current_level_number'], $startYear, $endYear, $academic_year);
  $insert_prog->execute();
  $insert_prog->close();

  // Portal access: create login credentials (NRC as initial password,
  // must_change_password forces the student to set their own on first login).
  admissionsEnsureStudentLogin($db, $newSID, $nrc_pass, $email);

  // Track the applicant → student conversion so processedApp.php marks the
  // applicant as "Already Added" and the same applicant can't be added twice.
  if ($applicant_id > 0 && $db->query("SHOW TABLES LIKE 'processed_applicants_added'")->num_rows > 0) {
    $added_by = (string) ($_SESSION['user_id'] ?? '');
    $track_ins = $db->prepare('INSERT INTO processed_applicants_added (applicant_id, student_id, added_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE student_id = VALUES(student_id)');
    $track_ins->bind_param('iss', $applicant_id, $newSID, $added_by);
    $track_ins->execute();
    $track_ins->close();
  }

  $db->commit();
} catch (Throwable $e) {
  $db->rollback();
  error_log('processForm registration failed: ' . $e->getMessage());
  $_SESSION['errorMessage'] = 'Could not register new student.';
  header('Location: processedApp.php');
  exit;
}

$_SESSION['successMessage'] = "New student was successfully registered from online application (ID: {$newSID}). Proceed to admit.";
header('Location: processedApp.php');
exit;

?>
