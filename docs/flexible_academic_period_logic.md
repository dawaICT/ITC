# Flexible Academic Period Logic

## Exact Logic That Was Causing Drift

1. Dashboard fee totals joined `fee_structure` to the latest `semester_registration`, then summed all successful payments for the student. The fee side was period-scoped, but the payment side was all-time, so balances could be wrong.
2. Course submission in `students/processCourseReg.php` looked up the latest registration row for a student and used it even when the submitted year/period did not match. That could attach courses and invoices to the wrong intake, term, or semester.
3. Several helpers normalized every program into `semester` or `term`, so short courses, intake-based courses, and duration-based courses were displayed as semester-based by default.
4. Program period AJAX endpoints returned only `semester|term`, forcing forms to hide custom structures even when the selected program was not a semester program.
5. Fee preview and final invoice queries did not consistently include `fee_structure.entity_type = 'program'`, so program and short-course fee rows could be mixed when both exist.

## Simple Explanation

The system had period fields, but the code often treated the period as "semester number." A student could be in a term, intake, short course, or duration-based program, yet the dashboard, course form, and fee logic still used semester-shaped queries. The fix is to detect the program structure first, carry that through the registration flow, and only map back to legacy `semester|term` where old database columns still require it.

## Recommended Database Fields

These are the recommended long-term fields. The code currently supports the live schema and safely maps custom structures back into legacy columns until these are added.

```sql
ALTER TABLE programs
  MODIFY period_mode VARCHAR(30) NOT NULL DEFAULT 'semester',
  ADD COLUMN IF NOT EXISTS period_count INT NULL AFTER period_mode,
  ADD COLUMN IF NOT EXISTS period_unit ENUM('day','week','month','term','semester','intake') NULL AFTER period_count;

ALTER TABLE academic_periods
  MODIFY period_type VARCHAR(30) NOT NULL DEFAULT 'semester',
  ADD COLUMN IF NOT EXISTS period_code VARCHAR(50) NULL AFTER period_type,
  ADD COLUMN IF NOT EXISTS intake_id INT NULL AFTER period_code;

ALTER TABLE semester_registration
  MODIFY period_type VARCHAR(30) NOT NULL DEFAULT 'semester',
  MODIFY semester VARCHAR(20) NOT NULL,
  ADD COLUMN IF NOT EXISTS intake_id INT NULL AFTER period_type,
  ADD COLUMN IF NOT EXISTS academic_period_id INT NULL AFTER intake_id;

ALTER TABLE fee_structure
  ADD COLUMN IF NOT EXISTS period_type VARCHAR(30) NULL AFTER short_course_id,
  ADD COLUMN IF NOT EXISTS period_code VARCHAR(50) NULL AFTER period_type,
  ADD INDEX IF NOT EXISTS idx_fee_program_period
    (entity_type, program_code, year_of_study, period_type, semester, status);
```

Recommended values for `programs.period_mode`: `semester`, `term`, `short_course`, `intake`, `duration`.

## Corrected Query Patterns

Latest registration for the student:

```sql
SELECT id, academic_year, semester, year_of_study, period_type
FROM semester_registration
WHERE student_id = ?
ORDER BY id DESC
LIMIT 1;
```

Fee total for the displayed registration period:

```sql
SELECT COUNT(fs.id) AS fee_rows, COALESCE(SUM(fs.amount), 0) AS total_fees
FROM fee_structure fs
WHERE fs.entity_type = 'program'
  AND fs.program_code = ?
  AND fs.year_of_study = ?
  AND fs.semester = ?
  AND fs.status = 'active';
```

Payments for the same period:

```sql
SELECT COALESCE(SUM(amount), 0) AS total_paid
FROM payments
WHERE student_id = ?
  AND status IN ('completed', 'posted', 'confirmed')
  AND academic_year = ?
  AND semester = ?;
```

Course registration must match the selected registration row:

```sql
SELECT id, academic_year, semester, year_of_study
FROM semester_registration
WHERE student_id = ?
  AND semester = ?
  AND year_of_study = ?
  AND period_type = ?
ORDER BY id DESC
LIMIT 1;
```

## XAMPP Testing Steps

1. Start Apache and MySQL in XAMPP.
2. Run syntax checks:

```powershell
& 'C:\xampp\php\php.exe' -l students\index.php
& 'C:\xampp\php\php.exe' -l students\registration.php
& 'C:\xampp\php\php.exe' -l students\courseReg.php
& 'C:\xampp\php\php.exe' -l students\processCourseReg.php
```

3. Verify a term program through the CLI:

```powershell
& 'C:\xampp\php\php.exe' -r "require_once 'db/connect.php'; require_once 'students/includes/period_mode_helper.php'; echo getProgramPeriodMode($db, 'AUTO-001'), PHP_EOL;"
```

4. In the browser, log in as a student assigned to a term program.
5. Open `/wucportal/students/registration.php`; confirm the page says `Term Registration`, not semester.
6. Register the current period, then open `/wucportal/students/courseReg.php`; confirm the year, period label, and program match the registration row.
7. Select courses and submit. If the posted year/period does not match a registration row, the page should reject it instead of using the latest unrelated row.
8. Open `/wucportal/students/index.php`; confirm the status card, course count, and fee balance all refer to the same latest registration period.
9. Test `/wucportal/admin/ajax/get_program_period_type.php?program_code=AUTO-001`; confirm the JSON includes `program_structure`, `period_type`, `period_label`, and `max_periods`.
