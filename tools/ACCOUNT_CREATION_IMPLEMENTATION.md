# Account Creation Standardization — Implementation & Fixes

**Project:** WUC Portal (`wucportal`)  
**Scope:** Admin account creation for staff, lecturers, and students  
**Status:** Implemented — automated hard gates pass (21/21 as of 2026-07-08)

This document records what was broken, what was built, which files changed, and how to verify the implementation.

---

## 1. Root cause

Account creation was **fragmented** across many PHP entry points. Each path inserted only part of what login and RBAC require:

| User type | Often created | Often missing |
|-----------|---------------|---------------|
| Staff / Lecturer | `staff`, sometimes `access_right` | `users`, `user_roles`, `staff_positions`, `user_portal_access`, lecturer `role_permissions` |
| Student | `students`, sometimes `student_login` | `users`, `user_roles`, portal access sync, `student_program` linkage |

**Downstream effects:**

- `wuc_resolve_staff_roles()` read `user_roles` first with no fallback → new accounts had empty `all_roles`
- Lecturer sidebar and `canAccessModule()` hid menus for under-provisioned accounts
- ITC900 (Systems Admin / All Roles) was mistakenly used as a lecturer baseline — it bypasses RBAC via `systems_admin`
- Some paths used raw SQL string concatenation instead of prepared statements
- Multi-table inserts were not transactional → half-created accounts possible

---

## 2. Solution architecture

Two centralized provisioning helpers are now the **single entry points** for login + RBAC repair:

```
┌─────────────────────────────────────────────────────────────────┐
│                    Account creation UI paths                     │
│  admin/add_staff, add_lecturer, createAccount, registrar/*,    │
│  vc/*, admissions registration, applicant conversion, etc.     │
└────────────────────────────┬────────────────────────────────────┘
                             │
         ┌───────────────────┴───────────────────┐
         ▼                                       ▼
┌─────────────────────────┐         ┌─────────────────────────────┐
│ wuc_provision_staff_    │         │ wuc_provision_student_      │
│ account()               │         │ account()                     │
│ staff_provisioning.php  │         │ student_provisioning.php    │
└────────────┬────────────┘         └──────────────┬──────────────┘
             │                                      │
             ▼                                      ▼
   users, user_roles, staff_positions,     student_login, users,
   access_right, user_credentials,          user_roles, portal sync
   user_portal_access, role_permissions
             │                                      │
             └──────────────┬───────────────────────┘
                            ▼
              Login self-heal on staffLogin.php /
              studentLogin.php if gaps remain
```

### Staff / lecturer provision (`wuc_provision_staff_account`)

**File:** `includes/helpers/staff_provisioning.php`

After a successful call, the account should have:

- `users` row (`username = staff_id`, `primary_role` set)
- `user_roles` row for canonical role
- `staff_positions` row (display name from role)
- `access_right` row (legacy compatibility)
- `user_credentials` row (legacy login)
- `user_portal_access` for role-appropriate portals
- Lecturer `role_permissions` when role is `lecturer`
- `staff.role` normalized if drifted

**Does not:** assign courses, departments, or programme links (those remain separate admin steps with empty-state UI when missing).

### Student provision (`wuc_provision_student_account`)

**File:** `includes/helpers/student_provisioning.php`

After a successful call, the account should have:

- `student_login` (create-if-missing; preserves existing password when `only_create_login=true`)
- `users` + `user_roles` (student) via `wuc_sync_student_password()`
- Portal access via `wuc_sync_student_portal_access_after_registration()`
- Student `role_permissions` (`dashboard.view`, `elearning.view`)

**Delegates:** `admissionsEnsureStudentLogin()` in `admissions/includes/registration_handlers.php`

### Role resolution fallback chain

**File:** `includes/staff_role_helpers.php` — `wuc_resolve_staff_roles()`

1. `user_roles` → `roles`
2. Fallback: `staff_positions` → `positions`
3. Fallback: `access_right`
4. Fallback: `staff.role` / `users.primary_role`

