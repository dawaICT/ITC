-- Student academic workflow — extend live tables (idempotent)
-- Run via: C:\xampp\php\php.exe scripts/run_student_academic_workflow_migration.php

-- academic_periods release windows
ALTER TABLE academic_periods
    ADD COLUMN IF NOT EXISTS registration_open TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN IF NOT EXISTS docket_open TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_open,
    ADD COLUMN IF NOT EXISTS exam_slip_open TINYINT(1) NOT NULL DEFAULT 0 AFTER docket_open;

UPDATE academic_periods
   SET period_number = CAST(NULLIF(semester_term, '') AS UNSIGNED)
 WHERE period_number IS NULL AND semester_term REGEXP '^[0-9]+$';

UPDATE academic_periods
   SET period_name = CONCAT(
        CASE WHEN period_type = 'semester' THEN 'Semester ' ELSE 'Term ' END,
        COALESCE(period_number, semester_term))
 WHERE (period_name IS NULL OR period_name = '') AND semester_term IS NOT NULL;

-- semester_registration workflow columns
ALTER TABLE semester_registration
    ADD COLUMN IF NOT EXISTS academic_period_id INT NULL AFTER academic_year,
    ADD COLUMN IF NOT EXISTS registration_status ENUM('pending','registered','blocked') NOT NULL DEFAULT 'registered' AFTER registration_date,
    ADD COLUMN IF NOT EXISTS fee_status ENUM('eligible','not_eligible','sponsored','unknown') NOT NULL DEFAULT 'unknown' AFTER registration_status;

-- Default: open registration on active periods
UPDATE academic_periods SET registration_open = 1 WHERE is_current = 1 AND status IN ('active','open','upcoming');
