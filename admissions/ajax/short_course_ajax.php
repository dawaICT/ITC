<?php
/**
 * Short Course AJAX Handler — Admissions module.
 * Thin wrapper: performs admissions auth + CSRF, then delegates to the shared
 * enrollment action handler (includes/short_course_actions.php).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/session_handler.php';
require_once dirname(__DIR__) . '/includes/short_course_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/short_course_actions.php';

if (!checkSessionTimeout(30) || !isAdminAuthenticated()) {
    sc_json(['success' => false, 'message' => 'Unauthorized'], 401);
}

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !sc_valid_csrf($_POST['csrf_token'] ?? null)) {
    sc_json(['success' => false, 'message' => 'Security token mismatch. Please refresh the page.'], 403);
}

sc_run_enrollment_action($db, $action, $staffId);