### Login self-heal

| File | Behavior |
|------|----------|
| `staffLogin.php` | Repairs empty `all_roles` via `wuc_provision_staff_account()` |
| `studentLogin.php` | Repairs missing student role via `wuc_repair_student_account_on_login()` |

---

## 3. Files created (new)

| File | Purpose |
|------|---------|
| `includes/helpers/staff_provisioning.php` | Central staff/lecturer provisioning |
| `includes/helpers/student_provisioning.php` | Central student provisioning |
| `includes/helpers/lecturer_course_helpers.php` | Resolved course codes + setup empty-state notices |
| `scripts/audit_account_creation.php` | Unified staff + student gap audit/backfill |
| `scripts/backfill_student_provisioning.php` | Student-only backfill wrapper |
| `scripts/backfill_staff_provisioning.php` | Staff/lecturer backfill |
| `scripts/run_hard_debug_gates.php` | Automated hard-debug gate runner (Phases 0–6) |
| `tools/audit_lecturer_accounts.php` | Compare lecturer RBAC records |
| `tools/debug_itc_lecturers.php` | ITC907 vs ITC900 comparison |
| `tools/repair_lecturer_orphan_assignments.php` | Deactivate orphan `course_lecturer` rows |
| `tools/DEBUG_PROMPT_account_creation.md` | Standard debug prompt |
| `tools/DEBUG_PROMPT_lecturer_accounts.md` | Lecturer feature-gap debug prompt |
| `tools/DEBUG_PROMPT_HARD_account_creation.md` | Strict pre-release verification prompt |
| `tools/ACCOUNT_CREATION_IMPLEMENTATION.md` | This document |

---

## 4. Files changed (fixes)

### Staff & lecturer creation

| File | Fix |
|------|-----|
| `admin/add_staff.php` | Transaction + `wuc_provision_staff_account()` after staff insert |
| `admin/createAccount.php` | Provision after `user_credentials` insert |
| `admin/add_lecturer.php` | Prepared statements, transaction, `role=lecturer`, provision |
| `admin/manage_lectures.php` | Same for inline “add lecturer” action |
| `registrar/add_staff.php` | Transaction + provision (already had pattern; verified) |
| `registrar/createAccount.php` | Provision after credential insert |
| `vc/add_staff.php` | Transaction wraps insert + provision; rollback on failure |
| `vc/createAccount.php` | Rewritten: CSRF, password policy, provision after credentials |
| `admin/employer_accounts.php` | Uses `wuc_provision_staff_account('employer')` instead of manual `users` insert |

### Student creation & login

| File | Fix |
|------|-----|
| `admissions/includes/registration_handlers.php` | `admissionsEnsureStudentLogin()` delegates to `wuc_provision_student_account()` |
| `studentLogin.php` | `wuc_repair_student_account_on_login()` on successful login |
| `studentPasswordReset.php` | Calls student provision after password set/reset |

### RBAC, access control & lecturer dashboard

| File | Fix |
|------|-----|
| `includes/staff_role_helpers.php` | Role resolution fallback chain |
| `includes/role_helpers.php` | `canAccessAcademics()` allows lecturer/HOS/dean/registrar by role |
| `includes/nav_unified.php` | Lecturer inherent modules bypass strict RBAC filter |
| `staffLogin.php` | Auto-repair empty `all_roles` on login |
| `includes/helpers/staff_provisioning.php` | Employer/alumni portal defaults; lecturer role permissions |
| `lecturers/index.php` | Setup notice when no courses assigned |
| `includes/lecturer_insights_engine.php` | Uses resolved courses; distinct student counts |
| `includes/academic_risk_engine.php` | Uses resolved courses |
| `includes/elearning_insights_engine.php` | Uses resolved courses |
| `includes/elearning_access.php` | INNER JOIN `courses` for assignment checks |

### Security

