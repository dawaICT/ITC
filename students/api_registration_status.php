<?php
/**
 * API: Get Registration Status
 * 
 * Returns the complete registration status for the current logged-in student.
 * Used by both registration.php and courseReg.php for consistent data.
 * 
 * Response JSON:
 * {
 *   "success": true,
 *   "data": {
 *     "student_id": "...",
 *     "has_semester_registration": true/false,
 *     "has_course_registration": true/false,
 *     "current_term": { "year_of_study": 1, "semester": 1, "program_code": "..." },
 *     "payment_status": { "percent_paid": 75.5, "can_receive_ca": true, ... },
 *     "registered_courses": [...],
 *     "available_courses": [...],
 *     "can_register_courses": true/false,
 *     "can_receive_ca": true/false
 *   }
 * }
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';
require_once __DIR__ . '/includes/FeeGuard.php';

try {
    if (!isset($db) || !($db instanceof mysqli)) {
        throw new Exception('Database unavailable', 500);
    }
    
    $studentId = wuc_api_require_student();
    $service = new RegistrationDataService($db);
    
    // Get complete registration status
    $status = $service->getRegistrationStatus($studentId);
    
    // Add available courses if we have a current term
    if ($status['has_semester_registration'] && $status['current_term']) {
        $status['available_courses'] = $service->getAvailableCourses(
            $status['current_term']['program_code'],
            $status['current_term']['year_of_study'],
            $status['current_term']['semester']
        );
    } else {
        $status['available_courses'] = [];
    }
    
    wuc_json_response([
        'success' => true,
        'data' => $status
    ]);
    
} catch (Throwable $e) {
    error_log('Registration status API failed: ' . $e->getMessage());
    $code = $e->getCode();
    $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
    wuc_json_error('Unable to load registration status.', $statusCode);
}
