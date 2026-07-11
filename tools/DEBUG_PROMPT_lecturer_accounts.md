# Debug Prompt — Lecturer Account Feature Gaps

Use this prompt when a newly created lecturer sees fewer menus, dashboard cards, or tools than an existing account (e.g. ITC900).

---

## Prompt (copy from here)

```
/wuc-backend /wuc-fullstack

Act as a senior PHP/MySQL backend developer, access-control auditor, and WUC portal systems analyst.

## Objective

Debug why a lecturer account has missing features, menus, permissions, dashboard cards, CA upload, eLearning access, or reports compared to a reference account.

**Important:** Do not assume the reference account (e.g. ITC900) is the correct lecturer baseline. ITC900 is a multi-role **Systems Admin / All Roles** test account — it bypasses RBAC via `systems_admin`. Use **ITC907** (pure lecturer) as the lecturer reference. Use ITC900 only to compare provisioning gaps.

## Do NOT fix by

- Hardcoding ITC900 features into every account
- Granting `systems_admin` to make menus appear
- Copying ITC900 rows into new lecturers

## DO fix by

- Completing staff account provisioning at creation time
- Aligning role resolution, portal access, and module permissions
- Showing empty-state messages when course assignment is missing

---

## 1. Compare database records

Inspect and diff these tables for **reference account** vs **broken lecturer**:

| Table | What to check |
|-------|----------------|
| `staff` | `staff_id`, `role`, `status`, `password`, `deptId` |
| `users` | `user_id`, `username`, `primary_role`, `staff_id`, `status` |
| `user_credentials` | legacy login row (if present) |
| `staff_positions` | `PosID` → `positions.PosName` |
| `access_right` | `assigned_access` (legacy) |
| `user_roles` | `role_id` → `roles.role_name` |
| `role_permissions` | lecturer role → `courses`, `ca_upload`, `reports`, `elearning` modules |
| `user_portal_access` | `academic`, `elearning` portals |
| `course_lecturer` | assigned courses, `status`, orphan codes (no `courses` row) |

**CLI helpers (run from project root):**

```bash
C:\xampp\php\php.exe tools\audit_lecturer_accounts.php
C:\xampp\php\php.exe scripts\backfill_staff_provisioning.php
C:\xampp\php\php.exe tools\debug_itc_lecturers.php
```

Apply repairs:

```bash
C:\xampp\php\php.exe scripts\backfill_staff_provisioning.php --apply
C:\xampp\php\php.exe scripts\backfill_staff_provisioning.php --apply --role=lecturer
```

---

## 2. Trace account creation workflow

Inspect the path used to create the lecturer:

| Path | File | Expected provisioning |
|------|------|----------------------|
| Admin add staff | `admin/add_staff.php` | Must call `wuc_provision_staff_account()` |
| Admin create login | `admin/createAccount.php` | Must sync `users` + provision |
| Registrar add staff | `registrar/add_staff.php` | Often incomplete — check role + provision |
| Login self-heal | `staffLogin.php` | Repairs empty `all_roles` on login |

A **complete** lecturer account needs:

- [ ] `staff` row (`role = lecturer`, `status = active`)
- [ ] `users` row (`username = staff_id`, `primary_role = lecturer`)
- [ ] `user_roles` row (lecturer role)
- [ ] `staff_positions` row (Lecturer position)
- [ ] `access_right` row (legacy compat)
- [ ] `user_portal_access` (academic; elearning for lecturers)
- [ ] Lecturer `role_permissions` (courses, ca_upload, reports, elearning)
- [ ] Course rows in `course_lecturer` (optional — features show empty-state until assigned)

Provisioning entry point: `includes/helpers/staff_provisioning.php` → `wuc_provision_staff_account()`

---

## 3. Search for hardcoded account logic

```bash
rg "ITC900|WUC900|staff_id\s*==\s*['\"]?(900|ITC)" --glob "*.php"
```

Remove any checks like:

```php
if ($_SESSION['staff_id'] == 'ITC900') { ... }
```

Replace with role/permission checks:

```php
hasRole(ROLE_LECTURER)
canAccessModule($userIdDb, 'ca_upload')
isLecturerAssignedToCourse($db, $staffId, $courseCode)
```

---

## 4. Debug session + role hydration

After login, compare `$_SESSION` for reference vs broken account:

| Key | Purpose |
|-----|---------|
| `staff_id` / `user_id` | Staff identifier |
| `user_id_db` | `users.user_id` — required for RBAC |
| `role` | Active canonical role |
| `all_roles` | From `wuc_resolve_staff_roles()` |
| `current_portal` | `academic` or `elearning` |

Role resolution chain (`includes/staff_role_helpers.php`):

1. `user_roles` → `roles`
2. Fallback: `staff_positions` → `positions`
3. Fallback: `access_right`
4. Fallback: `staff.role` / `users.primary_role`

If `all_roles` is empty → menus and guards break.

---

## 5. Debug access gates (in order)

| Gate | File | Symptom if failing |
|------|------|-------------------|
| Lecturer guard | `lecturers/includes/guard.php` | Redirect to login / portal_selection |
| Portal access | `includes/portal_access.php` | "no permission for this portal" |
| Academic module | `canAccessAcademics()` in `role_helpers.php` | Blocked from entire lecturer module |
| Sidebar filter | `includes/nav_unified.php` | Missing menu items |
| Module RBAC | `canAccessModule()` in `auth.php` | Hidden CA, courses, reports links |
| Course assignment | `isLecturerAssignedToCourse()` | CA upload / student list denied per course |

Lecturer sidebar expects modules: `dashboard`, `courses`, `ca_upload`, `reports`, `elearning`.

---

## 6. Course assignment vs broken account

Some features are **course-dependent** (not provisioning bugs):

- Student counts, CA upload students, risk alerts, timetable slots

If no courses assigned:

- Dashboard should show empty-state (not blank/broken UI)
- Message: "No courses assigned yet — contact admin/registrar"

Check:

```sql
SELECT * FROM course_lecturer WHERE staff_id = ? AND status <> 'inactive';
SELECT cl.course_code, c.course_code AS in_catalog
  FROM course_lecturer cl
  LEFT JOIN courses c ON c.course_code = cl.course_code
 WHERE cl.staff_id = ?;
