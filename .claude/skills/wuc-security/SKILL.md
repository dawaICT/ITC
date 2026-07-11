---
name: wuc-security
description: >
  Apply and audit security controls in the WUC portal — CSRF tokens, XSS
  escaping, session management, security headers, input validation, login
  lockout, and role/auth guards. Use whenever a task touches auth, form
  processing, user input, redirects, or when reviewing a page for security
  issues. This codebase had 42 security bugs fixed in a single audit
  (2026-06-28) — treat every boundary as untrusted.
---

# WUC Portal — Security Developer

## Where the helpers live

| File | Purpose |
|------|---------|
| `includes/security.php` | Low-level: headers, session cookie config, URL sanitisation, CSRF token gen |
| `includes/session_guard.php` | Unified session start + idle timeout + flash messages (requires `security.php`) |
| `includes/auth_helpers.php` | Login lockout (`wuc_is_login_locked`, `wuc_record_login_attempt`) |
| `includes/role_helpers.php` | Role constants (`ROLE_*`) + `canAccessTransport()`, `canAccessAdmissions()` etc. |
| `config/auth_check.php` | `hydrateStaffRolesFromDatabase()` — populates `$_SESSION['all_roles']` |
| `students/includes/guard.php` | Student-specific session gate |
| `transport/includes/transport.php` | Transport-specific session gate |

## Security headers — always send before HTML

`wuc_apply_security_headers()` emits the full set (called automatically by every guard):

```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), payment=(), usb=()
Cache-Control: no-store … (when $noStore=true)
Strict-Transport-Security (HTTPS only)
```

**Never remove `header_remove('X-Powered-By')`** — it's called inside
`wuc_apply_security_headers()` and stops PHP version disclosure.

## Session management

Call the guard include at the very top of every protected page (before any output):

```php
// Student page
require_once __DIR__ . '/includes/guard.php';   // handles session start, auth, redirect

// Staff/admin page
require_once __DIR__ . '/../../includes/session_guard.php';
wuc_enforce_session_guard([
    'context'          => 'admin',
    'session_keys'     => ['staff_id', 'user_id'],
    'activity_keys'    => ['last_activity', 'last_active_time'],
    'timeout'          => 1800,          // 30 min idle
    'post_grace'       => 30,
    'login_path'       => '/wucportal/staff_login.php',
    'flash_key'        => 'errorMessage',
    'timeout_message'  => 'Your session has expired. Please log in again.',
    'login_message'    => 'Please log in to continue.',
]);
```

Session cookies are configured by `wuc_configure_session_cookie()`:
- `httponly=true`, `samesite=Lax`, `use_only_cookies=1`
- `secure=true` on HTTPS automatically

**Never call `session_start()` directly** on guarded pages — the guard does it and sets cookie params correctly first.

## CSRF tokens

Generate once per session and embed in every mutating form:

```php
// In controller (before HTML):
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// In form HTML:
<input type="hidden" name="csrf_token"
       value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

// In POST handler:
$token = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    $errors[] = 'Request verification failed. Refresh and try again.';
}
```

Use `hash_equals()` — NOT `==` or `===` — to prevent timing attacks.

Transport module uses `$_SESSION['transport_csrf']` as a separate namespace:
```php
if (empty($_SESSION['transport_csrf'])) {
    $_SESSION['transport_csrf'] = bin2hex(random_bytes(32));
}
```

## XSS — escape every dynamic value

```php
// Single value — always use this in HTML context:
<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>

// Shorthand helper (define once per file):
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
```

Rules:
- **Never echo raw DB values** — even integers (e.g. IDs used in onclick/href).
- **No raw AI output in HTML** — use `wuc_ai_output_block()` (see [[wuc-ai]]).
- For JS context: JSON-encode and wrap in quotes: `<?= json_encode($val, JSON_HEX_TAG) ?>`.
- `htmlspecialchars_decode()` is only acceptable when the input was previously escaped by this codebase and you're round-tripping. When in doubt, escape again.

## Input validation pattern

Never trust `$_GET`, `$_POST`, or `$_FILES`. Sanitise at the boundary:

