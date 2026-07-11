-- Migration: posted_assessments / submitted_assess — the Continuous Assessment
-- upload feature (students/assignments.php + students/submittedAssign.php)
-- reads and writes these tables, but neither exists in the live database.
-- Every request hit them, and with the app's strict mysqli error mode
-- (includes/error_bootstrap.php) a missing-table SELECT/INSERT throws an
-- uncaught mysqli_sql_exception, which the global handler turns into the
-- generic "We could not load this page right now" error page.
--
-- Schema restored from db_schema_dump.txt (this app's own historical dump),
-- with `image`/`file_doc` narrowed from BLOB to VARCHAR: both pages store a
-- generated filename on disk (uploads/<random>.<ext>) and read that filename
-- back to build a download link — they never read/write raw binary content,
-- so BLOB was dead weight even in the original schema.
--
-- Apply as a DDL-capable user (root locally / wucportal_migrator in production).
-- Idempotent on MariaDB.

CREATE TABLE IF NOT EXISTS posted_assessments (
  id          INT(11)      NOT NULL AUTO_INCREMENT,
  title       VARCHAR(255) NOT NULL,
  course_code VARCHAR(50)  NOT NULL,
  dte         VARCHAR(20)  NOT NULL,
  due_dte     VARCHAR(20)  NOT NULL,
  image       VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_course_code (course_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submitted_assess (
  id          INT(11)      NOT NULL AUTO_INCREMENT,
  Sid         VARCHAR(50)  NOT NULL,
  course_code VARCHAR(50)  NOT NULL,
  due_dte     VARCHAR(20)  NOT NULL,
  dte         VARCHAR(20)  NOT NULL,
  file_doc    VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sid (Sid),
  KEY idx_course_code (course_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No GRANT needed: the app user already holds wucportal.* DML and reads/writes
-- these tables the same way it does every other students/* table.
