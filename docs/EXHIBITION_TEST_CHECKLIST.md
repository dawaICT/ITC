# Exhibition Test Checklist

## Automated preflight

Run from `C:\xampp\htdocs\wucportal`:

```powershell
C:\xampp\php\php.exe scripts\exhibition_schema_audit.php
C:\xampp\php\php.exe scripts\seed_exhibition_mode.php --apply
C:\xampp\php\php.exe scripts\e2e_exhibition_mode_test.php
C:\xampp\php\php.exe scripts\e2e_applicant_conversion_test.php
C:\xampp\php\php.exe scripts\e2e_registration_login_test.php
C:\xampp\php\php.exe scripts\e2e_lecturer_upload_test.php
C:\xampp\php\php.exe scripts\e2e_short_course_login_test.php
C:\xampp\php\php.exe tests\notification_context\run.php
```

Expected current results:

- Exhibition personas/data/eLearning/AI/skills/career/analytics/QR/attendance/materials/offline assets: 45 assertions, all pass.
- Public application through student login: 14 assertions, all pass.
- Registration/login: 21 assertions, all pass.
- Lecturer assignment/upload: 10 assertions, all pass.
- Short-course login: 18 assertions, all pass.
- Notification context/ownership/expiry: 8 assertions, all pass.

Then run every `tests/*/run.php` suite. No suite may return a non-zero exit code.

## Manual UI checklist

### Public and applicant

- [ ] Public application page has one valid responsive document/nav.
- [ ] Required fields and three required documents display useful validation.
- [ ] Unsupported extension/MIME and missing CSRF are rejected.
- [ ] Successful submission displays `APP-<id>`, account username, and login link.
- [ ] Applicant login auto-loads only the linked application.

### Admissions and registration

- [ ] Admissions role can open the applicant queue; unrelated staff cannot.
- [ ] Accepting a fresh application returns the generated student ID.
- [ ] Source, processed record, student, login, programme, courses, invoice, tracker, and audit record agree.
- [ ] Replay does not create a second student.
- [ ] A forced failure restores the source application and leaves no partial student.
- [ ] Registered student no longer sees Applicant Portal.

### Student

- [ ] Dashboard works at desktop and 390px mobile width.
- [ ] Programme, registration, fees, courses, attendance, results, alerts, and empty states use live data.
- [ ] Student cannot change a URL/request to view another `Sid`.
- [ ] Academic/eLearning portal switch respects grants.
- [ ] Skill Discovery shows evidence and career relevance.
- [ ] AI returns scoped help, citations/context, and refuses protected mutations.

### Lecturer

- [ ] New exhibition lecturer sees the same role features as existing lecturers.
- [ ] Only `DCSE-101` and `DCSE-103` appear for `EXH-LEC-001`.
- [ ] Unassigned course/student requests are denied.
- [ ] Valid PDF material upload succeeds; missing CSRF, invalid extension, and MIME spoof fail.
- [ ] Assessment tools enforce assignment and official workflow status.

### Notifications

- [ ] Recipient, source portal, target portal/page, type, state, creation, and expiry are stored.
- [ ] Expired alerts are hidden.
- [ ] External/JavaScript targets are not stored.
- [ ] Another user cannot read or update an alert.
- [ ] A target opens only when the user has that portal grant.

### Employer/alumni/management

- [ ] Employer sees only its own placement records.
- [ ] Graduate search returns only Approved/Graduated records.
- [ ] Alumni sees linked student, clearance, employment, and certificate.
- [ ] Certificate QR renders without internet access.
- [ ] `EXH-CERT-2025-001` verifies publicly and exposes no private grades/contact data.
- [ ] Management analytics reflect seeded database values and contain useful empty states.

### Exhibition safety

- [ ] All sample IDs are `EXH-*`; emails end `@exhibition.test`.
- [ ] Primary destructive student/staff handlers reject non-demo IDs while mode is enabled.
- [ ] Reset refuses to run without both confirmation arguments.
- [ ] Reset followed by seed restores the same reusable environment.
- [ ] No PHP warnings/notices/fatal errors appear in page output.
- [ ] Recorded 1080p demo fallback is present on two offline storage locations.

## Known manual review item

Student `ICT26307691` on programme `ICT-013` has no trustworthy enrolment mapping. Confirm Registrar treatment before production; do not “fix” it by guessing a course set.
