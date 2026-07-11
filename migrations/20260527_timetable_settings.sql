CREATE TABLE IF NOT EXISTS course_schedule (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(64) NOT NULL,
    lecturer_id VARCHAR(64) NULL,
    schedule_type VARCHAR(40) NOT NULL DEFAULT 'lecture',
    title VARCHAR(160) NULL,
    day VARCHAR(20) NULL,
    day_of_week VARCHAR(20) NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    room VARCHAR(80) NULL,
    semester TINYINT UNSIGNED NULL,
    `Year` SMALLINT UNSIGNED NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    created_by VARCHAR(64) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_course_schedule_course (course_code),
    INDEX idx_course_schedule_lecturer (lecturer_id),
    INDEX idx_course_schedule_period (`Year`, semester),
    INDEX idx_course_schedule_day (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE course_schedule ADD COLUMN IF NOT EXISTS schedule_type VARCHAR(40) NOT NULL DEFAULT 'lecture' AFTER lecturer_id;
ALTER TABLE course_schedule ADD COLUMN IF NOT EXISTS created_by VARCHAR(64) NULL AFTER status;
ALTER TABLE course_schedule ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
