<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

ob_start();
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
ob_end_clean();

header('Content-Type: application/json');

try {
    hod_require_hos_api_access();
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) { throw new Exception('Invalid or missing CSRF token', 403); }
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $a1 = isset($_POST['A1']) ? (float)$_POST['A1'] : 0.0;
    $a2 = isset($_POST['A2']) ? (float)$_POST['A2'] : 0.0;
    $t1 = isset($_POST['T1']) ? (float)$_POST['T1'] : 0.0;
    $t2 = isset($_POST['T2']) ? (float)$_POST['T2'] : 0.0;
    if ($id <= 0) { throw new Exception('Invalid ID', 400); }
    foreach ([['A1',$a1],['A2',$a2],['T1',$t1],['T2',$t2]] as $p) {
        if ($p[1] < 0 || $p[1] > 10) { throw new Exception($p[0].' out of range (0-10)', 422); }
    }
    $total = $a1 + $a2 + $t1 + $t2;
    if (!isset($db) || !$db instanceof mysqli) { throw new Exception('DB unavailable', 500); }

    // Fetch student and term info for fee check
    $studentInfo = null;
    $course = null;
    if ($st = $db->prepare("SELECT Sid, Year, semester, Course_Code FROM semester_assessment WHERE id = ? LIMIT 1")) {
        $st->bind_param('i', $id);
        if ($st->execute() && ($rs = $st->get_result()) && ($row = $rs->fetch_assoc())) { 
            $studentInfo = $row; 
            $course = $row['Course_Code'];
        }
        $st->close();
    }
    if (!$studentInfo) { throw new Exception('Record not found', 404); }
    if (!$course) { throw new Exception('Record not found', 404); }

    // Authorize FIRST — the section scope check must run before the fee check,
    // otherwise a cross-section HOS learns the student's payment status.
    $staffId = (string)$_SESSION['staff_id'];
    $deptContext = hod_resolve_department($db, $staffId);
    if (!empty($deptContext['section_type']) && (string)$deptContext['section_type'] !== 'academic') {
        throw new Exception('This section is not linked to academic continuous assessments', 403);
    }
    if (hod_section_department_ids($deptContext) === []) { throw new Exception('Cannot resolve department for this account', 403); }

    $allowed = in_array($course, hod_section_course_codes($db, $deptContext, $staffId), true);
    if (!$allowed) { throw new Exception('Forbidden', 403); }

    // Enforce 50% fee payment rule (only after the record is confirmed in scope)
    $elig = is_student_allowed_ca($db, $studentInfo['Sid'], $studentInfo['Year'], $studentInfo['semester']);
    if (!$elig['allowed']) {
        throw new Exception('Student not eligible for CA (paid '.round($elig['percent'],1).'% - minimum 50% required)', 403);
    }

    if ($st = $db->prepare("UPDATE semester_assessment SET A1=?, A2=?, T1=?, T2=?, Total_CA=? WHERE id=?")) {
        $st->bind_param('dddddi', $a1, $a2, $t1, $t2, $total, $id);
        $ok = $st->execute();
        $st->close();
        if (!$ok) { throw new Exception('Update failed', 500); }
    }

    echo json_encode(['success' => true, 'message' => 'Updated', 'total' => $total]);
} catch (Exception $e) {
    http_response_code(is_int($e->getCode()) && $e->getCode() >= 400 ? $e->getCode() : 400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

