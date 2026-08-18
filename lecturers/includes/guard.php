<?php
/**
 * Lecturer Module — Centralized Bootstrap & Auth Guard
 * =====================================================
 * This is the SINGLE entry point for all lecturer pages.
 * It handles: session init, DB connection, error handling,
 * idle timeout, login enforcement, and helper loading.
 *
 * EVERY lecturer page MUST include this file FIRST:
 *   require_once __DIR__ . '/includes/guard.php';
 *
 * After this file runs, the following are guaranteed:
 *   - $db        : mysqli connection (or graceful error page)
 *   - $_SESSION   : active session with staff_id
 *   - Helper functions loaded (schema detection, finance guard)
 */

// ─── 1. Session ────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/session_guard.php';
wuc_enforce_session_guard([
    'context' => 'lecturer',
    'session_keys' => ['staff_id', 'user_id'],
    'activity_keys' => ['last_activity', 'last_active_time'],
    'timeout' => 1800,
    'post_grace' => 30,
    'login_path' => '/wucportal/staff_login.php',
    'flash_key' => 'errorMessage',
    'timeout_message' => 'Your session has expired. Please log in again.',
    'login_message' => 'Please log in to access the Lecturer Portal.',
]);

require_once dirname(__DIR__, 2) . '/includes/role_helpers.php';
$lecturerGuardEarlyUri = str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? ''));
$lecturerGuardPortalHint = strtolower(trim((string)($_GET['portal'] ?? '')));
$lecturerGuardLooksElearning = strpos($lecturerGuardEarlyUri, '/elearning/') !== false
    || $lecturerGuardPortalHint === 'elearning'
    || strpos($lecturerGuardEarlyUri, '/lecturers/materials.php') !== false
    || strpos($lecturerGuardEarlyUri, '/lecturers/course_resources.php') !== false
    || strpos($lecturerGuardEarlyUri, '/lecturers/post_assign.php') !== false
    || strpos($lecturerGuardEarlyUri, '/lecturers/ai_question_bank.php') !== false
    || strpos($lecturerGuardEarlyUri, '/lecturers/assessments.php') !== false
    || strpos($lecturerGuardEarlyUri, '/lecturers/archive_submissions_drive.php') !== false
    || strpos($lecturerGuardEarlyUri, '/lecturers/grade_assignment.php') !== false;
if (!$lecturerGuardLooksElearning && !hasRole(ROLE_LECTURER) && !isSystemsAdmin()) {
    $_SESSION['errorMessage'] = 'Access denied. You do not have permission to access the Lecturer Portal.';
    header("Location: /wucportal/portal_selection.php");
    exit;
}

// ─── 2. Database Connection ────────────────────────────────────────
// connect.php includes error_bootstrap.php which sets up error handling
try {
    require_once dirname(__DIR__, 2) . '/db/connect.php';
} catch (Exception $e) {
    // If DB fails, show a graceful error instead of crashing
    wuc_show_error_page(
        'Database Unavailable',
        'The system could not connect to the database. Please try again later or contact the administrator.',
        $e->getMessage()
    );
}

// Verify $db is valid
if (!isset($db) || !($db instanceof mysqli) || $db->connect_error) {
    wuc_show_error_page(
        'Database Unavailable',
        'The database connection is not available. Please contact the system administrator.'
    );
}

// ─── 3. Safe Redirect Helper ───────────────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/portal_access.php';
require_once dirname(__DIR__, 2) . '/includes/portal_context.php';
$lecturerGuardUri = str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? ''));
$lecturerGuardContext = wuc_portal_context_from_request($lecturerGuardUri);
$lecturerGuardPortal = wuc_portal_code_from_context($lecturerGuardContext);
wuc_require_portal_access($db, $lecturerGuardPortal);
wuc_set_portal_context($lecturerGuardContext);

if (!function_exists('wuc_safe_redirect')) {
    /**
     * Redirect that works even if headers were already sent (BOM, whitespace, etc.)
     */
    function wuc_safe_redirect(string $url): void {
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit();
        }
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit();
    }
}

