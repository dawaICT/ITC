# Architecture Audit

## Runtime and structure

- PHP 8.2-style procedural/controllers with shared service/helper classes.
- MariaDB/MySQL through `mysqli`; XAMPP is the supported local runtime.
- Bootstrap 5, Font Awesome, Inter, and shared portal CSS, with legacy W3/Bootstrap remnants in older pages.
- Separate top-level modules for Admin, Admissions, Registrar, Accounts, Lecturers, Students, eLearning, Employer, Alumni, Library, and Transport.

## Authentication and sessions

| Identity | Entry point | Canonical records | Session identity |
|---|---|---|---|
| Student/alumni | `student_login.php` → `studentLogin.php` | `students`, `student_login`, `users` | `Sid`, `user_id_db`, role `student` |
| Staff/employer | `staff_login.php` → `staffLogin.php` | `staff`, `users`, `user_roles` | `staff_id`, `user_id_db`, canonical role |
| Applicant | `applicant_login.php` | `users`, `user_profiles`, applicant record | `username`, `user_id_db`, role `applicant` |

Sessions use the shared secure-start/security-header helpers, CSRF tokens, regeneration after login, inactivity checks, failed-login throttling, and password hashes. Legacy hashes are accepted only to self-heal into a current hash.

## Authorisation

Authorisation has three layers:

1. Canonical role normalisation and `user_roles`/`role_permissions`.
2. Portal grants in `user_portal_access` for academic, eLearning, applicant, alumni, employer, and library.
3. Module guards and record ownership, such as student `Sid`, lecturer course assignment, employer `logged_by_user_id`, and approved alumni clearance.

The applicant, employer, and alumni portals are direct, isolated workspaces. Registered students have the applicant grant revoked. Academic/eLearning access is filtered through account lifecycle and current assignment.

## Canonical learner workflow

```text
public form
  → online_applicants + applicant users/profile/grant
  → admissions decision
  → processed_applicants
  → student + login + programme + course registrations + invoice
  → portal access + tracker + audit log
```

Acceptance uses `wuc_accept_online_applicant()` and `admitProcessedApplicant()` inside one transaction/savepoint boundary. A failure rolls back the source move and every downstream record.

## Academic/eLearning boundary

Academic owns identity, programmes, course enrolment, periods, attendance, official assessments, moderation, publication, results, fees, and progression. eLearning owns materials, lessons, assignments, quizzes, discussions, sessions, and learning activity. Bridges are limited to:

- shared user/course identity;
- course access derived from academic enrolment or lecturer assignment;
- eLearning tasks/deadlines displayed in student context;
- published learning/assessment notifications pointing to their owning portal;
- permission-aware AI context reading approved extracts from each module.

No eLearning table is used as the official result or fee ledger.

## Notifications

`portal_alerts` is the consolidated in-app notification source. Integration helpers mirror legacy notification sources into it. Each current alert records recipient, role, source portal, target portal/page, type, message, state, creation date, and optional expiry. Opening an alert checks ownership, safe internal URL, expiry, and the viewer’s live portal grant.

## AI support layer

The AI bootstrap authenticates the viewer, checks CSRF/rate limits, resolves current portal/module/role, builds bounded authorised context, logs conversations/usage, supplies source references, filters unsafe operations, and escalates protected decisions to human officers. The AI does not receive a mutation capability for marks, admissions, registration, fees, or discipline.

## Duplication and legacy risk

- Historic student-course representations (`course_registration`, `student_courses`, newer registration structures) coexist. Canonical workflows favour `course_registration` plus programme mappings; legacy readers remain for compatibility.
- Staff access has both modern RBAC and legacy `positions`/`access_right`. Provisioning synchronises both until retirement can be separately migrated.
- Several admin/admissions pages duplicate student-list controllers. Critical mutations now share guards/helpers where practical, but full UI consolidation is a future refactor.
- Identifier case/naming differs (`SID`, `Sid`, `Course_Code`) and should not be renamed without a versioned compatibility migration.

## Security findings corrected

- Removed environment-specific audit debug output.
- Replaced loose staff-session admissions access with a role guard.
- Added applicant CSRF, upload MIME/extension/size validation, generated filenames, and transactional cleanup.
- Added server-side Exhibition Mode protection to primary student/staff delete paths.
- Replaced an external QR dependency with local generation.
- Parameterised the certificate verification counter update.
- Added notification target validation and portal-grant enforcement.

## Remaining architectural risks

- Legacy pages still contain local inline styling and mixed framework versions.
- Some older mutation pages need continued migration to the same guard/service conventions.
- External AI quality/availability depends on the configured provider; deterministic fallback remains essential.
- Production deployment still needs HTTPS termination, secret rotation, backups, monitoring, and an environment-specific CSP review.
