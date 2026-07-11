<?php
/**
 * 2026-07-06 — Company profile table for employer partner accounts.
 *
 * Employer accounts reuse the staff+users login machinery (users.primary_role
 * = 'employer'), but company details (name, industry, location) have no home
 * in `staff` — this table carries them, one profile per account.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 * Run: php migrations/20260706_employer_profiles.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// DDL requires the migrator user (the app user is DML-only).
$host = getenv('WUC_MIGRATOR_HOST') ?: '127.0.0.1';
$user = getenv('WUC_MIGRATOR_USER') ?: 'root';
$pass = getenv('WUC_MIGRATOR_PASSWORD') ?: '';
$name = getenv('WUC_DB_NAME') ?: 'wucportal';
$db = new mysqli($host, $user, $pass, $name);
$db->set_charset('utf8mb4');

$db->query("
CREATE TABLE IF NOT EXISTS employer_profiles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  company_name VARCHAR(150) NOT NULL,
  industry VARCHAR(100) NULL,
  location VARCHAR(150) NULL,
  contact_person VARCHAR(120) NOT NULL,
  contact_email VARCHAR(100) NOT NULL,
  contact_phone VARCHAR(30) NULL,
  status ENUM('pending','approved','rejected','disabled') NOT NULL DEFAULT 'approved',
  created_by VARCHAR(50) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employer_profile_user (user_id),
  CONSTRAINT fk_employer_profile_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "employer_profiles ensured\n";
