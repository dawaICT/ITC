-- ============================================================================
-- ITC Academic Data Structure — Phase 1: Academic Foundation
-- Spec: E:\ITC_Academic_Data_Structure_Goal.md  (sections 2, 5, 6, 7, 8)
--
-- Strategy (confirmed with stakeholder): REUSE & EXTEND the live schema, never
-- duplicate. ~85% of the spec already exists under existing names:
--   programmes -> programs        student_programmes -> student_program
--   courses    -> courses         sections/departments already present
-- This migration adds ONLY the genuinely-missing curriculum layer that lets
-- "courses connect through a curriculum version" instead of being attached
-- straight to a programme (the gap the spec identifies), plus small additive
-- columns so a programme's structure type and qualification level are explicit.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS / backfills
-- guarded by ON DUPLICATE KEY / WHERE so the file is safe to re-run.
-- Target: MariaDB 10.4 (XAMPP). Run as a migration user, not the DML-only app
-- user. Record in schema_migrations after applying.
-- ============================================================================

-- ─── 1) sections: add the spec's `description` field ────────────────────────
--     (section_id already serves as section_code; section_name/status exist.)
ALTER TABLE sections
    ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL AFTER section_name;

-- ─── 2) programs: make structure type + qualification explicit ──────────────
--     programs is already structure-aware (period_mode, academic_structure,
--     uses_terms/uses_semesters, is_short_course, is_transport_exception). We
--     surface a single normalized control field `structure_type` (the spec's
--     central switch) and `qualification_level`, and widen examination_type to
--     allow BOTH. No existing column is dropped or renamed.
ALTER TABLE programs
    ADD COLUMN IF NOT EXISTS qualification_level VARCHAR(60) NULL AFTER program_type,
    ADD COLUMN IF NOT EXISTS structure_type
        ENUM('TERM_BASED','SEMESTER_BASED','SHORT_COURSE','TRADE_TEST_LEVEL')
        NULL AFTER academic_structure;

-- widen examination_type to include BOTH (spec §5). Re-applying is harmless.
ALTER TABLE programs
    MODIFY COLUMN examination_type ENUM('external','internal','both')
        NULL DEFAULT 'external';

-- Backfill structure_type from existing flags ONLY where not already set, so a
-- later manual reclassification (e.g. trade-test programmes) is never clobbered.
-- Order matters: short course > semester > term.
UPDATE programs
   SET structure_type = CASE
        WHEN is_short_course = 1                              THEN 'SHORT_COURSE'
        WHEN period_mode = 'semester' OR uses_semesters = 1   THEN 'SEMESTER_BASED'
        ELSE 'TERM_BASED'
   END
 WHERE structure_type IS NULL;

-- Backfill qualification_level from the existing program_type where empty.
UPDATE programs
   SET qualification_level = program_type
 WHERE (qualification_level IS NULL OR qualification_level = '')
   AND program_type IS NOT NULL AND program_type <> '';

-- ─── 3) curriculum_versions (NEW) ───────────────────────────────────────────
--     Every programme has >=1 version. Courses attach to a VERSION, so TEVETA
--     syllabus changes create a new version without destroying old records.
CREATE TABLE IF NOT EXISTS curriculum_versions (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    program_code       VARCHAR(20)  NOT NULL,
    version_name       VARCHAR(120) NOT NULL,
    effective_year     SMALLINT     NULL,
    approved_by        VARCHAR(120) NULL,
    approval_reference VARCHAR(120) NULL,
    status             ENUM('draft','active','archived') NOT NULL DEFAULT 'active',
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_curr_ver_prog_name (program_code, version_name),
    KEY idx_curr_ver_program (program_code),
    CONSTRAINT fk_curr_ver_program
        FOREIGN KEY (program_code) REFERENCES programs (program_code)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 4) curriculum_courses (NEW) ────────────────────────────────────────────
--     The versioned superset of program_courses. Period columns are flexible:
--     term-based -> year/term, semester-based -> year/semester, trade test ->
--     level, short course -> leave period cols NULL.
CREATE TABLE IF NOT EXISTS curriculum_courses (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    curriculum_version_id INT          NOT NULL,
    course_code           VARCHAR(20)  NOT NULL,
    year_number           TINYINT      NULL,
    term_number           TINYINT      NULL,
    semester_number       TINYINT      NULL,
    level_number          TINYINT      NULL,
    is_core               TINYINT(1)   NOT NULL DEFAULT 1,
    credit_value          DECIMAL(5,2) NULL,
    contact_hours         INT          NULL,
    theory_hours          INT          NULL,
    practical_hours       INT          NULL,
    assessment_mode       VARCHAR(40)  NULL,
    display_order         INT          NOT NULL DEFAULT 0,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_curr_course (curriculum_version_id, course_code),
    KEY idx_curr_course_version (curriculum_version_id),
    KEY idx_curr_course_code (course_code),
    CONSTRAINT fk_curr_course_version
        FOREIGN KEY (curriculum_version_id) REFERENCES curriculum_versions (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_curr_course_course
        FOREIGN KEY (course_code) REFERENCES courses (course_code)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 5) Backfill: one active curriculum version per existing programme ───────
INSERT INTO curriculum_versions (program_code, version_name, effective_year, status)
SELECT p.program_code, 'Curriculum 2026', 2026, 'active'
  FROM programs p
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- ─── 6) Backfill curriculum_courses from the legacy program_courses map ──────
--     program_courses.semester holds the period number; route it to term_number
--     or semester_number based on the programme's period_mode. Legacy
--     program_courses is left intact as the source of truth for older code.
INSERT INTO curriculum_courses
        (curriculum_version_id, course_code, year_number, term_number,
         semester_number, is_core, display_order)
SELECT cv.id,
       pc.course_code,
       pc.year,
       CASE WHEN p.period_mode = 'semester' THEN NULL ELSE pc.semester END AS term_number,
       CASE WHEN p.period_mode = 'semester' THEN pc.semester ELSE NULL END AS semester_number,
       COALESCE(pc.is_required, 1),
       0
  FROM program_courses pc
  JOIN programs p           ON p.program_code = pc.program_code
  JOIN curriculum_versions cv
       ON cv.program_code = pc.program_code AND cv.version_name = 'Curriculum 2026'
  JOIN courses c            ON c.course_code = pc.course_code  -- guard FK (skip orphan course_codes)
ON DUPLICATE KEY UPDATE
       year_number     = VALUES(year_number),
       term_number     = VALUES(term_number),
       semester_number = VALUES(semester_number),
       is_core         = VALUES(is_core);
