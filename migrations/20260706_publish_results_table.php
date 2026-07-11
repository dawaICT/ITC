<?php
/**
 * 2026-07-06 — Create the `publish_results` table.
 *
 * admin/publishResults.php, registrar/publishResults.php and
 * vc/publishResults.php (all linked from portal navigation) write a
 * semester-level results-publication flag to this table; it never existed, so
 * every publish attempt fatalled. Columns derived from the three writers
 * (status, semester, year, dte_publish, dte) with the unique key their
 * ON DUPLICATE KEY UPDATE requires.
 *
 * Run: php migrations/20260706_publish_results_table.php
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
CREATE TABLE IF NOT EXISTS publish_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  status VARCHAR(20) NOT NULL,
  semester VARCHAR(20) NOT NULL,
  year VARCHAR(10) NOT NULL,
  dte_publish DATE NULL,
  dte DATETIME NULL,
  UNIQUE KEY uq_publish_sem_year (semester, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "publish_results table ready.\n";
