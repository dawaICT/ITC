-- Migration: add the `status` column that course_lecturer is missing.
-- Many pages filter lecturer-course assignments with COALESCE(cl.status,'active')
-- (and admin/manage_lectures.php uses cl.status = 'active' directly). The live
-- table had no status column, so those queries threw "Unknown column 'cl.status'"
-- and fataled the page (e.g. lecturers/assessments.php).
-- Apply as a DDL-capable user (root locally / wucportal_migrator in production).
-- Idempotent on MariaDB.

ALTER TABLE course_lecturer
  ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER semester;

-- Existing assignments default to 'active'. No GRANT needed: the app user already
-- holds wucportal.* DML.
