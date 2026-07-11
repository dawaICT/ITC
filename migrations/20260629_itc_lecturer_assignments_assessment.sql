-- ============================================================================
-- ITC Academic Data Structure — Phase 3: Lecturer Assignments and CA
-- Spec: E:\ITC_Academic_Data_Structure_Goal.md  (sections 13, 15, 16, 17)
--
-- Strategy: REUSE & EXTEND. The canonical results store `semester_assessment`
-- (Sid+Course_Code, grading_helpers.php, Draft->Submitted->Approved->Published)
-- is LEFT UNTOUCHED for existing pages. This phase adds the spec's normalized,
-- OFFERING-based model alongside it:
--   lecturer_course_assignments  -> staff bound to a course_offering (not a code)
--   assessment_schemes/components -> configurable CA/EXAM weighting
--   student_assessment_marks      -> per-component marks w/ approval workflow
--   student_course_results        -> computed final mark + grade per registration
-- Grade letters reuse the canonical scale in includes/grading_helpers.php
-- (wuc_result_grade: A>=80 B>=70 C>=60 D>=50 else F).
--
-- Idempotent. MariaDB 10.4 (CHECK constraints enforced). Run as migration user.
-- ============================================================================

-- ─── 1) lecturer_course_assignments (NEW, offering-based) ────────────────────
--     Legacy course_lecturer (course_code based) is kept for existing code.
CREATE TABLE IF NOT EXISTS lecturer_course_assignments (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    staff_id           VARCHAR(20) NOT NULL,
    course_offering_id INT         NOT NULL,
    assignment_role    ENUM('Main Lecturer','Assistant Lecturer','Practical Instructor','Examiner')
                       NOT NULL DEFAULT 'Main Lecturer',
    assigned_from      DATE        NULL,
    assigned_to        DATE        NULL,
    status             ENUM('active','inactive','ended') NOT NULL DEFAULT 'active',
    created_at         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lca (staff_id, course_offering_id, assignment_role),
    KEY idx_lca_staff (staff_id),
    KEY idx_lca_offering (course_offering_id),
    CONSTRAINT fk_lca_staff    FOREIGN KEY (staff_id)           REFERENCES staff (staff_id)        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_lca_offering FOREIGN KEY (course_offering_id) REFERENCES course_offerings (id)   ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 2) assessment_schemes (NEW) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS assessment_schemes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(20)  NOT NULL,
    course_code  VARCHAR(20)  NOT NULL,
    scheme_name  VARCHAR(120) NOT NULL,
    ca_weight    DECIMAL(5,2) NOT NULL DEFAULT 0,
    exam_weight  DECIMAL(5,2) NOT NULL DEFAULT 0,
    pass_mark    DECIMAL(5,2) NOT NULL DEFAULT 50,
    status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scheme (program_code, course_code, scheme_name),
    KEY idx_scheme_program (program_code),
    KEY idx_scheme_course (course_code),
    CONSTRAINT fk_scheme_program FOREIGN KEY (program_code) REFERENCES programs (program_code) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_scheme_course  FOREIGN KEY (course_code)  REFERENCES courses (course_code)   ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_scheme_weights CHECK (ca_weight >= 0 AND exam_weight >= 0 AND (ca_weight + exam_weight) <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 3) assessment_components (NEW) ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS assessment_components (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    assessment_scheme_id INT          NOT NULL,
    component_name       VARCHAR(120) NOT NULL,
    component_type       ENUM('CA','PRACTICAL','EXAM','PROJECT','ATTENDANCE') NOT NULL,
    weight               DECIMAL(5,2) NOT NULL DEFAULT 0,
    max_mark             DECIMAL(6,2) NOT NULL DEFAULT 100,
    display_order        INT          NOT NULL DEFAULT 0,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_component_scheme (assessment_scheme_id),
    CONSTRAINT fk_component_scheme FOREIGN KEY (assessment_scheme_id) REFERENCES assessment_schemes (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_component_weight CHECK (weight >= 0 AND weight <= 100 AND max_mark > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 4) student_assessment_marks (NEW) — per-component CA upload ─────────────
CREATE TABLE IF NOT EXISTS student_assessment_marks (
    id                            INT AUTO_INCREMENT PRIMARY KEY,
    student_course_registration_id INT          NOT NULL,
    assessment_component_id       INT          NOT NULL,
    mark_obtained                 DECIMAL(6,2) NULL,
    max_mark                      DECIMAL(6,2) NOT NULL DEFAULT 100,
    uploaded_by                   VARCHAR(50)  NULL,
    approved_by                   VARCHAR(50)  NULL,
    status                        ENUM('DRAFT','SUBMITTED','APPROVED_BY_HOD','LOCKED','RETURNED_FOR_CORRECTION')
                                  NOT NULL DEFAULT 'DRAFT',
    uploaded_at                   TIMESTAMP    NULL,
    approved_at                   TIMESTAMP    NULL,
    created_at                    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mark (student_course_registration_id, assessment_component_id),
    KEY idx_mark_reg (student_course_registration_id),
    KEY idx_mark_component (assessment_component_id),
    CONSTRAINT fk_mark_reg       FOREIGN KEY (student_course_registration_id) REFERENCES student_course_registrations (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mark_component FOREIGN KEY (assessment_component_id)        REFERENCES assessment_components (id)        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_mark_range CHECK (mark_obtained IS NULL OR (mark_obtained >= 0 AND mark_obtained <= max_mark))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 5) student_course_results (NEW) — computed final + grade per registration
CREATE TABLE IF NOT EXISTS student_course_results (
    id                            INT AUTO_INCREMENT PRIMARY KEY,
    student_course_registration_id INT          NOT NULL,
    ca_total                      DECIMAL(6,2) NULL,
    exam_mark                     DECIMAL(6,2) NULL,
    final_mark                    DECIMAL(6,2) NULL,
    grade                         VARCHAR(4)   NULL,
    result_status                 ENUM('PASS','FAIL','DEFERRED','INCOMPLETE','EXEMPTED','REFERRED') NULL,
    published_at                  TIMESTAMP    NULL,
    created_at                    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_result_reg (student_course_registration_id),
    CONSTRAINT fk_result_reg FOREIGN KEY (student_course_registration_id) REFERENCES student_course_registrations (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
