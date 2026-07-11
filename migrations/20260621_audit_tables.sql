CREATE TABLE IF NOT EXISTS login_activity (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(80) NOT NULL,
    user_type VARCHAR(20) NOT NULL DEFAULT 'student',
    user_name VARCHAR(160) NOT NULL DEFAULT '',
    ip_address VARCHAR(45) NOT NULL DEFAULT '0.0.0.0',
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_activity_user (user_id, user_type, login_at),
    INDEX idx_login_activity_time (login_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '0.0.0.0',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_audit_staff (staff_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(80) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user_action (user_id, action, created_at),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
