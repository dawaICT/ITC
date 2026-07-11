<?php
/**
 * Session debugging script for course registration
 * This tool identifies where session state is being lost during form processing
 */

// Start by enabling full error reporting
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set up error logging to a file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/session_debug.log');

// Create a wrapper to capture PHP errors
function session_debug_log($message, $section = 'general') {
    $timestamp = date('Y-m-d H:i:s');
    $log = "[{$timestamp}] [{$section}] {$message}" . PHP_EOL;
    error_log($log, 3, __DIR__ . '/../logs/session_debug.log');
    return $message;
}

// Log initial script execution
session_debug_log("Session debug script started", "init");

// Check if session is already started
if (session_status() === PHP_SESSION_ACTIVE) {
    session_debug_log("Session was already active: " . session_id(), "init");
} else {
    session_debug_log("Starting new session", "init");
    session_start();
    session_debug_log("New session started: " . session_id(), "init");
}

// Set session data to track changes
$_SESSION['debug_time'] = time();
$_SESSION['debug_id'] = bin2hex(random_bytes(8)); // Unique ID to track this session

// Log current session state
session_debug_log("Session state after initialization:", "state");
session_debug_log("Session ID: " . session_id(), "state");
session_debug_log("SID in session: " . (isset($_SESSION['Sid']) ? $_SESSION['Sid'] : 'Not set'), "state");
session_debug_log("Last activity: " . (isset($_SESSION['last_activity']) ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : 'Not set'), "state");

// Function to test including a file and track session changes
function test_include($file, $section) {
    global $original_session_data;
    session_debug_log("Testing include of file: {$file}", $section);
    
    // Save session data before include
    $before_data = $_SESSION ?? [];
    $before_id = session_id();
    session_debug_log("Session ID before include: " . $before_id, $section);
    session_debug_log("Session data before include: " . json_encode($before_data), $section);
    
    // Include the file with output buffering to capture any output
    ob_start();
    $include_result = @include_once $file;
    $output = ob_get_clean();
    
    // Check if session changed
    $after_id = session_id();
    $after_data = $_SESSION ?? [];
    session_debug_log("Session ID after include: " . $after_id, $section);
    session_debug_log("Session data after include: " . json_encode($after_data), $section);
    
    // Detect changes
    if ($before_id !== $after_id) {
        session_debug_log("⚠️ SESSION ID CHANGED during include of {$file}", "critical");
    }
    
    // Check for removed session vars
    foreach ($before_data as $key => $value) {
        if (!isset($after_data[$key])) {
            session_debug_log("⚠️ Session variable '{$key}' was REMOVED during include of {$file}", "critical");
        }
    }
    
    // Check for modified session vars
    foreach ($before_data as $key => $value) {
        if (isset($after_data[$key]) && $after_data[$key] !== $value) {
            $before_val = is_scalar($value) ? $value : json_encode($value);
            $after_val = is_scalar($after_data[$key]) ? $after_data[$key] : json_encode($after_data[$key]);
            session_debug_log("⚠️ Session variable '{$key}' was CHANGED from '{$before_val}' to '{$after_val}' during include of {$file}", "critical");
        }
    }
    
    // Check for added session vars
    foreach ($after_data as $key => $value) {
        if (!isset($before_data[$key])) {
            $val = is_scalar($value) ? $value : json_encode($value);
            session_debug_log("Session variable '{$key}' was ADDED with value '{$val}' during include of {$file}", $section);
        }
    }
    
    // Report include result
    session_debug_log("Include result: " . ($include_result ? "Success" : "Failed"), $section);
    if (!empty($output)) {
        session_debug_log("Include output: " . substr($output, 0, 200) . (strlen($output) > 200 ? '...' : ''), $section);
    }
    
    return $include_result;
}

// Save the original session data for comparison
$original_session_data = $_SESSION ?? [];
$original_session_id = session_id();

// Test key includes from processCourseReg.php
test_include(__DIR__ . '/includes/guard.php', 'guard');
test_include(__DIR__ . '/../db/connect.php', 'db_connect');
test_include(__DIR__ . '/includes/EligibilityService.php', 'eligibility');
test_include(__DIR__ . '/includes/FeeGuard.php', 'fee_guard');

