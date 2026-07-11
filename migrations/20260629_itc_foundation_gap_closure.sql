-- ============================================================================
-- ITC Academic Data Structure - Foundation Gap Closure
--
-- Purpose:
--   Close the gaps found after the first audit of the live WUC/ITC schema.
--   This migration is additive: it preserves the legacy tables currently used
--   by pages, while backfilling the TEVETA-aligned hierarchy so later code can
--   move safely to course_offering_id based workflows.
--
-- Depends on:
--   20260629_itc_academic_curriculum_layer.sql
--   20260629_itc_academic_calendar_registration.sql
--   20260629_itc_lecturer_assignments_assessment.sql
-- ============================================================================

-- 1) Sections and departments -------------------------------------------------

UPDATE sections
   SET section_name = 'Engineering and ICT Section',
       description = COALESCE(description, 'Engineering, ICT, electrical, automotive, and related academic departments.'),
       section_type = 'academic',
       status = 'active'
 WHERE section_id = 'ENGICT';

UPDATE sections
   SET section_name = 'Transport Section',
       description = COALESCE(description, 'Transport, logistics, driver training, fleet management, and related programmes.'),
       section_type = 'transport',
       status = 'active'
 WHERE section_id = 'TRANSPORT';

INSERT INTO departments (department_code, department_name, section_id, status)
SELECT 'PSM', 'Plumbing and Sheet Metal', 'ENGICT', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM departments WHERE department_code = 'PSM');

INSERT INTO departments (department_code, department_name, section_id, status)
SELECT 'TEL', 'Telecommunications', 'ENGICT', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM departments WHERE department_code = 'TEL');

INSERT INTO departments (department_code, department_name, section_id, status)
SELECT 'TLOG', 'Transport and Logistics', 'TRANSPORT', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM departments WHERE department_code = 'TLOG');

INSERT INTO departments (department_code, department_name, section_id, status)
SELECT 'DRV', 'Driver Training', 'TRANSPORT', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM departments WHERE department_code = 'DRV');

INSERT INTO departments (department_code, department_name, section_id, status)
SELECT 'FLEET', 'Fleet Management', 'TRANSPORT', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM departments WHERE department_code = 'FLEET');

UPDATE departments
   SET section_id = 'ENGICT', status = 'active'
 WHERE department_code IN ('ICT', 'AE', 'EEE', 'PSM', 'TEL')
   AND (section_id IS NULL OR section_id <> 'ENGICT');

UPDATE departments
   SET section_id = 'TRANSPORT', status = 'active'
 WHERE department_code IN ('TR', 'TLOG', 'DRV', 'FLEET')
   AND (section_id IS NULL OR section_id <> 'TRANSPORT');

-- 2) Programme ownership and structure type ----------------------------------

UPDATE programs
   SET department_id = (SELECT id FROM departments WHERE department_code = 'AE' LIMIT 1)
 WHERE department_id IS NULL
   AND (program_code LIKE 'AUTO-%' OR program_code LIKE 'AUTO-%-TT%' OR program_name LIKE '%Automotive%' OR program_name LIKE '%Vehicle%' OR program_name LIKE '%Auto Electrical%');

UPDATE programs
   SET department_id = (SELECT id FROM departments WHERE department_code = 'EEE' LIMIT 1)
 WHERE department_id IS NULL
   AND (program_code LIKE 'ELEC-%' OR program_name LIKE '%Electrical%');

UPDATE programs
   SET department_id = (SELECT id FROM departments WHERE department_code = 'ICT' LIMIT 1)
 WHERE department_id IS NULL
   AND (program_code LIKE 'ICT-%' OR program_code = 'CSE' OR program_name LIKE '%Computer%' OR program_name LIKE '%Telecommunication%');

UPDATE programs
   SET department_id = (SELECT id FROM departments WHERE department_code = 'PSM' LIMIT 1)
 WHERE department_id IS NULL
   AND (program_code LIKE 'PLUM-%' OR program_code LIKE 'FAB-%' OR program_name LIKE '%Plumbing%' OR program_name LIKE '%Sheet Metal%' OR program_name LIKE '%Fabrication%');

UPDATE programs
   SET department_id = (SELECT id FROM departments WHERE department_code = 'TLOG' LIMIT 1)
 WHERE department_id IS NULL
   AND (program_code = 'DTL' OR program_code LIKE 'TRANS-%' OR program_name LIKE '%Transport%' OR program_name LIKE '%Logistics%');

UPDATE programs
   SET department_id = COALESCE(department_id, (SELECT id FROM departments WHERE department_code = 'ICT' LIMIT 1))
 WHERE department_id IS NULL;

