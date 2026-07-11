<?php
/**
 * 2026-07-06 — Create the `exemption` table.
 *
 * The whole exemption workflow (students/apply_exemption.php,
 * students/processExemption.php, students/exempStatus.php,
 * students/withdrawExemption.php, admin/exemption.php,
 * admin/approveExemption.php, includes/manual_entry_guards.php) referenced
 * this table but it never existed in the live DB — every page fatalled.
 *
 * Columns derived from actual code usage:
 *   CoRegID      course_registration.id the application is attached to
 *   Sid          student number
 *   course_code  course being exempted
 *   support_doc  uploaded evidence filename (students/uploads/)
 *   status       0 = pending, 1 = approved, 2 = declined
 *
 * Run: php migrations/20260706_exemption_table.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('WUC_MIGRATOR_HOST') ?: '127.0.0.1';
$user = getenv('WUC_MIGRATOR_USER') ?: 'root';
$pass = getenv('WUC_MIGRATOR_PASSWORD') ?: '';
$name = getenv('WUC_DB_NAME') ?: 'wucportal';
$db = new mysqli($host, $user, $pass, $name);
$db->set_charset('utf8mb4');

$db->query("
CREATE TABLE IF NOT EXISTS exemption (
  id INT AUTO_INCREMENT PRIMARY KEY,
  CoRegID INT NOT NULL,
  Sid VARCHAR(50) NOT NULL,
  course_code VARCHAR(50) NOT NULL,
  support_doc VARCHAR(255) NOT NULL,
  status TINYINT NOT NULL DEFAULT 0 COMMENT '0=pending,1=approved,2=declined',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_exemption_coreg (CoRegID),
  KEY idx_exemption_sid (Sid),
  KEY idx_exemption_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "exemption table ready.\n";
