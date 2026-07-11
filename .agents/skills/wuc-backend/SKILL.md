---
name: wuc-backend
description: >
  Write and modify the WUC portal's PHP/MySQL backend — database queries, login
  and session handling, form processing, role/permission checks, and page
  controllers — following this codebase's mysqli, security, and error-handling
  conventions. Use whenever a task involves server-side logic: adding/editing an
  SQL query, a login or auth flow, processing a POST, wiring a new page's data,
  enforcing access control, or handling DB errors. ALWAYS verify queries against
  the real schema first, because this repo's code frequently references tables
  and columns that don't exist.
---

# WUC Portal — Backend Developer

## Stack & conventions

Plain PHP (no framework) on XAMPP, talking to MySQL via **mysqli with
exceptions enabled**. There is no ORM and no query builder — you write SQL by
hand, which means correctness depends on the SQL matching the live schema.

- PHP CLI for scripts/inspection: `E:\xampp\php\php.exe`
- DB bootstrap: `require_once __DIR__ . '/../db/connect.php';` → `$db`
  (configured `mysqli`, db `wucportal`, utf8mb4/utf8mb4_unicode_ci, host
  `127.0.0.1`, user `root`, empty password).
- App config / environment: `includes/portal_config.php` defines `APP_ENV`
  (currently `production`; some dev-only branches gate on
  `APP_ENV === 'development'`).
- Runtime errors log to `logs/error.log`; the DB bootstrap renders a friendly
  page via `wuc_render_error_response()`.

## Rule 0 — verify the schema before trusting any query

mysqli throws on error, so a query against a non-existent table or column is a
**fatal that takes down the whole page**, and this codebase is full of such
drift. Before writing or trusting a query, confirm its tables/columns exist.
This is important enough to have its own skill — see [[wuc-schema-debug]] for the
inspection commands, the verified known-good schema, and the list of phantom
table/column names that recur here (e.g. `student_payments` should be
`payments`; `programs.duration_months` should be `program_duration`).

Quick check:

```bash
"E:\xampp\php\php.exe" -r "require_once 'db/connect.php'; \$r=\$db->query('DESCRIBE payments'); while(\$row=\$r->fetch_assoc()) echo \$row['Field'].\"\n\";"
```

## Always use prepared statements

Every query that touches a variable goes through a prepared statement — never
string-concatenate user input into SQL. This is the established pattern across
the codebase:

```php
$stmt = $db->prepare("SELECT amount, status FROM payments WHERE student_id = ?");
if ($stmt) {
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { /* ... */ }
    $stmt->close();
}
```

For dynamic `IN (...)` lists, build placeholders and bind with the splat:

```php
$placeholders = implode(',', array_fill(0, count($codes), '?'));
$types = str_repeat('s', count($codes));
$stmt = $db->prepare("SELECT course_code, course_name FROM courses WHERE course_code IN ($placeholders)");
$stmt->bind_param($types, ...$codes);
```

Use upserts for idempotent writes (seeders, settings):
`INSERT ... ON DUPLICATE KEY UPDATE ...`.

## Guard optional tables/data — degrade, don't fatal

If a feature's table may not exist in every install, check before querying and
fall back to a sane empty result so the page survives:

```php
if ($db->query("SHOW TABLES LIKE 'announcement'")->num_rows > 0) {
    // run the query
}
```

There's a reusable `student_dashboard_table_exists($db, $table)` helper in
`students/index.php`. Functions like `getStudentEnrolledCourses()` already probe
several candidate tables and resolve column names dynamically — follow that
defensive style for anything optional.

## Authentication & sessions

- **Student login** lives behind `student_login.php` → session key
  `$_SESSION['Sid']`; **staff** behind `staff_login.php`.
- Passwords: hash with `password_hash($pw, PASSWORD_DEFAULT)` (bcrypt) and verify
  with `password_verify()`. Staff creds live in `user_credentials.pass`; student
  creds in `student_login.Password`. Some legacy student rows are MD5 — handle
  both only where you must, and prefer migrating to bcrypt.
- Validate the session identifier format before using it (the portal rejects
  malformed IDs and destroys the session):

  ```php
  if (!preg_match('/^[A-Za-z0-9\/\-_]+$/', $_SESSION['Sid'])) {
      session_destroy();
      header('Location: ../student_login.php');
      exit();
  }
  ```

## Page controller checklist

A typical portal page (top of file, before any HTML) does, in order:

1. `require_once includes/portal_config.php`.
2. Configure the session cookie (`httponly`, `samesite => 'Strict'`,
   `secure` when HTTPS), `session_start()`, regenerate id once per session.
3. Send security headers: `X-Frame-Options: DENY`,
   `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `X-XSS-Protection`.
4. `require_once db/connect.php`; bail with a logged error if
   `$db->connect_error`.
5. Auth gate: redirect to login if the session id is missing/invalid.
6. Run the (schema-verified) data queries with prepared statements.
7. Then — and only then — emit HTML (escaping every dynamic value; see
   [[wuc-frontend]]).

## Access control (roles)

Authorization is data-driven: a staff member's allowed actions come from
`staff_positions` (→ `positions.PosName`) and `access_right`. To restrict a page
to a role, check the staff member's positions rather than hard-coding IDs, so new
accounts and the all-roles superuser keep working. See [[wuc-seed-test-accounts]]
for how those role rows are structured and seeded.

## Error handling

- Log the technical detail (`error_log(...)`) and show the user something safe —
  never echo raw SQL or exception text to the browser in production.
- For fatal page-level problems, reuse `wuc_render_error_response()` (DB layer)
  or `students/includes/error_template.php` (student pages) instead of `die()`
  with a bare string.
- Don't add error handling for impossible states — validate at the real
  boundaries (session, request input, external data), and trust verified
  internal queries.

## After changes

Reproduce the page's full query sequence for a known record via PHP CLI with
`mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` to confirm nothing
throws (pattern in [[wuc-schema-debug]]), then load the page in a browser.
