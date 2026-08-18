<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/student_year_progression.php';

try {
    hod_require_hos_api_access();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
        throw new RuntimeException('Invalid request. Please refresh and try again.');
    }

    $staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
    $deptContext = hod_resolve_department($db, $staffId);
    $programCodes = hod_section_program_codes($db, $deptContext);
    $sectionId = (string)($_SESSION['hos_section_id'] ?? $deptContext['section_id'] ?? '');
    $role = isSystemsAdmin() ? ROLE_SYSTEMS_ADMIN : ROLE_HEAD_OF_DEPARTMENT;

    $result = wuc_progress_student_year(
        $db,
        (string)($_POST['student_id'] ?? ''),
        $staffId,
        $role,
        (string)($_POST['decision_basis'] ?? ''),
        (string)($_POST['decision_reference'] ?? ''),
        (string)($_POST['decision_notes'] ?? ''),
        $programCodes,
        $sectionId !== '' ? $sectionId : null
    );
    $_SESSION['progression_flash'] = ['type' => $result['ok'] ? 'success' : 'warning', 'message' => $result['message']];
} catch (Throwable $e) {
    $_SESSION['progression_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
}

header('Location: progression.php', true, 303);
exit();

