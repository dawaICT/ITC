<?php
/**
 * Migration: create the lms_modules / lms_contents e-learning content tables.
 *
 * admin/elearning/modules.php and admin/elearning/upload.php were written against
 * an lms_modules / lms_contents schema that was never created in the live DB
 * (only the parallel el_course_modules / el_contents tables exist, with a
 * different structure used by other pages). This script creates the base tables
 * to match what modules.php and upload.php actually query.
 *
 *   - lms_modules.id is AUTO_INCREMENT (upload.php selects it as the <option> value).
 *   - lms_modules has a UNIQUE(course_code, module_code) so modules.php's
 *     INSERT ... ON DUPLICATE KEY UPDATE upserts correctly.
 *   - lms_contents.module_id is INT to match lms_modules.id and the (int) bind.
 *
 * Re-runnable: every statement uses CREATE TABLE IF NOT EXISTS.
 *
 * Run:  E:\xampp\php\php.exe db\create_lms_content_tables.php
 */

require_once __DIR__ . '/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$statements = [];

$statements['lms_modules'] = <<<SQL
CREATE TABLE IF NOT EXISTS lms_modules (
  id INT NOT NULL AUTO_INCREMENT,
  course_code VARCHAR(64) NOT NULL,
  module_code VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  release_at DATETIME NULL,
  close_at DATETIME NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_course_module (course_code, module_code),
  KEY idx_course (course_code),
  KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$statements['lms_contents'] = <<<SQL
CREATE TABLE IF NOT EXISTS lms_contents (
  id INT NOT NULL AUTO_INCREMENT,
  module_id INT NOT NULL,
  content_type VARCHAR(40) NOT NULL,
  storage_path VARCHAR(1000) NOT NULL,
  mime_type VARCHAR(127) NULL,
  version INT NOT NULL DEFAULT 1,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_module (module_id),
  KEY idx_module_current (module_id, is_current),
  CONSTRAINT fk_lms_contents_module FOREIGN KEY (module_id)
    REFERENCES lms_modules(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$ok = true;
foreach ($statements as $table => $sql) {
    try {
        $db->query($sql);
        $existsRes = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
        $exists = $existsRes && $existsRes->num_rows > 0;
        echo ($exists ? '[OK]   ' : '[FAIL] ') . $table . "\n";
        if (!$exists) { $ok = false; }
    } catch (Throwable $e) {
        echo '[FAIL] ' . $table . ' -> ' . $e->getMessage() . "\n";
        $ok = false;
    }
}

echo $ok ? "\nMigration complete.\n" : "\nMigration finished with errors.\n";
exit($ok ? 0 : 1);
