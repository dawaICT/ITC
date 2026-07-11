CREATE TABLE IF NOT EXISTS ai_skill_taxonomy (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    skill_code VARCHAR(120) NOT NULL,
    preferred_label VARCHAR(255) NOT NULL,
    alt_labels TEXT NULL,
    description TEXT NULL,
    skill_group VARCHAR(120) NULL,
    skill_type VARCHAR(40) NULL,
    embedding LONGTEXT NULL,
    embed_model VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_skill_code (skill_code),
    KEY idx_label (preferred_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
