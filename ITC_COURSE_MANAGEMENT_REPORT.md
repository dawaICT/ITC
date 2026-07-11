# ITC Course Management — Implementation Report

**Date:** June 23, 2026
**Source spec:** `ITC_Courses_Duration_Intake_Level_Logic.md` (21 sections)
**Status:** ✅ Complete — all three phases built and verified end-to-end against the live portal
**Scope confirmed with stakeholder:** Full workflow (catalogue **and** intakes/batches/applications); data home = the existing `short_courses` table (extended, not a new silo).

---

## 1. Purpose

Give the portal a structured way to hold the ITC 2026 training catalogue and run it operationally:

- Classify every offering by **category → level → duration → intake type**.
- Hold the ~120 trainings from spec §15 with structured (not free-text) durations.
- Run the full admission lifecycle: **intake → course offering → training batch**, and **student apply → reserve slot → pay → confirm → assign to batch**.

---

## 2. Key decisions & rationale

| Decision | Rationale |
|---|---|
| **Extend `short_courses`** as the catalogue home | The system already had four disjoint course-like areas (`courses` = academic subjects, `programs`, `short_courses`, `transport_*`); the spec's "course" fit none cleanly. `short_courses` already carried `duration_value`/`duration_unit`/`fee`/`status`, and `scripts/move_itc_short_courses.php` showed prior intent to model ITC offerings there. |
| Classification table named **`course_classification_levels`** | A `course_levels` name is already referenced by legacy curriculum-mapping code (course_code/program/semester/year). Reusing it would have collided. |
| One **shared helper library** for the algorithms | §5 / §6 / §8 logic is reused by the admin UI, the "Suggest" endpoint, the seeder, batch scheduling, and the apply flow — a single source of truth avoids drift. |
| **`student_payments`** as the application charge ledger | The `invoices` table is program-centric (requires `program_code`, `semester`, bigint `SID`) and a poor fit for short-course applications; `student_payments` (Sid, balance, `payment_status` pending→completed) fits directly. |
| A confirmed application **is** the enrolment | `short_course_enrollments` was extended with an `application_status` lifecycle instead of adding a parallel applications table. |

---

## 3. Data model

### 3.1 New reference tables (seeded in the migration)

| Table | Rows | Source |
|---|---|---|
| `course_categories` | 9 | §3 — AUTO, TRANS, ELEC, MECH, ICT, AGRI, SAFE, MINE, SERV |
| `course_classification_levels` | 13 | §4 — SERVICE…DIPLOMA, with `level_rank` from §10.2 |
| `intake_types` | 7 | §7 — ON_DEMAND, ROLLING, WEEKLY, MONTHLY, TERM_BASED, SEMESTER_BASED, ANNUAL (+ `min_days`/`max_days`) |

### 3.2 `short_courses` (extended)

Added: `category_id`, `level_id`, `intake_type_id`, `standard_duration_days`, `is_duration_fixed`, `minimum_age`.
Widened: `duration_unit` ENUM now includes `'years'` (diplomas / craft certs are 2 years).

### 3.3 New workflow tables

| Table | Purpose |
|---|---|
| `intakes` | Admission window. `status` ENUM = the 9 lifecycle stages from §9 (default `Draft`). FK → `intake_types`. |
| `course_intakes` | A course **offered within** an intake. `capacity` / `available_slots`, `UNIQUE(short_course_id, intake_id)`. FK CASCADE from both parents. |
| `training_batches` | A training group under a `course_intake`. `start_date`/`end_date`, `trainer_id`, `location`, `capacity`, `current_enrolment`. FK CASCADE. |

### 3.4 `short_course_enrollments` (extended)

Added `intake_id`, `course_intake_id`, `training_batch_id`, `invoice_id`, `application_status`
(`pending_requirements` / `awaiting_payment` / `confirmed` / `rejected` / `cancelled`), `applied_at`.
Existing admin-driven enrolments keep working (all new columns nullable).

```
intakes ──< course_intakes ──< training_batches
                  │                    │
short_courses ────┘                    │
   (catalogue)                         │
                                       │
short_course_enrollments (intake_id, course_intake_id, training_batch_id, invoice_id, application_status)
                                       │
                              student_payments (the fee charge)
```

---

## 4. Algorithms (`includes/itc_course_helpers.php`)

| Function | Spec | Behaviour |
|---|---|---|
| `itc_duration_to_days` | §6 | day=1, week=7, month=30, year=365 |
| `itc_classify_level` | §5 | Keyword/duration classification; also recognises arabic "Level 1/2/3 Trade Test" naming used by the real catalogue |
| `itc_suggest_intake_type` | §8 | Exact §8 ordering — so a **12‑month** diploma → SEMESTER_BASED while a **24‑month** diploma → ANNUAL |
| `itc_course_is_publishable` / `itc_missing_classification` | BR-COURSE-001 / BR-CAT-002 | A course needs category + level + intake type + duration before it can be Active |
| `itc_batch_end_date` | §18 | Derives batch end from course duration; handles `years` (which `sc_derive_end_date` does not) |
| `itc_default_intake_dates` | §16 | Suggests an application window from intake-type cadence |

---

## 5. Files

### Created
| File | Role |
|---|---|
| `migrations/20260623_itc_course_management.sql` | Schema + reference-table seeds |
| `includes/itc_course_helpers.php` | Classification / duration / intake / guard algorithms + lookups |
| `scripts/seed_itc_2026_catalogue.php` | Seeds 101 §15 courses (idempotent) |
| `admin/itc_intakes.php` | Intake CRUD + status lifecycle |
| `admin/itc_intake_manage.php` | Attach courses, create batches, manage applicants |
| `students/itc_apply.php` | Student apply-to-intake flow |

