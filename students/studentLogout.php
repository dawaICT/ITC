<?php
/**
 * STUDENT LOGOUT HANDLER
 * 
 * Backward-compatible redirect to central logout.php for student users.
 * Handles both manual logout and session expiry redirects.
 * 
 * Query Parameters:
 *   - expired: Set if session expired (passed through for logging)
 *   - return: Return URL after re-login (not currently used)
 */

// Redirect to central logout handler with student target
$target = '../logout.php?to=student';

// Pass through the expired flag if present
if (isset($_GET['expired']) && $_GET['expired'] == '1') {
    $target .= '&expired=1';
}

if (!headers_sent()) {
    header('Location: ' . $target);
    exit;
}
echo '<script>window.location.href=' . json_encode($target) . ';</script>';
echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"></noscript>';
exit;
?>