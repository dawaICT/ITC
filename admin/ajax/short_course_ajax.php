<?php
/**
 * Short Course AJAX Handler — Admin module.
 * Thin wrapper: performs admin auth + CSRF, then delegates to the shared
 * enrollment action handler (includes/short_course_actions.php).
 */
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/short_course_actions.php';
require_once dirname(__DIR__, 2) . '/includes/itc_course_helpers.php';

header('Content-Type: application/json');

// admin.php treats user_id as the canonical session key; staff_id is not set on every login path.
$sessionStaffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? null;
if ($sessionStaffId === null) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$staffId = (string)$sessionStaffId;

// Read-only classification suggestion (§5/§8) for the catalogue "Suggest" buttons.
if ($action === 'classify') {
    $name  = trim((string)($_GET['name'] ?? $_POST['name'] ?? ''));
    $val   = max(0, (int)($_GET['duration_value'] ?? $_POST['duration_value'] ?? 0));
    $unit  = (string)($_GET['duration_unit'] ?? $_POST['duration_unit'] ?? 'days');
    $catId = (int)($_GET['category_id'] ?? $_POST['category_id'] ?? 0);

    $catCode = null;
    $cats = itc_categories($db);
    if ($catId > 0 && isset($cats[$catId])) {
        $catCode = $cats[$catId]['category_code'];
    }

    $days       = itc_duration_to_days($val, $unit);
    if ($days !== null && !sc_is_short_course_days($days)) {
        echo json_encode([
            'success' => false,
            'message' => 'Durations longer than six months must be managed as programmes, not short courses.',
            'standard_duration_days' => $days,
            'is_short_course_duration' => false,
        ]);
        exit;
    }
    $levelCode  = itc_classify_level($name, $days, $catCode);
    $intakeCode = itc_suggest_intake_type($levelCode, $val, $unit, $days);
    $levelId    = itc_level_id_by_code($db, $levelCode);
    $intakeId   = itc_intake_type_id_by_code($db, $intakeCode);
    $levels     = itc_levels($db);
    $intakes    = itc_intake_types($db);

    echo json_encode([
        'success'                => true,
        'standard_duration_days' => $days,
        'level_id'               => $levelId,
        'level_code'             => $levelCode,
        'level_name'             => ($levelId && isset($levels[$levelId])) ? $levels[$levelId]['level_name'] : $levelCode,
        'intake_type_id'         => $intakeId,
        'intake_type_code'       => $intakeCode,
        'intake_type_name'       => ($intakeId && isset($intakes[$intakeId])) ? $intakes[$intakeId]['type_name'] : $intakeCode,
    ]);
    exit;
}

// CSRF protection for all state-changing (POST) actions. GET actions are read-only.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['sc_admin_csrf']) || !is_string($token) || !hash_equals($_SESSION['sc_admin_csrf'], $token)) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh the page.']);
        exit;
    }
}

sc_run_enrollment_action($db, $action, $staffId);
