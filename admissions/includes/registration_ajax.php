<?php
/**
 * Shared new-student registration AJAX dispatcher.
 *
 * Included early (before any layout output) by BOTH admissions/regNewStud.php
 * and admin/regNewStud.php. Handles the single, bulk and recent-list actions via
 * the one canonical handler set so the two pages register students identically.
 *
 * Requires $db (mysqli) and $_SESSION['csrf_token'] to already be set
 * (registration_bootstrap.php does this).
 */

require_once __DIR__ . '/registration_handlers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Security token mismatch. Please refresh the page.',
        ]);
        exit;
    }

    try {
        switch ($_POST['action']) {
            case 'register':
                echo json_encode(handleNewStudentRegistration($db, $_POST, $_FILES));
                break;
            case 'get_recent':
                echo json_encode(handleGetRecentRegistrations($db));
                break;
            case 'bulk_register':
                echo json_encode(handleBulkStudentRegistration($db, $_POST, $_FILES));
                break;
            default:
                throw new Exception('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('Registration Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A system error occurred. Please try again.']);
    }
    exit;
}
