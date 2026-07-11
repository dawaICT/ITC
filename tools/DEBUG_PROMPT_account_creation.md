# Debug Prompt — Admin Account Creation (Staff, Lecturers, Students)

Use this when admin-created accounts are incomplete: missing menus, wrong portal redirect, login works but features don't, or half-created DB rows.

---

## Prompt (copy from here)

```
/wuc-backend /wuc-fullstack /wuc-schema-debug

Act as a senior PHP/MySQL full-stack developer, database architect, access-control auditor, and WUC portal systems analyst.

## Objective

Debug, audit, fix, and standardize how admins create user accounts for **staff**, **lecturers**, and **students**.

Ensure every account created by admin is complete, consistent, secure, correctly linked, assigned the correct role, redirected to the correct portal, and given correct features — without manual DB fixes.

## Critical baseline accounts

| Account | Use as reference for |
|---------|---------------------|
| **ITC907** | Pure lecturer (correct lecturer baseline) |
| **ITC900** | Systems Admin / All Roles — NOT a lecturer baseline |
| **CSE26456789** (or seeded test student) | Student portal baseline |

**Do NOT** copy ITC900 rows or grant `systems_admin` to fix missing menus.

---

## Root cause pattern (already identified)

Historical account creation inserted **partial** records:

| User type | Often created | Often missing |
|-----------|---------------|---------------|
| Staff/Lecturer | `staff`, sometimes `access_right` | `users`, `user_roles`, `staff_positions`, `user_portal_access`, `role_permissions` |
| Student | `students`, sometimes `student_login` | `users`, `user_roles`, `student_program`, portal access sync |

Login and menus then fail or show reduced features because RBAC reads `user_roles` first.

---

## 1. Central provisioning entry points

| Function | File | Purpose |
|----------|------|---------|
| `wuc_provision_staff_account()` | `includes/helpers/staff_provisioning.php` | Staff/lecturer login + RBAC + portals |
| `wuc_provision_student_account()` | `includes/helpers/student_provisioning.php` | Student login + RBAC + portals |
| `admissionsEnsureStudentLogin()` | `admissions/includes/registration_handlers.php` | Delegates to student provision (create-if-missing) |

**Login self-heal:**
- `staffLogin.php` → repairs empty `all_roles` via `wuc_provision_staff_account()`
- `studentLogin.php` → repairs missing student role via `wuc_repair_student_account_on_login()`

---

## 2. Account creation files to inspect

### Staff / Lecturer

| Path | File | Must call provision? |
|------|------|---------------------|
| Admin add staff | `admin/add_staff.php` | ✅ `wuc_provision_staff_account()` |
| Admin create login | `admin/createAccount.php` | ✅ after `user_credentials` |
| Admin add lecturer | `admin/add_lecturer.php` | ✅ (fixed) |
| Admin manage lectures | `admin/manage_lectures.php` (add action) | ✅ (fixed) |
| Registrar add staff | `registrar/add_staff.php` | ✅ transaction + provision |
| Registrar create login | `registrar/createAccount.php` | ✅ after `user_credentials` |
| VC add staff | `vc/add_staff.php` | ✅ provision on insert |

### Student

| Path | File | Must ensure login/RBAC? |
|------|------|------------------------|
| Admissions registration | `admissions/includes/registration_handlers.php` | ✅ via `admissionsEnsureStudentLogin()` |
| Admin new student | `admin/regNewStud.php` | delegates to admissions handlers |
| Registrar add student | `registrar/add_student.php` | ✅ `admissionsEnsureStudentLogin()` |
| Applicant conversion | `includes/applicant_admission.php` | ✅ `admissionsEnsureStudentLogin()` |
| Short course enroll | `includes/short_course_actions.php` | ✅ |

---

## 3. Database tables to diff (reference vs broken account)

### Staff / Lecturer

- `staff` — `staff_id`, `role`, `status`, `deptId`
- `users` — `user_id`, `username`, `primary_role`, `staff_id`
- `user_credentials` — legacy login hash
- `staff_positions` → `positions.PosName`
- `access_right` — legacy `assigned_access`
- `user_roles` → `roles.role_name`
- `role_permissions` — lecturer module keys
- `user_portal_access` — `academic`, `elearning`
- `course_lecturer` — course assignments (optional at creation)

### Student

- `students` — `SID`, `status`, `program`, `academic_year`, `intake`
- `student_program` — programme link (required for full setup)
- `student_login` — `Sid`, `Password`
- `users` — `student_id`, `primary_role=student`
- `user_roles` — student role
- `user_portal_access` — academic / elearning
- `semester_registration` / `course_registration` — enrollment state

---

## 4. CLI audit and repair

```powershell
Set-Location c:\xampp\htdocs\wucportal

