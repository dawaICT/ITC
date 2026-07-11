<?php
// Enable full error reporting
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session immediately
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log session details
$sessionDetails = [
    'session_id' => session_id(),
    'sid_in_session' => isset($_SESSION['Sid']),
    'last_activity' => isset($_SESSION['last_activity']) ? $_SESSION['last_activity'] : null,
    'time_since_activity' => isset($_SESSION['last_activity']) ? time() - $_SESSION['last_activity'] : null,
];

// Log PHP session configuration
$sessionConfig = [
    'session.cookie_lifetime' => ini_get('session.cookie_lifetime'),
    'session.gc_maxlifetime' => ini_get('session.gc_maxlifetime'),
    'session.gc_probability' => ini_get('session.gc_probability'),
    'session.gc_divisor' => ini_get('session.gc_divisor'),
    'session.use_strict_mode' => ini_get('session.use_strict_mode'),
    'session.use_cookies' => ini_get('session.use_cookies'),
    'session.use_only_cookies' => ini_get('session.use_only_cookies'),
    'session.save_handler' => ini_get('session.save_handler'),
];

// Refresh session timestamp
$_SESSION['last_activity'] = time();

// Include database connection
require_once __DIR__ . '/../db/connect.php';

// Include guard but capture any redirect
ob_start();
$guardIncluded = @include_once __DIR__ . '/includes/guard.php';
$guardOutput = ob_get_clean();

// Log database connection state
$dbState = [
    'connected' => isset($db) && $db instanceof mysqli && !$db->connect_error,
    'error' => isset($db) && $db instanceof mysqli ? $db->connect_error : 'Database connection not available',
];

// Debug data to show in page
$debugData = [
    'session_details' => $sessionDetails,
    'session_config' => $sessionConfig,
    'database_state' => $dbState,
    'server_info' => [
        'php_version' => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
        'request_time' => $_SERVER['REQUEST_TIME'] ?? 'Unknown',
    ],
    'cookies' => $_COOKIE,
    'guard_included' => $guardIncluded,
    'guard_output' => $guardOutput,
];

// Check if the session would timeout
$wouldTimeout = isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 300);

// Handler for manual session tests
$testResult = null;