### Modified
| File | Change |
|---|---|
| `admin/short_courses.php` | Category/Level/Intake selects + Min Age + `years`; classification badges; publish guard; `standard_duration_days` computed |
| `admin/ajax/short_course_ajax.php` | New read-only `classify` action (powers the "Suggest" button) |
| `admin/course_program_mgmt.php` | Hub tiles: "Training Catalogue" + "Training Intakes" |
| `students/includes/navbar.php` | "Apply for Training" nav item |

---

## 6. Feature breakdown

### Phase A — Catalogue, classification & seed
- Admin can add/edit a course with category, level, intake type, minimum age and a `years` duration.
- A **Suggest** button calls the server-side algorithms and pre-fills level + intake type.
- A **publish guard** blocks setting a course Active until it is fully classified.
- The table shows category/level/intake badges and flags unclassified courses.
- 101 courses seeded with codes `CAT-NNN` (e.g. `TRANS-001`); cross-listed forklift/cranes de-duped (9 skipped).

### Phase B — Intakes & batches
- Intake CRUD with the 9-stage status lifecycle and an in-row quick status change.
- "Suggest application window" derives application open/close from intake type + training start.
- Per intake: attach active+classified courses (capacity → available_slots) and create batches (end date derived from course duration). Capacity and lock guards (BR-INTAKE-006/007).

### Phase C — Apply → pay → confirm → batch
- Students browse **open** offerings (status, deadline, slot and age checks) and apply.
- Applying (one transaction): reserves a slot (`available_slots -1` with a race-safe `WHERE`), raises a pending `student_payments` charge, and creates an `awaiting_payment` enrolment linked to the charge.
- Admin **verifies payment** → `confirmed` (charge marked completed), then **assigns a batch** (increments `current_enrolment`); **release** cancels and returns the slot.
- Students see their applications with live payment/status/batch.

---

## 7. Business rules implemented

| Rule | Where |
|---|---|
| BR-COURSE-001 / BR-CAT-002 (must be classified to publish) | `itc_course_is_publishable` + add/edit guard in `short_courses.php` |
| BR-DUR-001…003 (structured duration, derived end dates) | `duration_value`/`duration_unit`/`standard_duration_days`; `itc_batch_end_date` |
| BR-LEVEL-002 (level drives intake type) | `itc_suggest_intake_type` |
| BR-INTAKE-001/002/005 (apply only to an open intake within its window) | `itc_apply.php` offering query + apply validation |
| BR-INTAKE-006 (no batch work when intake Cancelled/Completed) | `$locked` guard in `itc_intake_manage.php` |
| BR-INTAKE-007 (offering capacity not exceeded) | batch-capacity check; slot reservation guard |
| BR-INTAKE-008 (one active application per course/intake) | duplicate-application check on apply |
| §17 payment gate (confirm only after verification) | `verify_payment` → `confirmed`; batch assignment requires `confirmed` |

---

## 8. Verification

All flows exercised against **live Apache** with minted sessions (admin `WUC901`, student `STU900`), plus DB assertions. Representative results:

**Classification (algorithm vs §15):**
```
Diploma in Vehicle Maintenance  12 months → DIPLOMA / SEMESTER_BASED / 360 days
Diploma in Logistics            24 months → DIPLOMA / ANNUAL        / 720 days
Level 3 Trade Test              3 months  → TRADE_III / TERM_BASED  / 90 days
First Aid                       5 days    → SHORT / ROLLING         / 5 days
Driver Competence Assessment    1 day     → ASSESSMENT / ON_DEMAND  / (no fixed days)
```

**Seed:** 101 courses, **0** NULL classifications; 9/13/7 reference rows.

**Admin catalogue page:** HTTP 200, badges + selects render, **no PHP errors**; classify AJAX returns correct level+intake; publish guard rejected an unclassified Active course **and** inserted no row; a fully-classified Active add inserted correctly (18-column bind verified).

**Phase B:** create intake → attach course → create batch; batch end date auto-derived (07‑07 + 5 days = **07‑12**); cascade delete confirmed.

**Phase C:** student apply → `awaiting_payment`, `available_slots` 10→9, pending charge created → admin verify → `confirmed` + `completed` → assign batch → `training_batch_id` set, `current_enrolment` 0→1. All test rows cleaned up; error logs clean.

---

## 9. Operations

**Apply the migration** (already applied locally and recorded in `schema_migrations`, checksum `c4901f25…`):
```
php scripts/migrate.php            # canonical runner (migrator account)
# or, dev: mysql -uroot wucportal < migrations/20260623_itc_course_management.sql
```

**Seed/refresh the catalogue** (idempotent):
```
php scripts/seed_itc_2026_catalogue.php
```

**Entry points:** Admin → *Academic Structure* → **Training Catalogue** / **Training Intakes**; Student navbar → **Apply for Training**.

---

## 10. Security

CSRF on every state-changing POST (`sc_admin_csrf` for the catalogue/AJAX, `itc_csrf` for intakes, the student `csrf_token` for apply); prepared statements throughout; admin pages behind the existing admin session guard, the student page behind the student guard; slot reservation uses a race-safe conditional `UPDATE`.

---

## 11. Out of scope / future work

- Progression rules (§19 `course_progressions`).
- Holiday-aware end-date adjustment (BR-DUR-006).
- A real payment-gateway hook (the current `verify_payment` action models the verification step).
- **Cleanup note:** `scripts/seed_itc_catalogue.php` (older, unrelated) targets dropped `courses` columns and is superseded by `scripts/seed_itc_2026_catalogue.php`; it can be removed.
