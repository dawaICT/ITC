<?php
/**
 * UNIFIED LOGOUT HANDLER
 * 
 * Handles logout for ALL user types (students + staff).
 * Supports both GET (redirect from legacy links / session expiry) and POST (sidebar forms).
 * 
 * GET requests: Perform logout immediately and redirect to the appropriate login page.
 * POST requests: Validate CSRF token, then perform logout and redirect.
 * 
 * Query/POST Parameters:
 *   - to|target: 'student' | 'staff' | 'default' (determines redirect destination)
 *   - csrf_token: CSRF token (required for POST, skipped for GET)
 */

require_once __DIR__ . '/includes/security.php';

// ===== STEP 1: SECURITY HEADERS (MUST BE FIRST) =====
wuc_apply_security_headers(true);

require_once __DIR__ . '/config/auth_constants.php';

// ===== STEP 2: SESSION INIT =====
if (session_status() === PHP_SESSION_NONE) {
    wuc_configure_session_cookie();
    session_start();
}

// ===== STEP 3: DETERMINE TARGET =====
// Accept target from POST (form submission) or GET (redirect from old logout scripts / guards)
$target_map = [
    'staff'   => APP_BASE_PATH . '/staff_login.php',
    'student' => APP_BASE_PATH . '/student_login.php',
    'admin'   => APP_BASE_PATH . '/staff_login.php',  // admin uses same staff login
    'default' => APP_BASE_PATH . '/index.php'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // For POST requests, validate CSRF token
    $valid_token = $_SESSION['csrf_token'] ?? '';
    $submitted_token = $_POST['csrf_token'] ?? '';
    
    if (!empty($valid_token) && !empty($submitted_token) && !hash_equals($valid_token, $submitted_token)) {
        // CSRF mismatch — still log out (session is compromised anyway) but log the event
        error_log("LOGOUT: CSRF validation failed from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
    
    $requested_target = $_POST['target'] ?? ($_POST['to'] ?? 'default');
} else {
    // GET request — from old logout redirects, guards, or direct URL
    $requested_target = $_GET['to'] ?? ($_GET['target'] ?? 'default');
}

$redirect_to = $target_map[$requested_target] ?? $target_map['default'];

// ===== STEP 4: COMPLETE SESSION DESTRUCTION =====
unset($_SESSION['nav_flags'], $_SESSION['nav_flags_at']);

// Clear all session data
$_SESSION = [];

// Delete the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy server-side session
session_destroy();

// ===== STEP 5: CLEAR PERSISTENT AUTH TOKENS =====
$auth_cookies = ['staff_remember', 'student_remember', 'remember_token', 'auth_token'];
foreach ($auth_cookies as $cookie) {
    if (isset($_COOKIE[$cookie])) {
        setcookie($cookie, '', time() - 3600, '/', '', true, true);
        setcookie($cookie, '', time() - 3600, '/');
    }
}

// ===== STEP 6: REDIRECT TO LOGIN PAGE =====
$status = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 303 : 302;
wuc_safe_redirect($redirect_to, $status, APP_BASE_PATH . '/index.php');

if (!headers_sent()) {
    // 303 See Other for POST→GET (prevents resubmission), 302 for GET→GET
    $status = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 303 : 302;
    header('Location: ' . $redirect_to, true, $status);
    exit;
}

// Fallback (rare: if headers already sent)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($redirect_to, ENT_QUOTES, 'UTF-8') ?>">
    <title>Logged Out</title>
    <script>window.location.href = "<?= htmlspecialchars($redirect_to, ENT_QUOTES, 'UTF-8') ?>";</script>
</head>
<body>
    <p>Logged out. <a href="<?= htmlspecialchars($redirect_to, ENT_QUOTES, 'UTF-8') ?>">Click here if not redirected.</a></p>
</body>
</html>
<?php exit; ?>