// Test a typical database operation with session tracking
session_debug_log("Testing database operations", "db_test");
try {
    // Get current session state
    $sid = $_SESSION['Sid'] ?? 'no_sid';
    $before_id = session_id();
    
    session_debug_log("Session ID before DB operation: " . $before_id, "db_test");
    
    // Perform a database operation
    require_once __DIR__ . '/../db/connect.php';
    if (isset($db) && $db instanceof mysqli) {
        // Start a transaction to test long-running operations
        $db->begin_transaction();
        session_debug_log("Started DB transaction", "db_test");
        
        // Perform a typical query
        $start_time = microtime(true);
        $result = $db->query("SELECT * FROM semester_registration LIMIT 10");
        $duration = microtime(true) - $start_time;
        
        session_debug_log("Query execution time: {$duration} seconds", "db_test");
        
        // Check session after query
        $after_query_id = session_id();
        session_debug_log("Session ID after query: " . $after_query_id, "db_test");
        
        if ($before_id !== $after_query_id) {
            session_debug_log("⚠️ SESSION ID CHANGED during database query", "critical");
        }
        
        // Commit the transaction
        $db->commit();
        session_debug_log("Committed DB transaction", "db_test");
    } else {
        session_debug_log("Database connection not available", "db_test");
    }
} catch (Exception $e) {
    session_debug_log("Database error: " . $e->getMessage(), "db_test");
}

// Test the session timeout mechanism
session_debug_log("Testing session timeout mechanism", "timeout_test");
if (isset($_SESSION['last_activity'])) {
    $timeout_period = 300; // 5 minutes (300 seconds)
    $current_time = time();
    $last_activity = (int)$_SESSION['last_activity'];
    $time_remaining = $timeout_period - ($current_time - $last_activity);
    
    session_debug_log("Last activity time: " . date('Y-m-d H:i:s', $last_activity), "timeout_test");
    session_debug_log("Current time: " . date('Y-m-d H:i:s', $current_time), "timeout_test");
    session_debug_log("Time since last activity: " . ($current_time - $last_activity) . " seconds", "timeout_test");
    session_debug_log("Time remaining before timeout: " . $time_remaining . " seconds", "timeout_test");
    
    // Test if session would timeout
    if ($time_remaining <= 0) {
        session_debug_log("⚠️ SESSION WOULD TIMEOUT based on current activity timestamp", "critical");
    }
}

// Load auth guards with instrumentation
session_debug_log("Testing full auth flow", "auth_flow");
$_SESSION['debug_authflow_test'] = 'test_value';

// Run a session validation test
function validate_session() {
    // Store original session data
    $session_id_before = session_id();
    $session_data_before = $_SESSION ?? [];
    
    session_debug_log("Running session validation test", "validation");
    session_debug_log("Session ID before validation: " . $session_id_before, "validation");
    
    // Close and reopen the session to simulate what happens between requests
    session_write_close();
    session_debug_log("Session written and closed", "validation");
    
    // Re-open the session
    session_start();
    $session_id_after = session_id();
    $session_data_after = $_SESSION ?? [];
    
    session_debug_log("Session reopened with ID: " . $session_id_after, "validation");
    
    // Check for changes
    if ($session_id_before !== $session_id_after) {
        session_debug_log("⚠️ SESSION ID CHANGED after session reopen", "critical");
    }
    
    // Check for data loss
    foreach ($session_data_before as $key => $value) {
        if (!isset($session_data_after[$key])) {
            session_debug_log("⚠️ Session variable '{$key}' was LOST after session reopen", "critical");
        }
    }
    
    return [
        'session_id_changed' => $session_id_before !== $session_id_after,
        'data_loss' => array_diff_key($session_data_before, $session_data_after)
    ];
}

// Run the validation test
$validation_result = validate_session();
session_debug_log("Validation test results: " . json_encode($validation_result), "validation");

// Final session state
session_debug_log("Final session state:", "final");
session_debug_log("Session ID: " . session_id(), "final");
session_debug_log("SID in session: " . (isset($_SESSION['Sid']) ? $_SESSION['Sid'] : 'Not set'), "final");
session_debug_log("Last activity: " . (isset($_SESSION['last_activity']) ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : 'Not set'), "final");
session_debug_log("Session debug completed", "final");