UPDATE programs
   SET structure_type = 'SEMESTER_BASED',
       period_mode = 'semester',
       uses_semesters = 1,
       uses_terms = 0,
       is_transport_exception = 1
 WHERE program_code = 'DTL'
    OR program_code LIKE 'TRANS-%'
    OR program_name LIKE '%Transport%'
    OR program_name LIKE '%Logistics%';

UPDATE programs
   SET structure_type = 'TRADE_TEST_LEVEL',
       uses_terms = 0,
       uses_semesters = 0,
       academic_structure = 'trade_test_level'
 WHERE program_code LIKE '%-TT%'
    OR program_name LIKE '%Trade Test%'
    OR program_name LIKE '%Level I%'
    OR program_name LIKE '%Level 1%';

UPDATE curriculum_courses cc
  JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
  JOIN programs p ON p.program_code = cv.program_code
   SET cc.level_number = CASE
           WHEN p.program_code LIKE '%TT3%' OR p.program_name LIKE '%Level III%' OR p.program_name LIKE '%Level 3%' THEN 3
           WHEN p.program_code LIKE '%TT2%' OR p.program_name LIKE '%Level II%' OR p.program_name LIKE '%Level 2%' THEN 2
           ELSE 1
       END,
       cc.year_number = NULL,
       cc.term_number = NULL,
       cc.semester_number = NULL
 WHERE p.structure_type = 'TRADE_TEST_LEVEL';

-- 3) Calendar and intake compatibility ---------------------------------------

ALTER TABLE academic_periods
    MODIFY COLUMN period_type ENUM('semester','term','short_course_cycle','trade_test_level') NOT NULL DEFAULT 'semester';

ALTER TABLE intakes
    ADD COLUMN IF NOT EXISTS intake_type VARCHAR(40) NULL AFTER academic_year_id,
    ADD COLUMN IF NOT EXISTS start_date DATE NULL AFTER intake_type,
    ADD COLUMN IF NOT EXISTS end_date DATE NULL AFTER start_date;

INSERT INTO academic_years (academic_year_name, start_date, end_date, status)
SELECT '2026', '2026-01-01', '2026-12-31', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM academic_years WHERE academic_year_name = '2026');

UPDATE academic_years
   SET start_date = COALESCE(start_date, '2026-01-01'),
       end_date = COALESCE(end_date, '2026-12-31'),
       status = 'active'
 WHERE academic_year_name = '2026';

INSERT INTO academic_periods
        (academic_year, academic_year_id, period_type, period_number, period_name, semester_term, start_date, end_date, is_current, status)
SELECT '2026', ay.id, 'trade_test_level', 1, 'Trade Test Level 1', '1', '2026-01-01', '2026-12-31', 0, 'active'
  FROM academic_years ay
 WHERE ay.academic_year_name = '2026'
   AND NOT EXISTS (SELECT 1 FROM academic_periods WHERE academic_year = '2026' AND period_type = 'trade_test_level' AND semester_term = '1');

INSERT INTO academic_periods
        (academic_year, academic_year_id, period_type, period_number, period_name, semester_term, start_date, end_date, is_current, status)
SELECT '2026', ay.id, 'trade_test_level', 2, 'Trade Test Level 2', '2', '2026-01-01', '2026-12-31', 0, 'active'
  FROM academic_years ay
 WHERE ay.academic_year_name = '2026'
   AND NOT EXISTS (SELECT 1 FROM academic_periods WHERE academic_year = '2026' AND period_type = 'trade_test_level' AND semester_term = '2');

INSERT INTO academic_periods
        (academic_year, academic_year_id, period_type, period_number, period_name, semester_term, start_date, end_date, is_current, status)
SELECT '2026', ay.id, 'trade_test_level', 3, 'Trade Test Level 3', '3', '2026-01-01', '2026-12-31', 0, 'active'
  FROM academic_years ay
 WHERE ay.academic_year_name = '2026'
   AND NOT EXISTS (SELECT 1 FROM academic_periods WHERE academic_year = '2026' AND period_type = 'trade_test_level' AND semester_term = '3');

INSERT INTO academic_periods
        (academic_year, academic_year_id, period_type, period_number, period_name, semester_term, start_date, end_date, is_current, status)
SELECT '2026', ay.id, 'short_course_cycle', 1, 'Short Course Cycle 1', '1', '2026-01-01', '2026-12-31', 0, 'active'
  FROM academic_years ay
 WHERE ay.academic_year_name = '2026'
   AND NOT EXISTS (SELECT 1 FROM academic_periods WHERE academic_year = '2026' AND period_type = 'short_course_cycle' AND semester_term = '1');

