Migration: Add `semester_registration_id` to `course_registration`

What this migration does
- Adds a nullable `semester_registration_id` INT column to `course_registration` (if missing).
- Backfills the column by joining `course_registration` to `semester_registration` on student + semester + year.
- Adds an index and a foreign key constraint `fk_course_reg_sem_reg` referencing `semester_registration(id)` with ON DELETE CASCADE.
- If there are no orphan rows after backfill, the column is set NOT NULL for stronger integrity.

How to run (CLI)
```bash
php -f scripts/migrate_add_semester_registration_fk.php
```

Rollback steps (manual)
1. If you need to remove the FK and column:
```sql
ALTER TABLE course_registration DROP FOREIGN KEY fk_course_reg_sem_reg;
ALTER TABLE course_registration DROP INDEX semester_registration_id;
ALTER TABLE course_registration DROP COLUMN semester_registration_id;
```
2. If you only want to remove the FK but keep the column:
```sql
ALTER TABLE course_registration DROP FOREIGN KEY fk_course_reg_sem_reg;
```

Notes and safety
- This script uses the project's `db/connect.php` to connect to the database.
- It attempts to detect column name variations in `semester_registration` and `course_registration` (e.g., `Sid` vs `student_id`, `Year` vs `year_of_study`).
- The script exits on SQL errors and prints helpful diagnostics for orphan rows that could not be matched.
- Run a DB backup before applying to production.

Next recommended steps
- Run on a staging/dev copy first and inspect orphaned `course_registration` rows reported by the script.
- If orphan rows exist, either create corresponding `semester_registration` rows (if legitimate) or archive/delete invalid `course_registration` rows before setting NOT NULL.
