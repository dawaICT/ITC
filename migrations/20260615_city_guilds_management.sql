-- City & Guilds learner management module.
-- Safe to run multiple times. Rollback is manual: drop these tables only after
-- confirming City & Guilds operational records have been exported or backed up.

CREATE TABLE IF NOT EXISTS city_guilds_learners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    SID VARCHAR(50) NOT NULL,
    qualification_code VARCHAR(80) NOT NULL,
    cohort VARCHAR(80) NOT NULL,
    candidate_number VARCHAR(80) NOT NULL,
    progress_status VARCHAR(100) NOT NULL DEFAULT 'Enrolled',
    status ENUM('active','completed','withdrawn') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_by VARCHAR(64) NULL,
    updated_by VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cg_candidate_number (candidate_number),
    UNIQUE KEY uq_cg_student_qualification_cohort (SID, qualification_code, cohort),
    KEY idx_cg_learners_sid (SID),
    KEY idx_cg_learners_cohort (cohort),
    KEY idx_cg_learners_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_guilds_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    qualification_code VARCHAR(80) NOT NULL,
    unit_code VARCHAR(80) NOT NULL,
    unit_title VARCHAR(190) NOT NULL,
    syllabus_reference VARCHAR(190) NULL,
    created_by VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cg_unit (qualification_code, unit_code),
    KEY idx_cg_units_qualification (qualification_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_guilds_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    learner_id INT NOT NULL,
    unit_id INT NULL,
    instructor_id VARCHAR(64) NOT NULL,
    schedule_start DATETIME NULL,
    schedule_end DATETIME NULL,
    location VARCHAR(150) NULL,
    assignment_status ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    notes TEXT NULL,
    created_by VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cg_assignments_learner (learner_id),
    KEY idx_cg_assignments_unit (unit_id),
    KEY idx_cg_assignments_instructor (instructor_id),
    KEY idx_cg_assignments_schedule (schedule_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_guilds_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    learner_id INT NOT NULL,
    assignment_id INT NULL,
    title VARCHAR(190) NOT NULL,
    assessment_type ENUM('FORMATIVE','SUMMATIVE') NOT NULL,
    assessment_mode ENUM('practical','written','portfolio','other') NOT NULL DEFAULT 'other',
    date_assigned DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_due DATETIME NULL,
    grade VARCHAR(50) NULL,
    verifier_id VARCHAR(64) NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    notes TEXT NULL,
    created_by VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cg_assessments_learner (learner_id),
    KEY idx_cg_assessments_assignment (assignment_id),
    KEY idx_cg_assessments_due (date_due),
    KEY idx_cg_assessments_verified (is_verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_guilds_support_interventions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    learner_id INT NOT NULL,
    intervention_type VARCHAR(120) NOT NULL,
    support_date DATE NOT NULL,
    notes TEXT NOT NULL,
    follow_up_date DATE NULL,
    recorded_by VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cg_support_learner (learner_id),
    KEY idx_cg_support_follow_up (follow_up_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_guilds_qa_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_type ENUM('tutor_qualification','assessment_plan','iqa_report','external_quality_assurance','other') NOT NULL,
    title VARCHAR(190) NOT NULL,
    staff_id VARCHAR(64) NULL,
    evidence_date DATE NULL,
    file_reference VARCHAR(255) NULL,
    notes TEXT NULL,
    created_by VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cg_qa_type (record_type),
    KEY idx_cg_qa_staff (staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_guilds_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(64) NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id VARCHAR(80) NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cg_audit_staff (staff_id),
    KEY idx_cg_audit_entity (entity_type, entity_id),
    KEY idx_cg_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
