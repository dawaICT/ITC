<?php
// Configure secure session cookies BEFORE session_start so initializeSession()'s
// hardening in session_handler.php is not skipped.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '1800');
    session_start();
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';

// Shared registration bootstrap: CSRF token + period-aware $programs_data, and
// pulls in the canonical registration_handlers used by the AJAX dispatcher.
require_once __DIR__ . '/includes/registration_bootstrap.php';

// Check session timeout
if (!checkSessionTimeout(30)) {
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: /wucportal/staff_login.php');
    exit;
}

// Check authentication
if (!isAdminAuthenticated()) {
    header('Location: /wucportal/staff_login.php');
    exit;
}

// Shared AJAX dispatch (register / bulk_register / get_recent). Exits on POST.
require __DIR__ . '/includes/registration_ajax.php';

$page_title = 'Student Registration';
require "includes/nav.php";

// Render the shared single + bulk + recent registration UI.
$reg_back_url = 'students.php';
require __DIR__ . '/includes/registration_panel.php';

require 'includes/footer.php';