INSERT INTO intakes
        (intake_name, intake_year, academic_year_id, intake_type, start_date, end_date, training_start_date, training_end_date, status, created_by)
SELECT 'January 2026 Term Intake', 2026, ay.id, 'TERM', '2026-01-01', '2026-04-30', '2026-01-01', '2026-04-30', 'Training in Progress', 'migration'
  FROM academic_years ay
 WHERE ay.academic_year_name = '2026'
   AND NOT EXISTS (SELECT 1 FROM intakes WHERE intake_name = 'January 2026 Term Intake' AND intake_year = 2026);

INSERT INTO intakes
        (intake_name, intake_year, academic_year_id, intake_type, start_date, end_date, training_start_date, training_end_date, status, created_by)
SELECT 'January 2026 Semester Intake', 2026, ay.id, 'SEMESTER', '2026-01-01', '2026-06-30', '2026-01-01', '2026-06-30', 'Training in Progress', 'migration'
  FROM academic_years ay
 WHERE ay.academic_year_name = '2026'
   AND NOT EXISTS (SELECT 1 FROM intakes WHERE intake_name = 'January 2026 Semester Intake' AND intake_year = 2026);

INSERT INTO intakes
        (intake_name, intake_year, academic_year_id, intake_type, start_date, end_date, training_start_date, training_end_date, status, created_by)
SELECT 'Trade Test 2026 Intake', 2026, ay.id, 'TRADE_TEST_LEVEL', '2026-01-01', '2026-12-31', '2026-01-01', '2026-12-31', 'Training in Progress', 'migration'
  FROM academic_years ay
 WHERE ay.academic_year_name = '2026'
   AND NOT EXISTS (SELECT 1 FROM intakes WHERE intake_name = 'Trade Test 2026 Intake' AND intake_year = 2026);

INSERT INTO intakes
        (intake_name, intake_year, academic_year_id, intake_type, start_date, end_date, training_start_date, training_end_date, status, created_by)
SELECT 'Rolling Short Course 2026 Intake', 2026, ay.id, 'SHORT_COURSE_CYCLE', '2026-01-01', '2026-12-31', '2026-01-01', '2026-12-31', 'Training in Progress', 'migration'
  FROM academic_years ay
 WHERE ay.academic_year_name = '2026'
   AND NOT EXISTS (SELECT 1 FROM intakes WHERE intake_name = 'Rolling Short Course 2026 Intake' AND intake_year = 2026);

-- 4) Student programme metadata ---------------------------------------------

ALTER TABLE student_program
    ADD COLUMN IF NOT EXISTS curriculum_version_id INT NULL AFTER program_code,
    ADD COLUMN IF NOT EXISTS intake_id INT NULL AFTER intake,
    ADD COLUMN IF NOT EXISTS academic_year_id INT NULL AFTER academic_year,
    ADD COLUMN IF NOT EXISTS current_year_number TINYINT NULL AFTER year_of_study,
    ADD COLUMN IF NOT EXISTS current_term_number TINYINT NULL AFTER current_year_number,
    ADD COLUMN IF NOT EXISTS current_semester_number TINYINT NULL AFTER current_term_number,
    ADD COLUMN IF NOT EXISTS current_level_number TINYINT NULL AFTER current_semester_number,
    ADD COLUMN IF NOT EXISTS registration_date DATE NULL AFTER status;

ALTER TABLE student_program
    ADD INDEX IF NOT EXISTS idx_student_program_curriculum (curriculum_version_id),
    ADD INDEX IF NOT EXISTS idx_student_program_intake (intake_id),
    ADD INDEX IF NOT EXISTS idx_student_program_academic_year (academic_year_id);

UPDATE student_program sp
  JOIN curriculum_versions cv ON cv.program_code = sp.program_code AND cv.status = 'active'
   SET sp.curriculum_version_id = cv.id
 WHERE sp.curriculum_version_id IS NULL;

UPDATE student_program sp
  JOIN programs p ON p.program_code = sp.program_code
  JOIN academic_years ay ON ay.academic_year_name = (
       CONVERT(COALESCE(NULLIF(sp.academic_year, ''), CAST(sp.startYear AS CHAR), '2026') USING utf8mb4)
       COLLATE utf8mb4_unicode_ci
  )
   SET sp.academic_year_id = ay.id
 WHERE sp.academic_year_id IS NULL;

