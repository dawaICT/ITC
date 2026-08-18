# Database Audit

Audit date: 2026-07-20  
Database: live local `wucportal` MariaDB schema

## Inventory

- 324 tables after recoverable orphan archives and notification-context migration.
- 106 foreign-key relationships reported by the audit.
- More than 1,000 index entries reported across the schema.

Run the repeatable audit with:

```powershell
C:\xampp\php\php.exe scripts\exhibition_schema_audit.php
```

## Verified source-of-truth areas

| Domain | Principal tables |
|---|---|
| Identity/RBAC | `users`, `user_profiles`, `roles`, `user_roles`, `permissions`, `role_permissions`, `portals`, `user_portal_access` |
| Applicants | `online_applicants`, `processed_applicants`, `processed_applicants_added` |
| Students/programmes | `students`, `student_login`, `programs`, `student_program`, `program_courses` |
| Courses/lecturers | `courses`, `course_registration`, `course_lecturer`, `lecturer_course_assignments` |
| Academic records | `attendance_logs`, `semester_assessment`, academic-period tables |
| Finance | `invoices`, `invoice_items`, `payments`, sponsorship tables |
| Notifications/audit | `portal_alerts`, `notifications`, `audit_logs`, AI decision logs |
| AI/skills | `ai_conversations`, `ai_messages`, `ai_usage`, `ai_skill_taxonomy`, `ai_recommendations` |
| Employment/alumni | `employer_profiles`, `employer_internships`, `student_clearance`, `alumni_certificates`, `alumni_employment_tracking` |

## Integrity results after remediation

| Check | Count |
|---|---:|
| Students without login | 0 |
| Login rows without student | 0 |
| Student-programme rows without student | 0 |
| Student-programme rows without programme | 0 |
| Staff credential/position orphans | 0 |
| Duplicate student-programme assignments | 0 |
| Duplicate canonical course registrations | 0 |
| Course-registration orphans | 0 |
| Legacy student-course orphans | 0 |
| Active students without any enrolment | 1 |

The remaining enrolment exception is student `ICT26307691` on programme `ICT-013`. No trustworthy programme-course mapping exists, so the audit preserves it for Registrar reconciliation instead of fabricating enrolment.

## Recoverable cleanup

Migration `migrations/20260720_exhibition_orphan_cleanup.php` archived and then removed from active tables:

- 3 orphan `student_login` rows → `exhibition_orphan_student_login_archive`;
- 10 orphan legacy `student_courses` rows → `exhibition_orphan_student_courses_archive`.

The archive records include timestamp and reason, so the removed active data remains recoverable.

## Schema changes

`migrations/20260720_notification_context.php` adds to `portal_alerts`:

- `source_portal`;
- `target_portal`;
- `target_page`;
- `expires_at`;
- recipient/context/expiry index.

Existing rows are backfilled from their action URL, with academic as the conservative default.

## Constraints and indexing observations

- Canonical course registrations have a uniqueness rule over student, course, study year, and academic year.
- Student-programme assignments are unique per student/programme.
- Invoices are unique per student, academic year, and semester and also have unique invoice numbers.
- Employer placements are owner-scoped by `logged_by_user_id`.
- Some legacy tables lack foreign keys and therefore require explicit transactional teardown.

## Naming/normalisation risks

- `SID`/`Sid`, `Course_Code`/`course_code`, and mixed period labels are entrenched compatibility boundaries.
- Both modern RBAC and legacy position/access tables remain active.
- Multiple historical registration representations remain readable. New writes must use canonical services rather than broadcasting duplicate writes without a documented bridge.

## Database operating rule

Before changing any SQL, verify the table, columns, indexes, and relationships against the live schema. Do not infer columns from similarly named modules or old migration files.
