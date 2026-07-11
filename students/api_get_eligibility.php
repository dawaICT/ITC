<?php
// Simple API to check eligibility, with keepAlive support
error_reporting(0);
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/includes/EligibilityService.php';
$studentId = wuc_api_require_student();

// Handle session keep-alive requests
if (isset($_GET['keepAlive'])) {
    // Refresh the session activity timestamp
    $_SESSION['last_activity'] = time();
    
    // For pre-submission validation, do additional checks
    if (isset($_GET['pre_submit'])) {
        error_log('Pre-submission validation for SID=' . $_SESSION['Sid']);
    }
    
    wuc_json_response([
        'success' => true,
        'session_refreshed' => true,
        'sid' => $studentId,
        'time_remaining' => 1800
    ]);
}

// Get query parameters
$semester = isset($_GET['semester']) ? $_GET['semester'] : '';
$year = isset($_GET['Year']) ? $_GET['Year'] : '';

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

// Validate input
if (!$semester || !$year) {
    wuc_json_error('Missing required parameters: semester and Year.', 422);
}

try {
    // Compute failures and flags
    $failed = EligibilityService::getFailedCourses($db, $studentId);
    $flags = EligibilityService::computeFailureFlags(count($failed));
    
    // Get program code
    $program = '';
    $stmt = $db->prepare("SELECT program_code FROM student_program WHERE Sid = ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $stmt->bind_result($program);
        $stmt->fetch();
        $stmt->close();
    }
    
    // Filter failed courses available this semester
    $offeredFailed = EligibilityService::filterFailedCoursesOfferedThisTerm($db, $program, $year, $semester, $failed);
    
    // Get credit limits
    $maxCredits = EligibilityService::maxCreditsForYear((int)$year);
    
    // Return successful response
    $response['success'] = true;
    $response['data'] = [
        'flags' => $flags,
        'failed_courses' => $failed,
        'offered_failed' => $offeredFailed,
        'credits' => ['max_allowed' => $maxCredits]
    ];
    
} catch (Throwable $e) {
    error_log('Eligibility API failed: ' . $e->getMessage());
    wuc_json_error('Unable to calculate eligibility.', 500);
}

wuc_json_response($response);
