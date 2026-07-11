-- Migration: results status workflow + audit trail.
-- Apply as a user with ALTER/CREATE privileges (production: wucportal_migrator).
-- Idempotent on MariaDB (ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS).

ALTER TABLE semester_assessment
  ADD COLUMN IF NOT EXISTS submitted_by     VARCHAR(50)  NULL DEFAULT NULL AFTER posted_by,
  ADD COLUMN IF NOT EXISTS submitted_at     DATETIME     NULL DEFAULT NULL AFTER submitted_by,
  ADD COLUMN IF NOT EXISTS published_by     VARCHAR(80)  NULL DEFAULT NULL AFTER approved_at,
  ADD COLUMN IF NOT EXISTS published_at     DATETIME     NULL DEFAULT NULL AFTER published_by,
  ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(255) NULL DEFAULT NULL AFTER published_at;

CREATE TABLE IF NOT EXISTS result_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    assessment_id BIGINT UNSIGNED NULL,
    Sid VARCHAR(50) NOT NULL,
    Course_Code VARCHAR(50) NOT NULL,
    semester VARCHAR(20) NULL,
    Year VARCHAR(10) NULL,
    action VARCHAR(32) NOT NULL,
    field_changed VARCHAR(40) NULL,
    old_value VARCHAR(255) NULL,
    new_value VARCHAR(255) NULL,
    reason VARCHAR(255) NULL,
    actor_staff_id VARCHAR(50) NULL,
    actor_role VARCHAR(40) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_assessment (assessment_id),
    INDEX idx_audit_student (Sid, Course_Code, semester, Year),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: no per-table GRANT is required. The application user already holds
-- database-level DML (GRANT ... ON `wucportal`.* TO 'wucportal_app'@'127.0.0.1'),
-- which automatically covers this new table and the view below.
-- Do NOT grant to 'wucportal_app'@'localhost': on this setup that auto-creates a
-- passwordless account that shadows the real @127.0.0.1 account and breaks login.

-- Backward-compatible `exams` view: the legacy exams table never existed in this
-- database, but many report/dashboard pages still SELECT FROM exams. Writers have
-- been repointed to semester_assessment; this view exposes that canonical table
-- under the legacy name + column aliases so the readers return real data.
CREATE OR REPLACE VIEW exams AS
SELECT
    id, Sid, Course_Code,
    Exam AS Exam_marks,
    Exam AS Total_marks,
    Total_CA, A1, A2, A3, T1, T2,
    semester, Year, status,
    posted_by, submitted_by, approved_by, approved_at,
    published_by, published_at, rejection_reason,
    program_type, program_type AS programme_type,
    NULL AS assessment_date,
    created_at, updated_at
FROM semester_assessment;
