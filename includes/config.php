<?php
// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/security.php';

// Database configuration
require_once __DIR__ . '/portal_config.php';

$isDevelopment = APP_ENV === 'development';
define('DB_HOST', getenv('WUC_DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('WUC_DB_USER') ?: ($isDevelopment ? 'root' : ''));
define('DB_PASS', getenv('WUC_DB_PASSWORD') === false ? '' : getenv('WUC_DB_PASSWORD'));
define('DB_NAME', getenv('WUC_DB_NAME') ?: 'wucportal');
define('DB_CHARSET', 'utf8mb4');
define('DB_PORT', (int)(getenv('WUC_DB_PORT') ?: 3306));

// Database Connection Settings
define('DB_PERSISTENT', true);  // Use persistent connections
define('DB_TIMEOUT', 5);        // Connection timeout in seconds
define('DB_SSL', false);        // Enable SSL for database connection
define('DB_DEBUG', false);      // Never expose DB errors to end users

// SSL Configuration (if enabled)
define('DB_SSL_CA', '');        // Path to CA certificate
define('DB_SSL_CERT', '');      // Path to client certificate
define('DB_SSL_KEY', '');       // Path to client key

// Create database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Set charset to handle special characters correctly
    if (!$conn->set_charset("utf8mb4")) {
        throw new Exception("Error setting charset: " . $conn->error);
    }

    // Test the connection with a simple query
    if (!$conn->query("SELECT 1")) {
        throw new Exception("Connection test failed: " . $conn->error);
    }

} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    wuc_configure_session_cookie();
    session_start();
}

// Normalize user input for storage. HTML encoding must be done at output time, not here.
// Use prepared statements for all SQL — never interpolate this value into a query string.
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        return trim(stripslashes((string) $data));
    }
}
?> 
