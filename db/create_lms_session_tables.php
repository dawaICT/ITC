<?php
/**
 * Migration: create the lms_sessions internal-video / Google-Meet session tables.
 *
 * The admin/elearning "sessions" feature (sessions.php, manage_session.php,
 * session_analytics.php, cron_cleanup_videos.php, api/session_attendance_poll.php,
 * includes/google_meet_integration.php, includes/payment_verification.php) was
 * written against an lms_sessions schema that was never created in the live DB —
 * only ALTER-based migration fragments existed. This script creates the base
 * tables to match what that code actually queries.
 *
 * Column names follow the live PHP consumers, NOT the stale .sql fragments:
 *   - student_id is VARCHAR(50) so it joins students.SID (varchar(50)), not INT.
 *   - link timestamp column is generated_at (manage_session.php), not created_at.
 *   - access-attempt timestamp column is attempt_time (manage_session.php), not timestamp.
 *
 * Re-runnable: every statement uses CREATE TABLE IF NOT EXISTS.
 *
 * Run:  E:\xampp\php\php.exe db\create_lms_session_tables.php
 */

require_once __DIR__ . '/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$statements = [];

$statements['lms_sessions'] = <<<SQL
CREATE TABLE IF NOT EXISTS lms_sessions (
  id INT NOT NULL AUTO_INCREMENT,
  course_code VARCHAR(64) NOT NULL,
  provider VARCHAR(40) NOT NULL DEFAULT 'google_meet',
  session_type VARCHAR(24) NOT NULL DEFAULT 'external',
  topic VARCHAR(255) NOT NULL,
  start_time DATETIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 60,
  status VARCHAR(24) NOT NULL DEFAULT 'scheduled',
  access_requires_payment TINYINT(1) NOT NULL DEFAULT 1,
  min_payment_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  link_expiry_minutes INT DEFAULT 120,
  allow_link_sharing TINYINT(1) NOT NULL DEFAULT 0,
  max_participants INT DEFAULT 100,
  waiting_room_enabled TINYINT(1) NOT NULL DEFAULT 0,
  recording_enabled TINYINT(1) NOT NULL DEFAULT 0,
  google_meet_id VARCHAR(255) NULL,
  provider_meeting_id VARCHAR(128) NULL,
  join_url VARCHAR(1000) NULL,
  start_url VARCHAR(1000) NULL,
  recording_url VARCHAR(1000) NULL,
  recording_uploaded_at DATETIME NULL,
  video_file_path VARCHAR(500) NULL,
  video_file_size BIGINT NULL,
  video_format VARCHAR(50) NULL,
  video_uploaded_at DATETIME NULL,
  actual_start_time DATETIME NULL,
  actual_end_time DATETIME NULL,
  created_by VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created_by (created_by),
  KEY idx_start_time (start_time),
  KEY idx_provider (provider),
  KEY idx_status (status),
  KEY idx_course_code (course_code),
  KEY idx_video_cleanup (video_uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$statements['lms_student_meeting_links'] = <<<SQL
CREATE TABLE IF NOT EXISTS lms_student_meeting_links (
  id INT NOT NULL AUTO_INCREMENT,
  session_id INT NOT NULL,
  student_id VARCHAR(50) NOT NULL,
  meeting_link VARCHAR(1000) NOT NULL,
  meeting_code VARCHAR(128) NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  is_used TINYINT(1) NOT NULL DEFAULT 0,
  used_at DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  is_revoked TINYINT(1) NOT NULL DEFAULT 0,
  revoked_at DATETIME NULL,
  revoked_by VARCHAR(50) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_session_student (session_id, student_id),
  KEY idx_student_links (student_id, session_id),
  KEY idx_expiry (expires_at),
  KEY idx_meeting_code (meeting_code),
  CONSTRAINT fk_student_links_session FOREIGN KEY (session_id)
    REFERENCES lms_sessions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$statements['lms_meeting_access_attempts'] = <<<SQL
CREATE TABLE IF NOT EXISTS lms_meeting_access_attempts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  session_id INT NOT NULL,
  student_id VARCHAR(50) NULL,
  meeting_link_id INT NULL,
  attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  access_granted TINYINT(1) NOT NULL DEFAULT 0,
  denial_reason VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_session_attempts (session_id, attempt_time),
  KEY idx_student_attempts (student_id, attempt_time),
  KEY idx_access_granted (access_granted, attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$statements['lms_waiting_room_approvals'] = <<<SQL
CREATE TABLE IF NOT EXISTS lms_waiting_room_approvals (
  id INT NOT NULL AUTO_INCREMENT,
  session_id INT NOT NULL,
  student_id VARCHAR(50) NOT NULL,
  approved_by VARCHAR(50) NULL,
  approved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_approval (session_id, student_id),
  KEY idx_session_approvals (session_id),
  KEY idx_student_approvals (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$statements['lms_session_access_log'] = <<<SQL
CREATE TABLE IF NOT EXISTS lms_session_access_log (
  id INT NOT NULL AUTO_INCREMENT,
  session_id INT NOT NULL,
  student_id VARCHAR(50) NOT NULL,
  access_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  access_granted TINYINT(1) NOT NULL DEFAULT 0,
  denial_reason VARCHAR(255) NULL,
  payment_percentage DECIMAL(5,2) NULL,
  PRIMARY KEY (id),
  KEY idx_session_access (session_id, student_id),
  KEY idx_student_access (student_id, access_time)
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