# Dry-run audit (staff + students)
C:\xampp\php\php.exe scripts\audit_account_creation.php

# Apply repairs
C:\xampp\php\php.exe scripts\audit_account_creation.php --apply
C:\xampp\php\php.exe scripts\audit_account_creation.php --apply --type=staff
C:\xampp\php\php.exe scripts\backfill_student_provisioning.php --apply

# Staff-specific backfill
C:\xampp\php\php.exe scripts\backfill_staff_provisioning.php --apply

# Lecturer comparison
C:\xampp\php\php.exe tools\audit_lecturer_accounts.php
C:\xampp\php\php.exe tools\debug_itc_lecturers.php
```

---

## 5. Complete account checklist

### Staff account must have

- [ ] `staff` row with `role`, `status=active`, department if applicable
- [ ] `users` row (`username = staff_id`)
- [ ] `user_roles` for canonical role
- [ ] `staff_positions` matching role display name
- [ ] `user_portal_access` for role default portals
- [ ] `user_credentials` OR modern password in `users` (via createAccount or provision)

### Lecturer account must have (all staff items plus)

- [ ] `staff.role = lecturer`
- [ ] `role_permissions` for lecturer modules (CA, courses, elearning, reports)
- [ ] `user_portal_access` includes `elearning` when authorized
- [ ] Empty-state on dashboard if no `course_lecturer` rows yet

### Student account must have

- [ ] `students` profile row
- [ ] `student_program` row (warn if missing — incomplete setup)
- [ ] `student_login` row
- [ ] `users` + `user_roles` (student)
- [ ] Portal access synced after registration
- [ ] Not treated as applicant after conversion

---

## 6. Session and redirect expectations

### Staff login (`staffLogin.php`)

- `$_SESSION['staff_id']`, `$_SESSION['user_id_db']`, `$_SESSION['all_roles']`, `$_SESSION['role']`
- Redirect via role → portal map (lecturer → `lecturers/`, not generic staff)

### Student login (`studentLogin.php`)

- `$_SESSION['Sid']`, `$_SESSION['student_id']`, `$_SESSION['user_id_db']`, `$_SESSION['role']=student`
- Redirect via `wuc_after_login_portal_url()`

---

## 7. Security requirements

- Prepared statements on all inserts
- `password_hash()` / `password_verify()` — never plain text
- CSRF on admin/registrar forms where present
- Admin-only guards on account creation pages
- Backend role validation — never trust POST role without `wuc_normalize_staff_role()`
- Transactions when inserting staff + RBAC rows together

---

## 8. Deliverables format

When debugging, report:

1. Root cause
2. Files inspected
3. Files changed
4. Schema issues found
5. Staff / lecturer / student fixes
6. Role and permission fixes
7. Session and redirect fixes
8. Dashboard/sidebar fixes
9. Security improvements
10. Transaction improvements
11. Testing steps
12. Remaining risks

---

## 9. Do NOT

- Manually copy ITC900 data to new accounts
- Hardcode `if ($_SESSION['username'] == 'ITC900')`
- Grant admin/systems_admin to fix menus
- Create `user_credentials` without `users` + `user_roles`
- Create `student_login` without `users` + `user_roles`
- Leave half-created accounts (use transactions + rollback)
```

---

## Related tools

- `tools/DEBUG_PROMPT_lecturer_accounts.md` — lecturer-specific feature gap debugging
- `includes/helpers/staff_provisioning.php` — staff provision implementation
- `includes/helpers/student_provisioning.php` — student provision implementation
- `scripts/audit_account_creation.php` — unified audit/backfill
