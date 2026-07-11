-- WUCPortal Teaching Planner (additive, idempotent)
-- Target: MariaDB 10.4+, utf8mb4. Existing academic, staff, notification and
-- audit tables remain canonical and are referenced from this module.

CREATE TABLE IF NOT EXISTS document_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    document_type ENUM('scheme_of_work','lesson_plan','practical_lesson_plan','assessment_plan') NOT NULL,
    department_id INT NULL,
    program_type VARCHAR(60) NULL,
    structure_type ENUM('term','semester','annual','short_course') NOT NULL,
    status ENUM('draft','active','superseded','archived') NOT NULL DEFAULT 'draft',
    created_by VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tp_template_scope (document_type, department_id, program_type, structure_type, status),
    CONSTRAINT fk_tp_template_department FOREIGN KEY (department_id) REFERENCES departments(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tp_template_creator FOREIGN KEY (created_by) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_template_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    effective_date DATE NOT NULL,
    status ENUM('draft','active','superseded','archived') NOT NULL DEFAULT 'draft',
    original_filename VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    placeholder_map LONGTEXT NULL,
    validation_report LONGTEXT NULL,
    uploaded_by VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tp_template_version (template_id, version_number),
    UNIQUE KEY uq_tp_template_checksum (template_id, checksum_sha256),
    KEY idx_tp_template_version_status (template_id, status, effective_date),
    CONSTRAINT fk_tp_version_template FOREIGN KEY (template_id) REFERENCES document_templates(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_version_uploader FOREIGN KEY (uploaded_by) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS syllabus_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    curriculum_version_id INT NULL,
    program_code VARCHAR(20) NOT NULL,
    course_code VARCHAR(20) NOT NULL,
    version_label VARCHAR(120) NOT NULL,
    purpose TEXT NULL,
    credits DECIMAL(6,2) NULL,
    total_recommended_hours DECIMAL(7,2) NOT NULL,
    assessment_criteria TEXT NULL,
    resources TEXT NULL,
    source_type ENUM('manual','word_import','pdf_import','excel_import','ai_extraction') NOT NULL DEFAULT 'manual',
    status ENUM('draft','under_review','approved','superseded','archived') NOT NULL DEFAULT 'draft',
    created_by VARCHAR(20) NOT NULL,
    approved_by VARCHAR(20) NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tp_syllabus_version (program_code, course_code, version_label),
    KEY idx_tp_syllabus_approval (program_code, course_code, status),
    CONSTRAINT fk_tp_syllabus_curriculum FOREIGN KEY (curriculum_version_id) REFERENCES curriculum_versions(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tp_syllabus_program FOREIGN KEY (program_code) REFERENCES programs(program_code) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_syllabus_course FOREIGN KEY (course_code) REFERENCES courses(course_code) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_syllabus_creator FOREIGN KEY (created_by) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_syllabus_approver FOREIGN KEY (approved_by) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_tp_syllabus_hours CHECK (total_recommended_hours > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS syllabus_outcomes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    syllabus_version_id BIGINT UNSIGNED NOT NULL,
    outcome_code VARCHAR(40) NULL,
    outcome_text TEXT NOT NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_tp_outcome_syllabus (syllabus_version_id, display_order),
    CONSTRAINT fk_tp_outcome_syllabus FOREIGN KEY (syllabus_version_id) REFERENCES syllabus_versions(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS syllabus_topics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    syllabus_version_id BIGINT UNSIGNED NOT NULL,
    parent_topic_id BIGINT UNSIGNED NULL,
    topic_code VARCHAR(40) NULL,
    topic_title VARCHAR(255) NOT NULL,
    subtopics TEXT NULL,
    recommended_hours DECIMAL(7,2) NOT NULL,
    prerequisite_topic_id BIGINT UNSIGNED NULL,
    learning_outcomes TEXT NOT NULL,
    assessment_criteria TEXT NULL,
    resources TEXT NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_tp_topic_syllabus (syllabus_version_id, display_order),
    KEY idx_tp_topic_prereq (prerequisite_topic_id),
    CONSTRAINT fk_tp_topic_syllabus FOREIGN KEY (syllabus_version_id) REFERENCES syllabus_versions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_tp_topic_parent FOREIGN KEY (parent_topic_id) REFERENCES syllabus_topics(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tp_topic_prereq FOREIGN KEY (prerequisite_topic_id) REFERENCES syllabus_topics(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_tp_topic_hours CHECK (recommended_hours > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teaching_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_plan_id BIGINT UNSIGNED NULL,
    revision_number INT UNSIGNED NOT NULL DEFAULT 1,
    document_number VARCHAR(80) NOT NULL,
    lecturer_assignment_id INT NOT NULL,
    course_offering_id INT NOT NULL,
    lecturer_staff_id VARCHAR(20) NOT NULL,
    program_code VARCHAR(20) NOT NULL,
    class_group_id INT NULL,
    academic_year VARCHAR(20) NOT NULL,
    academic_period VARCHAR(40) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    template_version_id BIGINT UNSIGNED NOT NULL,
    syllabus_version_id BIGINT UNSIGNED NOT NULL,
    generation_settings LONGTEXT NOT NULL,
    status ENUM('draft','submitted','changes_requested','resubmitted','approved','in_use','archived') NOT NULL DEFAULT 'draft',
    version_lock INT UNSIGNED NOT NULL DEFAULT 1,
    coverage_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
    planned_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
    available_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
    warnings LONGTEXT NULL,
    created_by VARCHAR(20) NOT NULL,
    approved_by VARCHAR(20) NULL,
    submitted_at TIMESTAMP NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tp_document_number (document_number),
    UNIQUE KEY uq_tp_plan_revision (lecturer_staff_id, course_offering_id, academic_year, academic_period, revision_number),
    KEY idx_tp_plan_workflow (status, program_code, academic_year, academic_period),
    KEY idx_tp_plan_lecturer (lecturer_staff_id, status),
    CONSTRAINT fk_tp_plan_parent FOREIGN KEY (parent_plan_id) REFERENCES teaching_plans(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_plan_assignment FOREIGN KEY (lecturer_assignment_id) REFERENCES lecturer_course_assignments(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_plan_offering FOREIGN KEY (course_offering_id) REFERENCES course_offerings(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_plan_lecturer FOREIGN KEY (lecturer_staff_id) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_plan_program FOREIGN KEY (program_code) REFERENCES programs(program_code) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_plan_template FOREIGN KEY (template_version_id) REFERENCES document_template_versions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_plan_syllabus FOREIGN KEY (syllabus_version_id) REFERENCES syllabus_versions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_plan_creator FOREIGN KEY (created_by) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_plan_approver FOREIGN KEY (approved_by) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_tp_plan_dates CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teaching_plan_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    teaching_plan_id BIGINT UNSIGNED NOT NULL,
    syllabus_topic_id BIGINT UNSIGNED NULL,
    item_type ENUM('lesson','practical','assessment','revision','institutional_event') NOT NULL DEFAULT 'lesson',
    sequence_number INT UNSIGNED NOT NULL,
    week_number INT UNSIGNED NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    topic VARCHAR(255) NOT NULL,
    subtopics TEXT NULL,
    learning_outcomes TEXT NULL,
    teaching_methods TEXT NULL,
    lecturer_activities TEXT NULL,
    learner_activities TEXT NULL,
    resources TEXT NULL,
    assessment_method TEXT NULL,
    references_text TEXT NULL,
    duration_minutes INT UNSIGNED NOT NULL,
    remarks TEXT NULL,
    status ENUM('planned','completed','locked','cancelled') NOT NULL DEFAULT 'planned',
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    lock_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tp_plan_sequence (teaching_plan_id, sequence_number),
    KEY idx_tp_plan_date (teaching_plan_id, session_date),
    KEY idx_tp_plan_topic (syllabus_topic_id),
    CONSTRAINT fk_tp_item_plan FOREIGN KEY (teaching_plan_id) REFERENCES teaching_plans(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_tp_item_topic FOREIGN KEY (syllabus_topic_id) REFERENCES syllabus_topics(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_tp_item_duration CHECK (duration_minutes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    teaching_plan_item_id BIGINT UNSIGNED NOT NULL,
    template_version_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL DEFAULT 1,
    prior_knowledge TEXT NULL,
    introduction_text TEXT NULL,
    conclusion_text TEXT NULL,
    homework_text TEXT NULL,
    reflection_text TEXT NULL,
    status ENUM('draft','submitted','changes_requested','approved','archived') NOT NULL DEFAULT 'draft',
    version_lock INT UNSIGNED NOT NULL DEFAULT 1,
    created_by VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tp_lesson_revision (teaching_plan_item_id, revision_number),
    CONSTRAINT fk_tp_lesson_item FOREIGN KEY (teaching_plan_item_id) REFERENCES teaching_plan_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_lesson_template FOREIGN KEY (template_version_id) REFERENCES document_template_versions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tp_lesson_creator FOREIGN KEY (created_by) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_plan_stages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lesson_plan_id BIGINT UNSIGNED NOT NULL,
    stage_name VARCHAR(120) NOT NULL,
    lecturer_activity TEXT NULL,
    learner_activity TEXT NULL,
    method_resources TEXT NULL,
    formative_assessment TEXT NULL,
    duration_minutes INT UNSIGNED NOT NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_tp_lesson_stage (lesson_plan_id, display_order),
    CONSTRAINT fk_tp_stage_lesson FOREIGN KEY (lesson_plan_id) REFERENCES lesson_plans(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_tp_stage_duration CHECK (duration_minutes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teaching_plan_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    teaching_plan_id BIGINT UNSIGNED NOT NULL,
    action ENUM('submitted','resubmitted','changes_requested','approved','marked_in_use','archived') NOT NULL,
    from_status VARCHAR(30) NOT NULL,
    to_status VARCHAR(30) NOT NULL,
    comment TEXT NULL,
    actor_staff_id VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tp_approval_plan (teaching_plan_id, created_at),
    CONSTRAINT fk_tp_approval_plan FOREIGN KEY (teaching_plan_id) REFERENCES teaching_plans(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_tp_approval_actor FOREIGN KEY (actor_staff_id) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teaching_plan_exports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    teaching_plan_id BIGINT UNSIGNED NOT NULL,
    export_format ENUM('docx','pdf') NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    is_draft_watermarked TINYINT(1) NOT NULL DEFAULT 0,
    exported_by VARCHAR(20) NOT NULL,
    exported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tp_export_plan (teaching_plan_id, exported_at),
    CONSTRAINT fk_tp_export_plan FOREIGN KEY (teaching_plan_id) REFERENCES teaching_plans(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_tp_export_actor FOREIGN KEY (exported_by) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teaching_plan_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    teaching_plan_id BIGINT UNSIGNED NULL,
    actor_staff_id VARCHAR(20) NOT NULL,
    action VARCHAR(100) NOT NULL,
    record_type VARCHAR(60) NOT NULL,
    record_id VARCHAR(80) NULL,
    change_summary LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tp_audit_plan (teaching_plan_id, created_at),
    KEY idx_tp_audit_actor (actor_staff_id, created_at),
    CONSTRAINT fk_tp_audit_plan FOREIGN KEY (teaching_plan_id) REFERENCES teaching_plans(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tp_audit_actor FOREIGN KEY (actor_staff_id) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teaching_planner_settings (
    setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
    setting_value LONGTEXT NOT NULL,
    updated_by VARCHAR(20) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tp_setting_actor FOREIGN KEY (updated_by) REFERENCES staff(staff_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO teaching_planner_settings (setting_key, setting_value)
VALUES
 ('storage_root', 'storage/teaching_planner'),
 ('max_template_bytes', '10485760'),
 ('assessment_weeks', '[]'),
 ('revision_weeks', '[]')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
