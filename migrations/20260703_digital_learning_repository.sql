CREATE TABLE IF NOT EXISTS repository_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS repository_materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    material_type VARCHAR(50) NOT NULL,
    upload_mode ENUM('file','link') NOT NULL DEFAULT 'file',
    file_path VARCHAR(500) NULL,
    external_url VARCHAR(700) NULL,
    original_filename VARCHAR(255) NULL,
    mime_type VARCHAR(150) NULL,
    file_size BIGINT NULL,
    checksum_sha256 CHAR(64) NULL,
    uploader_staff_id VARCHAR(50) NULL,
    department_id VARCHAR(80) NULL,
    programme_code VARCHAR(80) NULL,
    course_code VARCHAR(80) NULL,
    academic_year VARCHAR(20) NULL,
    year_of_study INT NULL,
    term VARCHAR(30) NULL,
    semester VARCHAR(30) NULL,
    requested_visibility VARCHAR(40) NOT NULL DEFAULT 'course',
    visibility VARCHAR(40) NOT NULL DEFAULT 'course',
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    rejection_reason TEXT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    is_public_summary TINYINT(1) NOT NULL DEFAULT 0,
    approved_by VARCHAR(50) NULL,
    reviewed_at DATETIME NULL,
    ai_status VARCHAR(30) NOT NULL DEFAULT 'not_processed',
    ai_error TEXT NULL,
    view_count INT NOT NULL DEFAULT 0,
    download_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_repo_status (status, is_published, is_archived),
    INDEX idx_repo_scope (course_code, programme_code, department_id),
    INDEX idx_repo_uploader (uploader_staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS repository_material_categories (
    material_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (material_id, category_id),
    INDEX idx_repo_mc_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS repository_access_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    material_id INT NOT NULL,
    action VARCHAR(30) NOT NULL,
    user_role VARCHAR(30) NOT NULL,
    user_id VARCHAR(80) NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_repo_log_material (material_id, action),
    INDEX idx_repo_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS repository_ai_metadata (
    material_id INT PRIMARY KEY,
    summary MEDIUMTEXT NULL,
    keywords TEXT NULL,
    topics TEXT NULL,
    study_guide MEDIUMTEXT NULL,
    difficulty_level VARCHAR(50) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    generated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS repository_ai_questions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    material_id INT NOT NULL,
    question_type VARCHAR(40) NOT NULL DEFAULT 'revision',
    question_text TEXT NOT NULL,
    answer_text TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_repo_aiq_material (material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS repository_ai_search_index (
    material_id INT PRIMARY KEY,
    keywords_text MEDIUMTEXT NULL,
    embedding_json MEDIUMTEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO repository_categories (name) VALUES
('Lecture notes'),
('Past papers'),
('Question banks'),
('Assignments'),
('Practical manuals'),
('Slides'),
('Videos'),
('External links'),
('Research materials'),
('Policies'),
('Other academic resources');