```

Orphan codes (in `course_lecturer` but not in `courses`) are excluded by `wuc_lecturer_resolved_course_codes()`.

---

## 7. Test matrix

| Scenario | Expected |
|----------|----------|
| Login as ITC907 (pure lecturer) | Full lecturer menu, role = lecturer only |
| Login as ITC900 | More portals/roles (superuser) — not lecturer baseline |
| New lecturer, no courses | Full menu + empty-state banner |
| New lecturer, courses assigned | Students, CA, insights populate |
| Direct URL `lecturers/upload_ca.php?course=XXX` | Blocked if not assigned to course |
| Direct URL `admin/index.php` | Blocked for lecturer |

---

## 8. Deliverables required

After debugging, report:

1. Root cause (provisioning vs RBAC vs course assignment vs wrong baseline)
2. DB diff: reference vs broken account
3. Hardcoded logic found (if any)
4. Files inspected / changed
5. Whether `wuc_provision_staff_account()` was called
6. Backfill results (`backfill_staff_provisioning.php`)
7. Remaining risks

---

## Key files reference

```
admin/add_staff.php              — staff creation (must provision)
admin/createAccount.php          — login credential creation
staffLogin.php                   — login + self-heal
includes/helpers/staff_provisioning.php
includes/staff_role_helpers.php  — wuc_resolve_staff_roles()
includes/role_helpers.php        — canAccessAcademics(), hasRole()
includes/nav_unified.php         — sidebar module filter
includes/auth.php                — canAccessModule()
lecturers/includes/guard.php
lecturers/includes/nav.php
lecturers/index.php              — dashboard + empty-state
includes/helpers/lecturer_course_helpers.php
scripts/backfill_staff_provisioning.php
tools/audit_lecturer_accounts.php
```

Continue until every valid lecturer account has correct features and clear feedback where setup data is missing.
```

---

## Quick one-liner

```
Debug lecturer [STAFF_ID] vs ITC907: missing menus/features on lecturers/index.php — check users, user_roles, staff_positions, role_permissions, portal access, course_lecturer; do not use ITC900 as lecturer baseline; run backfill_staff_provisioning.php --apply if gaps found.
```
