-- Course availability exception flags.
--
-- Default behavior in application code is academic-year-wide availability.
-- These optional columns allow approved exceptions to remain period-specific
-- without making every legacy `semester` value a hard ownership boundary.

ALTER TABLE program_courses
  ADD COLUMN IF NOT EXISTS is_full_year TINYINT(1) NOT NULL DEFAULT 1 AFTER is_required,
  ADD COLUMN IF NOT EXISTS is_period_specific TINYINT(1) NOT NULL DEFAULT 0 AFTER is_full_year,
  ADD COLUMN IF NOT EXISTS is_term_specific TINYINT(1) NOT NULL DEFAULT 0 AFTER is_period_specific,
  ADD COLUMN IF NOT EXISTS is_semester_specific TINYINT(1) NOT NULL DEFAULT 0 AFTER is_term_specific,
  ADD COLUMN IF NOT EXISTS delivery_period VARCHAR(32) NULL AFTER is_semester_specific;

UPDATE program_courses
   SET is_full_year = 1,
       is_period_specific = 0,
       is_term_specific = 0,
       is_semester_specific = 0
 WHERE is_full_year IS NULL;

ALTER TABLE curriculum_courses
  ADD COLUMN IF NOT EXISTS is_full_year TINYINT(1) NOT NULL DEFAULT 1 AFTER assessment_mode,
  ADD COLUMN IF NOT EXISTS is_period_specific TINYINT(1) NOT NULL DEFAULT 0 AFTER is_full_year,
  ADD COLUMN IF NOT EXISTS is_term_specific TINYINT(1) NOT NULL DEFAULT 0 AFTER is_period_specific,
  ADD COLUMN IF NOT EXISTS is_semester_specific TINYINT(1) NOT NULL DEFAULT 0 AFTER is_term_specific,
  ADD COLUMN IF NOT EXISTS delivery_period VARCHAR(32) NULL AFTER is_semester_specific;

UPDATE curriculum_courses
   SET is_full_year = 1,
       is_period_specific = 0,
       is_term_specific = 0,
       is_semester_specific = 0
 WHERE is_full_year IS NULL;
