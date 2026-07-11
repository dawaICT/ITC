<?php
/**
 * Session Handler for WUC Portal Admissions Module
 * Handles session initialization, validation, and security
 */
require_once dirname(__DIR__, 2) . '/includes/session_guard.php';

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

/**
 * Initialize session with security best practices
 */
function initializeSession() {
    // Prevent double session start
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', 1800);
        wuc_guard_start_session('admissions');
        
        // Validate session fingerprint (prevent fixation)
        validateSessionFingerprint();
        
        return true;
    }
    return false;
}

/**
 * Validate session fingerprint to prevent session fixation attacks
 */
function validateSessionFingerprint() {
    $fingerprint = hash('sha256', $_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR']);
    
    if (isset($_SESSION['fingerprint'])) {
        if ($_SESSION['fingerprint'] !== $fingerprint) {
            // Session fingerprint mismatch - possible session fixation attack
            session_destroy();
            session_start();
            return false;
        }
    } else {
        // First visit - set fingerprint
        $_SESSION['fingerprint'] = $fingerprint;
    }
    
    return true;
}

/**
 * Regenerate session ID for security
 */
function regenerateSessionId() {
    session_regenerate_id(true);
}

/**
 * Check if user is authenticated as admin
 */
function isAdminAuthenticated() {
    // Check for Admissions specific login (index=admin) OR Global Staff login (staff_id)
    if (isset($_SESSION['index']) && $_SESSION['index'] === 'admin') return true;
    if (isset($_SESSION['staff_id'])) return true;
    return false;
}

/**
 * Check if user is authenticated as student
 */
function isStudentAuthenticated() {
    // Check for new Student login (index1=student) OR Global Student login (student_id)
    if (isset($_SESSION['index1']) && $_SESSION['index1'] === 'student') return true;
    if (isset($_SESSION['student_id'])) return true;
    return false;
}

/**
 * Check if user is authenticated (either admin or student)
 */
function isUserAuthenticated() {
    return isAdminAuthenticated() || isStudentAuthenticated();
}

/**
 * Get current authenticated user
 */
function getAuthenticatedUser() {
    if (isUserAuthenticated()) {
        return [
            'username' => $_SESSION['user_name'],
            'role' => isAdminAuthenticated() ? 'admin' : 'student',
            'is_admin' => isAdminAuthenticated(),
            'is_student' => isStudentAuthenticated()
        ];
    }
    return null;
}

/**
 * Redirect to login if not authenticated
 */
function requireAdminAuth() {
    initializeSession();
    if (!isAdminAuthenticated()) {
        header('Location: /wucportal/staff_login.php');
        exit;
    }
}

/**
 * Redirect to student dashboard if not authenticated as student
 */
function requireStudentAuth() {
    initializeSession();
    if (!isStudentAuthenticated()) {
        header('Location: /wucportal/index.php');
        exit;
    }
}

/**
 * Set flash message (one-time use message)
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash_' . $type] = $message;
}

/**
 * Get flash message and clear it
 */
function getFlashMessage($type) {
    $message = isset($_SESSION['flash_' . $type]) ? $_SESSION['flash_' . $type] : null;
    if ($message) {
        unset($_SESSION['flash_' . $type]);
    }
    return $message;
}

/**
 * Get all flash messages
 */
function getAllFlashMessages() {
    $messages = [];
    foreach (['error', 'success', 'warning', 'info'] as $type) {
        $msg = getFlashMessage($type);
        if ($msg) {
            $messages[$type] = $msg;
        }
    }
    return $messages;
}

/**
 * Destroy session and logout user
 */
function logoutUser() {
    wuc_guard_clear_session();
    return true;
}

/**
 * Log activity for audit trail
 */
function logActivity($user_name, $action, $details = '') {
    $WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
    
    if ($WUC_ENV === 'development') {
        $log_message = date('Y-m-d H:i:s') . " | User: $user_name | Action: $action | Details: $details\n";
        error_log($log_message, 3, dirname(__DIR__) . '/logs/activity.log');
    }
}

/**
 * Set session timeout check
 */
function checkSessionTimeout($timeout_minutes = 30) {
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];
        if ($elapsed > ($timeout_minutes * 60)) {
            logoutUser();
            return false;
        }
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Sanitize user input from session
 */
function getSafeSessionValue($key, $default = null) {
    if (isset($_SESSION[$key])) {
        return htmlspecialchars($_SESSION[$key], ENT_QUOTES, 'UTF-8');
    }
    return $default;
}

// Initialize session on include
initializeSession();
?>
