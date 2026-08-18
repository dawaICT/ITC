# Implementation Plan — Student Portal Academic Flow Repairs

## [Overview]

Repair five student-portal areas in `c:\xampp\htdocs\wucportal\students`: (1) course assignment/registration, (2) term/semester progression, (3) short courses, (4) diploma/certificate sub-portals, (5) CA uploads / marks availability. All root causes were confirmed by direct DB probes and code reads on 2026-08-15.

**Architecture recap:** `registration.php` → `StudentAcademicWorkflowService::registerStudentForPeriod()` auto-enrols the whole year-of-study catalogue (stamped with the *current period number*). `courseReg.php` is read-only and self-heals via `ensureYearCoursesEnrolled()`. CA flows lecturer-side (`upload_ca.php` → `ca_save_components()` → `semester_assessment` status `Submitted`) and becomes student-visible only at `status='Published'` (`continuousAssessment.php`). Sub-portals (`short_course_portal.php`, `certificate_portal.php`, `diploma_portal.php`, `trade_test_portal.php`) delegate to `index.php` gated by `wuc_student_program_portal_profile()`.

**Confirmed DB evidence:** `course_registration` mixes `status='active'` (seeded, EXH students, no `semester_registration`) with `'registered'` (canonical); `student_program.current_term_number` is stale for self-registered students (CVM26567121 = term 1 despite Term-2 registration); progression inserts `registration_status='pending'` rows for the next year which `findSemesterRegistration()` (no status filter) treats as "already registered"; `fee_structure` covers only AUTO-001/BBA (live programs fail-open); `short_course_assessment` holds an orphan mark (CSE26456789/PRN1130, no enrolment); CA class-list/guard queries are period-strict while auto-enrol stamps the registration period.

## [Types]

No type-system changes (procedural PHP + two service classes edited in place).

## [Files]

**Modify:**
1. `c:\xampp\htdocs\wucportal\students\includes\StudentDataService.php` — `getStudentYearOfStudy()` gains optional academic-year scoping.
2. `c:\xampp\htdocs\wucportal\students\includes\StudentAcademicWorkflowService.php` — pending-registration claim, fee-gate setting, student_program position sync, reactivation period update, `ensureYearCoursesEnrolled()` pending guard, year-scoped YoS resolution.
3. `c:\xampp\htdocs\wucportal\includes\ca_helpers.php` — `ca_course_is_full_year()` helper; period-tolerant matching in `ca_fetch_course_students()` + `ca_student_registered()` for full-year courses; `ca_ensure_normalized_registration_bridge()` targets the requested period for full-year courses.
4. `c:\xampp\htdocs\wucportal\students\short_courses.php` — render CA marks per enrolled short course (dual-enrolled students currently never see them).

**Create:**
5. `c:\xampp\htdocs\wucportal\repairs\repair_student_records_2026.php` — CLI-only, idempotent data repair (missing EXH `semester_registration` rows + link course rows; orphan short-course CA enrolment; seed missing `portal_settings` keys; report-only on BSCS students and missing fee structures).
6. `c:\xampp\htdocs\wucportal\tests\student_portal_flows\run.php` — CLI regression harness (not `test_*.php` at web root — `.htaccess` 403-blocks those).

**No schema changes** (data inserts only). No packages/dependencies added.

## [Functions]

