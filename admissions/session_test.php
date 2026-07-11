<?php
/**
 * Session Validation and Testing Utility
 * Run this file to verify session configuration and security
 */

require_once __DIR__ . '/includes/session_handler.php';
require_once dirname(__DIR__) . '/db/connect.php';

// Only allow access if debug flag is set
if (!isset($_GET['debug']) || $_GET['debug'] !== 'true') {
    die('Access denied. Use ?debug=true to enable.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Management Test</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #6f42c1 0%, #f9ad59 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 {
            color: white;
            text-align: center;
            margin-bottom: 30px;
        }
        h2 {
            color: #6f42c1;
            border-bottom: 2px solid #6f42c1;
            padding-bottom: 10px;
        }
        .test {
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid #ddd;
            border-radius: 4px;
        }
        .test.pass {
            background-color: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .test.fail {
            background-color: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }
        .test.warning {
            background-color: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }
        .test.info {
            background-color: #d1ecf1;
            border-color: #17a2b8;
            color: #0c5460;
        }
        .test-label {
            font-weight: 600;
            margin-bottom: 5px;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        .button-group {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        button.btn-primary {
            background: #6f42c1;
            color: white;
        }
        button.btn-primary:hover {
            background: #5b2fb0;
        }
        button.btn-danger {
            background: #dc3545;
            color: white;
        }
        button.btn-danger:hover {
            background: #c82333;
        }
        .session-data {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto;
            max-height: 300px;
            overflow-y: auto;
        }
        .success-badge {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }
        .error-badge {
            display: inline-block;
            background: #dc3545;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Session Management Test Suite</h1>

        <div class="card">
            <h2>Session Configuration</h2>
            
            <div class="test info">
                <div class="test-label">Session Status</div>
                <div><?php echo session_status() === PHP_SESSION_ACTIVE ? '<span class="success-badge">ACTIVE</span>' : '<span class="error-badge">INACTIVE</span>'; ?></div>
            </div>

            <div class="test <?php echo ini_get('session.use_strict_mode') ? 'pass' : 'fail'; ?>">
                <div class="test-label">Strict Mode</div>
                <div><?php echo ini_get('session.use_strict_mode') ? '✓ Enabled' : '✗ Disabled - SECURITY ISSUE'; ?></div>
            </div>

            <div class="test <?php echo ini_get('session.use_only_cookies') ? 'pass' : 'fail'; ?>">
                <div class="test-label">Cookies Only</div>
                <div><?php echo ini_get('session.use_only_cookies') ? '✓ Enabled' : '✗ Disabled - SECURITY ISSUE'; ?></div>
            </div>

            <div class="test <?php echo ini_get('session.cookie_httponly') ? 'pass' : 'fail'; ?>">
                <div class="test-label">HTTP-Only Cookies</div>
                <div><?php echo ini_get('session.cookie_httponly') ? '✓ Enabled' : '✗ Disabled - SECURITY ISSUE'; ?></div>
            </div>

            <div class="test <?php echo ini_get('session.cookie_samesite') ? 'pass' : 'warning'; ?>">
                <div class="test-label">SameSite Attribute</div>
                <div><?php echo ini_get('session.cookie_samesite') ?: 'Not set (recommend: Lax)'; ?></div>
            </div>

            <div class="test info">
                <div class="test-label">Session Timeout</div>
                <div><?php echo ini_get('session.gc_maxlifetime'); ?> seconds (<?php echo ini_get('session.gc_maxlifetime') / 60; ?> minutes)</div>
            </div>
        </div>

        <div class="card">
            <h2>Session Handler Functions</h2>
            
            <div class="test <?php echo function_exists('initializeSession') ? 'pass' : 'fail'; ?>">
                <div class="test-label">initializeSession()</div>
                <div><?php echo function_exists('initializeSession') ? '✓ Available' : '✗ Not found'; ?></div>
            </div>

            <div class="test <?php echo function_exists('validateSessionFingerprint') ? 'pass' : 'fail'; ?>">
                <div class="test-label">validateSessionFingerprint()</div>
                <div><?php echo function_exists('validateSessionFingerprint') ? '✓ Available' : '✗ Not found'; ?></div>
            </div>

            <div class="test <?php echo function_exists('isAdminAuthenticated') ? 'pass' : 'fail'; ?>">
                <div class="test-label">isAdminAuthenticated()</div>
                <div><?php echo function_exists('isAdminAuthenticated') ? '✓ Available' : '✗ Not found'; ?></div>
            </div>

            <div class="test <?php echo function_exists('checkSessionTimeout') ? 'pass' : 'fail'; ?>">
                <div class="test-label">checkSessionTimeout()</div>
                <div><?php echo function_exists('checkSessionTimeout') ? '✓ Available' : '✗ Not found'; ?></div>
            </div>

            <div class="test <?php echo function_exists('logActivity') ? 'pass' : 'fail'; ?>">
                <div class="test-label">logActivity()</div>
                <div><?php echo function_exists('logActivity') ? '✓ Available' : '✗ Not found'; ?></div>
            </div>

            <div class="test <?php echo function_exists('setFlashMessage') ? 'pass' : 'fail'; ?>">
                <div class="test-label">setFlashMessage()</div>
                <div><?php echo function_exists('setFlashMessage') ? '✓ Available' : '✗ Not found'; ?></div>
            </div>
        </div>

        <div class="card">
            <h2>Current Session Data</h2>
            
            <div class="test info">
                <div class="test-label">Session ID</div>
                <div><code><?php echo session_id(); ?></code></div>
            </div>

            <div class="test <?php echo isset($_SESSION['fingerprint']) ? 'pass' : 'warning'; ?>">
                <div class="test-label">Session Fingerprint</div>
                <div><?php 
                    if (isset($_SESSION['fingerprint'])) {
                        echo '✓ Present - <code>' . substr($_SESSION['fingerprint'], 0, 16) . '...</code>';
                    } else {
                        echo '⚠ Not set yet';
                    }
                ?></div>
            </div>

            <div class="test <?php echo isset($_SESSION['last_activity']) ? 'pass' : 'warning'; ?>">
                <div class="test-label">Last Activity</div>
                <div><?php 
                    if (isset($_SESSION['last_activity'])) {
                        echo '✓ ' . date('Y-m-d H:i:s', $_SESSION['last_activity']);
                    } else {
                        echo '⚠ Not tracked yet';
                    }
                ?></div>
            </div>

            <div class="test <?php echo isAdminAuthenticated() ? 'pass' : 'info'; ?>">
                <div class="test-label">Admin Authentication</div>
                <div><?php echo isAdminAuthenticated() ? '✓ Admin logged in as: ' . htmlspecialchars($_SESSION['user_name']) : '- Not logged in'; ?></div>
            </div>

            <div class="test <?php echo isStudentAuthenticated() ? 'pass' : 'info'; ?>">
                <div class="test-label">Student Authentication</div>
                <div><?php echo isStudentAuthenticated() ? '✓ Student logged in as: ' . htmlspecialchars($_SESSION['user_name']) : '- Not logged in'; ?></div>
            </div>

            <div style="margin-top: 15px;">
                <div class="test-label">All Session Variables</div>
                <div class="session-data">
<?php
foreach ($_SESSION as $key => $value) {
    if (is_string($value) || is_int($value)) {
        echo htmlspecialchars($key) . ' => ' . htmlspecialchars(substr($value, 0, 100)) . "\n";
    } else {
        echo htmlspecialchars($key) . ' => [' . gettype($value) . "]\n";
    }
}
?>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Server Information</h2>
            
            <div class="test info">
                <div class="test-label">Environment</div>
                <div><?php echo getenv('WUC_ENV') ?: 'production (default)'; ?></div>
            </div>

            <div class="test info">
                <div class="test-label">Remote Address</div>
                <div><code><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR']); ?></code></div>
            </div>

            <div class="test info">
                <div class="test-label">User Agent</div>
                <div style="word-break: break-all;"><code><?php echo htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'], 0, 100)); ?>...</code></div>
            </div>

            <div class="test info">
                <div class="test-label">Current Script</div>
                <div><code><?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?></code></div>
            </div>
        </div>

        <div class="card">
            <h2>Test Actions</h2>
            
            <div class="button-group">
                <button class="btn-primary" onclick="location.reload()">🔄 Refresh Test</button>
                <button class="btn-primary" onclick="testTimeout()">⏱️ Test Timeout</button>
                <button class="btn-danger" onclick="clearSession()">🗑️ Clear Session</button>
            </div>
        </div>

        <div class="card" style="background: #f8f9fa; color: #666;">
            <h2>Documentation</h2>
            <p>
                <strong>Session Handler:</strong> <code>admissions/includes/session_handler.php</code><br>
                <strong>Documentation:</strong> <code>admissions/SESSION_MANAGEMENT_FIX.md</code><br>
                <strong>Security Features:</strong> Session fingerprinting, timeout checking, activity logging, CSRF protection
            </p>
        </div>
    </div>

    <script>
        function testTimeout() {
            if (confirm('This will test session timeout by setting last_activity to 31 minutes ago. The next page load will clear your session.')) {
                fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=set_timeout_test')
                    .then(() => alert('Session modified. Next page load will trigger logout.'))
                    .catch(err => alert('Error: ' + err));
            }
        }

        function clearSession() {
            if (confirm('This will clear all session data. Are you sure?')) {
                fetch('<?php echo $_SERVER['PHP_SELF']; ?>?action=clear_session')
                    .then(() => location.reload())
                    .catch(err => alert('Error: ' + err));
            }
        }
    </script>
</body>
</html>

<?php
// Handle test actions
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'set_timeout_test':
            $_SESSION['last_activity'] = time() - 1860; // 31 minutes ago
            echo 'OK';
            exit;
        case 'clear_session':
            logoutUser();
            echo 'OK';
            exit;
    }
}
?>