UPDATE student_program sp
  JOIN programs p ON p.program_code = sp.program_code
  JOIN intakes i ON i.intake_year = COALESCE(sp.startYear, 2026)
                AND i.intake_type = CASE
                    WHEN p.structure_type = 'SEMESTER_BASED' THEN 'SEMESTER'
                    WHEN p.structure_type = 'TRADE_TEST_LEVEL' THEN 'TRADE_TEST_LEVEL'
                    WHEN p.structure_type = 'SHORT_COURSE' THEN 'SHORT_COURSE_CYCLE'
                    ELSE 'TERM'
                END
   SET sp.intake_id = i.id
 WHERE sp.intake_id IS NULL;

UPDATE student_program sp
  JOIN programs p ON p.program_code = sp.program_code
   SET sp.current_year_number = CASE WHEN p.structure_type IN ('TERM_BASED','SEMESTER_BASED') THEN COALESCE(sp.year_of_study, 1) ELSE NULL END,
       sp.current_term_number = CASE WHEN p.structure_type = 'TERM_BASED' THEN CAST(COALESCE(NULLIF(sp.term, ''), sp.semester, 1) AS UNSIGNED) ELSE NULL END,
       sp.current_semester_number = CASE WHEN p.structure_type = 'SEMESTER_BASED' THEN COALESCE(sp.semester, 1) ELSE NULL END,
       sp.current_level_number = CASE
           WHEN p.structure_type = 'TRADE_TEST_LEVEL' THEN
               CASE
                   WHEN p.program_code LIKE '%TT3%' OR p.program_name LIKE '%Level III%' OR p.program_name LIKE '%Level 3%' THEN 3
                   WHEN p.program_code LIKE '%TT2%' OR p.program_name LIKE '%Level II%' OR p.program_name LIKE '%Level 2%' THEN 2
                   ELSE 1
               END
           ELSE NULL
       END,
       sp.registration_date = COALESCE(sp.registration_date, DATE(sp.created_at))
 WHERE sp.curriculum_version_id IS NOT NULL;

-- 5) Class groups and course offerings ---------------------------------------

INSERT INTO class_groups
        (program_code, intake_id, academic_year_id, group_name, year_number, term_number, semester_number, level_number, status)
SELECT DISTINCT p.program_code, i.id, ay.id,
       CONCAT(p.program_code, ' Year ', cc.year_number, ' Term ', cc.term_number),
       cc.year_number, cc.term_number, NULL, NULL, 'active'
  FROM curriculum_courses cc
  JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
  JOIN programs p ON p.program_code = cv.program_code
  JOIN academic_years ay ON ay.academic_year_name = '2026'
  JOIN intakes i ON i.intake_name = 'January 2026 Term Intake' AND i.intake_year = 2026
 WHERE p.structure_type = 'TERM_BASED'
   AND cc.year_number IS NOT NULL
   AND cc.term_number IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO class_groups
        (program_code, intake_id, academic_year_id, group_name, year_number, term_number, semester_number, level_number, status)
SELECT DISTINCT p.program_code, i.id, ay.id,
       CONCAT(p.program_code, ' Year ', cc.year_number, ' Semester ', cc.semester_number),
       cc.year_number, NULL, cc.semester_number, NULL, 'active'
  FROM curriculum_courses cc
  JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
  JOIN programs p ON p.program_code = cv.program_code
  JOIN academic_years ay ON ay.academic_year_name = '2026'
  JOIN intakes i ON i.intake_name = 'January 2026 Semester Intake' AND i.intake_year = 2026
 WHERE p.structure_type = 'SEMESTER_BASED'
   AND cc.year_number IS NOT NULL
   AND cc.semester_number IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO class_groups
        (program_code, intake_id, academic_year_id, group_name, year_number, term_number, semester_number, level_number, status)
SELECT DISTINCT p.program_code, i.id, ay.id,
       CONCAT(p.program_code, ' Trade Test Level ', cc.level_number),
       NULL, NULL, NULL, cc.level_number, 'active'
  FROM curriculum_courses cc
  JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
  JOIN programs p ON p.program_code = cv.program_code
  JOIN academic_years ay ON ay.academic_year_name = '2026'
  JOIN intakes i ON i.intake_name = 'Trade Test 2026 Intake' AND i.intake_year = 2026
 WHERE p.structure_type = 'TRADE_TEST_LEVEL'
   AND cc.level_number IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO course_offerings
        (curriculum_course_id, program_code, intake_id, academic_year_id, academic_period_id, class_group_id, delivery_mode, status)
