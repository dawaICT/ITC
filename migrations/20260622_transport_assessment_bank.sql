-- Persistent store for AI Assessment Bank question sets.
-- Lets generated/edited TEVETA assessment questions be saved, reused, edited and
-- exported instead of being discarded on page reload. Apply as wucportal_migrator.
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS transport_assessment_bank (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    outcome_id INT NULL,
    title VARCHAR(200) NOT NULL,
    qtype ENUM('mixed','mcq','short_answer','scenario') NOT NULL DEFAULT 'mixed',
    question_count INT NOT NULL DEFAULT 0,
    content MEDIUMTEXT NOT NULL,
    source ENUM('ai','fallback','manual') NOT NULL DEFAULT 'ai',
    model VARCHAR(120) NULL,
    created_by VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tab_program (program_id),
    KEY idx_tab_outcome (outcome_id),
    KEY idx_tab_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
