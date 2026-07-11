<?php
/**
 * Database Migration - Phase 15 Alumni and Employer Integration Tables
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/connect.php';

echo "Running Alumni & Employer Integration Tables Migration...\n";

// 1. Create alumni_certificates table
$sql1 = "CREATE TABLE IF NOT EXISTS alumni_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    certificate_code VARCHAR(100) NOT NULL UNIQUE,
    program_code VARCHAR(50) NOT NULL,
    graduation_year INT NOT NULL,
    date_issued DATE NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Approved',
    verification_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql1)) {
    echo "[SUCCESS] Table 'alumni_certificates' created.\n";
} else {
    echo "[ERROR] Failed to create 'alumni_certificates': " . $db->error . "\n";
    exit(1);
}

// 2. Create employer_internships table
$sql2 = "CREATE TABLE IF NOT EXISTS employer_internships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    company_name VARCHAR(150) NOT NULL,
    supervisor_name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Active',
    performance_rating INT NULL CHECK (performance_rating BETWEEN 1 AND 5),
    feedback TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql2)) {
    echo "[SUCCESS] Table 'employer_internships' created.\n";
} else {
    echo "[ERROR] Failed to create 'employer_internships': " . $db->error . "\n";
    exit(1);
}

// 3. Create alumni_employment_tracking table
$sql3 = "CREATE TABLE IF NOT EXISTS alumni_employment_tracking (
    student_id VARCHAR(50) PRIMARY KEY,
    current_company VARCHAR(150) NULL,
    job_title VARCHAR(100) NULL,
    employment_status VARCHAR(100) NOT NULL DEFAULT 'Employed',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($db->query($sql3)) {
    echo "[SUCCESS] Table 'alumni_employment_tracking' created.\n";
} else {
    echo "[ERROR] Failed to create 'alumni_employment_tracking': " . $db->error . "\n";
    exit(1);
}

echo "Migration completed successfully!\n";
?>
