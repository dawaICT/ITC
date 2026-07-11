---
name: wuc-seed-test-accounts
description: >
  Create or repair test login accounts for the WUC portal — students and every
  staff role (admin, registrar, dean, HOD, lecturer, accountant, admissions,
  librarian). Use whenever someone asks to "create a test user/student/account",
  "seed logins", "make a demo account", needs credentials to log into the portal
  for testing, or when a freshly-created student hits "Account or Program Not
  Found" because supporting rows (program, program assignment) are missing. A
  valid student login needs rows across SEVERAL tables, not just one — this skill
  makes sure all of them exist.
---

# WUC Portal — Seed Test Accounts

## Why this skill exists

Logging into the portal requires a chain of related rows, not a single insert. A
student that exists in `students` but has no row in `student_program` will pass
login and then immediately hit **"Student Account or Program Not Found"**,
because the dashboard inner-joins student → program. The same is true for staff:
a `staff` row is useless without `user_credentials` and a `staff_positions`
mapping. This skill seeds the **complete** chain so accounts actually work.

## The fastest path: run the existing seed script

The repo already has a comprehensive seeder that creates one all-roles superuser,
one account per staff role, a test program, and a fully-wired test student:

```bash
"E:\xampp\php\php.exe" "E:\xampp\htdocs\wucportal\scripts\seed_test_accounts_all_roles.php"
```

It is **idempotent** — every insert uses `ON DUPLICATE KEY UPDATE`, so running it
again repairs/refreshes accounts without creating duplicates. Run it again
whenever an account is broken or you've changed the seeder.

### Credentials it produces

| Account | ID | Password |
|---|---|---|
| Test student | `STU900` | `Student@12345` |
| All-roles superuser | `WUC900` | `Test@12345` |
| Systems Admin | `WUC901` | `Test@12345` |
| Admission Officer | `WUC902` | `Test@12345` |
| Accountant | `WUC903` | `Test@12345` |
| Head of Department | `WUC904` | `Test@12345` |
| Registrar | `WUC905` | `Test@12345` |
| Dean | `WUC906` | `Test@12345` |
| Lecturer | `WUC907` | `Test@12345` |
| Librarian | `WUC908` | `Test@12345` |
| Transport Officer (transport-only) | `WUC909` | `Test@12345` |

`WUC909` is the only account that resolves as **transport-only** (holds just the
`transport_view`/`transport_manage` permissions via the "Transport Officer"
position, no admin/academic role). It triggers `isTransportOnlyUser()` and gets a
focused **Dashboard + Transport Section** menu instead of the full admin sidebar.

Students log in at `student_login.php`; staff at `staff_login.php`.

Other narrower seeders exist in `scripts/` (`seed_test_login.php`,
`seed_staff_test_login.php`, `seed_all_roles_test_user.php`,
`seed_user_portals_test.php`, `seed_elearning_lecturer_accounts.php`) — read them
before reaching for one; the all-roles script supersedes most.

## What a WORKING student account requires

If you create a student by hand (or debug why one is broken), all of these rows
must exist. Missing any of the first three is the usual cause of the
program-not-found error.

1. **`students`** — the person. PK `SID`. Set `program` to a real program_code.
2. **`programs`** — the program must exist (`program_code`, `program_name`,
   `program_type`, `study_mode`, `program_duration`, `is_active`).
3. **`student_program`** — links student → program (`Sid`, `program_code`,
   `intake`, `mode`, `startYear`, `endYear`, `status`, `academic_year`). **The
   dashboard INNER JOINs this — without it, login "succeeds" then errors.**
4. **`student_login`** — `Sid` (PK), `Password`. Student passwords are hashed
   with `password_hash()` (bcrypt) by the all-roles seeder; some legacy rows use
   MD5.

Optional, only needed for richer dashboards: `semester_registration` (drives
"Registered" status and fee lookup), `course_registration` (enrolled courses).

## What a WORKING staff account requires

1. **`staff`** — `staff_id` (PK), name, `email`, `role` (canonical snake_case
   like `systems_admin`), `status = 'active'`.
2. **`user_credentials`** — `staff_id` (PK), `pass` (bcrypt via
   `password_hash()`).
3. **`positions`** — the role must exist (`PosName`); the seeder creates it if
   absent.
4. **`staff_positions`** — maps `staff_id` → `PosID`. A superuser gets a row for
   every position; others get one.
5. **`access_right`** — `staff_id` → `assigned_access` (the human-readable role).

## Adding a new test account to the seeder

Prefer extending `scripts/seed_test_accounts_all_roles.php` over writing one-off
SQL, so the account is reproducible and idempotent:

- For a staff role, add an entry to the `$roles` array (`id`, `first`, `last`,
  `role`, `canonical`). The loop wires `staff` + `user_credentials` +
  `access_right` + `staff_positions` automatically.
- For students, follow the existing block: ensure a `programs` row, insert into
  `students`, insert the matching `student_program` row, then `student_login`.
  Use bcrypt: `password_hash($password, PASSWORD_DEFAULT)`.

Always use `ON DUPLICATE KEY UPDATE` so re-running repairs rather than duplicates.

## Verify after seeding

Confirm the join the dashboard depends on actually resolves:

```bash
"E:\xampp\php\php.exe" -r "
require_once 'db/connect.php';
\$r = \$db->query(\"SELECT sp.Sid, sp.program_code, p.program_name
  FROM student_program sp JOIN programs p ON sp.program_code=p.program_code
  WHERE sp.Sid='STU900'\");
echo (\$r && \$r->num_rows>0) ? 'OK: student linked to program' : 'STILL BROKEN';
"
```

If a seeded student still errors, the cause is almost always a missing
`student_program` or `programs` row — see [[wuc-schema-debug]] to confirm the
join and the surrounding queries.