SELECT cc.id, p.program_code, i.id, ay.id, ap.id, cg.id, COALESCE(p.study_mode, 'Full Time'), 'active'
  FROM curriculum_courses cc
  JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
  JOIN programs p ON p.program_code = cv.program_code
  JOIN academic_years ay ON ay.academic_year_name = '2026'
  JOIN intakes i ON i.intake_name = 'January 2026 Term Intake' AND i.intake_year = 2026
  JOIN academic_periods ap ON ap.academic_year = '2026' AND ap.period_type = 'term' AND ap.period_number = cc.term_number
  JOIN class_groups cg ON cg.program_code = p.program_code AND cg.intake_id = i.id AND cg.group_name = CONCAT(p.program_code, ' Year ', cc.year_number, ' Term ', cc.term_number)
 WHERE p.structure_type = 'TERM_BASED'
   AND cc.year_number IS NOT NULL
   AND cc.term_number IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO course_offerings
        (curriculum_course_id, program_code, intake_id, academic_year_id, academic_period_id, class_group_id, delivery_mode, status)
SELECT cc.id, p.program_code, i.id, ay.id, ap.id, cg.id, COALESCE(p.study_mode, 'Full Time'), 'active'
  FROM curriculum_courses cc
  JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
  JOIN programs p ON p.program_code = cv.program_code
  JOIN academic_years ay ON ay.academic_year_name = '2026'
  JOIN intakes i ON i.intake_name = 'January 2026 Semester Intake' AND i.intake_year = 2026
  JOIN academic_periods ap ON ap.academic_year = '2026' AND ap.period_type = 'semester' AND ap.period_number = cc.semester_number
  JOIN class_groups cg ON cg.program_code = p.program_code AND cg.intake_id = i.id AND cg.group_name = CONCAT(p.program_code, ' Year ', cc.year_number, ' Semester ', cc.semester_number)
 WHERE p.structure_type = 'SEMESTER_BASED'
   AND cc.year_number IS NOT NULL
   AND cc.semester_number IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO course_offerings
        (curriculum_course_id, program_code, intake_id, academic_year_id, academic_period_id, class_group_id, delivery_mode, status)
SELECT cc.id, p.program_code, i.id, ay.id, ap.id, cg.id, COALESCE(p.study_mode, 'Full Time'), 'active'
  FROM curriculum_courses cc
  JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
  JOIN programs p ON p.program_code = cv.program_code
  JOIN academic_years ay ON ay.academic_year_name = '2026'
  JOIN intakes i ON i.intake_name = 'Trade Test 2026 Intake' AND i.intake_year = 2026
  JOIN academic_periods ap ON ap.academic_year = '2026' AND ap.period_type = 'trade_test_level' AND ap.period_number = cc.level_number
  JOIN class_groups cg ON cg.program_code = p.program_code AND cg.intake_id = i.id AND cg.group_name = CONCAT(p.program_code, ' Trade Test Level ', cc.level_number)
 WHERE p.structure_type = 'TRADE_TEST_LEVEL'
   AND cc.level_number IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- 6) Bridge legacy registrations and lecturer assignments --------------------

INSERT INTO student_course_registrations
        (student_programme_id, course_offering_id, registration_status, registered_at)
SELECT DISTINCT sp.id, co.id,
       CASE
           WHEN LOWER(COALESCE(cr.status, 'active')) IN ('dropped','withdrawn','cancelled') THEN 'DROPPED'
           WHEN LOWER(COALESCE(cr.status, 'active')) IN ('completed') THEN 'COMPLETED'
           ELSE 'REGISTERED'
       END,
       COALESCE(cr.registration_date, cr.created_at, CURRENT_TIMESTAMP)
  FROM course_registration cr
  JOIN student_program sp ON sp.Sid = cr.Sid
  JOIN programs p ON p.program_code = sp.program_code
  JOIN curriculum_versions cv ON cv.program_code = p.program_code AND cv.id = sp.curriculum_version_id
  JOIN curriculum_courses cc ON cc.curriculum_version_id = cv.id AND cc.course_code = cr.course_code
  JOIN course_offerings co ON co.curriculum_course_id = cc.id
 WHERE (
       (p.structure_type = 'TERM_BASED' AND cc.year_number = cr.Year AND cc.term_number = cr.semester)
    OR (p.structure_type = 'SEMESTER_BASED' AND cc.year_number = cr.Year AND cc.semester_number = cr.semester)
    OR (p.structure_type = 'TRADE_TEST_LEVEL' AND cc.level_number IS NOT NULL)
 )
ON DUPLICATE KEY UPDATE
       registration_status = VALUES(registration_status),
       updated_at = CURRENT_TIMESTAMP;

