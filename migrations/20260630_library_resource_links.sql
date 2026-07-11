-- Migration: library_resource_links — the academic-scope backbone for the
-- Academic Resource Centre. The library previously had NO link between a
-- catalogue item / digital resource and a course, programme, or department:
-- the only classification was a free-text `subject`. This table adds that
-- missing relationship WITHOUT touching any existing table, so the new
-- "course-based / programme-based / department library" views can resolve
-- exactly which resources a given student or lecturer should see.
--
-- A single resource may be linked to many scopes (e.g. one textbook used by
-- three courses). scope_ref holds the course_code / program_code /
-- department_id depending on scope_type; it is NULL for institution-wide
-- 'public' resources. `visibility` lets a resource be staff-only or
-- student-facing.
--
-- Apply as a DDL-capable user (root locally / wucportal_migrator in production).
-- Idempotent on MariaDB.

CREATE TABLE IF NOT EXISTS library_resource_links (
  id            INT(11)     NOT NULL AUTO_INCREMENT,
  resource_kind ENUM('item','digital') NOT NULL DEFAULT 'item',
  resource_id   INT(11)     NOT NULL,
  scope_type    ENUM('course','programme','department','public') NOT NULL,
  scope_ref     VARCHAR(50) DEFAULT NULL,
  visibility    ENUM('students','staff','all') NOT NULL DEFAULT 'all',
  created_by    VARCHAR(32) DEFAULT NULL,
  created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_link (resource_kind, resource_id, scope_type, scope_ref),
  KEY idx_scope (scope_type, scope_ref),
  KEY idx_resource (resource_kind, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No GRANT needed: the app user already holds wucportal.* DML and reads
-- this table the same way it reads library_items / library_digital_resources.
