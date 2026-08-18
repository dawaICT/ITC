# Exhibition Implementation Plan

## Delivered foundation

| Workstream | Status | Evidence |
|---|---|---|
| Architecture and live-schema audit | Complete | `scripts/exhibition_schema_audit.php`, `docs/ARCHITECTURE_AUDIT.md`, `docs/DATABASE_AUDIT.md` |
| Applicant registration and protected applicant identity | Complete | `includes/online_application.php`, `applicant_login.php` |
| Transactional applicant conversion | Complete | `includes/applicant_workflow.php`, `tests/applicant_conversion/run.php` |
| Registration, course assignment, invoice setup | Complete for the canonical admission path | Registration handlers and integration tests |
| Staff/student RBAC and portal grants | Complete for demonstrated roles | Provisioning helpers, guards, role matrix, HTTP role tests |
| Lecturer assignment scope | Complete for demonstrated workflows | Lecturer upload test and seeded assignments |
| Context-aware notifications | Complete | Notification migration and 8-assertion regression suite |
| Evidence-derived skills | Complete for course/assessment evidence | `StudentSkillDiscoveryService`, exhibition results, HTTP skill check |
| Employer/alumni verification | Complete | Approved clearance, certificate registry, local QR, public verification test |
| Exhibition seed/reset | Complete | `scripts/seed_exhibition_mode.php`, `scripts/reset_exhibition_mode.php` |
| Student dashboard (attendance/results/skills) | Complete for exhibition demo path | `students/attendance.php`, dashboard widgets, skills/career links |
| eLearning demo content | Complete for exhibition demo path | Seeded DCSE-101 material via canonical eLearning tables |
| Offline student/certificate assets | Complete for exhibition demo path | `assets/vendor/bootstrap/5.3.2`, `assets/vendor/fontawesome/6.4.0` |
| Required documentation | Complete | Files in `docs/` |

## Prioritised findings and treatment

### P0 — resolved

- Applicant acceptance copied records without guaranteeing complete student conversion. Replaced with one canonical transaction and replay protection.
- Public application uploads and form processing lacked a canonical secure service. Added MIME/extension/size checks, generated filenames, validation, CSRF, transaction rollback, and applicant account creation.
- Invoice creation used incomplete legacy columns. Routed admission invoices through the canonical invoice helper.
- Loose admissions authentication treated any staff session as an administrator. Replaced with the admissions guard.
- Audit code wrote session diagnostics to a developer-specific file. Removed.

### P1 — resolved

- Applicant tracking had no real login identity. Added an isolated applicant login and portal grant.
- Orphan login and legacy course rows polluted account integrity. Archived them before removal from active tables.
- Notifications lacked portal context and expiry. Added source/target/page/expiry fields and permission validation.
- Exhibition QR rendering depended on an internet QR service. Replaced it with bundled TCPDF QR generation.
- Public application navigation emitted a second closed HTML document. Converted it to a reusable responsive partial.
- Student dashboard claimed attendance/results/skills without UI. Added attendance page, dashboard widgets, and skills/career links.
- Seeded student alert pointed at missing `results.php`. Added alias redirect and retargeted seed URL.
- Exhibition eLearning grant had no sample material. Seeded DCSE-101 module/content/version/lesson note with local PDF.
- Certificate verification and student shell depended on CDN assets. Vendored Bootstrap/FA locally for offline demos.

### P2 — retained and documented

- Several legacy modules still duplicate page/controller patterns. They remain where replacement would risk unrelated workflows; new work routes through shared helpers.
- One active record (`ICT26307691`, programme `ICT-013`) has no active enrolment. It is preserved for Registrar reconciliation because no valid course mapping can be inferred safely.
- The portal contains historic naming (`WUC`, `ITC`, `Sid`, `SID`, `Course_Code`) that should be normalised only through a separately tested migration programme.
- Recorded-video fallback is an operational deliverable, not a code artefact. Use the demo script to record it before the event.

## Operating commands

```powershell
C:\xampp\php\php.exe scripts\exhibition_schema_audit.php
C:\xampp\php\php.exe scripts\seed_exhibition_mode.php --apply
C:\xampp\php\php.exe scripts\e2e_exhibition_mode_test.php
```

Reset requires an explicit confirmation phrase:

```powershell
C:\xampp\php\php.exe scripts\reset_exhibition_mode.php --apply --confirm=RESET-EXHIBITION
```

Then rerun the seed command.

## Pre-event operations

1. Start Apache and MySQL in XAMPP.
2. Run the schema audit and verify all integrity counters except the documented enrolment exception are zero.
3. Reset and reseed Exhibition Mode.
4. Run the complete test checklist.
5. Open each login once in the target browser and confirm local assets are cached.
6. Record the flow in `EXHIBITION_DEMO_SCRIPT.md` as the offline presentation fallback.
