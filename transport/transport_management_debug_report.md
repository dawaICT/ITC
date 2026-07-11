# Transport Management Debug Report

URL reviewed: `http://localhost:8080/wucportal/transport/transport_management.php`

Date: 2026-06-15

## Scope

Reviewed the legacy PHP transport management controller/view, its transport guard, local migration schema, and Apache-rendered page output. The page is shared by the overview and section wrappers such as `trainees.php`, `sessions.php`, `fleet.php`, and `clients.php`.

## Findings And Fixes

### 1. Cohort contact-hour report could overstate delivered hours

The cohort report joined `transport_enrollments` and `transport_sessions` in the same grouped query. When a cohort has multiple enrollments and multiple sessions, each session row is repeated once per enrollment, inflating delivered contact hours.

Fix: replaced the joined aggregate with two pre-aggregated subqueries:

- enrollment count grouped by `cohort_id`
- delivered contact hours grouped by `cohort_id`

Evidence: a transaction-only test with 2 enrollments and 2 sessions showed the old join returning `6.00` hours for `3.00` actual hours. The fixed query returned `3.00`.

### 2. Aggregate reports were fragile under stricter MySQL modes

The old cohort and corporate client queries selected `c.*` / `cc.*` while grouping only by ID. This works on the current XAMPP SQL mode, but can fail under `ONLY_FULL_GROUP_BY`.

Fix: moved report aggregates into derived tables and removed the outer `GROUP BY`, making the reports compatible with stricter SQL modes.

### 3. Failed trainee enrollment status existed in the schema but could not be preserved

`transport_enrollments.status` includes `failed`, but the update path allowed only `enrolled`, `active`, `completed`, and `withdrawn`. Updating a failed enrollment would silently reset it to `enrolled`.

Fix: added `failed` to the update validation and trainee table status dropdown.

### 4. Instructor removal ignored incident history

Instructor deletion checked session and pre-use check history, but not incident reports. Incident records are operational history and should keep the instructor profile from being hard-deleted.

Fix: included `transport_incident_reports.instructor_id` in the instructor history check. Instructors with incident history are now deactivated instead of deleted.

## Verification

- `E:\xampp\php\php.exe -l transport\transport_management.php`
  - Result: no syntax errors.
- CLI render harness with systems-admin session:
  - Result: overview rendered, no install warning, no fatal output.
- Apache URL check with temporary authenticated session:
  - `transport_management.php`: `200 OK`, full Transport Operations HTML, no install warning, no fatal output, cohort report present.
  - `trainees.php`: full Trainee Enrolment HTML, no fatal output, trainee report present.
- Database schema check:
  - All required `transport_%` tables from `migrations/20260602_transport_management_system.sql` exist.
- SQL aggregate proof:
  - Old cohort aggregate: `old_joined_hours = 6.00`, `actual_hours = 3.00`.
  - Fixed cohort aggregate: `fixed_hours = 3.00`.

## Residual Notes

The local database currently has transport seed/reference data but little operational data: 2 campuses, 13 programs, 1 instructor, and no live cohorts, enrollments, sessions, or vehicles at review time. The aggregate bug was therefore proven with rollback-only temporary data rather than production rows.

## Architecture And AI Improvement

Date: 2026-06-19

### Architecture assessment

- `transport_management.php` remains the compatibility controller/view for the section wrappers, but its operational-intelligence logic is now separated from the monolith.
- `transport/services/TransportIntelligence.php` owns read-only risk aggregation, scoring, prioritisation, local-AI status, briefing generation, and response validation.
- `transport/api/intelligence.php` is a narrow authenticated JSON boundary. It accepts POST only, validates the Transport CSRF token, disables caching, and returns no personal trainee or staff data.
- The dashboard consumes a deterministic snapshot during normal rendering. Local AI is an optional presentation layer and is never required for the safety priorities to work.

### AI defect found and fixed

The local Ollama-compatible mock advertised a chat model but treated every chat request as a lecturer question-bank request. A Transport prompt therefore returned unrelated course questions while still reporting success.

Fixes:

- Added Transport aggregate-context handling to `ai/mock_server.php`.
- Added output grounding checks that reject empty, oversized, question-bank, or domain-mismatched responses.
- Added a deterministic briefing fallback when Ollama is down, a model is missing, a request times out, or output validation fails.
- Restricted the model input to aggregate counts, score, and priority titles. No names, phone numbers, student IDs, staff IDs, or free-form incident descriptions are sent.

### Risk model

The score starts at 100 and applies bounded penalties for open incidents, recent unfit inspections, expired or expiring documents, unavailable fleet, overdue maintenance, and parts below reorder level. The dashboard links each resulting priority to the relevant operational workspace. The score is an operational triage index, not a statistical probability or a substitute for staff safety decisions.

### Verification

- All 18 Transport tables were checked against the live `wucportal` schema.
- PHP lint passed for the service, API endpoint, dashboard, and mock server.
- A systems-admin render harness produced the full dashboard and confirmed the intelligence card, score, priority, AI control, and status markup.
- The authenticated endpoint returned valid JSON with a `100/100` empty-data baseline and a grounded three-sentence Transport briefing.

---

## Follow-up Deep Debug

Date: 2026-06-18

Scope: reviewed trainee enrollment, instructor/staff linkage, staff/admin navigation integration, transport reports, and the `transport_management.php` frontend forms.

### Findings And Fixes

1. Trainee enrollment could create duplicate trainee profiles for the same student.
   - Fix: new enrollments now reuse the existing `transport_trainees` profile for a student and update its current contact/compliance snapshot before adding the cohort enrollment.

2. The same student could be enrolled into the same cohort through duplicate trainee profiles.
   - Fix: enrollment now checks `transport_enrollments` joined to `transport_trainees.student_id` before insert and blocks duplicate student/cohort combinations.

3. Tampered trainee update forms could submit mismatched `trainee_id` and `enrollment_id`.
   - Fix: update now verifies the enrollment belongs to the submitted trainee, blocks identity changes from the inline editor, and prevents duplicate same-cohort student enrollments during updates.

4. Instructor profiles could be duplicated for the same staff member, including inactive/deleted staff.
   - Fix: add/update instructor paths now require an active staff record and reject duplicate staff-to-instructor profiles. The staff dropdown also filters out inactive, deleted, disabled, suspended, and terminated staff records.

### Integration Notes

- Staff dashboard links to Transport Operations through `canAccessTransport()` when available.
- Unified navigation supports a `transport` required-access key backed by `canAccessTransport()`.
- Transport access remains Head-of-Section/system-admin controlled; being listed as a transport instructor does not grant management access.
- `admin/transport_management.php` remains a compatibility redirect to the transport section routes.

### Verification

- `E:\xampp\php\php.exe -l transport\transport_management.php`
  - Result: no syntax errors.
- `E:\xampp\php\php.exe -l transport\reports.php`
  - Result: no syntax errors.
- `E:\xampp\php\php.exe -l transport\includes\transport.php`
  - Result: no syntax errors.
- `E:\xampp\php\php.exe -l admin\transport_management.php`
  - Result: no syntax errors.
- `E:\xampp\php\php.exe -l staff\dashboard.php`
  - Result: no syntax errors.
- CLI render harness with systems-admin session:
  - Result: `transport_management.php` rendered 44,215 bytes, no install warning, no fatal text.
