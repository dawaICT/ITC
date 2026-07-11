<?php
/**
 * Registration API Endpoints
 * RESTful API for handling registration operations
 * Provides JSON responses for AJAX requests from frontend
 */

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable for production
ini_set('log_errors', 1);

require_once __DIR__ . '/../includes/api_auth.php';
wuc_api_start_session('registration-api');

// Cross-origin policy for the public registration-status routes.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once __DIR__ . '/../includes/DatabaseConnection.php';
require_once __DIR__ . '/../includes/RegistrationService.php';

// Initialize service
$registrationService = new RegistrationService();

// CSRF protection
function validateCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!$token || !isset($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $token)) {
            sendError('Invalid CSRF token', 403);
        }
    }
}

// Authentication check
function requireAuth() {
    wuc_api_require_student();
}

// Send JSON response
function sendResponse($data, $statusCode = 200) {
    wuc_json_response((array) $data, (int) $statusCode);
}

// Send error response
function sendError($message, $statusCode = 400) {
    wuc_json_error((string) $message, (int) $statusCode);
}

// Get request input
function getInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}

// Parse path-info consistently whether Apache exposes the full script path or
// a rewritten route.
$pathParts = wuc_api_path_segments(
    (string) ($_SERVER['REQUEST_URI'] ?? ''),
    (string) ($_SERVER['SCRIPT_NAME'] ?? '/wucportal/api/registration.php')
);

$method = $_SERVER['REQUEST_METHOD'];
$action = $pathParts[0] ?? '';

try {
    // Route handling
    switch ($action) {
        
        // GET /api/registration/session - Get current academic session
        case 'session':
            if ($method === 'GET') {
                $session = $registrationService->getCurrentSession();
                if ($session) {
                    sendResponse(['success' => true, 'data' => $session]);
                } else {
                    sendError('No active session found', 404);
                }
            }
            break;
        
        // GET /api/registration/check-status - Check if registration is open
        case 'check-status':
            if ($method === 'GET') {
                $isOpen = $registrationService->isRegistrationOpen();
                sendResponse([
                    'success' => true,
                    'is_open' => $isOpen,
                    'session' => $registrationService->getCurrentSession()
                ]);
            }
            break;
        
        // POST /api/registration/create - Create new semester registration
        case 'create':
            if ($method === 'POST') {
                requireAuth();
                validateCSRF();
                
                $input = getInput();
                $data = array_merge($input, $_POST);
                
                // Never trust a client-supplied identity.
                $data['student_id'] = (string) $_SESSION['Sid'];
                
                $result = $registrationService->createSemesterRegistration($data);
                sendResponse($result, $result['success'] ? 201 : 400);
            }
            break;
        
        // POST /api/registration/{id}/courses - Register courses
        case 'courses':
            if ($method === 'POST') {
                requireAuth();
                validateCSRF();
                
                $registrationId = $pathParts[1] ?? null;
                if (!$registrationId) {
                    sendError('Registration ID required');
                }
                
                $input = getInput();
                $courses = $input['courses'] ?? $_POST['courses'] ?? [];
                
                if (!is_array($courses)) {
                    $courses = json_decode($courses, true) ?? [];
                }
                
                $result = $registrationService->registerCourses((int)$registrationId, $courses, (string)$_SESSION['Sid']);
                sendResponse($result, $result['success'] ? 200 : 400);
            }
            break;
        
        // PUT /api/registration/{id}/submit - Submit registration
        case 'submit':
            if ($method === 'POST' || $method === 'PUT') {
                requireAuth();
                validateCSRF();
                
                $registrationId = $pathParts[1] ?? $_POST['registration_id'] ?? null;
                if (!$registrationId) {
                    sendError('Registration ID required');
                }
                
                $result = $registrationService->submitRegistration((int)$registrationId, (string)$_SESSION['Sid']);
                sendResponse($result, $result['success'] ? 200 : 400);
            }
            break;
        
        // GET /api/registration/{id}/details - Get registration details
        case 'details':
            if ($method === 'GET') {
                requireAuth();
                
                $registrationId = $pathParts[1] ?? $_GET['id'] ?? null;
                if (!$registrationId) {
                    sendError('Registration ID required');
                }
                
                $details = $registrationService->getRegistrationDetails((int)$registrationId, (string)$_SESSION['Sid']);
                if ($details) {
                    sendResponse(['success' => true, 'data' => $details]);
                } else {
                    sendError('Registration not found', 404);
                }
            }
            break;
        
        // GET /api/registration/history - Get student's registration history
        case 'history':
            if ($method === 'GET') {
                requireAuth();
                
                $studentId = (string)$_SESSION['Sid'];
                $history = $registrationService->getStudentRegistrationHistory($studentId);
                sendResponse(['success' => true, 'data' => $history]);
            }
            break;
        
        // GET /api/registration/courses - Get available courses
        case 'available-courses':
            if ($method === 'GET') {
                requireAuth();
                
                $programCode = $_GET['program_code'] ?? null;
                $semester = $_GET['semester'] ?? null;
                
                if (!$programCode || !$semester) {
                    sendError('Program code and semester required');
                }
                
                $courses = $registrationService->getAvailableCourses($programCode, $semester);
                sendResponse(['success' => true, 'data' => $courses]);
            }
            break;
        
        // DELETE /api/registration/course/{id} - Drop a course
        case 'drop-course':
            if ($method === 'POST' || $method === 'DELETE') {
                requireAuth();
                validateCSRF();
                
                $courseRegId = $pathParts[1] ?? $_POST['course_registration_id'] ?? null;
                if (!$courseRegId) {
                    sendError('Course registration ID required');
                }
                
                $result = $registrationService->dropCourse((int)$courseRegId, (string)$_SESSION['Sid']);
                sendResponse($result, $result['success'] ? 200 : 400);
            }
            break;
        
        // GET /api/registration/csrf - Get CSRF token
        case 'csrf':
            if ($method === 'GET') {
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
                sendResponse(['csrf_token' => $_SESSION['csrf_token']]);
            }
            break;
        
        // Default - API info
        default:
            if (empty($action)) {
                sendResponse([
                    'name' => 'ITC Portal Registration API',
                    'version' => '1.0',
                    'endpoints' => [
                        'GET /session' => 'Get current academic session',
                        'GET /check-status' => 'Check if registration is open',
                        'POST /create' => 'Create new semester registration',
                        'POST /{id}/courses' => 'Register courses',
                        'PUT /{id}/submit' => 'Submit registration',
                        'GET /{id}/details' => 'Get registration details',
                        'GET /history' => 'Get registration history',
                        'GET /available-courses' => 'Get available courses',
                        'DELETE /course/{id}' => 'Drop a course',
                        'GET /csrf' => 'Get CSRF token'
                    ]
                ]);
            } else {
                sendError('Invalid endpoint', 404);
            }
    }
    
} catch (Throwable $e) {
    error_log('API Error: ' . $e->getMessage());
    sendError('Internal server error.', 500);
}
