<?php
// TOP OF get_required_courses.php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/StudentRegistrationSystem.php';

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Keep the student session alive during AJAX operations
$_SESSION['last_activity'] = time();

try {
    // Log request details
    error_log("get_required_courses.php - Request received: " . json_encode($_POST));

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Invalid request method']);
        exit;
    }

    // Validate required parameters
    if ((!isset($_POST['year_of_study']) && !isset($_POST['academic_year'])) || !isset($_POST['semester'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
        exit;
    }

    $yearOfStudy = (int)($_POST['year_of_study'] ?? 1);
    $semester = (int)$_POST['semester'];
    $isTransfer = isset($_POST['is_transfer']) ? (bool)$_POST['is_transfer'] : false;

    // Log processed parameters
    error_log("get_required_courses.php - Parameters: year=$yearOfStudy, semester=$semester, isTransfer=$isTransfer");

    // Initialize registration system
    $db = new Database();
    $registrationSystem = new StudentRegistrationSystem($db);

    // Get program code when available for better filtering
    $programCode = $_POST['program_code'] ?? null;
    $studentId = $_POST['student_id'] ?? ($_SESSION['Sid'] ?? null);
    
    if (!$programCode && $studentId) {
        try {
            $pdo = $db->getConnection();
            $st = $pdo->prepare("SELECT program_code FROM student_program WHERE Sid = ? ORDER BY id DESC LIMIT 1");
            $st->execute([ (string)$studentId ]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['program_code'])) { $programCode = $row['program_code']; }
        } catch (Throwable $e) { /* ignore */ }
    }

    // Get required courses
    error_log("get_required_courses.php - Calling getRequiredCourses with: year=$yearOfStudy, sem=$semester, isTransfer=$isTransfer, program=$programCode");
    $courses = $registrationSystem->getRequiredCourses($yearOfStudy, $semester, $isTransfer, $programCode);

    // Log response
    error_log("get_required_courses.php - Found " . count($courses) . " courses");
    if (!empty($courses)) {
        error_log("get_required_courses.php - Sample course: " . json_encode($courses[0]));
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'courses' => $courses
    ]);

} catch (Exception $e) {
    // Log error
    error_log("get_required_courses.php - Error: " . $e->getMessage());

    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 