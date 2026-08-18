# Exhibition Changelog

## 2026-07-20 (gap closure)

### Student dashboard and attendance

- Added `students/attendance.php` for the logged-in student’s own `attendance_logs` summary and recent check-ins.
- Surfaced attendance, published results, and skills/career placement on `students/index.php`.
- Linked Attendance and Skills & Career in student quick navigation and the academic sidebar.
- Added `students/results.php` as a safe redirect alias to `continuousAssessment.php`.

### Exhibition seed / offline assets

- Seeded DCSE-101 eLearning content (`el_course_modules` → `el_contents` → `el_content_versions` → `lesson_notes`) with a local PDF.
- Seeded `EXH-STU-001` profile image (`EXH-STU-001.svg`) and corrected the student alert URL to continuous assessment.
- Vendored Bootstrap 5.3.2 and Font Awesome 6.4.0 under `assets/vendor/` for offline student shell and public certificate verification.
- Removed CDN/`placehold.co` dependencies from `verify_certificate.php` and the student navbar/dashboard Bootstrap/FA links.
- Extended exhibition reset cleanup for sample eLearning material rows.
- Extended exhibition e2e coverage for attendance, materials, results alias, and offline assets.

## 2026-07-20

### Applicant and admissions

- Added secure, transactional public application service with validation, CSRF, safe document storage, and rollback cleanup.
- Added applicant password/account creation and isolated Applicant Portal login.
- Added automatic application tracking by the authenticated account email.
- Added canonical `wuc_accept_online_applicant()` transaction with replay/duplicate protection, officer audit, source movement, conversion, tracking, and rollback.
- Routed admission invoice creation through the canonical invoice helper.
- Rejected retired/progression-only CSE assignment in direct admission workflows.
- Replaced loose admissions session checks with the admissions guard.

### Authentication, RBAC, and safety

- Removed a developer-specific audit debug file write.
- Added Exhibition Mode configuration and destructive-target guard.
- Applied the guard to primary Admin, Registrar, Admissions, and lecturer deletion/deactivation paths.
- Added exact role/portal seed provisioning for Applicant, Student, Lecturer, Admissions, Registrar, Administrator, Employer, and Alumni.

### Data integrity

- Added repeatable live schema/integrity audit.
- Archived then removed 3 orphan student logins and 10 orphan legacy course rows.
- Documented the one unresolved active-student enrolment exception.
- Added idempotent notification-context migration.

### Notifications

- Added source portal, target portal, target page, and expiry to `portal_alerts`.
- Backfilled existing alerts from action URLs.
- Added safe internal target validation, expiry filtering, recipient ownership, and portal-grant enforcement before redirect.
- Added notification context regression tests.

### Exhibition data and verification

- Added idempotent seed and confirmed reset scripts.
- Seeded programme/course registration, invoice/items/payment, attendance, published results, lecturer assignments, alerts, employer placement, graduation clearance, certificate, employment profile, and pending application.
- Added HTTP test coverage for all eight personas and their workspaces.
- Replaced remote QR generation with bundled TCPDF QR encoding.
- Added alumni employer-verification QR and public approved-certificate verification.
- Parameterised the certificate verification counter update and removed an unsupported cryptographic claim.

### User interface

- Converted the online-services navigation from a nested full HTML document into a responsive shared partial.
- Standardised the public application page around Bootstrap 5, Inter, and the portal purple theme.
- Added visible application reference, applicant username, and status-login call to action.

### Tests

- Added transactional applicant-conversion suite.
- Added full public application → applicant login → admissions UI → student login test.
- Updated registration and lecturer upload end-to-end tests to use live programme/course mappings and deterministic cleanup.
- Added Exhibition Mode role/data/skills/QR smoke suite.
- Baseline repository test suites were run successfully before the changes; the complete suite is rerun as the final release gate.
