<?php
/**
 * 2026-07-06 — Add an owner column to employer_internships so each employer
 * account only sees the placements it logged (previously every employer saw
 * every record, including other companies' feedback and student lists).
 *
 * logged_by_user_id references users.user_id logically (no FK: legacy rows
 * are NULL and the users table may be pruned in dev seeds).
 *
 * Idempotent: checks INFORMATION_SCHEMA before altering.
 * Run: php migrations/20260706_employer_internships_owner.php
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

$col = $db->query("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employer_internships'
      AND COLUMN_NAME = 'logged_by_user_id'
")->num_rows;

if ($col === 0) {
    $db->query("ALTER TABLE employer_internships
        ADD COLUMN logged_by_user_id INT NULL AFTER feedback,
        ADD KEY idx_ei_logged_by (logged_by_user_id)");
    echo "Added employer_internships.logged_by_user_id\n";
} else {
    echo "employer_internships.logged_by_user_id already exists — nothing to do\n";
}