// ─── 4. Graceful Error Page Helper ─────────────────────────────────
if (!function_exists('wuc_show_error_page')) {
    /**
     * Display a user-friendly error page and stop execution.
     * This prevents blank white pages or raw PHP errors for end users.
     */
    function wuc_show_error_page(string $title, string $message, string $technical = ''): void {
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>System Error - ITC Portal</title>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">';
        echo '<style>
            body { font-family: "Inter", sans-serif; background: #f8f9fa; display: flex; 
                   justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
            .error-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); 
                          padding: 40px; max-width: 500px; text-align: center; }
            .error-icon { font-size: 48px; color: #dc3545; margin-bottom: 16px; }
            .error-title { font-size: 20px; font-weight: 600; color: #1a1a2e; margin-bottom: 8px; }
            .error-message { color: #6c757d; line-height: 1.6; margin-bottom: 24px; }
            .error-technical { background: #f8d7da; color: #842029; padding: 10px 14px; 
                               border-radius: 6px; font-size: 12px; text-align: left; 
                               margin-top: 16px; word-break: break-word; }
            .btn-back { display: inline-block; padding: 10px 24px; background: #003366; color: #fff; 
                        border-radius: 8px; text-decoration: none; font-weight: 500; }
            .btn-back:hover { background: #004488; }
        </style></head><body>';
        echo '<div class="error-card">';
        echo '<div class="error-icon">⚠️</div>';
        echo '<div class="error-title">' . htmlspecialchars($title) . '</div>';
        echo '<div class="error-message">' . htmlspecialchars($message) . '</div>';
        echo '<a href="/wucportal/lecturers/index.php" class="btn-back">← Back to Dashboard</a>';
        if ($technical !== '' && defined('WUC_DEBUG') && WUC_DEBUG) {
            echo '<div class="error-technical"><strong>Debug:</strong> ' . htmlspecialchars($technical) . '</div>';
        }
        echo '</div></body></html>';
        exit();
    }
}

// ─── 5. Session Flash Message Helper ───────────────────────────────
if (!function_exists('wuc_flash')) {
    /**
     * Set a flash message that persists for one page load.
     * Usage: wuc_flash('success', 'Record saved!');
     * In view: <?php if ($flash = wuc_get_flash()): ?>...<?php endif; ?>
     */
    function wuc_flash(string $type, string $message): void {
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('wuc_get_flash')) {
    /**
     * Retrieve and clear flash message. Returns null if none.
     */
    function wuc_get_flash(): ?array {
        if (isset($_SESSION['_flash'])) {
            $flash = $_SESSION['_flash'];
            unset($_SESSION['_flash']);
            return $flash;
        }
        return null;
    }
}

// ─── 6. Idle Timeout (5 minutes) ───────────────────────────────────
// ─── 7. Login Enforcement ──────────────────────────────────────────
// ─── 8. CSRF Token Generation ──────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

// ─── 9. CSRF Validation Helper ─────────────────────────────────────
if (!function_exists('wuc_verify_csrf')) {
    /**
     * Validate CSRF token for POST requests.
     * Call at the top of any form handler: wuc_verify_csrf();
     */
    function wuc_verify_csrf(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            wuc_show_error_page(
                'Invalid Request',
                'Your request could not be verified. Please go back and try again.',
                'CSRF token mismatch'
            );
        }
    }
}

// ─── 10. Input Sanitisation Helpers ────────────────────────────────
if (!function_exists('wuc_input')) {
    /**
     * Safely get a trimmed POST/GET value. Returns $default if not set.
     */
    function wuc_input(string $key, string $default = '', string $method = 'POST'): string {
        $source = ($method === 'GET') ? $_GET : $_POST;
        return isset($source[$key]) ? trim($source[$key]) : $default;
    }
}

// ─── 11. Lecturer Course Assignment Guard ─────────────────────────
if (!function_exists('wuc_lecturer_enforce_course_assignment')) {
    function wuc_lecturer_enforce_course_assignment(mysqli $db, string $courseCode): void {
        $staffId = $_SESSION['staff_id'] ?? '';
        if ($staffId === '') {
            wuc_safe_redirect('/wucportal/staff_login.php');
        }
        // Systems Admin is exempted from this check
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'systems_admin') {
            return;
        }
        require_once dirname(__DIR__, 2) . '/includes/elearning_access.php';
        if (!isLecturerAssignedToCourse($db, $staffId, $courseCode)) {
            wuc_show_error_page(
                'Access Denied',
                'You are not assigned to this course. Please contact the administrator if you believe this is an error.'
            );
        }
    }
}

// ─── Bootstrap complete ────────────────────────────────────────────
// At this point: $db, $_SESSION['staff_id'], helpers are all ready.