- `StudentDataService::getStudentYearOfStudy(string $studentId, ?string $academicYear = null): int` — when an academic year is supplied: latest registration **for that year** → else `student_program.current_year_number/year_of_study` → else 1. Unscoped legacy behavior preserved when omitted. Fixes wrong-year enrolment after a progression row exists for a future year.
- `StudentAcademicWorkflowService::getActiveAcademicPeriod()` — passes the resolved academic year into `getStudentYearOfStudy()`.
- `StudentAcademicWorkflowService::registerStudentForPeriod()` — a found `semester_registration` row with `registration_status='pending'` (progression placeholder) is *claimed* (status columns updated + courses enrolled) instead of "already registered"; honors `portal_settings.reg_payment_gate_enabled` (default enabled); syncs programme position.
- `StudentAcademicWorkflowService::syncStudentProgramPosition()` (new private) — updates `student_program.year_of_study/current_year_number` and term/semester position columns on the latest matching active assignment.
- `StudentAcademicWorkflowService::isRegistrationFeeGateEnabled()` (new private) — reads `reg_payment_gate_enabled`, defaults to enforced.
- `StudentAcademicWorkflowService::ensureYearCoursesEnrolled()` — skips `pending` rows (progression placeholders must not bypass fee-gated registration).
- `StudentAcademicWorkflowService::reactivateCourseRegistrationForYear()` — also moves `semester` to the current period number so period-scoped consumers (CA lists, fee sums) stay aligned.
- `ca_course_is_full_year(mysqli $db, string $courseCode): bool` (new) — cached lookup: `curriculum_courses.is_full_year=1` else `program_courses.is_full_year=1`.
- `ca_fetch_course_students()` / `ca_student_registered()` — full-year courses match registrations by academic year regardless of stored period; period-specific courses stay period-strict.
- `ca_ensure_normalized_registration_bridge()` — for full-year courses the offering/academic-period join uses the *requested* period instead of the stored registration period.

## [Classes]

`StudentAcademicWorkflowService` and `StudentDataService` edited as above; no signatures broken (new params optional/private).

## [Dependencies]

None added. Verification uses bundled `php.exe` CLI and curl (dev impersonation hook `?dev=1&dev_as=` exists on `students/index.php` under `APP_ENV=development`).

## [Testing]

CLI harness `tests\student_portal_flows\run.php` asserts, against the live dev DB:
1. `getStudentYearOfStudy('CSE26456789','2026')` = 1; with a simulated future-year pending row present, current-year resolution still returns 1 and next-year returns 2 (rolled back after test).
2. `registerStudentForPeriod()` on a synthetic student with a `pending` progression row → claims it (`registration_status='registered'`, courses enrolled), and `student_program.current_term_number` updated.
3. Re-registration in a later period moves `course_registration.semester` to the new period (idempotent on repeat).
4. `ca_fetch_course_students('DCSE-101', '2', '2026')` lists CSE26456789 **and** EXH-ALU-001/EXH-STU-001 (full-year tolerance); a period-specific course (if present) stays period-strict.
5. `ca_student_registered()` agrees for the same matrix.
6. `ca_ensure_normalized_registration_bridge()` resolves a registration for a full-year course at a requested period different from the stored registration period (transaction rolled back).
7. Short-course enrolment guard blocks CA for non-enrolled students; `short_courses.php` marks query returns CSE26456789's PRN1130 row after repair.

HTTP smoke matrix (curl): registration/courseReg/continuousAssessment/short_courses pages render (302/200 as appropriate) for CSE26456789, CVM26567121, EXH-ALU-001, SCONLYTEST01 via dev impersonation; lecturer `ajax_get_course_students.php` returns the full class list for DCSE-101 period 2.

`php -l` on every edited file before and after.

## [Implementation Order]

1. Write this plan; `php -l` baseline on the four edit targets.
2. Phase 1 — registration/progression core: `StudentDataService.php`, `StudentAcademicWorkflowService.php`. Run harness items 1-3.
3. Phase 2 — CA availability: `includes/ca_helpers.php`; verify HOD/registrar publish listing shows term rows; seed settings via repair script. Run harness items 4-6 + lecturer AJAX smoke.
4. Phase 3 — short courses: `students/short_courses.php` CA section; confirm upload guard; repair orphan. Harness item 7 + page smoke.
5. Phase 4 — data repairs: run `repairs\repair_student_records_2026.php`, capture before/after row dumps.
6. Full regression matrix; update this file's verification log.

**Deferred (no user decision received):** fee_structure seeding for ICT-001/ICT-002/AUTO-002 (amounts unknown — fail-open retained, still logged); BSCS student records (4 accounts reference a nonexistent program — left untouched, reported by repair script); auto-redirect from `index.php` to dedicated sub-portals (landing behavior unchanged); trade-test period alignment (no trade-test students exist yet).

---

# Archive — Completed: Test Timetable / Test Schedule 403 Fix (2026-08)

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