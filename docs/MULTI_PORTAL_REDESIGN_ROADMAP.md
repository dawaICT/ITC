# Multi-Portal / eLearning Redesign — Roadmap

**Goal:** Evolve WUCPortal into a modular multi-portal Academic/eLearning platform with
shared identity, portal-aware permissions, dynamic HOS sections, validation, security,
AI context separation, and reporting — **by extending the existing implementation, not
rebuilding it.**

Date started: 2026-06-30. This doc is the living audit + plan; update it as phases land.

---

## Audit: what already exists (verified against the live DB)

The platform is **already multi-portal**. Every pillar has a dedicated, DB-backed helper
with a graceful fallback for when its tables are absent. Supporting tables exist and are
populated:

| Concern | Code | DB tables (live) |
|---|---|---|
| Multi-portal switching | `includes/portal_access.php`, `portal_selection.php`, `portal_config.php` | `portals` (6), `user_portal_access` (43) |
| Shared identity | `includes/student_identity.php`, `auth.php`, `auth_helpers.php`, `users` unifies student_id/staff_id | `users` (28), `user_roles` (36), `roles` (14) |
| RBAC permissions | `includes/permissions.php` (`wuc_has_permission_unified`, `hasPermission`) + legacy fallback | `modules` (18), `permissions` (220), `role_permissions` (293), `user_module_access` (0) |
| Dynamic HOS sections | `includes/hos_section_helpers.php` | `sections` (2: ENGICT, TRANSPORT), `staff_section_assignments` (6) |
| AI context separation | `includes/ai_context_service.php`, `ai_portal.php` | `ai_contexts` (14: per portal/module/role) |
| Validation | `includes/upload_validator.php`, `csrf_guard.php`, `security.php` | — |
| Security | hardened in 2026-06-28 audit (42 bugs fixed); guards in `includes/guards/` | — |
| Reporting | `includes/training_reports_engine.php`, `itc_report_helpers.php`, `student_progression_report.php` | — |

Portals defined: `academic`, `elearning`, `applicant`, `alumni`, `employer`, `library`.

**Conclusion: this is a refactor/extension effort, not a greenfield rebuild.**

---

## Gaps (the real redesign work)

1. **Portal-aware permissions — DONE (Phase 1).** `role_permissions.portal_id` existed but
   was NULL on all 293 rows and ignored by the permission check. Fixed: see below.
2. **No portal_id data.** Permission rows are still global (portal_id NULL). To actually
   *scope* a permission to a portal, populate `role_permissions.portal_id`. Needs an admin
   UI / migration. Until then portal scoping is inert-but-ready.
3. **`user_module_access` unused (0 rows)** — direct per-user grants path is dormant; has no
   portal_id column. Decide whether to keep or retire.
4. **AI context separation** is DB-backed and works. Staff/admissions/registrar plus
   dean/exams/transport/systems-admin role coverage is now seeded; future work should add
   only concrete missing module/role contexts as new AI entry points appear.
