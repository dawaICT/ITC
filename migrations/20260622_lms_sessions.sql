-- Legacy LMS sessions table (recorded / internal virtual-classroom sessions).
--
-- admin/elearning/sessions.php is a hybrid page: live Google Meet sessions use
-- the canonical el_live_sessions table, while recorded video-upload sessions
-- "remain on the legacy lms_sessions table" (see the comment at sessions.php).
-- That table was never created by a migration, so the page (and its hourly
-- cleanup include cron_cleanup_videos.php) died with
--   "Table 'wucportal.lms_sessions' doesn't exist".
--
-- The column set below is the union of every lms_sessions reference across the
-- e-learning module: sessions.php, cron_cleanup_videos.php (video_uploaded_at,
-- updated_at), ajax_upload_handler.php (video_* + status), and
-- manage_session.php (actual_*_time, recording_*, google_meet_id,
-- provider_meeting_id). It supersedes admin/elearning/migrations/
-- add_video_uploaded_at.sql (video_uploaded_at is included here).
--
-- Run as wucportal_migrator (or another DDL-capable user); the application user
-- cannot create tables.

CREATE TABLE IF NOT EXISTS lms_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(64) NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'internal',
  session_type VARCHAR(32) NOT NULL DEFAULT 'internal',
  topic VARCHAR(255) NOT NULL,
  start_time DATETIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 60,
  access_requires_payment TINYINT(1) NOT NULL DEFAULT 0,
  min_payment_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
  video_file_path VARCHAR(500) NULL,
  video_file_size BIGINT NULL,
  video_format VARCHAR(20) NULL,
  video_uploaded_at DATETIME NULL,
  recording_url VARCHAR(500) NULL,
  recording_uploaded_at DATETIME NULL,
  actual_start_time DATETIME NULL,
  actual_end_time DATETIME NULL,
  google_meet_id VARCHAR(64) NULL,
  provider_meeting_id VARCHAR(64) NULL,
  created_by VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lms_sessions_course (course_code),
  KEY idx_lms_sessions_status (status),
  KEY idx_lms_sessions_created_by (created_by),
  KEY idx_lms_sessions_start (start_time),
  KEY idx_lms_sessions_dup (course_code, session_type, start_time),
  KEY idx_video_cleanup (video_uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
