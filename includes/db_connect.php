<?php
require_once __DIR__ . '/config.php';
// Shared security helpers (wuc_encode_id / wuc_decode_id / wuc_resolve_id).
require_once __DIR__ . '/security.php';

try {
    // Use the existing mysqli connection from config.php
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("Base mysqli connection not available");
    }

    $db = $conn; // unify variable name expected across the app

    // Enable mysqli error reporting to throw exceptions
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // Verify connection is alive
    if (!$db->ping()) {
        throw new Exception("Database connection lost");
    }

    // Ensure charset (config.php already sets this, but safe to enforce)
    if (!$db->set_charset("utf8mb4")) {
        throw new Exception("Error setting charset: " . $db->error);
    }

    if (DB_DEBUG) {
        error_log("Database connection test successful");
    }
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    if (DB_DEBUG) {
        die("Database connection failed: " . $e->getMessage());
    } else {
        die("A database error occurred. Please try again later.");
    }
}
?> 