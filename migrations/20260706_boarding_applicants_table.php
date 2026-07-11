<?php
/**
 * 2026-07-06 — Create the `boarding_applicants` table.
 *
 * students/boardingApp.php (application form), admin/hostels.php and
 * registrar/hostels.php referenced it but the table never existed.
 * Columns derived from actual code usage: Sid, semester, reason, dte.
 *
 * Run: php migrations/20260706_boarding_applicants_table.php
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
CREATE TABLE IF NOT EXISTS boarding_applicants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  Sid VARCHAR(50) NOT NULL,
  semester VARCHAR(20) NOT NULL,
  reason TEXT NULL,
  dte DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_boarding_sid_sem (Sid, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "boarding_applicants table ready.\n";
