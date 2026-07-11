-- ============================================================================
-- ITC Academic Data Structure — Phase 2: Calendar and Registration
-- Spec: E:\ITC_Academic_Data_Structure_Goal.md  (sections 9, 10, 11, 12, 14)
--
-- Strategy: REUSE & EXTEND. Existing calendar/intake tables are kept:
--   academic_periods (extended), intakes (extended), students, student_program.
-- New relational layer added: academic_years, class_groups, course_offerings,
-- student_course_registrations — wiring students+lecturers to OFFERINGS (real
-- teaching instances) rather than generic courses.
--
-- Idempotent. Target MariaDB 10.4 (XAMPP). Run as migration user; record in
-- schema_migrations after applying.
-- ============================================================================

-- ─── 1) academic_years (NEW) — a first-class year reference ──────────────────
--     academic_periods already carries a free-text academic_year; this gives it
--     a proper parent row without disturbing existing columns.
CREATE TABLE IF NOT EXISTS academic_years (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_name VARCHAR(20) NOT NULL,
    start_date         DATE        NULL,
    end_date           DATE        NULL,
    status             ENUM('upcoming','active','completed') NOT NULL DEFAULT 'active',
    created_at         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_academic_year_name (academic_year_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed from every academic_year string already in use.
INSERT INTO academic_years (academic_year_name, status)
SELECT DISTINCT academic_year, 'active'
  FROM academic_periods
 WHERE academic_year IS NOT NULL AND academic_year <> ''
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- ─── 2) academic_periods: add the spec's structured columns (additive) ───────
--     Keep period_type enum + semester_term as-is (live code depends on them);
--     add academic_year_id + period_number + period_name alongside.
ALTER TABLE academic_periods
    ADD COLUMN IF NOT EXISTS academic_year_id INT NULL AFTER academic_year,
    ADD COLUMN IF NOT EXISTS period_number    TINYINT NULL AFTER period_type,
    ADD COLUMN IF NOT EXISTS period_name      VARCHAR(40) NULL AFTER period_number;

UPDATE academic_periods ap
  JOIN academic_years ay ON ay.academic_year_name = ap.academic_year
   SET ap.academic_year_id = ay.id
 WHERE ap.academic_year_id IS NULL;

UPDATE academic_periods
   SET period_number = CAST(NULLIF(semester_term, '') AS UNSIGNED)
 WHERE period_number IS NULL AND semester_term REGEXP '^[0-9]+$';

UPDATE academic_periods
   SET period_name = CONCAT(
        CASE WHEN period_type = 'semester' THEN 'Semester ' ELSE 'Term ' END,
        COALESCE(period_number, semester_term))
 WHERE period_name IS NULL;

-- ─── 3) intakes: link to academic_years (additive) ───────────────────────────
ALTER TABLE intakes
    ADD COLUMN IF NOT EXISTS academic_year_id INT NULL AFTER intake_year;

UPDATE intakes i
  JOIN academic_years ay ON ay.academic_year_name = CAST(i.intake_year AS CHAR)
   SET i.academic_year_id = ay.id
 WHERE i.academic_year_id IS NULL AND i.intake_year IS NOT NULL;

-- ─── 4) class_groups (NEW) ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS class_groups (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    program_code     VARCHAR(20) NOT NULL,
    intake_id        INT         NULL,
    academic_year_id INT         NULL,
    group_name       VARCHAR(80) NOT NULL,
    year_number      TINYINT     NULL,
    term_number      TINYINT     NULL,
    semester_number  TINYINT     NULL,
    level_number     TINYINT     NULL,
    status           ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_class_group (program_code, intake_id, group_name),
    KEY idx_cg_program (program_code),
    KEY idx_cg_intake (intake_id),
    KEY idx_cg_year (academic_year_id),
    CONSTRAINT fk_cg_program     FOREIGN KEY (program_code)     REFERENCES programs (program_code)     ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cg_intake      FOREIGN KEY (intake_id)        REFERENCES intakes (id)                ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_cg_year        FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)         ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 5) course_offerings (NEW) — a real teaching instance of a course ────────
CREATE TABLE IF NOT EXISTS course_offerings (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    curriculum_course_id INT         NOT NULL,
    program_code         VARCHAR(20) NOT NULL,
    intake_id            INT         NULL,
    academic_year_id     INT         NULL,
    academic_period_id   INT         NULL,
    class_group_id       INT         NULL,
    delivery_mode        VARCHAR(40) NULL,
    status               ENUM('planned','active','completed','cancelled') NOT NULL DEFAULT 'active',
    created_at           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_offering (curriculum_course_id, intake_id, academic_period_id, class_group_id),
    KEY idx_off_curr_course (curriculum_course_id),
    KEY idx_off_program (program_code),
    KEY idx_off_period (academic_period_id),
    KEY idx_off_group (class_group_id),
    CONSTRAINT fk_off_curr_course FOREIGN KEY (curriculum_course_id) REFERENCES curriculum_courses (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_off_program     FOREIGN KEY (program_code)         REFERENCES programs (program_code)  ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_off_intake      FOREIGN KEY (intake_id)            REFERENCES intakes (id)             ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_off_year        FOREIGN KEY (academic_year_id)     REFERENCES academic_years (id)      ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_off_period      FOREIGN KEY (academic_period_id)   REFERENCES academic_periods (id)    ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_off_group       FOREIGN KEY (class_group_id)       REFERENCES class_groups (id)        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 6) student_course_registrations (NEW) — student ↔ offering ──────────────
--     References student_program (the existing "student_programmes") so student
--     identity stays separate from academic registration.
CREATE TABLE IF NOT EXISTS student_course_registrations (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    student_programme_id INT NOT NULL,
    course_offering_id   INT NOT NULL,
    registration_status  ENUM('REGISTERED','DROPPED','DEFERRED','COMPLETED','REPEATING') NOT NULL DEFAULT 'REGISTERED',
    registered_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_offering (student_programme_id, course_offering_id),
    KEY idx_scr_student_prog (student_programme_id),
    KEY idx_scr_offering (course_offering_id),
    CONSTRAINT fk_scr_student_prog FOREIGN KEY (student_programme_id) REFERENCES student_program (id)   ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_scr_offering     FOREIGN KEY (course_offering_id)   REFERENCES course_offerings (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
