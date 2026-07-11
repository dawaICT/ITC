<?php
/**
 * get_short_courses.php
 *
 * Returns short courses with their current enrollment counts, for the
 * short-course branch of admin/print_registers.php.
 *
 * Method: GET
 * Params: search (optional) filters by course_code or course_name
 * Response: JSON array of { id, course_code, course_name, status, enrolled }
 */

require_once __DIR__ . "/../includes/admin.php";
require_once __DIR__ . "/../includes/register_types.php";
header('Content-Type: application/json');

try {
    $search = trim($_GET['search'] ?? '');
    echo json_encode(wuc_register_short_courses($db, $search));
} catch (Throwable $e) {
    error_log('get_short_courses error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load short courses. Please try again.']);
}