| File | Fix |
|------|-----|
| `grant_access.php` | Disabled at top (`403`) — legacy utility blocked |
| `admin/add_lecturer.php` | Replaced raw SQL with prepared statements |
| `vc/createAccount.php` | Added CSRF token to form and validation |

---

## 5. Baseline test accounts (do not misuse)

| Account | Role | Use as baseline for |
|---------|------|---------------------|
| **ITC907** | Pure lecturer | Lecturer menus, dashboard, RBAC |
| **ITC900** | Systems Admin / All Roles | RBAC bypass detection only — **not** lecturer baseline |
| **CSE26456789** | Student | Student portal baseline |

**Never:** copy ITC900 rows, grant `systems_admin`, or hardcode `if (username == 'ITC900')` to fix missing features.

---

## 6. Verification commands

```powershell
Set-Location c:\xampp\htdocs\wucportal

# Full automated gate check (recommended before release)
C:\xampp\php\php.exe scripts\run_hard_debug_gates.php

# Apply backfill if gaps found, then re-run
C:\xampp\php\php.exe scripts\run_hard_debug_gates.php --apply

# Individual audits
C:\xampp\php\php.exe scripts\audit_account_creation.php
C:\xampp\php\php.exe scripts\backfill_staff_provisioning.php --apply
C:\xampp\php\php.exe scripts\backfill_student_provisioning.php --apply
C:\xampp\php\php.exe tools\audit_lecturer_accounts.php
C:\xampp\php\php.exe tools\debug_itc_lecturers.php
```

### Expected gate runner output

```
ALL HARD GATES PASSED
Passed: 21
Failed: 0
Staff gaps: 0
Student gaps: 0
```

---

## 7. Entry-point compliance (Phase 3)

| Path | Creates | Provision call | Transaction | Status |
|------|---------|----------------|-------------|--------|
| `admin/add_staff.php` | Staff profile | `wuc_provision_staff_account` | Yes | PASS |
| `admin/createAccount.php` | Staff credentials | `wuc_provision_staff_account` | No* | PASS |
| `admin/add_lecturer.php` | Lecturer | `wuc_provision_staff_account` | Yes | PASS |
| `admin/manage_lectures.php` | Lecturer (add) | `wuc_provision_staff_account` | Yes | PASS |
| `admin/employer_accounts.php` | Employer staff | `wuc_provision_staff_account` | Yes | PASS |
| `registrar/add_staff.php` | Staff | `wuc_provision_staff_account` | Yes | PASS |
| `registrar/createAccount.php` | Credentials | `wuc_provision_staff_account` | No* | PASS |
| `vc/add_staff.php` | Staff | `wuc_provision_staff_account` | Yes | PASS |
| `vc/createAccount.php` | Credentials | `wuc_provision_staff_account` | No* | PASS |
| `admissions/.../registration_handlers.php` | Student login | `wuc_provision_student_account` | Varies | PASS |
| `registrar/add_student.php` | Student | via `admissionsEnsureStudentLogin` | Varies | PASS |
| `includes/applicant_admission.php` | Converted student | via `admissionsEnsureStudentLogin` | Varies | PASS |
| `grant_access.php` | Legacy | N/A — **disabled** | — | PASS |
| `staffLogin.php` | — | Self-heal provision | — | PASS |
| `studentLogin.php` | — | Self-heal repair | — | PASS |

\*Credential-only endpoints provision after insert; staff row must already exist from `add_staff.php`.

---

## 8. Complete account checklists

### Staff account

- [x] `staff` row (`role`, `status=active`)
- [x] `users` row
- [x] `user_roles` for canonical role
- [x] `staff_positions` matching role
- [x] `user_portal_access` for default portals
- [x] `user_credentials` (via createAccount or provision)

### Lecturer account (staff items plus)

- [x] `staff.role = lecturer`
- [x] Lecturer `role_permissions` (CA, courses, elearning, reports)
- [x] `user_portal_access` includes `elearning` when authorized
- [x] Empty-state on dashboard if no `course_lecturer` rows

