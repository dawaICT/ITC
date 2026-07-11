-- Migration: add program_code + year_of_study to course_lecturer.
-- Several lecturer pages reference cl.program_code / lec.year_of_study (usually
-- inside COALESCE fallbacks), but the live table lacked them, throwing
-- "Unknown column 'cl.program_code'" / "'lec.year_of_study'" and fataling the page
-- (e.g. lecturers/myStudent.php queries 2, 4, 6; lecturers/viewCaRes.php).
-- Both are nullable so the existing COALESCE(sp.program_code, st.program, lec.program_code)
-- and COALESCE(lec.year_of_study, st.year, cr.Year) expressions fall back to student data.
-- Apply as a DDL-capable user (root locally / wucportal_migrator in production). Idempotent.

ALTER TABLE course_lecturer
  ADD COLUMN IF NOT EXISTS program_code  VARCHAR(20) NULL AFTER course_code,
  ADD COLUMN IF NOT EXISTS year_of_study INT NULL AFTER academic_year;
