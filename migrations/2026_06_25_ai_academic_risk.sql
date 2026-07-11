-- AI-integrated LMS academic risk support tables.
-- Adapted for WUC Portal's existing string student/staff IDs and course_code keys.

CREATE TABLE IF NOT EXISTS student_risk_summary (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  course_code VARCHAR(64) NULL,
  program_code VARCHAR(20) NULL,
  department_id INT NULL,
  risk_score INT NOT NULL DEFAULT 0,
  risk_level ENUM('Low', 'Medium', 'High') NOT NULL DEFAULT 'Low',
  risk_reason TEXT NULL,
  recommended_action TEXT NULL,
  data_quality TEXT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_risk_student_generated (student_id, generated_at),
  KEY idx_risk_level_generated (risk_level, generated_at),
  KEY idx_risk_course (course_code),
  KEY idx_risk_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_alerts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  staff_id VARCHAR(50) NULL,
  course_code VARCHAR(64) NULL,
  department_id INT NULL,
  alert_category VARCHAR(100) NOT NULL,
  alert_message TEXT NOT NULL,
  alert_status ENUM('Unread', 'Read', 'In Progress', 'Resolved') NOT NULL DEFAULT 'Unread',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_alert_student_status (student_id, alert_status),
  KEY idx_alert_staff_status (staff_id, alert_status),
  KEY idx_alert_course (course_code),
  KEY idx_alert_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_interventions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  staff_id VARCHAR(50) NULL,
  course_code VARCHAR(64) NULL,
  department_id INT NULL,
  intervention_type VARCHAR(100) NOT NULL,
  intervention_note TEXT NULL,
  intervention_status ENUM('Pending', 'In Progress', 'Resolved', 'Escalated') NOT NULL DEFAULT 'Pending',
  follow_up_date DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_intervention_student_status (student_id, intervention_status),
  KEY idx_intervention_staff_status (staff_id, intervention_status),
  KEY idx_intervention_followup (follow_up_date),
  KEY idx_intervention_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_report_summaries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_type VARCHAR(100) NOT NULL,
  reference_id VARCHAR(100) NULL,
  summary_text TEXT NOT NULL,
  generated_by VARCHAR(100) NOT NULL DEFAULT 'System AI',
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report_type_generated (report_type, generated_at),
  KEY idx_report_reference (reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_threshold_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_name VARCHAR(100) NOT NULL,
  setting_value VARCHAR(100) NOT NULL,
  description TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ai_threshold_setting (setting_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_threshold_settings (setting_name, setting_value, description) VALUES
('attendance_threshold', '60', 'Attendance percentage below this value adds risk points.'),
('marks_threshold', '50', 'Average mark below this value adds academic performance risk.'),
('assignment_threshold', '50', 'Assignment submission percentage below this value adds risk points.'),
('inactive_days_threshold', '14', 'Days without eLearning activity before inactivity risk is added.'),
('course_progress_threshold', '50', 'Average course progress percentage below this value adds risk points.'),
('medium_risk_threshold', '40', 'Minimum total score for Medium risk.'),
('high_risk_threshold', '70', 'Minimum total score for High risk.')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  description = VALUES(description);
