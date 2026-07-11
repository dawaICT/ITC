-- Legacy LMS modules table.
--
-- The admin e-learning pages admin/elearning/modules.php and
-- admin/elearning/upload.php read and write `lms_modules`, but the table was
-- never part of any migration (the canonical course-content system uses the
-- el_* tables). Under the DML-only app user a missing table surfaces as the
-- generic "We could not load this page" error, so create it here.
--
-- Run as wucportal_migrator (or another DDL-capable user); the application user
-- cannot create tables.

CREATE TABLE IF NOT EXISTS lms_modules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(64) NOT NULL,
  module_code VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  release_at DATETIME NULL,
  close_at DATETIME NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lms_module (course_code, module_code),
  KEY idx_lms_modules_course (course_code),
  KEY idx_lms_modules_published (is_published),
  KEY idx_lms_modules_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