// -------------- HTML Output --------------
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Session Debug Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        .debug-section {
            margin-bottom: 2rem;
            border-radius: 0.25rem;
            border: 1px solid #dee2e6;
        }
        .debug-section-header {
            padding: 0.75rem 1.25rem;
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .critical {
            color: #dc3545;
            font-weight: bold;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .test-card {
            margin-bottom: 1rem;
        }
        .session-data {
            max-height: 200px;
            overflow-y: auto;
        }
    </style>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
    <div class="container mt-4 mb-5">
        <h1 class="mb-4">Session Debug Report</h1>
        
        <div class="alert alert-info">
            This tool analyzes session handling issues in processCourseReg.php and included files.
        </div>
        
        <!-- Session Overview -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Session Overview</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Current Session State</h6>
                        <ul class="list-group mb-3">
                            <li class="list-group-item d-flex justify-content-between">
                                <span>Session ID:</span>
                                <strong><?= session_id() ?></strong>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span>Student ID:</span>
                                <strong><?= isset($_SESSION['Sid']) ? htmlspecialchars($_SESSION['Sid']) : 'Not set' ?></strong>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span>Last Activity:</span>
                                <strong>
                                    <?php if (isset($_SESSION['last_activity'])): ?>
                                        <?= date('Y-m-d H:i:s', $_SESSION['last_activity']) ?>
                                        (<?= time() - $_SESSION['last_activity'] ?> seconds ago)
                                    <?php else: ?>
                                        Not set
                                    <?php endif; ?>
                                </strong>
                            </li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Timeout Analysis</h6>
                        <?php if (isset($_SESSION['last_activity'])): ?>
                            <?php 
                                $timeout = 300;
                                $remaining = $timeout - (time() - $_SESSION['last_activity']);
                                $percentage = max(0, min(100, ($remaining / $timeout) * 100));
                                $barClass = $percentage < 25 ? 'danger' : ($percentage < 50 ? 'warning' : 'success');
                            ?>
                            <div class="mb-2">
                                Time remaining: <?= floor($remaining / 60) ?>m <?= $remaining % 60 ?>s
                            </div>
                            <div class="progress mb-3">
                                <div class="progress-bar bg-<?= $barClass ?>" role="progressbar" 
                                     style="width: <?= $percentage ?>%" 
                                     aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100">
                                    <?= round($percentage) ?>%
                                </div>
                            </div>
                            
                            <?php if ($remaining <= 0): ?>
                                <div class="alert alert-danger">
                                    <strong>Critical:</strong> Session would timeout based on current activity timestamp!
                                </div>
                            <?php elseif ($remaining < 60): ?>
                                <div class="alert alert-warning">
                                    <strong>Warning:</strong> Session is close to timing out (less than 1 minute remaining).
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success">
                                    <strong>OK:</strong> Session timeout not imminent.
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-danger">
                                <strong>Critical:</strong> Last activity timestamp not set in session!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <h6 class="mt-3">Session Data</h6>
                <div class="session-data border rounded p-2 bg-light">
                    <pre><?= htmlspecialchars(print_r($_SESSION, true)) ?></pre>
                </div>
            </div>
        </div>
        
        <!-- Test Form Submission -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Test Form Submission</h5>
            </div>
            <div class="card-body">
                <p>Use these tests to simulate the form submission process:</p>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="test-card">
                            <div class="card-body">
                                <h5 class="card-title">Session Stability Test</h5>
                                <p class="card-text">Tests if session remains stable after database operations.</p>
                                <form method="post" action="process_direct_submit.php">
                                    <input type="hidden" name="test_type" value="session_stability">
                                    <input type="hidden" name="debug" value="1">
                                    <input type="hidden" name="Sid" value="<?= $_SESSION['Sid'] ?? '' ?>">
                                    <button type="submit" class="btn btn-primary">Run Test</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="test-card">
                            <div class="card-body">
                                <h5 class="card-title">Transaction Timeout Test</h5>
                                <p class="card-text">Simulates a long database transaction to test timeout behavior.</p>
                                <form method="post" action="process_direct_submit.php">
                                    <input type="hidden" name="test_type" value="transaction_timeout">
                                    <input type="hidden" name="debug" value="1">
                                    <input type="hidden" name="delay" value="5">
                                    <button type="submit" class="btn btn-warning">Run Test (5s delay)</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="test-card">
                            <div class="card-body">
                                <h5 class="card-title">Guard Include Test</h5>
                                <p class="card-text">Tests how guard.php affects the session when included.</p>
                                <form method="post" action="process_direct_submit.php">
                                    <input type="hidden" name="test_type" value="guard_include">
                                    <input type="hidden" name="debug" value="1">
                                    <button type="submit" class="btn btn-info">Run Test</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Navigation Links -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Registration Options</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Fixed Registration Pages</h6>
                        <div class="list-group mb-3">
                            <a href="courseReg_fixed.php" class="list-group-item list-group-item-action">PHP-only Fixed Registration</a>
                            <a href="courseReg_react.php" class="list-group-item list-group-item-action">React-based Registration</a>
                            <a href="direct_course_submit.php" class="list-group-item list-group-item-action">Direct Submission Form</a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6>Debug Tools</h6>
                        <div class="list-group mb-3">
                            <a href="debug_course_reg.php" class="list-group-item list-group-item-action">Course Registration Debugger</a>
                            <a href="test_session.php" class="list-group-item list-group-item-action">Session Testing Tool</a>
                            <a href="#" class="list-group-item list-group-item-action" onclick="refreshSession(); return false;">Refresh Session</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function refreshSession() {
        fetch('api_get_eligibility.php?keepAlive=1&_=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Session refreshed successfully!');
                location.reload();
            } else {
                alert('Failed to refresh session: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            alert('Error refreshing session: ' + err.message);
        });
    }
    
    // Keep session alive automatically
    setInterval(function() {
        fetch('api_get_eligibility.php?keepAlive=1&_=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store'
        }).catch(console.error);
    }, 240000); // 4 minutes
    </script>
</body>
</html>