INSERT INTO lecturer_course_assignments
        (staff_id, course_offering_id, assignment_role, status)
SELECT DISTINCT cl.staff_id, co.id, 'Main Lecturer',
       CASE WHEN LOWER(COALESCE(cl.status, 'active')) IN ('inactive','ended') THEN 'inactive' ELSE 'active' END
  FROM course_lecturer cl
  JOIN curriculum_courses cc ON cc.course_code = cl.course_code
  JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
  JOIN course_offerings co ON co.curriculum_course_id = cc.id
 WHERE (cl.program_code IS NULL OR cl.program_code = '' OR cl.program_code = cv.program_code)
ON DUPLICATE KEY UPDATE
       status = VALUES(status),
       updated_at = CURRENT_TIMESTAMP;

-- 7) Assessment defaults ------------------------------------------------------

INSERT INTO assessment_schemes
        (program_code, course_code, scheme_name, ca_weight, exam_weight, pass_mark, status)
SELECT DISTINCT p.program_code, cc.course_code, 'Default TEVETA Scheme',
       CASE WHEN p.structure_type IN ('TRADE_TEST_LEVEL','SHORT_COURSE') THEN 100 ELSE 40 END,
       CASE WHEN p.structure_type IN ('TRADE_TEST_LEVEL','SHORT_COURSE') THEN 0 ELSE 60 END,
       50,
       'active'
  FROM curriculum_courses cc
  JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
  JOIN programs p ON p.program_code = cv.program_code
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO assessment_components
        (assessment_scheme_id, component_name, component_type, weight, max_mark, display_order)
SELECT s.id, 'Assignment 1', 'CA', 10, 100, 10
  FROM assessment_schemes s
  JOIN programs p ON p.program_code = s.program_code
 WHERE p.structure_type IN ('TERM_BASED','SEMESTER_BASED')
   AND NOT EXISTS (SELECT 1 FROM assessment_components ac WHERE ac.assessment_scheme_id = s.id AND ac.component_name = 'Assignment 1');

INSERT INTO assessment_components
        (assessment_scheme_id, component_name, component_type, weight, max_mark, display_order)
SELECT s.id, 'Assignment 2', 'CA', 10, 100, 20
  FROM assessment_schemes s
  JOIN programs p ON p.program_code = s.program_code
 WHERE p.structure_type IN ('TERM_BASED','SEMESTER_BASED')
   AND NOT EXISTS (SELECT 1 FROM assessment_components ac WHERE ac.assessment_scheme_id = s.id AND ac.component_name = 'Assignment 2');

INSERT INTO assessment_components
        (assessment_scheme_id, component_name, component_type, weight, max_mark, display_order)
SELECT s.id, 'Practical Test', 'PRACTICAL', 20, 100, 30
  FROM assessment_schemes s
  JOIN programs p ON p.program_code = s.program_code
 WHERE p.structure_type IN ('TERM_BASED','SEMESTER_BASED')
   AND NOT EXISTS (SELECT 1 FROM assessment_components ac WHERE ac.assessment_scheme_id = s.id AND ac.component_name = 'Practical Test');

INSERT INTO assessment_components
        (assessment_scheme_id, component_name, component_type, weight, max_mark, display_order)
SELECT s.id, 'Final Exam', 'EXAM', 60, 100, 40
  FROM assessment_schemes s
  JOIN programs p ON p.program_code = s.program_code
 WHERE p.structure_type IN ('TERM_BASED','SEMESTER_BASED')
   AND NOT EXISTS (SELECT 1 FROM assessment_components ac WHERE ac.assessment_scheme_id = s.id AND ac.component_name = 'Final Exam');

INSERT INTO assessment_components
        (assessment_scheme_id, component_name, component_type, weight, max_mark, display_order)
SELECT s.id, 'Practical Competency Assessment', 'PRACTICAL', 100, 100, 10
  FROM assessment_schemes s
  JOIN programs p ON p.program_code = s.program_code
 WHERE p.structure_type IN ('TRADE_TEST_LEVEL','SHORT_COURSE')
   AND NOT EXISTS (SELECT 1 FROM assessment_components ac WHERE ac.assessment_scheme_id = s.id AND ac.component_name = 'Practical Competency Assessment');

-- 8) Permissions, report logs, and canonical audit logs ----------------------

