CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(80) NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ip_time (ip_address, attempt_time),
    INDEX idx_login_attempts_user_time (user_id, attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_payments_student_status_date
    ON payments (student_id, status, payment_date);
CREATE INDEX IF NOT EXISTS idx_fee_structure_program_period
    ON fee_structure (program_code, year_of_study, semester, status);
CREATE INDEX IF NOT EXISTS idx_student_program_student_status
    ON student_program (Sid, status);
CREATE INDEX IF NOT EXISTS idx_semester_registration_student_year_semester
    ON semester_registration (student_id, academic_year, semester);
