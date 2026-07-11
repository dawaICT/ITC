<?php
// SESSION TRACE UTILITY
// Set marker to detect file inclusion sequence
define('SESSION_TRACE_INCLUDED', true);

// Enable full error reporting
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Create log directory if it doesn't exist
$logDir = __DIR__ . '/../logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

// Set up a log file for session debugging
ini_set('error_log', $logDir . '/session_debug.log');
error_log("----------- SESSION TRACE STARTED -----------");

// Function to log session state at different points
function log_session_state($location, $additionalData = []) {
    static $counter = 0;
    $counter++;
    
    $state = [
        'location' => $location,
        'counter' => $counter,
        'time' => date('Y-m-d H:i:s'),
        'session_id' => session_id() ?: 'none',
        'session_status' => session_status(),
        'session_started' => (session_status() === PHP_SESSION_ACTIVE) ? 'yes' : 'no',
        'session_name' => session_name(),
        'sid_exists' => isset($_SESSION['Sid']) ? 'yes' : 'no',
        'sid_value' => isset($_SESSION['Sid']) ? $_SESSION['Sid'] : 'not set',
        'last_activity' => isset($_SESSION['last_activity']) ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : 'not set',
        'time_since_last' => isset($_SESSION['last_activity']) ? (time() - $_SESSION['last_activity']) . ' seconds' : 'n/a',
        'cookie_exists' => isset($_COOKIE[session_name()]) ? 'yes' : 'no',
        'cookie_value' => isset($_COOKIE[session_name()]) ? substr($_COOKIE[session_name()], 0, 10) . '...' : 'not set',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    ];
    
    $state = array_merge($state, $additionalData);
    
    $logMessage = json_encode($state);
    error_log("SESSION STATE: " . $logMessage);
    
    return $state;
}

// Detect if we're being included or executed directly
$isDirectExecution = !defined('SESSION_TRACE_EXECUTED') && 
                    (!isset($GLOBALS['SESSION_TRACE_INCLUDED']) || 
                     $GLOBALS['SESSION_TRACE_INCLUDED'] === SESSION_TRACE_INCLUDED);

if ($isDirectExecution) {
    // This is being executed directly, not just included
    define('SESSION_TRACE_EXECUTED', true);
    
    // Create session tracking cookie to detect issues across requests
    $trackingId = uniqid('track_', true);
    setcookie('session_tracking', $trackingId, [
        'expires' => time() + 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    // Log initial state before any session operations
    log_session_state('direct_execution_start', [
        'tracking_id' => $trackingId,
        'included_files' => get_included_files(),
    ]);
    
    // Manually start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        log_session_state('session_started_manually', [
            'tracking_id' => $trackingId
        ]);
    } else {
        log_session_state('session_already_active', [
            'tracking_id' => $trackingId
        ]);
    }
    
    // Set/update last_activity
    $_SESSION['last_activity'] = time();
    
    // If we're showing debug info, display the HTML
    if (!isset($_GET['silent']) || $_GET['silent'] !== '1') {
        header('Content-Type: text/html');
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Session Trace</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        pre.json { background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 300px; overflow: auto; }
        .session-info { font-family: monospace; }
    </style>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
    <div class="container my-5">
        <h1>Session Trace Utility</h1>
        <p class="lead">This tool helps debug session issues in the course registration process.</p>
        
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Current Session State</h5>
            </div>
            <div class="card-body">
                <?php
                $sessionState = log_session_state('html_display', [
                    'tracking_id' => $trackingId,
                    'get_params' => $_GET,
                    'post_params' => array_keys($_POST)
                ]);
                
                // Display session timeout warning
                if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 270) { // 270 = 4.5 minutes
                    $timeLeft = 300 - (time() - $_SESSION['last_activity']);
                    echo '<div class="alert alert-danger"><strong>Warning:</strong> Session will timeout in ' . $timeLeft . ' seconds!</div>';
                }
                
                // Display if we have an active session
                if (isset($_SESSION['Sid'])) {
                    echo '<div class="alert alert-success">User is logged in with Student ID: ' . htmlspecialchars($_SESSION['Sid']) . '</div>';
                } else {
                    echo '<div class="alert alert-danger">No user is logged in (Session[\'Sid\'] not set)</div>';
                }
                ?>
                
                <h5>Session Information</h5>
                <table class="table table-hover align-middle">
                    <tbody>
                        <?php foreach ($sessionState as $key => $value): ?>
                        <tr>
                            <th><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?></th>
                            <td class="session-info"><?= htmlspecialchars(is_array($value) ? json_encode($value) : $value) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <h5>Session Data</h5>
                <pre class="json"><?= htmlspecialchars(print_r($_SESSION, true)) ?></pre>
                
                <h5>Cookies</h5>
                <pre class="json"><?= htmlspecialchars(print_r($_COOKIE, true)) ?></pre>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Session Tests</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-3">
                    <a href="session_trace.php?action=refresh&_=<?= time() ?>" class="btn btn-primary">
                        Refresh Session
                    </a>
                    
                    <a href="session_trace.php?action=test_guard&_=<?= time() ?>" class="btn btn-warning">
                        Test guard.php Inclusion
                    </a>
                    
                    <a href="session_trace.php?action=simulate_long_request&_=<?= time() ?>" class="btn btn-danger">
                        Simulate Long Request (4 seconds)
                    </a>
                </div>
                
                <h5 class="mt-4">Form Post Test</h5>
                <form action="session_trace.php?action=test_post" method="post" class="mb-3">
                    <div class="mb-3">
                        <label for="testData" class="form-label">Test Data</label>
                        <input type="text" class="form-control" id="testData" name="testData" value="test value">
                    </div>
                    <button type="submit" class="btn btn-success">Test POST Submission</button>
                </form>
                
                <h5 class="mt-4">Course Registration Test</h5>
                <div class="d-grid gap-2">
                    <a href="courseReg_fixed.php" class="btn btn-outline-primary">Open Fixed Registration Form</a>
                    <a href="debug_course_reg.php" class="btn btn-outline-secondary">Open Debug Console</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
        <?php
    } else {
        // Silent mode - just output JSON
        header('Content-Type: application/json');
        echo json_encode(log_session_state('silent_execution', [
            'tracking_id' => $trackingId
        ]));
    }
    
    // Process actions
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'refresh':
                // Just refresh the session
                $_SESSION['last_activity'] = time();
                $_SESSION['debug_refresh_count'] = ($_SESSION['debug_refresh_count'] ?? 0) + 1;
                break;
                
            case 'test_guard':
                // Include guard.php and see what happens
                log_session_state('before_guard_include');
                
                // Capture any output/redirects
                ob_start();
                $guardResult = @include_once __DIR__ . '/includes/guard.php';
                $guardOutput = ob_get_clean();
                
                log_session_state('after_guard_include', [
                    'guard_included' => $guardResult ? 'yes' : 'no',
                    'guard_output' => $guardOutput ? substr($guardOutput, 0, 100) . '...' : 'none'
                ]);
                break;
                
            case 'simulate_long_request':
                // Simulate a long-running request
                log_session_state('long_request_start');
                
                // Sleep for 4 seconds
                sleep(4);
                
                log_session_state('long_request_end');
                break;
                
            case 'test_post':
                // Handle POST data
                log_session_state('post_received', [
                    'post_data' => $_POST
                ]);
                break;
        }
    }
    
    // Log final state
    log_session_state('direct_execution_end');
    exit; // Stop execution here if we're running directly
}

// If we get here, we're being included in another file
// Just log the inclusion and return
log_session_state('included_in_another_file', [
    'including_file' => get_included_files()[0],
    'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)
]);
?>
