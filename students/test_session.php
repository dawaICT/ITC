<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Store current time in session
$_SESSION['last_activity'] = time();

// Include header
require_once __DIR__ . '/includes/guard.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Session Debugging</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Session Debugging Tool</h4>
                    </div>
                    <div class="card-body">
                        <h5>Session Information:</h5>
                        <ul class="list-group mb-4">
                            <li class="list-group-item d-flex justify-content-between">
                                <strong>Session ID:</strong> 
                                <span><?php echo session_id(); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <strong>Student ID:</strong> 
                                <span><?php echo htmlspecialchars($_SESSION['Sid'] ?? 'Not set'); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <strong>Last Activity:</strong> 
                                <span>
                                    <?php 
                                    if (isset($_SESSION['last_activity'])) {
                                        echo date('Y-m-d H:i:s', $_SESSION['last_activity']);
                                        echo ' (', time() - $_SESSION['last_activity'], ' seconds ago)';
                                    } else {
                                        echo 'Not set';
                                    }
                                    ?>
                                </span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <strong>Session Cookie:</strong> 
                                <span><?php echo htmlspecialchars($_COOKIE[session_name()] ?? 'Not set'); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <strong>Session Timeout:</strong> 
                                <span><?php echo (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 300) ? 'Yes' : 'No'; ?> (5 minutes)</span>
                            </li>
                        </ul>
                        
                        <h5>Test Registration Flow:</h5>
                        <div class="d-flex flex-column gap-2">
                            <a href="courseReg.php" class="btn btn-primary">
                                Go to Course Registration
                            </a>
                            <button id="session-refresh" class="btn btn-outline-secondary">
                                Refresh Session (API Call)
                            </button>
                        </div>
                        
                        <div id="api-result" class="mt-3"></div>
                    </div>
                    
                    <div class="card-footer text-muted">
                        <div id="timer">Session will timeout in: calculating...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // Timer to show remaining session time
    const startTime = <?php echo $_SESSION['last_activity'] ?? time(); ?>;
    const timeout = 300; // 5 minutes in seconds
    const timerElement = document.getElementById('timer');
    
    function updateTimer() {
        const now = Math.floor(Date.now() / 1000);
        const elapsed = now - startTime;
        const remaining = timeout - elapsed;
        
        if (remaining <= 0) {
            timerElement.textContent = 'Session has timed out!';
            timerElement.style.color = 'red';
        } else {
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            timerElement.textContent = `Session will timeout in: ${minutes}:${seconds.toString().padStart(2, '0')}`;
        }
    }
    
    // Update every second
    setInterval(updateTimer, 1000);
    updateTimer();
    
    // Session refresh button
    document.getElementById('session-refresh').addEventListener('click', function() {
        const resultElement = document.getElementById('api-result');
        resultElement.innerHTML = '<div class="alert alert-info">Calling API to refresh session...</div>';
        
        fetch('api_get_eligibility.php?keepAlive=1&_=' + Date.now(), {
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.session_refreshed) {
                resultElement.innerHTML = '<div class="alert alert-success">Session refreshed successfully!</div>';
                // Update the timer's start time
                window.startTime = Math.floor(Date.now() / 1000);
                updateTimer();
            } else {
                resultElement.innerHTML = '<div class="alert alert-danger">Failed to refresh session.</div>';
            }
        })
        .catch(error => {
            resultElement.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
        });
    });
    </script>
</body>
</html>