CREATE TABLE IF NOT EXISTS report_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_type VARCHAR(80) NOT NULL,
    generated_by VARCHAR(50) NULL,
    section_id VARCHAR(30) NULL,
    department_id INT NULL,
    programme_id VARCHAR(20) NULL,
    academic_year_id INT NULL,
    academic_period_id INT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_report_type (report_type),
    KEY idx_report_scope (section_id, department_id, programme_id),
    KEY idx_report_period (academic_year_id, academic_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NULL,
    action VARCHAR(120) NOT NULL,
    module VARCHAR(80) NOT NULL,
    record_id VARCHAR(80) NULL,
    old_value LONGTEXT NULL,
    new_value LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_module_record (module, record_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO audit_logs (user_id, action, module, record_id, new_value, created_at)
SELECT al.user_id, al.action, 'legacy', CAST(al.id AS CHAR), al.details, al.created_at
  FROM audit_log al
 WHERE NOT EXISTS (
       SELECT 1
         FROM audit_logs x
        WHERE x.module = 'legacy'
          AND x.record_id = (CONVERT(CAST(al.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci)
 );

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'students.view', 'View Students', 'View student identity and registration records.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'students.view');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'students.create', 'Create Students', 'Create student records and initial programme registration.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'students.create');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'students.edit', 'Edit Students', 'Edit student identity and academic registration details.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'students.edit');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'curriculum.manage', 'Manage Curriculum', 'Manage curriculum versions and curriculum courses.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'curriculum.manage');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'intakes.manage', 'Manage Intakes', 'Manage intake definitions and intake dates.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'intakes.manage');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'academic_periods.manage', 'Manage Academic Periods', 'Manage terms, semesters, short course cycles, and trade test periods.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'academic_periods.manage');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'lecturer.assign', 'Assign Lecturers', 'Assign lecturers to course offerings.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'lecturer.assign');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'ca.upload', 'Upload CA', 'Upload continuous assessment marks for assigned course offerings.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'ca.upload');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'ca.approve', 'Approve CA', 'Approve or return submitted continuous assessment marks.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'ca.approve');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'results.calculate', 'Calculate Results', 'Calculate final course results from approved assessments.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'results.calculate');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'results.publish', 'Publish Results', 'Publish approved student course results.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'results.publish');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'reports.department.view', 'View Department Reports', 'View reports scoped to assigned departments.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'reports.department.view');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'reports.section.view', 'View Section Reports', 'View reports scoped to assigned sections.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'reports.section.view');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'users.manage', 'Manage Users', 'Manage staff and user accounts.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'users.manage');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'roles.manage', 'Manage Roles', 'Manage roles and role permissions.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'roles.manage');

INSERT INTO permissions (permission_key, permission_label, description, status)
SELECT 'audit.view', 'View Audit Logs', 'View immutable audit log entries.', 'active'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'audit.view');

-- Systems Administrator receives every explicit objective permission.
INSERT INTO role_permissions (role_id, module_id, permission_id, status)
SELECT r.role_id,
       CASE
           WHEN p.permission_key LIKE 'students.%' THEN (SELECT module_id FROM modules WHERE module_key = 'student_records' LIMIT 1)
           WHEN p.permission_key IN ('curriculum.manage','programmes.manage') THEN (SELECT module_id FROM modules WHERE module_key = 'programmes' LIMIT 1)
           WHEN p.permission_key = 'courses.manage' THEN (SELECT module_id FROM modules WHERE module_key = 'courses' LIMIT 1)
           WHEN p.permission_key IN ('intakes.manage','academic_periods.manage') THEN (SELECT module_id FROM modules WHERE module_key = 'student_registration' LIMIT 1)
           WHEN p.permission_key = 'lecturer.assign' THEN (SELECT module_id FROM modules WHERE module_key = 'lecturer_assignment' LIMIT 1)
           WHEN p.permission_key = 'ca.upload' THEN (SELECT module_id FROM modules WHERE module_key = 'ca_upload' LIMIT 1)
           WHEN p.permission_key = 'ca.approve' THEN (SELECT module_id FROM modules WHERE module_key = 'ca_approval' LIMIT 1)
           WHEN p.permission_key LIKE 'results.%' THEN (SELECT module_id FROM modules WHERE module_key = 'ca_approval' LIMIT 1)
           WHEN p.permission_key LIKE 'elearning.%' THEN (SELECT module_id FROM modules WHERE module_key = 'elearning' LIMIT 1)
           WHEN p.permission_key LIKE 'reports.%' THEN (SELECT module_id FROM modules WHERE module_key = 'reports' LIMIT 1)
           WHEN p.permission_key IN ('users.manage','roles.manage') THEN (SELECT module_id FROM modules WHERE module_key = 'users_roles' LIMIT 1)
           WHEN p.permission_key = 'audit.view' THEN (SELECT module_id FROM modules WHERE module_key = 'settings' LIMIT 1)
           ELSE (SELECT module_id FROM modules WHERE module_key = 'dashboard' LIMIT 1)
       END,
       p.permission_id,
       'active'
  FROM roles r
  JOIN permissions p ON p.permission_key IN (
       'students.view','students.create','students.edit','programmes.manage','courses.manage','curriculum.manage',
       'intakes.manage','academic_periods.manage','lecturer.assign','ca.upload','ca.approve',
       'results.calculate','results.publish','elearning.view','elearning.manage','reports.department.view',
       'reports.section.view','users.manage','roles.manage','audit.view'
  )
 WHERE r.role_name = 'systems_admin'
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
   );

