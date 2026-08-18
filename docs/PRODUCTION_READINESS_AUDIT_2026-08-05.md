# WUCPortal Production Readiness Audit

**Date:** 2026-08-05  
**Scope:** Integration & Stabilization → Pre-Production / UAT  
**Method:** Full structure/RBAC/security/payment/database review; critical defects fixed and re-verified (PHP lint + integrity probe). Browser UAT journeys remain manual.

---

## A. Production-readiness percentage

**62%** (was ~45–50% before this hardening pass)

Scoring basis (weighted): auth/RBAC 70%, security blockers 75%, payments 70%, config/ops 55%, performance/scale 40%, backup evidence 35%, full UAT evidence 40%.

## B. Current maturity stage

**Pre-Production / UAT candidate** — not Production GO.

Core login/guards and several P0 holes were hardened. Remaining work is staging deployment, UAT sign-off, backup restore drill evidence, host capacity, and residual endpoint/guard coverage across legacy PHP.

## C. Issue counts (post-fix residual)

| Severity | Count | Notes |
|----------|------:|-------|
| P0 CRITICAL | **0** | Previously open unauthenticated destructive/mutating endpoints closed in this pass |
| P1 HIGH | **6** | See Remaining blockers |
| P2 MEDIUM | **12** | Schema drift, dual payment callbacks, N+1 insights, upload MIME gaps, data orphans |
| P3 LOW | **8** | UX/wording, legacy duplicate pages, docs polish |

**Production rule P0=0 / P1=0 is not met** because residual P1 items remain.

## D. Security findings

### Fixed this pass (were P0/P1)

| ID | Finding | Fix |
|----|---------|-----|
| SEC-P0-01 | `accounts/delete_payment.php` CSRF-only, no auth | Finance staff + CSRF + audit log |
| SEC-P0-02 | `students/process_unregister.php` unauthenticated unregister | Session SID force + CSRF + safe errors |
| SEC-P0-03 | `students/process_direct_submit.php` IDOR on `Sid` | Session SID force + CSRF |
| SEC-P0-04 | `students/process_invoice_1.php` unauthenticated invoice/reg | Session SID force + CSRF |
| SEC-P0-05 | `api/student_search.php` public PII | Staff + capability gate |
| SEC-P1-01 | Registration/eligibility IDOR | Session SID forced |
| SEC-P1-02 | Assignment submit IDOR + weak upload | Session SID, CSRF, MIME check |
| SEC-P1-03 | Admissions `isAdminAuthenticated()` = any staff | Admissions entitlement required |
| SEC-P1-04 | Admissions search API any `user_id` | Admissions staff gate |
| SEC-P0-06 | Destructive setup/DDL web-reachable | CLI-only + `.htaccess` deny |
| SEC-P1-05 | eLearning localhost admin bootstrap | CLI-only |
| SEC-P1-06 | DPO amount/ref not bound to local tx | Amount + company-ref validation in `paygate_return.php` |

### Residual security

- Legacy PHP surface still large (~2400 files); not every endpoint individually reclassified.
- Some upload paths still extension-only (mitigated by uploads rewrite deny).
- Duplicate DPO callback surface (`dpo_callback.php` vs `paygate_return.php`).
- DPO merchant credentials live in `portal_settings` (DB), not secret manager.
- Online payment callback outcomes not written to normalized `audit_log()`.

## E. Database findings

Integrity probe (`php scripts/production_integrity_check.php`):

- Core tables present (students, programs, payments, semester_registration, sections, staff_section_assignments, schema_migrations, payment_gateway_transactions).
- Orphan `student_login` / `student_program` → students: **0**
- Duplicate active student programs: **0**
- **4** `student_program` rows reference missing program `BSCS` (test SIDs STU900, STU260001–003) — data cleanup, non-destructive
- `wuc_audit_logs` table missing (Laravel-era name); live trail uses `audit_logs`
- Important uniqueness/atomicity depends on migrations having been applied on the target host

## F. Performance findings

From `docs/PRODUCTION_SERVER_AUDIT.md` (2026-08-04) + code review:

- Current XAMPP host: realistic **~15–25** concurrent authenticated PHP users; not 250–1000.
- Health endpoint improved to ~260 ms avg at n=100 after OPcache/gzip.
- Lecturer eLearning insights N+1 in `includes/elearning_insights_engine.php`.
- File sessions block multi-node scale.
- Target &lt;2s pages / &lt;5s reports: not re-benchmarked end-to-end in this pass for all portals.

### Scalability estimate (concurrent authenticated users)

| Users | Outlook on current host | Outlook on dedicated ≥16 GB Linux (PHP-FPM) |
|------:|-------------------------|---------------------------------------------|
| 50 | Marginal / degraded | Feasible with tuning |
| 100 | Unreliable | Feasible |
| 250 | No | Needs load test + Redis sessions + DB tuning |
| 500–1000 | No | Architecture change (workers, caching, possibly read replica) |

Peak risk windows: registration, results publish, timetable release, CA upload, admissions.

## G. RBAC findings

### Fixed / improved

- Admin bootstrap (`admin/includes/admin.php`) now requires **systems_admin**.
- `canAccessRegistrar()` no longer grants systems_admin (Admin must use `/admin`).
- Registrar chrome `required_roles` = registrar only.
- Admissions staff gate tightened.
- `manager` / `director` role aliases no longer map to `systems_admin`.

### Residual

- Some systems_admin-only tools still physically live under `registrar/` (staff delete/edit). Relocate or redirect to `/admin` in a follow-up.
- Module grant hydration (`user_id_db` / `canAccessModule`) must be present for finance/admissions capability checks — verify on staging accounts.
- Employer/alumni guards exist; full journey UAT not completed in this pass.

