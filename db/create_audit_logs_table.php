<?php
/**
 * Database Migration - Phase 16 System Audit Logs Table
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/connect.php';

echo "Running System Audit Logs Migration...\n";

// Create system_audit_logs table
$sql = "CREATE TABLE IF NOT EXISTS system_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    user_role VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql)) {
    echo "[SUCCESS] Table 'system_audit_logs' created.\n";
} else {
    echo "[ERROR] Failed to create 'system_audit_logs': " . $db->error . "\n";
    exit(1);
}

echo "Migration completed successfully!\n";
?>