if (isset($_POST['test_action'])) {
    switch ($_POST['test_action']) {
        case 'refresh_session':
            $_SESSION['last_activity'] = time();
            $testResult = ['success' => true, 'message' => 'Session refreshed successfully'];
            break;
        
        case 'simulate_submit':
            try {
                // Simulate form submission with a database query
                if ($db && $db instanceof mysqli) {
                    $startTime = microtime(true);
                    
                    // Perform a longer-running query to simulate processing time
                    $result = $db->query("SELECT COUNT(*) as count FROM course_registration");
                    
                    $endTime = microtime(true);
                    $executionTime = $endTime - $startTime;
                    
                    if ($result) {
                        $row = $result->fetch_assoc();
                        $testResult = [
                            'success' => true,
                            'message' => 'Query executed successfully',
                            'execution_time' => $executionTime,
                            'result' => $row['count'] . ' records found'
                        ];
                    } else {
                        $testResult = [
                            'success' => false,
                            'message' => 'Query failed: ' . $db->error,
                            'execution_time' => $executionTime
                        ];
                    }
                } else {
                    $testResult = [
                        'success' => false,
                        'message' => 'Database connection not available'
                    ];
                }
            } catch (Exception $e) {
                $testResult = [
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage()
                ];
            }
            break;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Registration Debug</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
    <div class="container mt-4 mb-5">
        <h1>Course Registration Debug Tool</h1>
        <p class="lead">This tool helps identify session and submission issues in the course registration process.</p>
        
        <!-- Session status -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Session Status</h5>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['Sid'])): ?>
                    <div class="alert alert-success">
                        <strong>Session Active:</strong> User ID: <?= htmlspecialchars($_SESSION['Sid']) ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <strong>No Active Session:</strong> You are not logged in.
                    </div>
                <?php endif; ?>
                
                <?php if ($wouldTimeout): ?>
                    <div class="alert alert-warning">
                        <strong>Session Timeout:</strong> Your session would timeout based on last activity.
                    </div>
                <?php endif; ?>
                
                <h6>Session Details:</h6>
                <ul class="list-group mb-3">
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Session ID:</span>
                        <strong><?= session_id() ?></strong>
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
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Session Cookie:</span>
                        <strong><?= htmlspecialchars($_COOKIE[session_name()] ?? 'Not set') ?></strong>
                    </li>
                </ul>
                
                <div class="d-grid gap-2">
                    <form method="post" action="">
                        <input type="hidden" name="test_action" value="refresh_session">
                        <button type="submit" class="btn btn-primary">Refresh Session</button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Test form submission -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Test Form Submission</h5>
            </div>
            <div class="card-body">
                <p>Click the button below to simulate a form submission with database operations:</p>
                
                <?php if ($testResult): ?>
                    <div class="alert alert-<?= $testResult['success'] ? 'success' : 'danger' ?>">
                        <strong><?= $testResult['success'] ? 'Success:' : 'Error:' ?></strong> <?= htmlspecialchars($testResult['message']) ?>
                        <?php if (isset($testResult['execution_time'])): ?>
                            <br>Execution time: <?= number_format($testResult['execution_time'], 4) ?> seconds
                        <?php endif; ?>
                        <?php if (isset($testResult['result'])): ?>
                            <br>Result: <?= htmlspecialchars($testResult['result']) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" action="">
                    <input type="hidden" name="test_action" value="simulate_submit">
                    <div class="d-grid">
                        <button type="submit" class="btn btn-info">Simulate Submission</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Registration Links -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Registration Options</h5>
            </div>
            <div class="card-body">
                <p>Choose one of the following registration options:</p>
                
                <div class="list-group">
                    <a href="courseReg.php" class="list-group-item list-group-item-action">
                        <div class="d-flex w-100 justify-content-between">
                            <h5 class="mb-1">Original Course Registration</h5>
                            <small>PHP + React</small>
                        </div>
                        <p class="mb-1">The original registration page with React enhancements.</p>
                    </a>
                    
                    <a href="courseReg_fixed.php" class="list-group-item list-group-item-action">
                        <div class="d-flex w-100 justify-content-between">
                            <h5 class="mb-1">Fixed Course Registration</h5>
                            <small>PHP Only</small>
                        </div>
                        <p class="mb-1">PHP-only registration with improved session handling.</p>
                    </a>
                    
                    <a href="courseReg_react.php" class="list-group-item list-group-item-action">
                        <div class="d-flex w-100 justify-content-between">
                            <h5 class="mb-1">React Course Registration</h5>
                            <small>React</small>
                        </div>
                        <p class="mb-1">New React-based implementation with minimal PHP dependencies.</p>
                    </a>
                    
                    <a href="direct_course_submit.php" class="list-group-item list-group-item-action">
                        <div class="d-flex w-100 justify-content-between">
                            <h5 class="mb-1">Direct Submission Form</h5>
                            <small>Simple</small>
                        </div>
                        <p class="mb-1">Simplified form with direct form submission.</p>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Technical Debug Data -->
        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0">Technical Debug Information</h5>
            </div>
            <div class="card-body">
                <div class="accordion" id="debugAccordion">
                    <?php foreach ($debugData as $section => $data): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading<?= ucfirst($section) ?>">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" 
                                        data-bs-target="#collapse<?= ucfirst($section) ?>" aria-expanded="false" 
                                        aria-controls="collapse<?= ucfirst($section) ?>">
                                    <?= ucwords(str_replace('_', ' ', $section)) ?>
                                </button>
                            </h2>
                            <div id="collapse<?= ucfirst($section) ?>" class="accordion-collapse collapse" 
                                 aria-labelledby="heading<?= ucfirst($section) ?>" data-bs-parent="#debugAccordion">
                                <div class="accordion-body">
                                    <pre class="bg-light p-3 rounded"><?= htmlspecialchars(print_r($data, true)) ?></pre>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
