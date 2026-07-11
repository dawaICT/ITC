-- Lecturer materials/eLearning storage schema.
-- Run once during deployment or maintenance, not from lecturers/materials.php.

CREATE TABLE IF NOT EXISTS lesson_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(64) NOT NULL,
  topic VARCHAR(255) NOT NULL,
  url VARCHAR(1000) NULL,
  dte DATE NULL,
  notes VARCHAR(255) NOT NULL,
  el_content_id INT NULL,
  el_version_id INT NULL,
  file_size BIGINT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lesson_notes_course (course_code),
  KEY idx_lesson_notes_date (dte),
  KEY idx_lesson_notes_el_content (el_content_id),
  KEY idx_lesson_notes_el_version (el_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE lesson_notes
  ADD COLUMN IF NOT EXISTS el_content_id INT NULL AFTER notes,
  ADD COLUMN IF NOT EXISTS el_version_id INT NULL AFTER el_content_id,
  ADD COLUMN IF NOT EXISTS file_size BIGINT NULL AFTER el_version_id,
  ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS el_course_modules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  release_at DATETIME NULL,
  close_at DATETIME NULL,
  position INT DEFAULT 0,
  created_by VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_el_modules_course_pos (course_code, position),
  INDEX idx_el_modules_release (release_at),
  INDEX idx_el_modules_close (close_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS el_contents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  module_id INT NOT NULL,
  content_type ENUM('video','pdf','docx','scorm','link') NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  mime_type VARCHAR(127) NULL,
  current_version_id INT NULL,
  captions_url VARCHAR(500) NULL,
  accessibility_meta TEXT NULL,
  storage_provider ENUM('local','s3','gcs','azure','external') DEFAULT 'local',
  created_by VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_el_contents_module (module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS el_content_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  content_id INT NOT NULL,
  version_no INT NOT NULL,
  file_path VARCHAR(1000) NULL,
  file_size BIGINT NULL,
  checksum_sha256 CHAR(64) NULL,
  notes VARCHAR(500) NULL,
  created_by VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_content_version (content_id, version_no),
  INDEX idx_el_versions_content (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
