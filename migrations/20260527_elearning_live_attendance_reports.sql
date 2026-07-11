-- Online class attendance/report support.
-- Safe to run multiple times on MySQL/MariaDB.

CREATE TABLE IF NOT EXISTS el_attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    actor_type VARCHAR(20) NOT NULL,
    actor_id VARCHAR(64) NOT NULL,
    join_time DATETIME NOT NULL,
    leave_time DATETIME NULL,
    duration_secs INT NULL,
    KEY idx_el_attendance_session (session_id),
    KEY idx_el_attendance_actor (actor_id),
    KEY idx_el_attendance_join (join_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM el_live_session_links WHERE expires_at <= NOW();
