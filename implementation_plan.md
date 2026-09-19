# Implementation Plan — Teaching Planner: Frontend Shell Fix + End-to-End Provisioning

## [Overview]

Make `lecturers/teaching_planner.php` render a complete, valid document and become actually usable by lecturers. Two coordinated changes, confirmed by lint, the 14/14 service suite, a live authenticated curl render (ITC907 → HTTP 200, zero PHP warnings) and direct DB probes on 2026-08-24:

1. **Frontend (shell defect):** the page includes `lecturers/includes/nav.php` (which opens the `main-wrapper`/`main-content` shell via `includes/nav_unified.php`) but **never includes `lecturers/includes/footer.php`** — the only nav-based lecturer page missing it. The rendered HTML therefore ends at `</div>` with **0** `</body>` / 0 `</html>` matches, the two shell divs are never closed, and the module footer scripts (the lecturer "needs-validation" binding, active-link guard) never load. Fix: append the standard footer include after the closing `</div>`.
2. **Backend/functional gap:** the page is inert because no app code populates the provisioning chain it depends on — `lecturer_course_assignments`=0 (only a one-off migration backfill writes it, and it has not left any rows), `document_templates`/versions=0, `syllabus_versions`=0. `course_schedule` holds only legacy rows (null `lecturer_id`/times, or bound to the wrong account), so `lecturerAssignments()` returns `[]` and the page permanently shows the empty state with `Generate review preview` disabled. Fix: add an idempotent CLI provisioning script that (a) backfills assignments from the legacy `course_lecturer` table (same join the `20260629_itc_foundation_gap_closure.sql` migration uses — currently finds ~22 real pairs), (b) creates the minimal chain for a deterministic term-based demo (a `course_schedule` row keyed to the lecturer's numeric staff id, an `active` scheme-of-work template + version in protected storage reusing the existing fixture, an `approved` syllabus + topics), and (c) generates one saved plan so the lecturer list and plan view are real.

No schema changes; no new packages. Backend queries are confirmed against the live schema (all 253 `course_offerings.status='active'`; `lecturer_course_assignments`/`academic_periods`/`curriculum_courses` columns match the code).

## [Types]

None — procedural PHP + data seeding only.

## [Files]

1. **`c:\xampp\htdocs\wucportal\lecturers\teaching_planner.php`** (modify — the only live-page code fix)
   - Append at end of file (after the final `</div>` that closes the `tp-page` container):
     ```php
     <?php require_once __DIR__ . '/includes/footer.php'; ?>
     ```
   - `footer.php` closes the two shell divs opened by `nav_unified.php` and emits `</body></html>` plus the module's shared footer JS. No other markup change; every other nav-based lecturer page (viewCaRes, test_schedule, upload_ca, …) already does this.

---

2. **`c:\xampp\htdocs\wucportal\scripts\seed_teaching_planner_live.php`** (new — CLI-only, idempotent, `declare(strict_types=1)`)
   - Bootstraps `db/connect.php` + `includes/teaching_planner/init.php`, sets `$_SESSION['staff_id']` as it goes (needed by `tp_actor_id()`/audit).
   - **Step A — backfill assignments:** re-runs the foundation-gap-closure join (`course_lecturer → curriculum_courses → curriculum_versions → course_offerings`) with `INSERT … ON DUPLICATE KEY UPDATE`, so all ~22 real offering-based pairs exist (probe-confirmed candidates today: ITC900/ITC907 → CSE DCSE-101..109, EXH-LEC-001 → ICT-001 DCSE-101/103).
   - **Step B — deterministic demo:** pick a fixed lecturer with real teaching assignments — **`EXH-LEC-001`** (numeric `staff.id` = 308, role `lecturer`), course **`DCSE-101`** under **`ICT-001`** (TERM_BASED), Term 1 offering (2026-01-01 → 2026-04-30). Verified present in the probe.
   - **Step C — schedule:** ensure a `course_schedule` row (`course_code='DCSE-101'`, `lecturer_id=308` — the *numeric* staff id the `timetable()` query `INNER JOIN staff ON staff.id = cs.lecturer_id` expects — recurring weekday within the term, `start_time='08:00'`, `end_time='10:00'`, `status='active'`, `is_active=1`) if none exists. Existing rows are unusable (null `lecturer_id`/times, or COM101 bound to the wrong numeric id).
   - **Step D — template:** copy `tests/teaching_planner/fixtures/sample_scheme_template.docx` into `tp_storage_root()`; register an `active` `document_templates` (`document_type='scheme_of_work'`, `structure_type='term'`, `program_type=null` → matches term-based CSE/ICT dropdown filtering) and `version 1` `document_template_versions` (`status='active'`, real `checksum_sha256`, `validation_report`, `uploaded_by=EXH-LEC-001`), mirroring the suite's DB-test fixture exactly.
   - **Step E — syllabus:** upsert one `approved` `syllabus_versions` for (`ICT-001`, `DCSE-101`) (`version_label='2026 Seed'`, `approved_at=NOW()`) plus 2–3 `syllabus_topics`, so the scheduler has content.
   - **Step F — demonstration plan:** if no non-archived `teaching_plans` row exists for (EXH-LEC-001, that offering, 2026-Term 1), run `TeachingPlannerService::generatePreview()` + `savePreview()` exactly as the UI would; "My teaching plans" then has a real row to open. Skips when present (idempotent).
   - CLI echoes before/after row dumps (`lecturer_course_assignments`, templates, syllabus, plans) and exits 0/1.

3. **`c:\xampp\htdocs\wucportal\tests\teaching_planner\run.php`** (modify — add assertions, keep the 14 existing green)
   - Add one read-only test: after the seed, `lecturerAssignments('EXH-LEC-001')` is non-empty, one returned row is `DCSE-101`; `approvedSyllabi('ICT-001','DCSE-101')` is non-empty; `activeTemplates('scheme_of_work', structure='term')` is non-empty. If the seed has not been run it fails loudly with "run scripts\seed_teaching_planner_live.php first".

## [Functions]

No library functions change. Page/service logic is confirmed correct (`lecturerAssignments()`, `generatePreview()`, `savePreview()`, `plan()`, `plansForLecturer()` — 14/14 suite). The seed reuses `tp_storage_root()`, `TeachingPlannerTemplateValidator`, `TeachingPlannerService`, `tp_actor_id()`.

## [Classes]

None — procedural PHP; services reused, not modified.

## [Dependencies]

None added. Uses the existing fixture `tests/teaching_planner/fixtures/sample_scheme_template.docx` and `tp_storage_root()` (`C:\xampp\wucportal-var\teaching-planner`).

## [Testing]

1. `C:\xampp\php\php.exe -l` on `lecturers/teaching_planner.php`, `scripts/seed_teaching_planner_live.php`, `tests/teaching_planner/run.php`.
2. `C:\xampp\php\php.exe scripts\seed_teaching_planner_live.php` — run **twice**: first run provisions, second run changes nothing (idempotency).
3. `C:\xampp\php\php.exe tests\teaching_planner\run.php` → 14+1 PASS.
4. Authenticated curl smoke (staffLogin with `EXH-LEC-001` or `ITC907` / `Test@12345`): the page's `assignmentSelect` lists real assignments, `Generate review preview` is enabled, `My teaching plans` shows the demo plan; open `?view=…` and confirm the plan table + Save-row/lesson-plan controls render.
5. **Shell fix proof:** the rendered page now ends with the footer → `</body>` 1 and `</html>` 1, `main-wrapper`/`main-content` closed once. Re-test unauth access → still 302 to login.
6. Regression: `tests/teaching_planner/run.php` untouched tests still 14/14; sibling lecturer pages unaffected.

## [Implementation Order]

1. Append footer include to `lecturers/teaching_planner.php`; `php -l`; curl render → shell count `</html>` 0→1.
2. Write `scripts/seed_teaching_planner_live.php` (Steps A–F); `php -l`; run twice.
3. Extend `tests/teaching_planner/run.php`; run; confirm 15/15.
4. Authenticated curl smoke (ITC907 / EXH-LEC-001): assignments listed, generation allowed, plan saved + viewable, shell closed.
5. Update this file's verification log.

## [Verification Log]

**IMPLEMENTED & VERIFIED 2026-08-25**

- `php -l` clean on `lecturers/teaching_planner.php`, `scripts/seed_teaching_planner_live.php`, `tests/teaching_planner/run.php`.
- Seed `scripts/seed_teaching_planner_live.php` run twice: first run populated 22 assignments, schedule #32, template v22, syllabus v19 + 3 topics, plan #27 (18 items); second run changed nothing (all "already" paths) — idempotency PASS.
- Suite `tests/teaching_planner/run.php` → **15 passed, 0 failed**, including new `live provisioning seed makes the lecturer page usable` (EXH-LEC-001 → DCSE-101; approved syllabus ICT-001/DCSE-101; active term scheme-of-work template; saved plan present).
- Shell fix: authenticated render (ITC907) now has `</body>` ×1 and `</html>` ×1 (previously 0); page 200, zero PHP warnings. Same for EXH-LEC-001 list + plan view (`?view=27`, 19 save-row controls, SOW-2026 doc number).
- Functional: ITC907 assignment dropdown lists 9 real DCSE offerings; "Generate review preview" button now ENABLED (was disabled after the backfill); EXH-LEC-001 plans badge = 1 with an openable plan row.

---

# Implementation Plan - Lecturer CA Upload Success Message & CA Results View Fixes

## [Overview]

Two coordinated lecturer-portal changes, confirmed by code/CSS reads on 2026-08-22:

1. **`lecturers/upload_ca.php`** - remove the "My Uploaded CA Records" section (server fetch + table card) and replace it with a clear success message after a CA upload, using the existing flash-message infrastructure in a Post/Redirect/Get (PRG) flow, consistent with how the CSV upload path already reports results.
2. **`lecturers/viewCaRes.php`** - fix how CA results are viewed: (a) the official grade-sheet table collapses into stacked cards at any viewport <= 1199.98px (i.e. all laptop widths), hiding the two-row header; (b) programs with no `semester_registration` rows (e.g. CSE) fall back to period type `'period'`, producing "Term / Semester: Period 2" labels - default to `'term'` per the DB-wide convention (every `semester_registration.period_type` is `'term'`); (c) the page renders with the teal assignment theme (`--assignment-primary: #0f766e`) on the command bar, stat values, and focus rings, clashing with the portal purple - add `.ca-results-page` purple overrides following the existing `.ai-question-bank-page` precedent.

## [Types]

None - procedural PHP + CSS only.

## [Files]

**1. `c:\xampp\htdocs\wucportal\lecturers\upload_ca.php` (modify)**
- Delete lines 244-278: the `$uploadedCaRecords` / `$uploadedCaCapped` server-side fetch block.
- Delete lines 580-665: the "My Uploaded CA Records" card and its wrapper.
- Change the manual-save success branch (lines 157-162): on `$save['ok']`, call `wuc_flash('success', $msg)` then `wuc_safe_redirect()` back to the manual tab, preserving course/year preselect via the existing deep-link support.

**2. `c:\xampp\htdocs\wucportal\lecturers\viewCaRes.php` (modify)**
- Line 330: `$periodTypeByProgram[$code] = 'period';` -> `'term'`.
- Lines 427-429: `$selectedPeriodType` fallback `: 'period'` -> `: 'term'`.
- JS line 829: `periodTypeByProgram[program] || 'period'` -> `|| 'term'`.
- JS `periodLabelFor()` (line 785): fallback `'Term / Semester'` -> `'Term'`.

**3. `c:\xampp\htdocs\wucportal\lecturers\css\module-reusable.css` (modify - append)**
- Grade-sheet layout restore for `.ca-results-page .official-sheet .table-mobile-stack` at 768-1199.98px.
- Purple accent overrides for `.ca-results-page` (command-link active/hover, `.lecturer-section` left border, `.workflow-stat-value`, form focus rings).

## [Implementation Order]

1. `lecturers/css/module-reusable.css`: append the `.ca-results-page` section (table-layout restore + purple accents).
2. `lecturers/viewCaRes.php`: switch the three `'period'` defaults to `'term'` + the JS label fallback.
3. `lecturers/upload_ca.php`: convert the success branch to PRG + flash; delete the records fetch block (244-278); delete the records card HTML (580-665).
4. `php -l` both PHP files; browser UAT.

---

# Implementation Plan â€” Student Portal Academic Flow Repairs

## [Overview]

Repair five student-portal areas in `c:\xampp\htdocs\wucportal\students`: (1) course assignment/registration, (2) term/semester progression, (3) short courses, (4) diploma/certificate sub-portals, (5) CA uploads / marks availability. All root causes were confirmed by direct DB probes and code reads on 2026-08-15.

**Architecture recap:** `registration.php` â†’ `StudentAcademicWorkflowService::registerStudentForPeriod()` auto-enrols the whole year-of-study catalogue (stamped with the *current period number*). `courseReg.php` is read-only and self-heals via `ensureYearCoursesEnrolled()`. CA flows lecturer-side (`upload_ca.php` â†’ `ca_save_components()` â†’ `semester_assessment` status `Submitted`) and becomes student-visible only at `status='Published'` (`continuousAssessment.php`). Sub-portals (`short_course_portal.php`, `certificate_portal.php`, `diploma_portal.php`, `trade_test_portal.php`) delegate to `index.php` gated by `wuc_student_program_portal_profile()`.

**Confirmed DB evidence:** `course_registration` mixes `status='active'` (seeded, EXH students, no `semester_registration`) with `'registered'` (canonical); `student_program.current_term_number` is stale for self-registered students (CVM26567121 = term 1 despite Term-2 registration); progression inserts `registration_status='pending'` rows for the next year which `findSemesterRegistration()` (no status filter) treats as "already registered"; `fee_structure` covers only AUTO-001/BBA (live programs fail-open); `short_course_assessment` holds an orphan mark (CSE26456789/PRN1130, no enrolment); CA class-list/guard queries are period-strict while auto-enrol stamps the registration period.

## [Types]

No type-system changes (procedural PHP + two service classes edited in place).

## [Files]

**Modify:**
1. `c:\xampp\htdocs\wucportal\students\includes\StudentDataService.php` â€” `getStudentYearOfStudy()` gains optional academic-year scoping.
2. `c:\xampp\htdocs\wucportal\students\includes\StudentAcademicWorkflowService.php` â€” pending-registration claim, fee-gate setting, student_program position sync, reactivation period update, `ensureYearCoursesEnrolled()` pending guard, year-scoped YoS resolution.
3. `c:\xampp\htdocs\wucportal\includes\ca_helpers.php` â€” `ca_course_is_full_year()` helper; period-tolerant matching in `ca_fetch_course_students()` + `ca_student_registered()` for full-year courses; `ca_ensure_normalized_registration_bridge()` targets the requested period for full-year courses.
4. `c:\xampp\htdocs\wucportal\students\short_courses.php` â€” render CA marks per enrolled short course (dual-enrolled students currently never see them).

**Create:**
5. `c:\xampp\htdocs\wucportal\repairs\repair_student_records_2026.php` â€” CLI-only, idempotent data repair (missing EXH `semester_registration` rows + link course rows; orphan short-course CA enrolment; seed missing `portal_settings` keys; report-only on BSCS students and missing fee structures).
6. `c:\xampp\htdocs\wucportal\tests\student_portal_flows\run.php` â€” CLI regression harness (not `test_*.php` at web root â€” `.htaccess` 403-blocks those).

**No schema changes** (data inserts only). No packages/dependencies added.

## [Functions]

- `StudentDataService::getStudentYearOfStudy(string $studentId, ?string $academicYear = null): int` â€” when an academic year is supplied: latest registration **for that year** â†’ else `student_program.current_year_number/year_of_study` â†’ else 1. Unscoped legacy behavior preserved when omitted. Fixes wrong-year enrolment after a progression row exists for a future year.
- `StudentAcademicWorkflowService::getActiveAcademicPeriod()` â€” passes the resolved academic year into `getStudentYearOfStudy()`.
- `StudentAcademicWorkflowService::registerStudentForPeriod()` â€” a found `semester_registration` row with `registration_status='pending'` (progression placeholder) is *claimed* (status columns updated + courses enrolled) instead of "already registered"; honors `portal_settings.reg_payment_gate_enabled` (default enabled); syncs programme position.
- `StudentAcademicWorkflowService::syncStudentProgramPosition()` (new private) â€” updates `student_program.year_of_study/current_year_number` and term/semester position columns on the latest matching active assignment.
- `StudentAcademicWorkflowService::isRegistrationFeeGateEnabled()` (new private) â€” reads `reg_payment_gate_enabled`, defaults to enforced.
- `StudentAcademicWorkflowService::ensureYearCoursesEnrolled()` â€” skips `pending` rows (progression placeholders must not bypass fee-gated registration).
- `StudentAcademicWorkflowService::reactivateCourseRegistrationForYear()` â€” also moves `semester` to the current period number so period-scoped consumers (CA lists, fee sums) stay aligned.
- `ca_course_is_full_year(mysqli $db, string $courseCode): bool` (new) â€” cached lookup: `curriculum_courses.is_full_year=1` else `program_courses.is_full_year=1`.
- `ca_fetch_course_students()` / `ca_student_registered()` â€” full-year courses match registrations by academic year regardless of stored period; period-specific courses stay period-strict.
- `ca_ensure_normalized_registration_bridge()` â€” for full-year courses the offering/academic-period join uses the *requested* period instead of the stored registration period.

## [Classes]

`StudentAcademicWorkflowService` and `StudentDataService` edited as above; no signatures broken (new params optional/private).

## [Dependencies]

None added. Verification uses bundled `php.exe` CLI and curl (dev impersonation hook `?dev=1&dev_as=` exists on `students/index.php` under `APP_ENV=development`).

## [Testing]

CLI harness `tests\student_portal_flows\run.php` asserts, against the live dev DB:
1. `getStudentYearOfStudy('CSE26456789','2026')` = 1; with a simulated future-year pending row present, current-year resolution still returns 1 and next-year returns 2 (rolled back after test).
2. `registerStudentForPeriod()` on a synthetic student with a `pending` progression row â†’ claims it (`registration_status='registered'`, courses enrolled), and `student_program.current_term_number` updated.
3. Re-registration in a later period moves `course_registration.semester` to the new period (idempotent on repeat).
4. `ca_fetch_course_students('DCSE-101', '2', '2026')` lists CSE26456789 **and** EXH-ALU-001/EXH-STU-001 (full-year tolerance); a period-specific course (if present) stays period-strict.
5. `ca_student_registered()` agrees for the same matrix.
6. `ca_ensure_normalized_registration_bridge()` resolves a registration for a full-year course at a requested period different from the stored registration period (transaction rolled back).
7. Short-course enrolment guard blocks CA for non-enrolled students; `short_courses.php` marks query returns CSE26456789's PRN1130 row after repair.

HTTP smoke matrix (curl): registration/courseReg/continuousAssessment/short_courses pages render (302/200 as appropriate) for CSE26456789, CVM26567121, EXH-ALU-001, SCONLYTEST01 via dev impersonation; lecturer `ajax_get_course_students.php` returns the full class list for DCSE-101 period 2.

`php -l` on every edited file before and after.

## [Implementation Order]

1. Write this plan; `php -l` baseline on the four edit targets.
2. Phase 1 â€” registration/progression core: `StudentDataService.php`, `StudentAcademicWorkflowService.php`. Run harness items 1-3.
3. Phase 2 â€” CA availability: `includes/ca_helpers.php`; verify HOD/registrar publish listing shows term rows; seed settings via repair script. Run harness items 4-6 + lecturer AJAX smoke.
4. Phase 3 â€” short courses: `students/short_courses.php` CA section; confirm upload guard; repair orphan. Harness item 7 + page smoke.
5. Phase 4 â€” data repairs: run `repairs\repair_student_records_2026.php`, capture before/after row dumps.
6. Full regression matrix; update this file's verification log.

**Deferred (no user decision received):** fee_structure seeding for ICT-001/ICT-002/AUTO-002 (amounts unknown â€” fail-open retained, still logged); BSCS student records (4 accounts reference a nonexistent program â€” left untouched, reported by repair script); auto-redirect from `index.php` to dedicated sub-portals (landing behavior unchanged); trade-test period alignment (no trade-test students exist yet).

---

# Archive â€” Completed: Test Timetable / Test Schedule 403 Fix (2026-08)

> Status: IMPLEMENTED and VERIFIED (10/10 curl checks PASS). Kept for reference.

## [Overview]

Fix the "My Test Schedule" / "Test Timetable" pages that return **HTTP 403 Forbidden** - the pages never reach PHP because an Apache `.htaccess` security rule blocks every `test*.php` filename in any directory.

**Root cause (confirmed):** `c:\xampp\htdocs\wucportal\.htaccess` line 28:
```apache
RewriteRule (?:^|/)(?:debug|test|setup|migrate|fix|insert_demo|simple_db|update_tables)[^/]*\.php$ - [F,L,NC]
```
This rule (added in the 2026-07-06 security audit to keep unauthenticated diagnostic scripts unreachable) also matches four legitimate, nav-linked portal pages:

| Page | Linked from | Verified status |
|---|---|---|
| `lecturers/test_schedule.php` (My Test Schedule) | `lecturers/includes/nav.php:28` | 403 |
| `students/test_timetable.php` (Test Timetable) | `students/includes/navbar.php:288` | 403 |
| `registrar/test_timetable.php` (Test Timetable) | `registrar/includes/nav.php:25` | 403 |
| `hod/test_timetable.php` (Test Timetable) | `hod/includes/nav.php:163` | 403 |

**Evidence:** `curl -i` returns Apache-level 403 (no PHP output) for all four; sibling pages (`lecturers/index.php`, `students/previous_test_timetables.php`, `students/trade_test_portal.php`) return the normal 302 login redirect. Both `lecturers/test_schedule.php` and `includes/test_timetable.php` pass `php -l`. All DB objects used by the page exist and match the code: `assessment_periods` (all columns incl. status enum draft/scheduling/published/active/closed/archived), `test_timetable` (all 17 columns used by `tt_list_entries`), `students.year`, `course_lecturer.staff_id/course_code`, `academic_periods`, `classrooms.status`. No PHP or schema changes are required.

**Approach (user-approved):** add one `RewriteCond` exception above the blocking rule, whitelisting the two live page basenames `test_schedule.php` and `test_timetable.php`. This follows the exact precedent already in the same file (whitelist of `check_eligibility.php` / `check_registration_status.php`). Diagnostic `test_*.php` scripts (root `test_db.php`, `students/test_session.php`, `students/test1_slip.php`, etc.) stay blocked. No Apache restart needed - `.htaccess` is evaluated per request.


## [Types]

No type-system changes (procedural PHP codebase; no classes, interfaces, or enums touched).

## [Files]

**Modify - `c:\xampp\htdocs\wucportal\.htaccess`** (only file changed):
- Insert immediately **above** the existing line 28 (`RewriteRule (?:^|/)(?:debug|test|setup|migrate|fix|insert_demo|simple_db|update_tables)[^/]*\.php$ - [F,L,NC]`):
```apache
# Live test-timetable pages are genuine nav-linked portal pages, not
# diagnostic scripts: lecturers/test_schedule.php and
# students|registrar|hod/test_timetable.php. Keep them reachable (same
# exception pattern as check_eligibility.php below).
RewriteCond %{REQUEST_URI} !/(?:test_schedule|test_timetable)\.php$ [NC]
```
- The existing `RewriteRule` line is kept byte-for-byte unchanged; the condition applies only to the rule directly beneath it.

**No new files. No files deleted. No PHP files modified. No DB/schema changes. No config changes beyond this one `.htaccess` edit.**

## [Functions]

No function changes. Verified-unchanged call chain that starts working once the 403 is lifted:

- `lecturers/test_schedule.php` -> `tt_ensure_schema($db)` (includes/test_timetable.php:20) -> `tt_lecturer_tests($db, $staffId)` (:970) -> `tt_list_periods` (:137) / `tt_lecturer_course_codes` (:941) / `tt_list_entries` (:296) / `tt_entry_student_count` (:1018). All lint clean and schema-compatible.

## [Classes]

No class changes.

## [Dependencies]

No new packages, no version changes. Requires only the already-loaded `mod_rewrite` (rules already fire today, confirming it is active).

## [Testing]

No PHPUnit suite covers URL access; verification is an HTTP status-code matrix via curl:

**Fixed (expect 302 login redirect, not 403):**
1. `curl.exe -s -i http://localhost/wucportal/lecturers/test_schedule.php` -> `HTTP/1.1 302`
2. `curl.exe -s -i http://localhost/wucportal/students/test_timetable.php` -> `HTTP/1.1 302`
3. `curl.exe -s -i http://localhost/wucportal/registrar/test_timetable.php` -> `HTTP/1.1 302`
4. `curl.exe -s -i http://localhost/wucportal/hod/test_timetable.php` -> `HTTP/1.1 302`

**Regression - diagnostics must stay blocked (expect 403):**
5. `curl.exe -s -i http://localhost/wucportal/test_db.php` -> 403
6. `curl.exe -s -i http://localhost/wucportal/students/test_session.php` -> 403
7. `curl.exe -s -i http://localhost/wucportal/migrate_timetable.php` -> 403

**Regression - unaffected pages still work (expect 302):**
8. `curl.exe -s -i http://localhost/wucportal/lecturers/index.php` -> 302
9. `curl.exe -s -i http://localhost/wucportal/students/previous_test_timetables.php` -> 302
10. `curl.exe -s -i http://localhost/wucportal/students/trade_test_portal.php` -> 302

**Browser UAT:** log in as a lecturer, open Lecturer Portal -> "My Test Schedule". Expected: page renders with hero panel; since `test_timetable` currently has 0 rows, the blue info alert "No test assignments found for your courses in current assessment periods." displays (correct empty state, not an error). Then confirm registrar/student/HOD test-timetable pages load.

## [Implementation Order]

1. Edit `c:\xampp\htdocs\wucportal\.htaccess`: insert the 4-line comment + `RewriteCond` block directly above the current line-28 `RewriteRule`. No other changes.
2. Run curl verification matrix items 1-4 (fix) - all must flip 403 -> 302.
3. Run curl regression items 5-10 - statuses must be unchanged (403 for diagnostics, 302 for others).
4. Browser UAT per portal (lecturer first), confirming the empty-state info alert renders rather than an error.
5. Done - no restart, migration, or cache clear required (`.htaccess` is per-request; no PHP files change).

**Known accepted side effect:** `includes/test_timetable.php` (a function-library include) also becomes directly requestable. It is side-effect-free (defines functions only, emits no output), matching the accepted exposure of other whitelisted basenames. Tightening the exception to full paths is possible but deviates from the file basename-exception convention.

**Convention note for future work:** never name a live page with the prefixes `debug|test|setup|migrate|fix|insert_demo|simple_db|update_tables` - the security rule will 403 it.

---

# Implementation Plan — Short Course / Long-Term Programme Separation & Student CA Page Fixes

## [Overview]

Guarantee that short-course programmes (e.g. TRANS-001 "Class A Motor Bike Riding", `is_short_course=1` / `structure_type='SHORT_COURSE'`) can never be assigned to a student as a long-term programme in `student_program`, and confirm `students/short_courses.php` + `students/continuousAssessment.php` correctly show assigned courses and CA marks for both short-only and dual-enrolled students.

**Investigation findings (verified against live DB + curl HTTP smoke tests, 2026-08-23):**

1. Both pages render correctly today: `short_courses.php` → SCONLYTEST01 sees the PRN1130 card; CSE26456789 (dual: ICT-001 long + PRN1130 short) sees the PRN1130 card with its CA badge. `continuousAssessment.php` → SCONLYTEST01 gets the "Short Course Continuous Assessment" report; CSE26456789 gets the long-term report (DCSE-10x) with short-course CA suppressed (dual students see short-course CA on `short_courses.php` — confirmed desired behavior).
2. The real cross-contamination bug: programme-selection UIs that feed `student_program` list short courses alongside long-term programmes, and the write path has no guard — `registrar/admitStudent.php:25`, `admin/update_student_program.php:242-248`, `admin/get_programs.php:22-24` dropdowns all include TRANS-001; `admissionsEnrollExistingStudent()` only validates that the program *exists*. Assigning TRANS-001 would silently reroute the student into the short-course portal and hide their academic portal.
3. No corrupted data exists today: zero `student_program` rows joined to short-flagged programs — latent-risk hardening, not a data repair.

**Confirmed user decision:** keep the current display split for dual-enrolled students; fix the root cross-contamination by filtering dropdowns and adding server-side guards. No behavior change to what students see.

## [Types]

None — procedural PHP only.

## [Files]

**Modify:**

1. `c:\xampp\htdocs\wucportal\registrar\admitStudent.php` — add `require_once` for `includes/short_course_student.php`; dropdown query gains `sc_sql_programs_long_only_predicate($db,'p')` filter; early POST rejection via `sc_program_is_short_course()`.
2. `c:\xampp\htdocs\wucportal\admin\update_student_program.php` — same `require_once`; GET dropdown query gains the long-only predicate; POST guard after the "program exists" check flashing a danger message and redirecting to SELF_URL.
3. `c:\xampp\htdocs\wucportal\admin\get_programs.php` — `require_once` + long-only predicate on the JSON feed query.
4. `c:\xampp\htdocs\wucportal\includes\applicant_admission.php` — `require_once __DIR__ . '/short_course_student.php'`; short-course rejection guard in `admissionsEnrollExistingStudent()` (after the CSE check) and in the applicant-admission block (after line ~171).
5. `c:\xampp\htdocs\wucportal\tests\student_portal_separation\run.php` — new assertions: (a) `admissionsEnrollExistingStudent(..., 'TRANS-001', ...)` returns `success=false` mentioning "short course" and writes no `student_program` row; (b) structural checks that the three assignment UIs reference the short-course helpers; (c) live DB invariant: zero `student_program` rows on short-flagged programs.

**No schema changes. No new packages.**

## [Functions]

- Modified — `admissionsEnrollExistingStudent(mysqli, string, string, string, string, ?int): array` (`includes/applicant_admission.php:417`): early-return rejection for short-flagged programs; return shape unchanged.
- Modified — applicant-admission block in the same file (~line 169): same rejection before enrollment.
- No functions removed; no signatures changed. Reuses shipped helpers `sc_program_is_short_course()` and `sc_sql_programs_long_only_predicate()`.

## [Classes]

None.

## [Dependencies]

None added.

## [Testing]

1. `php -l` on all four modified PHP files.
2. `C:\xampp\php\php.exe tests\student_portal_separation\run.php` — existing + new assertions PASS.
3. HTTP smoke (dev impersonation): `short_courses.php` + `continuousAssessment.php` for SCONLYTEST01, CSE26456789, CVM26567121 — unchanged correct rendering.
4. UAT: registrar/admin programme dropdowns no longer list TRANS-001; direct POST of TRANS-001 is rejected with no `student_program` row written.

## [Implementation Order]

1. Append this plan to `implementation_plan.md`.
2. `includes/applicant_admission.php` — server-side guard (both write paths).
3. `registrar/admitStudent.php` — dropdown predicate + early POST check.
4. `admin/update_student_program.php` — dropdown predicate + POST guard.
5. `admin/get_programs.php` — dropdown predicate.
6. `php -l` all four files.
7. Extend + run `tests\student_portal_separation\run.php`.
8. HTTP smoke matrix + dropdown UAT.

## [Verification Log] — IMPLEMENTED & VERIFIED 2026-08-23

- `php -l` clean: `includes/applicant_admission.php`, `registrar/admitStudent.php`, `admin/update_student_program.php`, `admin/get_programs.php`, `tests/student_portal_separation/run.php`.
- Harness `tests/student_portal_separation/run.php` → **RESULT=OK** (46 assertions). New assertions confirmed: `data.no_short_program_in_student_program` (0 rows), `guard.enroll_rejects_short_course` ("TRANS-001" is a short course…), `guard.no_student_program_row_written`, and the three UI structural checks.
- HTTP smoke (dev impersonation): SCONLYTEST01 → `short_courses.php` PRN1130 card, `continuousAssessment.php` short-course report with PRN1130 row; CSE26456789 (dual) → `short_courses.php` PRN1130 card with "CA 80.0%" badge, `continuousAssessment.php` long-term report (DCSE-101, no PRN1130); CVM26567121 (long-only) → CCAM-101 report, unchanged.
- Dropdown predicate verified at SQL level: TRANS-001 excluded; 30 long-term programmes remain selectable.

---

# Implementation Plan — Backend & Frontend Debug: Students, Lecturers, Admin
---

# Implementation Plan — Add Z-Library (z-library.biz) as a Book Source for the Portal Library & Reading Resources

## [Overview]

Add `https://z-library.biz/` as a "where to get books" source so students and library staff can reach a large free eBook/textbook collection from the portal's library pages and reading-resource surfaces. Approached three ways that reinforce each other: a **seeded digital-library catalog entry** (so it appears as a resource card in the student Digital Library grid — the *reading resources* listing), **prominent "Get Books" CTA buttons** on the student Catalog + Digital Library pages (always visible, above the fold), and a **Get Books link/banner on the staff-facing Library dashboard** (`library/index.php` + `admin/library.php`), per the confirmed user decision ("student pages + staff Library dashboard so librarians see it too").

All changes are low-risk and self-contained: no schema changes (the `library_digital_resources` and `library_resource_links` tables already exist), no new packages, no modifications to the shared `includes/dashboard_template.php` (the staff banner uses the existing `$extra_dashboard_content` hook). Every external anchor uses `target="_blank" rel="noopener noreferrer"` per the codebase's security conventions. The seed is delivered as an idempotent, defensive PHP migration following the established `migrations/2026zzzz_*.php` pattern (`php migrations/20260904_add_zlibrary_book_source.php`), and is verified by a new CLI harness at `tests/library_book_source/run.php`.

## [Types]

None — procedural PHP markup + a single data seed. No interfaces, enums, or data structures change.

## [Files]

**Create (2):**

1. **`migrations/20260904_add_zlibrary_book_source.php`** (new — CLI-only, idempotent, `declare(strict_types=1)`)
   - `require_once __DIR__ . '/../db/connect.php';` (wires `wuc_table_exists` via `includes/schema_guard.php`).
   - Guard: missing `library_digital_resources` → stderr message + `exit(1)`.
   - Introspect columns via `SHOW COLUMNS` (defensive — live table may carry `visibility`/`course_code` beyond `db/library_schema.sql`).
   - Idempotency: skip if a row exists with `url = 'https://z-library.biz/'`.
   - Insert (only columns present; `title, resource_type, url, access_level, description` guaranteed by base schema):
     - `title` = `Z-Library — Free eBooks & Textbooks`
     - `resource_type` = `ebook`
     - `url` = `https://z-library.biz/`
     - `access_level` = `open` (passes `digital_student_visibility_clause` allowlist)
     - `visibility` = `all` (only if column exists)
     - `subject` = `General`
     - `description` = `Get books — free digital library of eBooks, textbooks and academic reading material for study and research. Opens in a new tab.`
     - `created_at` / `updated_at` = `NOW()`
   - Echo before/after count + `already present` on re-run; `exit(0/1)`.

2. **`tests/library_book_source/run.php`** (new — CLI harness, `declare(strict_types=1)`, `require_once db/connect.php`)
   - Structural checks (via `file_get_contents` on the four pages): each contains `href="https://z-library.biz/"` with `target="_blank"` and `rel="noopener noreferrer"`.
   - DB invariant: exactly one `library_digital_resources` row with `url='https://z-library.biz/'`; `resource_type` = `ebook`; `access_level` in the student-visibility allowlist. If the row is missing, fail loudly with `run php migrations/20260904_add_zlibrary_book_source.php first`.
   - Uses the standard `$ok(bool,label)` / `$pass/$fail` / `exit($fail>0?1:0)` conventions from `tests/notification_context/run.php`.
## [Functions]

None — no library function signatures change. Reuses `wuc_table_exists()`, the `$extra_dashboard_content` render hook, and the existing `digital_student_visibility_clause` (allows `open`).

## [Classes]

None — procedural PHP only.

## [Dependencies]

None added. External URL is a plain static anchor; `access_level='open'` avoids the role-gating path (`library_digital_resource_roles`).

## [Testing]

1. `php -l` on migration, 4 modified pages, harness.
2. Migration idempotency: run twice (1 row → still 1 row).
3. `tests/library_book_source/run.php` → `Result: N passed, 0 failed`, exit 0.
4. HTTP smoke (dev impersonation where applicable): buttons/banner render on all four pages; seeded card appears in Digital Library All/Ebooks chips; unauthenticated → 302 login.
5. Regression: `tests/frontend_audit/run.php`, `tests/student_ui_consistency/run.php`.
6. Confirm the seeded row appears in `admin/library_digital.php`.

## [Implementation Order]

1. Append this plan to `implementation_plan.md`.
2. Write `migrations/20260904_add_zlibrary_book_source.php`; `php -l`; run twice.
3. Add Get Books buttons to `students/digital_library.php` + `students/library.php`; `php -l`; curl smoke.
4. Add banner to `library/index.php` + button to `admin/library.php`; `php -l`; curl smoke.
5. Write `tests/library_book_source/run.php`; `php -l`; run.
6. Regression suites + full HTTP smoke matrix.
7. Append the verification log.

## [Edge Cases / Notes]

- Seeded general card scores 4 (`course_code===''&&score===0 → 4`), below the `recommended=1 / score>=30` threshold → appears in All/Ebooks chips + search, not the default Recommended chip. The always-visible Get Books button is the primary CTA.
- No `library_resource_links` row is added — "My Course Library" accordion only lists course-scoped resources (avoids duplication inside every course).
- PHP CLI may be blocked by the session's Application Control policy; fallback is applying the identical `INSERT … WHERE NOT EXISTS` via phpMyAdmin/mysql CLI.
- Security: every external anchor is `target="_blank" rel="noopener noreferrer"`.

## [Verification Log]

**IMPLEMENTED & VERIFIED 2026-09-04**

- `php -l` clean on all 6 PHP artifacts: `migrations/20260904_add_zlibrary_book_source.php`, `tests/library_book_source/run.php`, `students/digital_library.php`, `students/library.php`, `library/index.php`, `admin/library.php`.
- Migration run **twice**: first run inserted the Z-Library digital resource (`library_digital_resources 0 -> 1`); second run reported `= already present … Left unchanged` — idempotency PASS.
- Harness `tests/library_book_source/run.php` → **12 passed, 0 failed, exit 0** (DB invariant ×4 + structural link/security checks ×8).
- Confirmed the seeded row in the live DB: `resource_type=ebook`, `access_level=open` — passes `digital_student_visibility_clause` (`access_level IN ('open','registered','student','students','campus','public','all')`), so it renders in the student Digital Library All/Ebooks chips.
- Regression: `tests/frontend_audit/run.php` exit 0 (no `[fail]`; pre-existing duplicate-Bootstrap `[warn]s` unchanged), `tests/student_ui_consistency/run.php` exit 0.
- HTTP smoke: all four pages still return **302 → login** unauthenticated (auth guards intact; no 500s).
- External links all use `target="_blank" rel="noopener noreferrer"` (`btn-warning` "Get Books" on both student pages; banner + "Open Z-Library" on `library/index.php` via `$extra_dashboard_content`; "Get Books" on `admin/library.php`).
- Note: `php.exe` CLI was Application-Control-blocked in this session; `C:\xampp\php\php-win.exe` was used for all lint/run steps and worked identically. A UTF-8 BOM accidentally introduced into `admin/library.php` during a PowerShell rewrite was detected and stripped (file re-verified byte-clean starting at `<?`).

**Modify (4):**

3. **`students/digital_library.php`** — hero button group (lines ~634–641), insert Get Books button (amber `btn-warning`) **first**, before the Ask-AI toggle.
4. **`students/library.php`** — page-header button group (lines ~29–35), insert Get Books button before "Digital Library".
5. **`library/index.php`** — set the `$extra_dashboard_content` hook (before `require_once dashboard_template.php`) to a Get Books banner card.
6. **`admin/library.php`** — header action group (lines ~18–23), add "Get Books" button (Bootstrap Icons).

**No file deletions, moves, or config changes.**

## [Overview]

Full-stack debug sweep across the three portals. Investigation (harness runs + curl smoke + log scan, 2026-08-23) found the portals largely healthy; exactly two real defects plus test-inflicted data corruption need fixing:

1. **Frontend (student):** `students/previous_test_timetables.php` does not open the `<div class="content-wrapper">` shell after `navbar.php` (every sibling, e.g. `timetable.php:421`, does) — page renders unwrapped/misaligned against the sidebar. This is the only `[fail]` in `tests/frontend_audit/run.php`.
2. **Backend (test, not app):** `tests/student_academic_workflow/run.php` is stale and destructive — it asserts `ca_save_component()` rejects edits after publish ("locked"), but the app intentionally auto-publishes on save and only locks `Approved` rows (`includes/ca_helpers.php:1580-1584`, deliberate design, user-confirmed). Each run overwrites the live DCSE-101 A1 mark, decaying its own expectation (`13.25→…→17.25`).
3. **Data:** DCSE-101 (CSE26456789, sem 2, 2026) A1/Total_CA corrupted by that test; original A1 = 15.25 (Total_CA 15.31).

**Healthy (verified):** `student_module` OK; `student_reg_ca` OK; `student_courses_integrity` 16/16; `student_year_progression` 11/11; `student_portal_flows` 14/14; `student_portal_separation` 68/68; `student_ui_consistency` 51 pages OK. Lecturer/admin core pages (`index`, `upload_ca`, `viewCaRes`, `test_schedule`, `programs`, `update_student_program`, `get_programs`) all correctly 302 to login unauthenticated. No PHP warnings/fatals in Apache error log. Deferred pre-existing warnings: ~40 student pages double-load Bootstrap CDN; legacy admin/accounts pages missing viewport meta (cosmetic, out of scope per user decision).

## [Types]

None — procedural PHP/HTML + one test edit + one data correction.

## [Files]

**Modify:**
1. `c:\xampp\htdocs\wucportal\students\previous_test_timetables.php`
   - After `require_once __DIR__ . '/includes/navbar.php';` (line 21), wrap the page body: open `<div class="content-wrapper">` before the existing `<div class="container-fluid px-3 px-md-4 tt-prev-page">` (line 39) and close it immediately before `require_once __DIR__ . '/includes/footer.php';` (line 73). The footer already closes the navbar's two divs, matching `timetable.php`'s structure.
2. `c:\xampp\htdocs\wucportal\tests\student_academic_workflow\run.php` (lines ~179-226)
   - Replace the stale lock assertion with the intended behavior: when the row is `Published` (auto-publish), `ca_save_component()` **succeeds** and the new mark is visible; lock applies only to `Approved`. Keep a guard that if the row is `Approved`, the save is rejected with "locked".
   - Make it non-destructive: capture the full original row (A1, A2, A3, T1, T2, Exam, Total_CA, status) before the save; after assertions, restore it in a `finally`-style block (direct UPDATE, then re-run `ca_sync_normalized_result` equivalent or a plain UPDATE of the normalized mirror if present) so repeated runs are idempotent and leave no residue.

**Data repair (no file):**
3. Reset `semester_assessment` row (Sid=CSE26456789, Course_Code=DCSE-101, semester=2, Year=2026): `A1=15.25`, `Total_CA=15.31` (restores the pre-corruption values; A2=10.00, T2=18.00 already intact). Also realign the normalized mirror (`ca_results`/`result_status`) if it drifted, via the same helper the app uses.

## [Functions]

- **Modified:** none in app code. `ca_save_component()` behavior is confirmed correct (auto-publish editable; `Approved` locked) — no change.
- **Test-only:** the workflow script's assertion block rewritten; no library signature changes.

## [Classes]

None.

## [Dependencies]

None.

## [Testing]

1. `php -l` on `previous_test_timetables.php` and the edited test.
2. `tests/frontend_audit/run.php` → zero `[fail]` (the previous_test_timetables shell item clears).
3. `tests/student_academic_workflow/run.php` → RESULT=OK; run twice consecutively to prove idempotency (second run must also pass and the DCSE-101 mark must be unchanged afterward).
4. Re-run full harness suite to confirm all green: `student_module`, `student_reg_ca`, `student_courses_integrity`, `student_year_progression`, `student_portal_flows`, `student_portal_separation`, `student_ui_consistency`.
5. curl smoke: `previous_test_timetables.php` renders 200 with `content-wrapper` present; lecturer/admin core pages still 302 unauthenticated; student CA page still shows DCSE-101 with restored 15.3 term-2 component.
6. DB check: DCSE-101 A1=15.25/Total_CA=15.31 after the repair and after the idempotency runs.

## [Implementation Order]

1. Append this plan to `implementation_plan.md`.
2. Repair DCSE-101 data (A1/Total_CA + normalized mirror realignment).
3. Fix `previous_test_timetables.php` shell wrapper; `php -l`; re-run `frontend_audit` (expect 0 fails).
4. Rewrite the `student_academic_workflow` CA block (correct assertion + non-destructive restore); `php -l`; run twice; verify mark unchanged.
5. Re-run the full harness suite; confirm all RESULT=OK.
6. curl smoke matrix (item 5 above).
7. Update this file's verification log.

---

# Implementation Plan — Portal Switcher for Dual-Enrolled Students (Long Programme + Short Course)

## [Overview]

Dual-enrolled students (long programme + short course) previously landed only on their long-term portal: `index.php`'s sub-portal gate 302-redirected them away from `short_course_portal.php`, and the navbar always rendered the long-programme menu. This change adds a session-scoped portal view override so a dual student can flip into the Short Course Portal (dashboard + short-course nav menu) and back, without affecting short-only or long-only students. Login routing and `wuc_student_program_portal_profile()` semantics are unchanged — the override is a view-layer session concern applied in `index.php`/`navbar.php` only.

## [Types]

None — procedural PHP; one new session key `$_SESSION['student_portal_view']` (`'short_course'`), unset when viewing the long portal.

## [Files]

1. `c:\xampp\htdocs\wucportal\students\portal_view.php` (new) — switch endpoint: grants `?view=short_course` only when `sc_student_has_long_program()` AND `sc_student_enrolments() !== []` (dual-enrolled), sets the session key, busts the navbar flag cache, 302s to `short_course_portal.php`; `?view=academic` clears the override and returns to `index.php`.
2. `c:\xampp\htdocs\wucportal\students\index.php` — `$studentPortalViewOverride` (verified dual-enrolment + session key); sub-portal gate honors the override for `short_course_portal.php`; portal branding swapped to the `short_course` definition; long-programme record query skipped under the override so the existing short-course dashboard branch renders; quick-nav gains "Academic Portal" back-link while overridden.
3. `c:\xampp\htdocs\wucportal\students\includes\navbar.php` — forces short-course nav mode (menu branch, section title, dashboard href/label) while the override is active; long-portal menu shows "Short Course Portal" switcher link to dual students; short-course menu shows "Back to Academic Portal" when the student also has a long programme.
4. `c:\xampp\htdocs\wucportal\tests\student_portal_separation\run.php` — structural + HTTP assertions for the switcher (gate holds without override, switch/back round-trip, short-only student never gets an override or back-link).

## [Functions]

No library functions added or changed. Switch logic is page-level in `students/portal_view.php`; reuses `sc_student_has_long_program()`, `sc_student_enrolments()`, `wuc_student_program_portal_definitions()`.

## [Classes]

None.

## [Dependencies]

None added.

## [Verification Log] — IMPLEMENTED & VERIFIED 2026-08-23

- `php -l` clean: `students/portal_view.php`, `students/index.php`, `students/includes/navbar.php`, `tests/student_portal_separation/run.php`.
- Harness `tests/student_portal_separation/run.php` → **RESULT=OK (63 assertions)**, including: `http.dual_gate_holds_without_override` (302 to certificate portal), `http.dual_long_nav_has_switcher`, `http.dual_switch_redirects_short_portal`, `http.dual_short_dashboard_rendered`, `http.dual_short_nav_has_back_link`, `http.dual_switch_back_redirects_index`, `http.dual_gate_restored_after_switch_back`, `http.short_only_no_override_granted`, `http.short_only_portal_200`, `http.short_only_no_back_link`.
- Manual smoke: dual student under override sees "Short Course Dashboard" title/eyebrow, PRN1130 enrolment, short-course quick-nav with "Academic Portal" back-link; long-only student (CVM26567121) sees no switcher link.

---

# Implementation Plan — Correct the Course List on the Student CA Report

## [Overview]

`students/continuousAssessment.php` must list only currently-enrolled courses, scoped by programme structure (term = whole year, semester = 6 months, short course = own duration/report). Period scoping already conforms (`student_ca_period_columns()` resolves valid periods per structure; short courses use the separate `short_course_assessment` report). The defect: the marks-merge safety net re-added every course with published marks even when its `course_registration` row was `status='dropped'` — surfacing COM101 and DCSE-102 (both dropped) for CSE26456789.

## [Files]

1. `c:\xampp\htdocs\wucportal\students\continuousAssessment.php` — the marks merge now excludes courses whose registration status is `dropped`/`withdrawn`/`cancelled`/`inactive` for the selected academic year (schema-safe column picks; year leniency mirrors `appendAcademicYearOwnershipMatch`). The merge remains for courses with marks but no registration row (legacy/EXH safety net).
2. `c:\xampp\htdocs\wucportal\tests\student_portal_separation\run.php` — structural + HTTP assertions on the rendered course list.

## [Functions]

None modified — page-level change only; helper functions untouched.

## [Verification Log] — IMPLEMENTED & VERIFIED 2026-08-23

- `php -l` clean on both files.
- Harness `tests/student_portal_separation/run.php` → **RESULT=OK (68 assertions)**, including: `http.ca_dual_lists_enrolled_only` (DCSE-101, DCSE-103…DCSE-109 — exactly the 8 enrolled courses), `http.ca_cvm_lists_all_registered` (7 CCAM courses unchanged), `http.ca_short_only_report_unchanged` (PRN1130 short-course report), `struct.ca_merge_excludes_dropped`.
- curl smoke: CSE26456789 renders 8 course rows, no COM101/DCSE-102; marks and summary intact.



---

# Implementation Plan — Notification Center Debug & Fix (`notifications.php`)

## [Overview]

Fix the portal notification center (`notifications.php`, root, `/wucportal/`). Two confirmed defects:

1. **Backend — dismiss does not stick + duplicate rows on every visit.** `notifications.php` calls
   `wuc_portal_alerts_sync_sources()` each load, and the shared alert helpers
   (`wuc_portal_alert_upsert_current` / `wuc_portal_alert_create` in `includes/portal_alerts.php`) only
   deduplicated on `status IN ('unread','read')`. A **dismissed** alert was invisible to the dedupe, and the
   upsert unconditionally forced `status = IF(status='dismissed','unread',status)`. Result: dismissed alerts were
   re-surfaced unread AND re-inserted as duplicates on every later load — DB already showed ITC900/ITC907 carrying
   many identical `lecturer_missing_ca_upload` rows (42–51; entity rows 1–6 twice).
2. **Frontend (student layout).** The student branch rendered
   `<main class="notification-shell portal-dashboard content-wrapper">`; `content-wrapper`
   (`margin-left: var(--sidebar-width)` to sit beside the sidebar) conflicted with `.notification-shell`
   (`margin:0 auto; max-width:1180px`), unlike every sibling student page
   (`<main class="dash-content content-wrapper portal-dashboard pt-3">`).

**Decision (user):** a dismissed synced ("current") alert stays dismissed until its content actually changes
(title/message/severity/action), then re-surfaces unread. Duplicate rows are eliminated going forward.

## [Files]

- `c:\xampp\htdocs\wucportal\includes\portal_alerts.php` (modify): dismissed-aware dedupe + content-change
  re-open in `wuc_portal_alert_upsert_current`; include `'dismissed'` in the dedupe window in
  `wuc_portal_alert_create`.
- `c:\xampp\htdocs\wucportal\notifications.php` (modify): student `<main>` → `dash-content content-wrapper
  portal-dashboard pt-3`.
- `c:\xampp\htdocs\wucportal\tests\notification_context\run.php` (modify): add regression assertions.

## [Functions]

- Modified `wuc_portal_alert_upsert_current(mysqli, array): bool` — SELECT now includes `id, status, severity,
  title, message, action_url` over `status IN ('unread','read','dismissed')`; computes `contentChanged`; status
  update sets `nextStatus = existingStatus`, and only flips `dismissed → unread` when `contentChanged`. Binds 11
  `s` + `is`.
- Modified `wuc_portal_alert_create(mysqli, array): bool` — dedupe window now includes `'dismissed'`.

## Testing
- `php -l` on all three files.
- Standalone behavior probe: create→dismiss→unchanged upsert keeps dismissed & single row; changed upsert
  re-surfaces unread & single row — 12/12 PASS.
- Extended `tests/notification_context/run.php` → **12 passed, 0 failed** (incl. 4 new regression assertions).
- HTTP: staff ITC900 200 balanced 131/131; student STU900 (dev impersonation) 200 balanced 65/65, `<main
  class="dash-content content-wrapper portal-dashboard pt-3">`, no PHP errors. Unauth → 302 login.
- Repeated-load de-dupe: ITC900 `lecturer_missing_ca_upload` count stable 10 → 10 across 3 loads.

## [Verification Log] — IMPLEMENTED & VERIFIED 2026-08-26
- `includes/portal_alerts.php`, `notifications.php`, `tests/notification_context/run.php` lint clean.
- Behavior probe (temp user): 12/12 checks pass for dismiss-stays + content-reopen + no-duplicate.
- `tests/notification_context/run.php` → 12 passed, 0 failed.
- Student notifications page: balanced 65/65, correct `dash-content` wrapper, no PHP fatal.
- Staff notifications page: balanced 131/131, no PHP fatal.
- Duplicate-growth check: loading the page no longer adds `lecturer_missing_ca_upload` rows (10→10 over 3 loads).
- Note: pre-existing duplicate/`dismissed` history rows in `portal_alerts` (ITC900 42–51, ITC907 1–6) were left
  untouched (data cleanup is a separate concern and would require separate approval).