-- Registrar: admissions, records, calendar, registration, reports, publishing.
INSERT INTO role_permissions (role_id, module_id, permission_id, status)
SELECT r.role_id,
       CASE
           WHEN p.permission_key LIKE 'students.%' THEN (SELECT module_id FROM modules WHERE module_key = 'student_records' LIMIT 1)
           WHEN p.permission_key IN ('intakes.manage','academic_periods.manage') THEN (SELECT module_id FROM modules WHERE module_key = 'student_registration' LIMIT 1)
           WHEN p.permission_key LIKE 'reports.%' THEN (SELECT module_id FROM modules WHERE module_key = 'reports' LIMIT 1)
           WHEN p.permission_key LIKE 'results.%' THEN (SELECT module_id FROM modules WHERE module_key = 'ca_approval' LIMIT 1)
           ELSE (SELECT module_id FROM modules WHERE module_key = 'dashboard' LIMIT 1)
       END,
       p.permission_id,
       'active'
  FROM roles r
  JOIN permissions p ON p.permission_key IN (
       'students.view','students.create','students.edit','intakes.manage','academic_periods.manage',
       'results.calculate','results.publish','reports.department.view','reports.section.view'
  )
 WHERE r.role_name = 'registrar'
   AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id);

-- HOD/HOS: department/section scope, lecturer assignment, CA approval, reports.
INSERT INTO role_permissions (role_id, module_id, permission_id, status)
SELECT r.role_id,
       CASE
           WHEN p.permission_key = 'lecturer.assign' THEN (SELECT module_id FROM modules WHERE module_key = 'lecturer_assignment' LIMIT 1)
           WHEN p.permission_key = 'ca.approve' THEN (SELECT module_id FROM modules WHERE module_key = 'ca_approval' LIMIT 1)
           WHEN p.permission_key LIKE 'reports.%' THEN (SELECT module_id FROM modules WHERE module_key = 'reports' LIMIT 1)
           ELSE (SELECT module_id FROM modules WHERE module_key = 'student_records' LIMIT 1)
       END,
       p.permission_id,
       'active'
  FROM roles r
  JOIN permissions p ON p.permission_key IN (
       'students.view','lecturer.assign','ca.approve','results.calculate','reports.department.view','reports.section.view'
  )
 WHERE r.role_name = 'head_of_department'
   AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id);

-- Lecturer: assigned course delivery, CA upload, and eLearning content.
INSERT INTO role_permissions (role_id, module_id, permission_id, status)
SELECT r.role_id,
       CASE
           WHEN p.permission_key = 'ca.upload' THEN (SELECT module_id FROM modules WHERE module_key = 'ca_upload' LIMIT 1)
           WHEN p.permission_key LIKE 'elearning.%' THEN (SELECT module_id FROM modules WHERE module_key = 'elearning' LIMIT 1)
           ELSE (SELECT module_id FROM modules WHERE module_key = 'student_records' LIMIT 1)
       END,
       p.permission_id,
       'active'
  FROM roles r
  JOIN permissions p ON p.permission_key IN ('students.view','ca.upload','elearning.view','elearning.manage')
 WHERE r.role_name = 'lecturer'
   AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id);

-- Student: learning access and own record visibility.
INSERT INTO role_permissions (role_id, module_id, permission_id, status)
SELECT r.role_id,
       CASE WHEN p.permission_key LIKE 'elearning.%'
            THEN (SELECT module_id FROM modules WHERE module_key = 'elearning' LIMIT 1)
            ELSE (SELECT module_id FROM modules WHERE module_key = 'student_records' LIMIT 1)
       END,
       p.permission_id,
       'active'
  FROM roles r
  JOIN permissions p ON p.permission_key IN ('students.view','elearning.view')
 WHERE r.role_name = 'student'
   AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id);
