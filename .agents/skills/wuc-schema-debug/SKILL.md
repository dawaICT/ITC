---
name: wuc-schema-debug
description: >
  Diagnose and fix "Unknown column", "Table doesn't exist", and other SQL
  schema-mismatch fatal errors in the WUC portal (PHP/mysqli/XAMPP). Use this
  whenever a page throws a mysqli_sql_exception, when editing or reviewing any
  SQL query in this codebase, or before trusting that a query's tables and
  columns actually exist. The code in this repo frequently references tables and
  columns that are NOT in the live database, so ALWAYS verify a query against the
  real schema before assuming it works.
---

# WUC Portal — SQL Schema-Mismatch Debugging

## Why this skill exists

This codebase was written against a schema that drifted from the actual
database. Queries routinely reference tables and columns that do not exist, and
because mysqli is configured to throw exceptions, each mismatch is a **fatal
error** that takes the whole page down. Errors surface one at a time: you fix one
query, reload, and the next bad query down the page throws. The fix is not to
play whack-a-mole — it's to verify every query on the page against the live
schema in one pass.

## The core rule

**Never trust a query in this repo. Verify its tables and columns against the
live database before assuming it runs.** A query that "looks right" is the most
common source of production fatals here.

## Tools you have

- **PHP CLI** (most reliable for inspecting the DB): `E:\xampp\php\php.exe`
- The DB connection bootstrap: `require_once __DIR__ . '/../db/connect.php';`
  exposes `$db` as a configured `mysqli` (database `wucportal`, host
  `127.0.0.1`, user `root`, empty password, utf8mb4).
- The `mysql.exe` client (`E:\xampp\mysql\bin\mysql.exe`) exists but has been
  unreliable / silent in this environment — prefer PHP CLI for inspection.

## Diagnostic workflow

### 1. Read the error precisely

A fatal like:

```
Uncaught mysqli_sql_exception: Unknown column 'fs.entity_type' in 'where clause'
... in students/index.php:114
```

tells you the exact file, line, and the offending identifier. Open that line and
identify the table and the column.

### 2. Inspect the real schema

Run from the project root (`E:\xampp\htdocs\wucportal`). Check whether a table
exists:

```bash
"E:\xampp\php\php.exe" -r "require_once 'db/connect.php'; \$r=\$db->query('SHOW TABLES LIKE \"payments\"'); echo (\$r && \$r->num_rows>0)?'EXISTS':'MISSING';"
```

List a table's real columns:

```bash
"E:\xampp\php\php.exe" -r "require_once 'db/connect.php'; \$r=\$db->query('DESCRIBE fee_structure'); while(\$row=\$r->fetch_assoc()) echo \$row['Field'].' '.\$row['Type'].\"\n\";"
```

Find candidate tables when a name is wrong:

```bash
"E:\xampp\php\php.exe" -r "require_once 'db/connect.php'; \$r=\$db->query('SHOW TABLES LIKE \"%pay%\"'); while(\$row=\$r->fetch_row()) echo \$row[0].\"\n\";"
```

### 3. Decide the fix per the decision table below

| Situation | Correct fix |
|---|---|
| Column has a different real name | Rename the identifier to the real column. |
| Column genuinely doesn't belong | Drop that condition / select item. |
| Table has a different real name | Point the query at the real table (and fix its column names too — they often differ together). |
| Table is genuinely optional/absent | Guard the query so the page degrades gracefully (see below) rather than fatals. |
| Required data row is simply missing | The schema is fine — seed the data (see [[wuc-seed-test-accounts]]). |

### 4. Guard truly-optional tables

When a table may legitimately not exist in some installs, don't let it fatal.
Check first and fall back to the page's existing empty state:

```php
$Records = [];
$tblRes = $db->query("SHOW TABLES LIKE 'announcement'");
if ($tblRes && $tblRes->num_rows > 0) {
    // ... run the real query, populate $Records ...
}
if ($tblRes) { $tblRes->free(); }
```

There is already a reusable helper in `students/index.php`:
`student_dashboard_table_exists($db, $table)` — prefer it where present.

### 5. Sweep the whole page, don't stop at the first error

After fixing the reported line, scan every other query on the same page and
verify each one the same way **before** handing back. Reproduce the page's query
sequence end-to-end for a known record to confirm nothing else throws:

```bash
"E:\xampp\php\php.exe" -r "
require_once 'db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  \$db->query('SELECT ...query 1... ');
  \$db->query('SELECT ...query 2... ');
  echo 'ALL QUERIES OK';
} catch (Throwable \$e) { echo 'FAILED: '.\$e->getMessage(); }
"
```

Getting ahead of the cascade this way is the difference between one fix and ten
round-trips with the user.

## Known-good schema (verified)

These tables and their key columns are confirmed to exist. Use them as the
source of truth when correcting queries.

- `students` — PK `SID`; `Fname`, `Lname`, `email`, `mobile`, `profile_image`, `status`, `program`, `academic_year`, `intake`, `mode`, `year`
- `student_login` — `Sid` (PK), `Password`
- `student_program` — `Sid`, `program_code`, `intake`, `mode`, `startYear`, `endYear`, `status`, `academic_year`, `term`
- `programs` — `program_code` (PK), `program_name`, `program_type`, `study_mode`, `program_duration`, `is_active`
- `payments` — `student_id`, `receipt_no`, `amount`, `method`, `payment_date`, `status`
- `fee_structure` — `program_code`, `year_of_study`, `semester`, `fee_description`, `amount`, `status`
- `semester_registration` — `student_id`, `academic_year`, `semester`, `year_of_study`
- `courses` — `course_code`, `course_name`
- `course_registration` — student↔course enrollment (column names vary; resolve dynamically)
- `staff` — `staff_id` (PK), `Fname`, `Lname`, `email`, `role`, `status`
- `user_credentials` — `staff_id` (PK), `pass`
- `positions` — `PosID` (PK), `PosName`
- `staff_positions` — `staff_id`, `PosID`

## Phantom references seen in this codebase (do NOT trust these)

These appear in code but do **not** exist in the live DB — each caused a real
fatal. When you see them, apply the mapping:

| Wrong (in code) | Reality |
|---|---|
| table `student_payments` | use `payments` |
| table `announcement` | does not exist — guard it (only `elearning_announcements` exists, and it's course-scoped) |
| table `student_courses` | does not exist — `getStudentEnrolledCourses()` falls through to `course_registration` |
| `programs.duration_months` | real column is `program_duration` |
| `programs.period_mode` | no such column — alias `study_mode AS period_mode` (so term/semester logic still works) |
| `fee_structure.entity_type` | no such column — drop the condition |
| `payments` columns `Sid`, `amount_paid`, `payment_status` | real columns are `student_id`, `amount`, `status` |

Note how table renames and column renames travel together: `student_payments`
also implied wrong column names. When you correct a table, re-check its columns.

## Where errors are logged

Runtime fatals are written to `logs/error.log`. The DB bootstrap
(`db/connect.php` → `includes/error_bootstrap.php`) renders a friendly message
via `wuc_render_error_response()` while logging the technical detail there.
