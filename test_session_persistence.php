<?php
// Test session persistence and configuration
session_start();

echo "Session Configuration:\n";
echo "session.gc_maxlifetime: " . ini_get('session.gc_maxlifetime') . " seconds (" . round(ini_get('session.gc_maxlifetime')/60, 1) . " minutes)\n";
echo "session.cookie_lifetime: " . ini_get('session.cookie_lifetime') . " seconds (" . (ini_get('session.cookie_lifetime') == 0 ? "until browser closes" : round(ini_get('session.cookie_lifetime')/60, 1) . " minutes") . ")\n";
echo "session.save_path: " . session_save_path() . "\n\n";

echo "Current Session Data:\n";
echo "Session ID: " . session_id() . "\n";
echo "Session started: " . (isset($_SESSION['login_time']) ? date('Y-m-d H:i:s', $_SESSION['login_time']) : 'not set') . "\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n\n";

// Set a timestamp if not already set
if (!isset($_SESSION['login_time'])) {
    $_SESSION['login_time'] = time();
    $_SESSION['staff_id'] = 'WUC015';
    $_SESSION['user_name'] = 'Mss. Wenndy Katongo';
    $_SESSION['user_role'] = 'admin';
    $_SESSION['position'] = 'Admission';
    echo "✅ Session initialized with test data\n";
} else {
    $elapsed = time() - $_SESSION['login_time'];
    echo "Session age: " . round($elapsed/60, 1) . " minutes\n";
    if ($elapsed > ini_get('session.gc_maxlifetime')) {
        echo "⚠️  WARNING: Session may have expired based on gc_maxlifetime\n";
    } else {
        echo "✅ Session is within lifetime limits\n";
    }
}

echo "\nSession Variables:\n";
foreach ($_SESSION as $key => $value) {
    if ($key !== 'login_time') {
        echo "- $key: $value\n";
    }
}
?>