## H. Portal-isolation findings

- `includes/portal_context.php` + student guards separate `academic` vs `student_elearning`.
- Intentional academic dashboard bridge to eLearning insights remains.
- Residual risk: legacy enrollment fallbacks in `includes/elearning_access.php` can over-grant historic courses if normalized tables are absent.
- HOS section scope helpers and several HOS APIs enforce section assignment (IDOR-resistant patterns present).

## I. Remaining blockers (must clear before Production GO)

1. **P1** Staging environment with production-like secrets, HTTPS, non-root DB user, and passing `production_preflight.php`.
2. **P1** Demonstrated backup restore drill (`recovery_drill.php`) with recorded RPO/RTO.
3. **P1** Full role UAT sign-off (Student, Lecturer, HOS, Registrar, Admin, Admissions) including unauthorized URL/IDOR attempts.
4. **P1** Relocate or redirect systems_admin-only pages still under `registrar/`.
5. **P1** Consolidate DPO callbacks; verify amount/ref binding on both active paths; audit-log payment outcomes.
6. **P1** Dedicated production host (≥16 GB, service autostart, disk headroom, phpMyAdmin locked down).
7. **P2** Repair/remove orphan `BSCS` student_program test rows; confirm program catalogue completeness.
8. **P2** Pending SQL migrations applied and ledger-verified on staging/production.

## J. Files changed

- `includes/production_guards.php` **(new)**
- `includes/role_helpers.php`
- `includes/staff_role_helpers.php`
- `admin/includes/admin.php`
- `admin/drop_table.php`
- `admin/update_database.php`
- `admin/db_repair_standalone.php`
- `admin/fix_schema.php`
- `accounts/delete_payment.php`
- `admissions/includes/session_handler.php`
- `admissions/api/search_student.php`
- `api/student_search.php`
- `setup_database.php`
- `students/process_unregister.php`
- `students/process_invoice_1.php`
- `students/process_direct_submit.php`
- `students/check_registration_status.php`
- `students/check_eligibility.php`
- `students/assignments.php`
- `students/elearning/admin.php`
- `students/payments/paygate_return.php`
- `students/js/registration.js`
- `students/js/new_student_registration.js`
- `students/js/react/components/CourseRegistrationApp.jsx`
- `registrar/includes/admin.php`
- `.htaccess`
- `scripts/production_integrity_check.php` **(new)**
- `docs/PRODUCTION_READINESS_AUDIT_2026-08-05.md` **(new)**

## K. Database migrations required

On staging/production (CLI only):

```text
php scripts/migrate.php
php scripts/production_preflight.php
php scripts/production_integrity_check.php
```

Confirm these integrity migrations are applied if not already:

- `20260703_dpo_paygate_payments.php` / related payment uniqueness
- `20260717_invoice_integrity.php`
- `20260717_registration_atomicity.php`
- `20260717_student_courses_integrity.php`
- Results / audit workflow migrations as listed under `migrations/`

Do **not** run browser-based `admin/fix_schema.php` (now CLI-only).

## L. Manual tests required

### Student
login → dashboard → registration → courses → eLearning → timetable → CA/results → notifications  
Negative: change `Sid` / `student_id` in POST/AJAX → must 403.

### Lecturer
login → assigned courses only → students → eLearning → upload CA → verify.

### HOS
login → assigned section only → programs/courses/lecturers/students/reports  
Negative: alter section/student IDs → denied.

### Registrar
academic setup → intakes → programs → registration → results → records  
Negative: systems_admin session must **not** enter Registrar portal.

### Admin
users → roles → permissions → portal access → system config → logs  
Negative: non-admin staff hitting `/admin/*` → denied.

### Admissions
application → review → decision → admit → student conversion  
Negative: lecturer/random staff cannot call admissions APIs.

### Payments
DPO success/fail/cancel; duplicate callback; return URL without verify; registration activation after pay.

### AI
Stop Ollama / unset AI keys → login, registration, results, eLearning core, lecturer, registrar still work.

## M. Recommended staging deployment steps

1. Provision staging VM (not local XAMPP desktop): PHP 8.2+, MariaDB, HTTPS cert, ≥16 GB RAM preferred.
2. Deploy immutable release from Git tag/commit (never copy live from developer `htdocs` ad hoc).
3. Load secrets via platform env / `WUC_CONFIG_FILE` outside docroot (non-root DB user).
4. `composer install --no-dev --classmap-authoritative`
5. `php scripts/backup_database.php` then `php scripts/migrate.php`
6. `php scripts/production_preflight.php` (must pass)
7. `php scripts/production_integrity_check.php`
8. `php scripts/recovery_drill.php <backup.sql> --confirm-drill` and archive evidence
9. Seed UAT accounts (no production passwords); run section L matrix
10. Promote only after P0=0, P1=0, and UAT sign-off

## N. GO / NO-GO production recommendation

# **NO-GO for production**

**GO for staging + formal UAT.**

Rationale: critical unauthenticated mutation/disclosure paths found in audit are closed, Admin/Registrar separation is enforced in gates, and DPO verify-before-post remains with stronger amount/ref binding. However, production still requires staging preflight success, restore-drill evidence, host capacity, complete role UAT, payment callback consolidation/audit logging, and clearance of remaining P1 items.

---

## AI dependency

AI remains non-blocking by design (Ollama optional; pages should degrade). No change made that couples core academic flows to AI availability.

## Backup readiness claim

Scripts exist (`backup_database.php`, `restore_database.php`, `recovery_drill.php`). **Restore has not been demonstrated in this audit** — do not claim backup readiness until a drill record exists.
