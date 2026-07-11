# HARD Debug Prompt — Account Creation Implementation Verification

**Use this when you need to prove the provisioning implementation is correct — not just "it seems to work."**  
This prompt is stricter than `DEBUG_PROMPT_account_creation.md`. Every phase must pass before marking done.

---

## Prompt (copy everything below this line)

```
/wuc-backend /wuc-fullstack /wuc-schema-debug /wuc-security

You are a senior PHP/MySQL engineer, database architect, access-control auditor, QA lead, and security reviewer.

## Mission

**Prove** that the WUC Portal account-creation implementation is production-ready.

You are NOT allowed to:
- Mark the task complete after fixing one file
- Use ITC900 as a lecturer or student baseline
- Grant `systems_admin` to make menus appear
- Manually INSERT rows into `users`/`user_roles` for a broken account without fixing the creation path
- Patch a single user in the database and call it done

You MUST:
- Verify the **live schema** before trusting any query
- Trace **every** account-creation entry point in the codebase
- Run CLI audits and compare DB rows for reference vs newly created accounts
- Test login → session → redirect → sidebar → guarded page for each user type
- Fix root creation logic, not symptoms
- Continue until **all acceptance gates** below pass

---

## Phase 0 — Baseline accounts (do not skip)

| ID | Role | Use for |
|----|------|---------|
| **ITC907** | Pure lecturer | Lecturer feature baseline |
| **ITC900** | Systems Admin / All Roles | RBAC bypass detection ONLY — never copy |
| **CSE26456789** or latest seeded student | Student | Student portal baseline |

Document actual IDs found in DB if different. Run:

```powershell
Set-Location c:\xampp\htdocs\wucportal
C:\xampp\php\php.exe tools\debug_itc_lecturers.php
C:\xampp\php\php.exe tools\audit_lecturer_accounts.php
```

**Gate 0 PASS:** ITC907 resolves as `lecturer` only. ITC900 resolves with `systems_admin` and multiple roles. You understand why ITC900 must NOT be used as lecturer baseline.

---

## Phase 1 — Schema verification (mandatory)

Before changing code, DESCRIBE every table the provisioning layer touches:

```powershell
C:\xampp\php\php.exe -r "
require_once 'db/connect.php';
foreach (['staff','users','user_credentials','user_roles','roles','staff_positions','positions','access_right','role_permissions','user_portal_access','students','student_login','student_program','course_lecturer'] as \$t) {
  \$r = \$db->query(\"SHOW TABLES LIKE '\$t'\");
  echo \$t . ': ' . ((\$r && \$r->num_rows) ? 'EXISTS' : 'MISSING') . PHP_EOL;
  if (\$r && \$r->num_rows) {
    \$d = \$db->query('DESCRIBE ' . \$t);
    while (\$row = \$d->fetch_assoc()) echo '  ' . \$row['Field'] . PHP_EOL;
  }
}
"
```

**Gate 1 PASS:** No query in provisioning helpers references columns that do not exist. Document any optional/missing tables and confirm graceful degradation.

---

## Phase 2 — Central provisioning contract

Read and understand these files **in full**:

| File | Function | Contract |
|------|----------|----------|
| `includes/helpers/staff_provisioning.php` | `wuc_provision_staff_account()` | After call: `users`, `user_roles`, `staff_positions`, `access_right`, `user_portal_access`, lecturer `role_permissions` |
| `includes/helpers/student_provisioning.php` | `wuc_provision_student_account()` | After call: `student_login` (create-if-missing), `users`, `user_roles`, portal sync |
| `admissions/includes/registration_handlers.php` | `admissionsEnsureStudentLogin()` | Must delegate to student provision |
| `includes/staff_role_helpers.php` | `wuc_resolve_staff_roles()` | Fallback chain works |
| `staffLogin.php` | login self-heal | Repairs empty `all_roles` |
| `studentLogin.php` | `wuc_repair_student_account_on_login()` | Repairs missing student role |

**Gate 2 PASS:** You can explain in one paragraph what each function guarantees and what it deliberately does NOT do (e.g. does not assign courses).

---

## Phase 3 — Entry-point inventory (grep the entire codebase)

Find EVERY file that creates accounts. Minimum search:

```powershell
rg "INSERT INTO (staff|user_credentials|student_login|users)" --glob "*.php" -l
rg "wuc_provision_staff_account|wuc_provision_student_account|admissionsEnsureStudentLogin" --glob "*.php" -l
```

Build a table:

| File | Creates | Calls provision? | Transaction? | CSRF? | PASS/FAIL |
|------|---------|------------------|--------------|-------|-----------|
| `admin/add_staff.php` | staff | | | | |
| `admin/createAccount.php` | credentials | | | | |
| `admin/add_lecturer.php` | lecturer | | | | |
| `admin/manage_lectures.php` | lecturer | | | | |
| `registrar/add_staff.php` | staff | | | | |
| `registrar/createAccount.php` | credentials | | | | |
| `vc/add_staff.php` | staff | | | | |
| `vc/createAccount.php` | credentials | | | | |
| `grant_access.php` | login only | | | | |
| `admin/employer_accounts.php` | employer staff | | | | |
| `admissions/.../registration_handlers.php` | student | | | | |
| `registrar/add_student.php` | student | | | | |
| `includes/applicant_admission.php` | converted student | | | | |

**Known FAIL candidates until fixed:** `grant_access.php`, `vc/createAccount.php`, `admin/employer_accounts.php`

**Gate 3 PASS:** Every production admin/registrar/admissions path either calls provision or has documented exception. No unlisted INSERT-only paths remain in active UI flows.

---

## Phase 4 — Hardcoded privilege bypass hunt

```powershell
rg "ITC900|WUC900|STU900" --glob "*.php" -g "!tools/*" -g "!scripts/*" -g "!scratch/*"
rg "staff_id\s*===?\s*['\"]" --glob "*.php" -g "!tools/*"
rg "username\s*===?\s*['\"]" --glob "*.php" -g "!tools/*"
rg "systems_admin" --glob "lecturers/**/*.php"
```

**Gate 4 PASS:** No lecturer/student feature visibility depends on a hardcoded username. Any ITC900 references in nav/guards are documented as multi-role admin exceptions only.

---

## Phase 5 — CLI audit (must run, must report output)

```powershell
Set-Location c:\xampp\htdocs\wucportal
C:\xampp\php\php.exe scripts\run_hard_debug_gates.php
C:\xampp\php\php.exe scripts\run_hard_debug_gates.php --apply
```

**Gate 5 PASS:**
```
Staff gaps: 0
Student gaps: 0
```
If not zero, identify each gap ID, trace creation path, fix path, re-run until zero.

---

## Phase 6 — DB row parity (reference vs new account)

For each user type, pick **reference account** and **newly admin-created test account**. Run parity checks:

### Staff/Lecturer parity query

```sql
-- Replace ? with staff_id
SELECT 'staff' src, staff_id, role, status FROM staff WHERE staff_id = ?
UNION ALL
SELECT 'users', username, primary_role, status FROM users WHERE staff_id = ? OR username = ?
;
SELECT r.role_name, ur.status FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
 JOIN users u ON u.user_id = ur.user_id WHERE u.staff_id = ? OR u.username = ?;
SELECT p.PosName FROM staff_positions sp JOIN positions p ON p.PosID = sp.PosID WHERE sp.staff_id = ?;
SELECT portal_key, status FROM user_portal_access upa JOIN users u ON u.user_id = upa.user_id WHERE u.staff_id = ?;
```

### Student parity query

```sql
SELECT SID, status, program, academic_year, intake FROM students WHERE SID = ?;
SELECT program_code, status, academic_year FROM student_program WHERE Sid = ?;
SELECT 1 FROM student_login WHERE Sid = ?;
SELECT u.user_id, u.primary_role FROM users u WHERE u.student_id = ? OR u.username = ?;
SELECT r.role_name FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id JOIN users u ON u.user_id = ur.user_id WHERE u.student_id = ?;
```

**Gate 6 PASS:** New account has same *categories* of rows as reference (not necessarily same course assignments). Missing row category = FAIL.

---

## Phase 7 — End-to-end creation tests (browser + DB)

Create **fresh** test accounts through the real UI. Do not reuse old broken IDs.

### Test A — Admin creates lecturer

1. `admin/add_lecturer.php` OR `admin/add_staff.php` with role=Lecturer
2. Record generated `staff_id`
3. Login at `staff_login.php`
4. Confirm redirect → `lecturers/index.php` (not admin, not applicant)
5. Confirm sidebar shows: Dashboard, Courses, CA, Reports, eLearning (or role-appropriate subset)
6. Confirm empty-state if no `course_lecturer` rows (not blank page)
7. Re-run DB parity for new `staff_id`

**Test A PASS:** Full lecturer shell + empty-state; DB rows complete.

### Test B — Admin creates staff (non-lecturer)

1. `admin/add_staff.php` with role e.g. Accountant
2. `admin/createAccount.php` for same `staff_id`
3. Login → correct staff portal (not lecturer, not admin)
4. Direct URL `lecturers/index.php` → blocked

**Test B PASS:** Correct portal; lecturer pages blocked.

### Test C — Admin/registrar creates student

1. Register via admissions/registrar flow
2. Login at `student_login.php`
3. Confirm redirect → `students/index.php`
4. Confirm programme/intake/year visible OR clean "setup incomplete" message
5. Applicant portal URL → blocked or redirected

**Test C PASS:** Student dashboard works; not treated as applicant.

### Test D — Transaction rollback

Simulate provision failure (temporarily break a non-critical optional insert OR use test DB):
- Staff insert + provision in transaction must **rollback** entire staff row if provision fails
- No orphan `staff` without `users`

**Test D PASS:** No half-created accounts after forced failure.

### Test E — Duplicate prevention

- Duplicate staff_id / email / student SID → rejected with clear error
- No second `user_credentials` row for same staff_id

**Test E PASS:** Duplicates blocked.

### Test F — Login self-heal

1. Manually delete `user_roles` row for test lecturer (test DB only)
2. Login again
3. Confirm `all_roles` restored without manual SQL

**Test F PASS:** Self-heal works on staff and student login paths.

---

## Phase 8 — Security hardening verification

| Check | How | PASS |
|-------|-----|------|
| Prepared statements | No string-concat INSERT with `$_POST` in creation paths | |
| Password hashing | `password_hash()` on create; no plain text in DB | |
| CSRF | POST to `add_staff.php`, `createAccount.php` without token → rejected | |
| Role escalation | POST `role=systems_admin` as non-admin → rejected/normalized | |
| Direct URL | Student → `admin/index.php` blocked | |
| Direct URL | Lecturer → `admin/index.php` blocked | |
| Direct URL | Lecturer → `lecturers/upload_ca.php?course=NOT_ASSIGNED` blocked | |
| Error exposure | Force DB error → friendly page, detail in `logs/error.log` only | |

**Gate 8 PASS:** All security checks pass.

---

## Phase 9 — Session structure verification

After login, dump session keys (dev only) for each account type:

### Staff/Lecturer expected keys
- `staff_id` (or equivalent)
- `user_id_db` (integer — `users.user_id`)
- `role` (canonical)
- `all_roles` (non-empty array)
- `logged_in` = true

### Student expected keys
- `Sid` and `student_id` (same value)
- `user_id_db`
- `role` = `student`
- `logged_in` = true

**Gate 9 PASS:** Sessions consistent; `user_id_db` present for RBAC calls.

---

## Phase 10 — Permission module matrix

Confirm these modules resolve via role/permission — NOT username:

| Module key | Lecturer | Student | Staff |
|------------|----------|---------|-------|
| `dashboard.view` | ✓ | ✓ | ✓ |
| `courses.view` / lecturer courses | ✓ | — | — |
| `ca.upload` | ✓ (course-scoped) | — | — |
| `elearning.view` | if authorized | if authorized | — |
| `reports.view` | ✓ | — | optional |

Check: `includes/role_helpers.php`, `includes/auth.php`, `canAccessModule()`, `includes/nav_unified.php`

**Gate 10 PASS:** Module visibility matches role; no username gate.

---

## Phase 11 — Applicant → student conversion

If applicant flow exists:

1. Accept/convert applicant
2. Confirm applicant status updated
3. Confirm `students` + `student_program` + login provision
4. Applicant login → redirected away from applicant portal
5. Second conversion attempt → blocked

**Gate 11 PASS:** Single student record; applicant access ended.

---

## Phase 12 — Deliverables (required format)

Do not skip any section:

1. **Root cause summary** — what was broken and why
2. **Schema findings** — tables/columns verified; phantom references fixed
3. **Entry-point audit table** — every creation file with PASS/FAIL
4. **Files changed** — with one-line reason each
5. **CLI audit output** — before and after (must show 0 gaps)
6. **DB parity** — reference vs new account diff
7. **E2E test results** — Tests A–F with PASS/FAIL
8. **Security results** — Phase 8 table
9. **Session/redirect results** — Phase 9–10
10. **Remaining risks** — e.g. `grant_access.php`, `vc/createAccount.php`
11. **Recommended next fixes** — prioritized list
12. **Sign-off** — explicit statement: "All gates passed" OR list of failing gates

---

## Stop conditions

**You may stop ONLY when:**
- [ ] Gate 0–5 all PASS
- [ ] Gate 6 DB parity PASS for staff, lecturer, student
- [ ] Tests A–F all PASS
- [ ] Gate 8–10 all PASS
- [ ] CLI audit shows `Staff gaps: 0` and `Student gaps: 0`
- [ ] No production creation path inserts login/profile without provision
- [ ] Deliverables sections 1–12 complete

**If any gate fails:** fix root cause in creation logic, re-run from Phase 5. Do not stop at first fix.

---

## Quick invocation (one-liner)

```
HARD DEBUG account creation implementation: verify all gates in tools/DEBUG_PROMPT_HARD_account_creation.md — schema, grep all INSERT paths, CLI audit must show 0 gaps, create fresh lecturer/staff/student via admin UI, DB parity vs ITC907/CSE26456789, login session/redirect/sidebar, security checks, no ITC900 baseline, fix root paths not single users, deliver all 12 sections.
```

---

## File map (implementation under test)

```
includes/helpers/staff_provisioning.php      ← staff/lecturer provision
includes/helpers/student_provisioning.php    ← student provision
includes/staff_role_helpers.php              ← role resolution
includes/role_helpers.php                    ← canAccessAcademics, hasRole
includes/auth_helpers.php                    ← wuc_sync_*_password
includes/portal_access.php                   ← portal grants
includes/nav_unified.php                     ← sidebar filter
staffLogin.php                               ← staff self-heal
studentLogin.php                             ← student self-heal
admin/add_staff.php
admin/createAccount.php
admin/add_lecturer.php
admin/manage_lectures.php
registrar/add_staff.php
registrar/createAccount.php
admissions/includes/registration_handlers.php
scripts/run_hard_debug_gates.php          ← automated gate runner (Phases 0–6)
scripts/audit_account_creation.php
scripts/backfill_staff_provisioning.php
scripts/backfill_student_provisioning.php
tools/audit_lecturer_accounts.php
tools/debug_itc_lecturers.php
```

---

## Related prompts

- Standard: `tools/DEBUG_PROMPT_account_creation.md`
- Lecturer-only: `tools/DEBUG_PROMPT_lecturer_accounts.md`
```

---

## When to use which prompt

| Situation | Prompt |
|-----------|--------|
| Quick fix / one broken account | `DEBUG_PROMPT_account_creation.md` |
| Lecturer menu/features only | `DEBUG_PROMPT_lecturer_accounts.md` |
| Prove implementation before release | **This file** (`DEBUG_PROMPT_HARD_account_creation.md`) |
