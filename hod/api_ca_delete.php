<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';

header('Content-Type: application/json');

try {
    hod_require_hos_api_access();
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) { throw new Exception('Invalid or missing CSRF token', 403); }
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) { throw new Exception('Invalid ID', 400); }
    if (!isset($db) || !$db instanceof mysqli) { throw new Exception('DB unavailable', 500); }

    // Resolve section scope for HOS (all departments in the section)
    $staffId = (string)$_SESSION['staff_id'];
    $deptContext = hod_resolve_department($db, $staffId);
    if (!empty($deptContext['section_type']) && (string)$deptContext['section_type'] !== 'academic') {
        throw new Exception('This section is not linked to academic continuous assessments', 403);
    }
    if (hod_section_department_ids($deptContext) === []) { throw new Exception('Cannot resolve department for this account', 403); }

    // Check permission via course_lecturer
    $course = null;
    if ($st = $db->prepare("SELECT Course_Code FROM semester_assessment WHERE id = ? LIMIT 1")) {
        $st->bind_param('i', $id);
        if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) { $course = $row['Course_Code']; }
        $st->close();
    }
    if (!$course) { throw new Exception('Record not found', 404); }

    $allowed = in_array($course, hod_section_course_codes($db, $deptContext, $staffId), true);
    if (!$allowed) { throw new Exception('Forbidden', 403); }

    if ($st = $db->prepare("DELETE FROM semester_assessment WHERE id = ?")) {
        $st->bind_param('i', $id);
        $ok = $st->execute();
        $st->close();
        if (!$ok) { throw new Exception('Delete failed', 500); }
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(is_int($e->getCode()) && $e->getCode() >= 400 ? $e->getCode() : 400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

