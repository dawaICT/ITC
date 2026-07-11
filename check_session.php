<?php
// Start output buffering to prevent header issues
ob_start();

// Force error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "<h1>Session Health Check</h1>";

// Test 1: Basic session start
echo "<h2>Test 1: Basic Session</h2>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    echo "✓ Session started<br>";
    echo "Session ID: " . session_id() . "<br>";
} else {
    echo "✓ Session already active<br>";
    echo "Session ID: " . session_id() . "<br>";
}

// Test 2: Set test data
echo "<h2>Test 2: Set Test Data</h2>";
$_SESSION['test_time'] = date('Y-m-d H:i:s');
$_SESSION['test_random'] = rand(1000, 9999);
echo "✓ Test data set<br>";
echo "test_time: " . $_SESSION['test_time'] . "<br>";
echo "test_random: " . $_SESSION['test_random'] . "<br>";

// Test 3: Write and reload
echo "<h2>Test 3: Write and Reload</h2>";
session_write_close();
session_start();
echo "✓ Session closed and reopened<br>";
echo "test_time after reload: " . ($_SESSION['test_time'] ?? 'NOT FOUND') . "<br>";
echo "test_random after reload: " . ($_SESSION['test_random'] ?? 'NOT FOUND') . "<br>";

// Test 4: Cookie check
echo "<h2>Test 4: Cookie Check</h2>";
$cookieParams = session_get_cookie_params();
echo "Cookie name: " . session_name() . "<br>";
echo "Cookie lifetime: " . $cookieParams['lifetime'] . "<br>";
echo "Cookie path: " . $cookieParams['path'] . "<br>";
echo "Cookie domain: " . $cookieParams['domain'] . "<br>";

// Test 5: File system check
echo "<h2>Test 5: File System Check</h2>";
$savePath = session_save_path();
echo "Save path: " . $savePath . "<br>";
echo "Is writable: " . (is_writable($savePath) ? '✓ Yes' : '✗ No') . "<br>";

$sessionFile = $savePath . '/sess_' . session_id();
if (file_exists($sessionFile)) {
    echo "✓ Session file exists<br>";
    echo "File size: " . filesize($sessionFile) . " bytes<br>";
} else {
    echo "✗ Session file not found!<br>";
}

echo "<h2>Final Session Data:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Clean output buffer
ob_end_flush();
?>