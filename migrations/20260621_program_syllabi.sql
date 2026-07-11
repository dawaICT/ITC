CREATE TABLE IF NOT EXISTS program_syllabi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(20) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    uploaded_by VARCHAR(80) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_program_syllabus_version (program_code, version),
    INDEX idx_program_syllabi_program (program_code),
    CONSTRAINT fk_program_syllabi_program
        FOREIGN KEY (program_code) REFERENCES programs(program_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE program_syllabi
    ADD COLUMN IF NOT EXISTS original_name VARCHAR(255) NOT NULL DEFAULT '' AFTER filename,
    ADD COLUMN IF NOT EXISTS mime_type VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream' AFTER original_name,
    ADD COLUMN IF NOT EXISTS file_size BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER mime_type,
    ADD COLUMN IF NOT EXISTS uploaded_by VARCHAR(80) NOT NULL DEFAULT 'legacy' AFTER version;
