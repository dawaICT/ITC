<?php
/**
 * Migration: create the lms_events / lms_forums e-learning engagement tables.
 *
 * includes/elearning_events.php (analytics event logging) and
 * admin/elearning/forum.php (discussion forums) were written against lms_events
 * and lms_forums tables that were never created in the live DB. This script
 * creates them to match what those files actually query.
 *
 *   - lms_events.Sid is VARCHAR(50) so it LEFT JOINs students.SID (varchar(50)).
 *   - module_id is a nullable INT in both (forum.php accepts a raw module id and
 *     events log an optional module); no FK, matching the loose usage.
 *
 * Re-runnable: every statement uses CREATE TABLE IF NOT EXISTS.
 *
 * Run:  E:\xampp\php\php.exe db\create_lms_engagement_tables.php
 */

require_once __DIR__ . '/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$statements = [];

$statements['lms_events'] = <<<SQL
CREATE TABLE IF NOT EXISTS lms_events (
  id BIGINT NOT NULL AUTO_INCREMENT,
  Sid VARCHAR(50) NULL,
  actor_role VARCHAR(40) NULL,
  course_code VARCHAR(64) NULL,
  module_id INT NULL,
  event_type VARCHAR(64) NOT NULL,
  event_data TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_student (Sid),
  KEY idx_events_type (event_type),
  KEY idx_events_created (created_at),
  KEY idx_events_course (course_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$statements['lms_forums'] = <<<SQL
CREATE TABLE IF NOT EXISTS lms_forums (
  id INT NOT NULL AUTO_INCREMENT,
  scope VARCHAR(24) NOT NULL DEFAULT 'course',
  course_code VARCHAR(64) NOT NULL,
  module_id INT NULL,
  title VARCHAR(255) NOT NULL,
  created_by VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_forums_course (course_code),
  KEY idx_forums_module (module_id)
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
