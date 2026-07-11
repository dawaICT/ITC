-- Store recorded-class metadata only; video bytes remain on the lecturer's Google Drive.

CREATE TABLE IF NOT EXISTS el_recorded_videos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  drive_file_id VARCHAR(255) NULL,
  drive_url VARCHAR(1000) NOT NULL,
  recorded_at DATETIME NULL,
  duration_minutes INT NULL,
  video_format VARCHAR(80) NULL,
  playback_provider VARCHAR(40) NOT NULL DEFAULT 'google_drive',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_el_recorded_course_published (course_code, is_published, recorded_at),
  KEY idx_el_recorded_created_by (created_by, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE el_recorded_videos
  ADD COLUMN IF NOT EXISTS video_format VARCHAR(80) NULL AFTER duration_minutes,
  ADD COLUMN IF NOT EXISTS playback_provider VARCHAR(40) NOT NULL DEFAULT 'google_drive' AFTER video_format;