5. **Reporting** — engine exists; "improvements" pillar is undefined. Needs a concrete spec
   before touching (don't speculative-build).
6. **Validation** — scattered. A shared server-side validation helper could centralize the
   per-page ad-hoc checks, but only if a pain point is identified first.

---

## Phase 1 — Portal-aware permission scoping (LANDED 2026-06-30)

**File:** `includes/permissions.php`

- Added `wuc_permissions_current_portal_id()` — resolves the active portal id from
  `$_SESSION['current_portal']` (or an explicit code), lazy-loading `wuc_portal_id()` from
  `portal_access.php`. Returns null when no portal context exists.
- `wuc_has_permission_unified()` now takes an optional `?string $portalCode` and, when a
  portal context is present, scopes the role query with
  `AND (rp.portal_id IS NULL OR rp.portal_id = ?)`.
  - `portal_id IS NULL` = **global** permission (every portal) — preserves all current behavior.
  - `portal_id = N` = permission applies **only** inside portal N.
- All 74 existing call-sites become portal-aware automatically (default param resolves from
  session); no signatures changed.

**Verified:**
- All-NULL data → identical results with/without portal context (no regression).
- With rows temporarily bound to a portal (in a rolled-back transaction): granted inside that
  portal, denied in others, and unscoped when no portal context — exactly as designed.

**Not changed:** the direct `user_module_access` path (no portal_id column, 0 rows) and the
legacy `role_permissions_legacy` fallback.

---

## Phase 3 — Extend AI context separation (LANDED 2026-06-30)

**File:** `migrations/20260630_seed_ai_contexts_staff_admissions_registrar.php` (idempotent seed)

Gap found: `registrar`, `exams_officer`, `dean`, `admission_officer`, `transport_officer`
and `systems_admin` had **no** `ai_contexts` row, and there was no generic `staff` fallback
even though `wuc_ai_resolve_context()` explicitly matches `user_role = 'staff'`. Those roles
received an un-guardrailed AI.

Added three academic-portal contexts (`module_name = 'academic'`, which the resolver always
treats as a candidate, ordered exact-role-first): `staff` (institutional fallback),
`registrar`, `admission_officer`. **Data-only, no code change.**

**Verified** via `wuc_ai_resolve_context()`: registrar/admission_officer now get their
role-specific context plus the staff fallback; dean/transport_officer/exams_officer/
systems_admin (previously *no context*) now get the guardrailed staff fallback; finance and
library contexts still win on their own module pages. `ai_contexts` row count 7 → 10.

## Phase 3b — Complete priority staff AI context coverage (LANDED 2026-07-05)

**Files:** `migrations/20260630_seed_ai_contexts_staff_admissions_registrar.php`,
`includes/ai_context_service.php`, `scripts/verify_ai_context_resolver.php`.

Closed the remaining priority-role AI context gap from Phase 3. Added active academic-portal
`ai_contexts` rows for `dean`, `exams_officer`, `transport_officer`, and `systems_admin`.
The seed stayed idempotent: existing `staff`, `registrar`, and `admission_officer` rows are
skipped, while the four missing rows are inserted.

Also aligned `wuc_ai_context_normalize_role()` with the current staff role aliases used by
the codebase. This matters because current AI entry points pass aliases, not always canonical
role names:

- `dean/reports.php` passes `user_role => 'dean'`.
- `transport/reports.php` and `transport/ai_assessment_bank.php` pass `user_role => 'transport'`,
  which now resolves to `transport_officer`.
- `admin/ai_reports.php` passes `user_role => 'admin'`, which now resolves to `systems_admin`.

**Validated exactly:**

```powershell
C:\xampp\php\php.exe -l includes\ai_context_service.php
C:\xampp\php\php.exe -l migrations\20260630_seed_ai_contexts_staff_admissions_registrar.php
C:\xampp\php\php.exe -l scripts\verify_ai_context_resolver.php
C:\xampp\php\php.exe migrations\20260630_seed_ai_contexts_staff_admissions_registrar.php
C:\xampp\php\php.exe scripts\verify_ai_context_resolver.php
```

Seed output: existing `staff`, `registrar`, and `admission_officer` skipped; added
`dean`, `exams_officer`, `transport_officer`, and `systems_admin`; `ai_contexts` row count
10 -> 14.

Resolver verifier output: active rows exist for all four roles; `dean/reports.php` options
resolve first to `Dean AI Assistant`; `transport/reports.php` options normalize
`transport` -> `transport_officer` and resolve first to `Transport Operations AI Assistant`;
`admin/ai_reports.php` options normalize `admin` -> `systems_admin` and resolve first to
`Systems Admin AI Assistant`; explicit `exams_officer` resolves first to
`Exams Office AI Assistant`. All checks passed.

## Phase 2 — Admin tool to scope permissions per portal (LANDED 2026-06-30)

**File:** `admin/portal_permission_scope.php` (linked from `user_role_mgmt.php` → Advanced).

Turns Phase 1 from "ready" into "operable": a systems-admin page that lists every active
`role_permissions` grant (role / module / permission) with a per-row **Portal scope** dropdown
— *Global (all portals)* = `portal_id NULL`, or a specific portal = `portal_id = N`. Filter by
role; CSRF-protected; prepared statements; only rows whose scope actually changed are written
inside one transaction; invalid portal ids are ignored. POST handled before chrome (clean PRG).

It stands alone on purpose: the legacy editors (`user_role_mgmt.php` permissions-overview,
`update_permissions.php`) still query the pre-migration `PosID`/`permission_name` columns and
**fail against the migrated `role_permissions` table** — this page touches only the new RBAC
columns.

**Verified** end-to-end (rolled-back transaction): scoping a grant to eLearning made
`wuc_has_permission_unified` deny it in the academic portal and allow it in eLearning; invalid
portal id ignored; resetting to Global restored access; DB untouched.

### Why no blanket migration / library guard yet (data-proven)

eLearning entry points (`elearning/index.php`, `manage.php`, `live_room.php`) call
`wuc_require_portal_access`, so `current_portal='elearning'` is reliably set — eLearning is
**safe to scope today** via the new tool. But:

- **Library pages never call `wuc_require_portal_access`**, so they never set `current_portal`.
- `user_portal_access` is populated only for **academic (28)** and **elearning (15)** — the
  library / applicant / alumni / employer portals have **zero** access grants, and all 3
  librarian users have no library access row.

So scoping library permissions now (or adding a hard library portal guard) would lock
librarians out. Library enforcement therefore stays deferred until (a) library entry points
adopt the portal guard and (b) librarians are granted library portal access.

## Phase 2b — Light up the Library portal (LANDED 2026-06-30)

**Files:** `migrations/20260630_grant_library_portal_access.php` (idempotent grant),
`library/index.php` (added the guard).

- Granted `library` portal access to the 5 users who already had library access (every
  systems_admin + librarian + any role carrying a library-module permission), so adopting the
  guard locks nobody out. `user_portal_access` library grants: 0 → 5.
- `library/index.php` now calls `wuc_require_portal_access($db, 'library')` after the
  student/guest routing. This enforces the portal boundary **and** sets
  `current_portal = 'library'`, so library permissions can now be portal-scoped via the
  Phase 2 admin tool. (The library *portal* is a single entry point; admin-area library pages
  live under the admin portal.)

**Verified:** the 5 granted users resolve `wuc_user_has_portal_access(...,'library') = true`;
a non-library staff user resolves `false` (the guard redirects them with a message instead of
showing an empty dashboard). `user_id_db` is set at staff login (`staffLogin.php:151`), which
the guard relies on.

Note: the 5 users now hold ≥2 portals, so after login they get the portal picker
(`portal_selection.php`) — intended multi-portal behavior.

## Phase 4 — Portal-scoped report access (LANDED 2026-06-30)

**File:** `includes/training_reports_engine.php`

The engine already enforced its per-caller `allowed` allow-list consistently across view, CSV
export (`trx_handle_csv`) and AI summary — report access scoping was already solid. The gap was
the **default**: `trx_inputs` fell back to *every* report when a wrapper omitted `allowed`, so a
future non-transport wrapper could accidentally expose another portal's reports.

- Tagged each report def with a `'portal'` (the 4 training reports → `'academic'`; `'*'` = global).
- Added `trx_reports_for_portal(?string $portalCode)` → the report keys visible in the given /
  active (`$_SESSION['current_portal']`) portal.
- `trx_inputs` now defaults `allowed` to **the active portal's reports** instead of all reports
  — fail-safe. Explicit `allowed` (both current wrappers pass it) is unchanged.

**Verified:** explicit allow-list unchanged; omitted + academic → all 4; omitted + library → 0
(academic reports hidden, no cross-portal leak); out-of-scope report key rejected.
The registrar/itc academic reports use a different helper family (`itc_report_helpers.php`) and
are untouched.

## Phase 6 — Exam/test payment eligibility gate (LANDED 2026-06-30)

Spec rule: CA marks need ≥50% paid; **exam/test marks need 100% paid** — enforced server-side,
never saved for ineligible students. The CA half already existed
(`is_student_allowed_ca` in `includes/finance_guard.php`); the exam half was missing.

- **`includes/finance_guard.php`** — extracted the shared computation into
  `wuc_student_payment_eligibility($db,$sid,$year,$sem,$minPercent,$enforce)` (DRY), kept
  `is_student_allowed_ca` (threshold from `min_ca_paid_percent`, default 50) behaviourally
  identical, and added `is_student_allowed_exam` (threshold from `min_exam_paid_percent`,
  default 100, toggle `enforce_exam_payment`). Sponsorship (TEVETA/CDF) exempt; no-fee-due
  passes.
- **`includes/grading_helpers.php`** — `result_save_exam_mark()` (the single canonical save
  used by lecturer entry + admin/registrar/VC bulk + CSV import) now calls
  `is_student_allowed_exam` and returns `ok=false` with a clear message for ineligible
  students. One chokepoint ⇒ no entry surface can bypass it via direct URL or bulk upload.
- **`migrations/20260630_seed_exam_payment_settings.php`** — seeds `enforce_exam_payment=1`,
  `min_exam_paid_percent=100`, `min_ca_paid_percent=50` into `portal_settings` (idempotent,
  never overwrites an admin's value).

**Verified** (synthetic fee rows in rolled-back transactions): 50% paid → CA allowed / exam
denied; 100% → both; 40% → both denied; and `result_save_exam_mark` refuses to write for a
40%-paid student with a clear message. Fails open on today's data (no per-term fee rows ⇒ no
due ⇒ allowed), so current operations are undisturbed; bites only once fee data is present.

## Spec Phase 4 — HOD→HOS (ALREADY IMPLEMENTED; empty-state added 2026-06-30)

**Audit finding (check-before-build paid off): the two section-aware HOS dashboards already
exist** in `hod/index.php` and are comprehensive:

- **Transport HOS** (section_type=transport): fleet counts (total/available/maintenance),
  instructors, trainees, recent practical schedules, fuel logs, maintenance logs, RTSA
  readiness doughnut, fleet/accreditation/pre-use notifications, and **AI Fleet Insights**
  (`feature=transport_hos_dashboard`, role `transport_hos`).
- **Academic / Eng-ICT HOS** (section_type=academic): section students/programmes/courses,
  lecturer workload, grade distribution, CA approvals, academic risk engine panel, and
  **AI Academic Insights** (`feature=academic_hos_dashboard`, role `academic_hos`).

Resolution is **fully dynamic** — `hod_resolve_department()` →
`hos_hydrate_section_session()` reads `sections` + `staff_section_assignments` (no hardcoded
HOS names/IDs). The nav (`hod/includes/nav.php`) branches the menu by section type and shows a
section switcher for multi-section users. Separate AI roles/features already realise AI
context separation per section.

**Only gap closed:** an unassigned HOS account previously saw a zeroes dashboard. Added the
spec's empty-state to `hod/index.php` — when `activeSectionId` is empty and the user has no
hydrated sections (systems-admins always resolve every section, so they're unaffected), the
page now shows *"No section has been assigned to your Head of Section account…"* with no
misleading KPI cards. Verified `dashboard_template.php` guards empty `stat_cards`/announcements,
so the change is layout-safe.

## Phase 5 — Academic structure integrity audit (LANDED 2026-06-30)

Per-operation guards (`wuc_registration_guard`, period/structure validation in
`academic_structure_helpers.php`) already existed; the missing piece was a whole-database
health check of the spec's chain: **Section → Department → Programme → Course → Lecturer
assignment → Student registration**.

- **`includes/academic_structure_audit.php`** — `wuc_academic_structure_audit($db)` runs 8
  read-only checks (departments without an active section; active sections with no HOS;
  programmes without a department; orphaned program_courses by programme/course; lecturer
  assignments orphaned by course/programme; active registrations for a missing course), each
  returning severity/count/fix-hint, plus `wuc_academic_structure_audit_summary()`. Table- and
  catalogue-existence guarded, so absent tables are skipped not fatal.
- **`admin/academic_integrity.php`** — admin-only, read-only page rendering the audit with
  severity tiles, a colour-coded table and fix hints. Linked from `user_role_mgmt.php` →
  Advanced.

**Verified** against live data: 7 checks clean, and it **caught a real issue** — 1
`program_courses` row references a course not in any catalogue (warning) that the
per-operation guards never surface. No writes; safe to run anytime.

## Data fix + portal-scoping safety note (2026-06-30)

- **Resolved the orphan the Phase 5 audit found.** `program_courses` id=99 mapped `CS101`
  (a Computer-Science code) to `AUTO-001` (Automotive) — not in any catalogue, referenced by
  zero registrations/assessments/lecturer-assignments, semantically wrong, created 2026-06-29
  (stale seed). Removed it (guarded delete, row backed up in the session log); the integrity
  audit is now **fully clean (8/8)**.
- **Do NOT blanket-scope the eLearning module's permissions to portal 2.** Investigated this as
  the "obvious" first portal-scoping. The 15 `elearning.*` grants (incl. 13 systems_admin) are
  checked **outside** the eLearning portal too — `admin/includes/nav.php`,
  `admin/includes/sidebar.php`, `includes/auth.php`, `role_helpers.php`. If the academic/admin
  context has `current_portal` set (the staff dashboard sets `'academic'`), scoping these to the
  eLearning portal would **deny admins/lecturers eLearning management from the academic side**.
  Safe portal-scoping requires per-check portal-context discipline that isn't uniformly present,
  so use `admin/portal_permission_scope.php` deliberately per-row, not a blanket pass.

## Suggested next phases (each independently shippable)
- **Phase 4 — Reporting spec + improvements.** Define the concrete reporting asks first, then
  extend `training_reports_engine` / report wrappers.
- **Phase 5 — Centralized validation helper.** Only after identifying repeated ad-hoc
  validation; wrap with the existing CSRF/security guards.

**Process rule (from the goal):** before each phase, re-check the existing implementation —
this codebase frequently has the scaffolding already in place under a different name.
