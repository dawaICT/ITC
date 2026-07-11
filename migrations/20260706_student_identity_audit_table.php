<?php
/**
 * 2026-07-06 — Create the `student_identity_audit` table.
 *
 * includes/student_identity.php writes one audit row per changed identity
 * field (SID renames etc.) but the table never existed, so every identity
 * change fatalled after the update had already been applied.
 *
 * Run: php migrations/20260706_student_identity_audit_table.php
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
CREATE TABLE IF NOT EXISTS student_identity_audit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_sid VARCHAR(50) NOT NULL,
  field_changed VARCHAR(64) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  changed_by VARCHAR(64) NOT NULL,
  reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sia_sid (student_sid),
  KEY idx_sia_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "student_identity_audit table ready.\n";
