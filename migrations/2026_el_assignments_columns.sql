-- Migration: add the assignment-posting columns post_assign.php expects on
-- el_assignments. The page previously tried to ADD these at request time, which
-- fataled for the DML-only app user ("ALTER command denied"). Apply as a
-- DDL-capable user (root locally / wucportal_migrator in production).
-- Idempotent on MariaDB (ADD COLUMN IF NOT EXISTS).

ALTER TABLE el_assignments
  ADD COLUMN IF NOT EXISTS assessment_type VARCHAR(20) NOT NULL DEFAULT 'assignment' AFTER title,
  ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255) NULL AFTER description;

-- due_at, created_by, created_at already exist on el_assignments.
-- No GRANT is needed: the app user already holds wucportal.* DML, which covers
-- existing and new columns.