```php
// Scalar — extract then validate, never use raw:
$studentId = trim((string)($_POST['student_id'] ?? ''));
if (!preg_match('/^[A-Za-z0-9]{3,20}$/', $studentId)) {
    $errors[] = 'Invalid student ID.';
}

// Integer — cast, then range-check:
$year = (int)($_POST['year'] ?? 0);
if ($year < 2020 || $year > 2030) { $errors[] = 'Invalid year.'; }

// Allowlist — enum-like fields:
$status = (string)($_POST['status'] ?? '');
if (!in_array($status, ['active', 'inactive', 'pending'], true)) {
    $errors[] = 'Invalid status.';
}

// File uploads — always validate MIME on the server, never trust the extension:
$allowedMime = ['image/jpeg', 'image/png', 'application/pdf'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($_FILES['upload']['tmp_name']);
if (!in_array($detectedMime, $allowedMime, true)) {
    $errors[] = 'File type not allowed.';
}
```

## Redirect safety

Never redirect to a user-supplied URL directly. Use the portal helper:

```php
require_once dirname(__DIR__) . '/includes/security.php';
$safe = wuc_normalize_local_url($_GET['return'] ?? '', '/wucportal/index.php');
header('Location: ' . $safe);
exit;
```

`wuc_normalize_local_url()` strips null bytes, CRLFs, and rejects off-origin URLs,
falling back to the supplied default.

## SQL injection — always prepared statements

This is covered by [[wuc-backend]] and [[wuc-schema-debug]], but the rule bears
repeating here: **zero string-concatenation of user input into SQL, ever**.

```php
// RIGHT:
$stmt = $db->prepare("SELECT * FROM students WHERE SID = ?");
$stmt->bind_param("s", $studentId);

// WRONG (injection risk):
$db->query("SELECT * FROM students WHERE SID = '$studentId'");
```

## Login lockout

`wuc_is_login_locked($db, $ip, $userId)` in `includes/auth_helpers.php` blocks
brute-force after 5 failed attempts in 15 minutes. Key facts:

- **Loopback IPs** (`::1`, `127.*`, empty) lock **per user only** (not per IP),
  so local dev doesn't lock everyone out. Fixed 2026-06-20.
- Failed attempts live in `login_attempts`; clear stuck local entries with:
  `DELETE FROM login_attempts WHERE ip_address IN ('::1','127.0.0.1') OR user_id LIKE 'staff:%'`
- Call `wuc_record_login_attempt($db, $ip, $userId)` on every failed login.
- On success, call `wuc_clear_login_attempts($db, $ip, $userId)`.

## Role-based access control

Check roles via the constants and helpers in `includes/role_helpers.php` — never
hard-code role strings:

```php
// Module-level gate (transport page):
if (!canAccessTransport()) { http_response_code(403); exit; }

// Admin-only check:
$isAdmin = in_array(ROLE_SYSTEMS_ADMIN, (array)($_SESSION['all_roles'] ?? []), true);

// Page-level role check:
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['systems_admin', 'registrar'], true)) {
    header('Location: /wucportal/staff_login.php'); exit;
}
```

`hydrateStaffRolesFromDatabase()` (called by the staff guards) populates
`$_SESSION['all_roles']` from `staff_positions` — use `all_roles` for multi-role
staff rather than just `$_SESSION['role']`.

## Error disclosure — never show internals

```php
// Log the technical detail:
error_log('Query failed: ' . $db->error);

// Show the user something safe:
$errors[] = 'A database error occurred. Please try again.';
// or use the portal error template:
require_once dirname(__DIR__) . '/students/includes/error_template.php';
```

Paths that are dangerous to echo: raw SQL, stack traces, `$db->error`,
`$_SERVER`, and exception messages. The portal's `wuc_render_error_response()`
is the approved way to show a page-level fatal.

## Security checklist (audit a page)

- [ ] Guard include is the **first** thing (before any output)
- [ ] CSRF token present in every `<form method="post">` and checked with `hash_equals()`
- [ ] Every echoed value is `htmlspecialchars()`-escaped
- [ ] All SQL uses prepared statements (grep for `"SELECT.*\$` — should be zero hits)
- [ ] File uploads check MIME with `finfo`, not just extension
- [ ] Redirects use `wuc_normalize_local_url()` (not raw `$_GET['return']`)
- [ ] No raw exception/DB error text reaches the browser
- [ ] Role check happens before any data is fetched
- [ ] `hash_equals()` used for token comparisons (not `==`)
