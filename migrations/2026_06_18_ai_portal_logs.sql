-- AI portal logging for role-aware assistant features.
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS ai_portal_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_role VARCHAR(40) NOT NULL,
    user_id VARCHAR(80) NOT NULL,
    feature VARCHAR(80) NOT NULL,
    action VARCHAR(80) NOT NULL DEFAULT 'generate',
    input_summary TEXT NULL,
    context_hash CHAR(64) NULL,
    model VARCHAR(120) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'unknown',
    response_excerpt TEXT NULL,
    error_message TEXT NULL,
    duration_ms INT UNSIGNED NULL,
    ip_hash CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_portal_user (user_role, user_id, created_at),
    KEY idx_ai_portal_feature (feature, created_at),
    KEY idx_ai_portal_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