### Student account

- [x] `students` profile row
- [x] `student_login` row
- [x] `users` + `user_roles` (student)
- [x] Portal access synced
- [ ] `student_program` row — warned if missing (incomplete setup, not a login blocker)

---

## 9. Session & redirect expectations

### Staff / lecturer (`staffLogin.php`)

- `$_SESSION['staff_id']`, `$_SESSION['user_id_db']`, `$_SESSION['all_roles']`, `$_SESSION['role']`
- Lecturer → `lecturers/` portal (not generic staff, not admin)

### Student (`studentLogin.php`)

- `$_SESSION['Sid']`, `$_SESSION['student_id']`, `$_SESSION['user_id_db']`, `$_SESSION['role'] = student`
- Redirect via `wuc_after_login_portal_url()`

---

## 10. Secondary data fixes (lecturer dashboard)

These were separate from provisioning but affected lecturer feature visibility:

| Issue | Fix |
|-------|-----|
| Orphan `course_lecturer` rows (courses deleted, assignments left) | `tools/repair_lecturer_orphan_assignments.php`; reset script updated |
| Duplicate COM101 row for ITC900 (`academic_year=1` vs `2026`) | Repair script |
| Insights summed per-course enrollments instead of distinct students | `lecturer_insights_engine.php` fix |
| ITC900 used as lecturer comparison baseline | Documented; use ITC907 instead |

---

## 11. Remaining risks & recommended follow-ups

| Risk | Priority | Recommendation |
|------|----------|----------------|
| `admin/manage_lectures.php` edit/delete still use raw SQL | Medium | Migrate to prepared statements |
| `admin/employer_accounts.php` toggle/disable flows | Low | Verify `user_roles` stays in sync on status change |
| No `created_by` on all account tables | Low | Extend audit logging if compliance requires |
| Applicant → student conversion | Medium | Manual E2E test with live applicant record |
| Browser E2E (Phase 7 of hard prompt) | Medium | Create lecturer/staff/student via UI and confirm redirects |
| Seed/migration scripts (`grant_access.php` body) | Low | Dead code below `exit`; safe while 403 guard remains |

---

## 12. Manual browser test matrix

Run after deployment:

| # | Action | Expected |
|---|--------|----------|
| A | Admin creates lecturer → login | `lecturers/index.php`, full sidebar, empty-state if no courses |
| B | Admin creates staff → createAccount → login | Correct staff portal; lecturer URLs blocked |
| C | Registrar registers student → login | `students/index.php`; programme visible or setup message |
| D | Force provision failure (test DB) | Transaction rollback; no orphan `staff` row |
| E | Duplicate staff_id / student SID | Clear error; no duplicate rows |
| F | Delete `user_roles` for test lecturer → re-login | Self-heal restores roles |

---

## 13. Related documentation

| Document | When to use |
|----------|-------------|
| `tools/ACCOUNT_CREATION_IMPLEMENTATION.md` | **This file** — what was built and fixed |
| `tools/DEBUG_PROMPT_account_creation.md` | Quick debug of incomplete accounts |
| `tools/DEBUG_PROMPT_lecturer_accounts.md` | Lecturer menu/feature gaps |
| `tools/DEBUG_PROMPT_HARD_account_creation.md` | Strict pre-release verification |

---

## 14. Sign-off

| Check | Result |
|-------|--------|
| Central staff provision helper | Implemented |
| Central student provision helper | Implemented |
| All primary admin/registrar/vc paths wired | Yes |
| Login self-heal (staff + student) | Yes |
| Automated hard gates | **21/21 PASS** |
| CLI audit gaps | **Staff: 0, Student: 0** |
| ITC900 misuse as lecturer baseline | Documented and avoided |
| Legacy `grant_access.php` | Disabled |

**Implementation status: production-ready for account creation paths listed in Section 7.**  
Re-run `scripts/run_hard_debug_gates.php` after any future changes to account creation or RBAC code.
