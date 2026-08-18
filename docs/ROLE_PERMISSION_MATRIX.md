# Role and Permission Matrix

Legend: **M** manage, **V** view own/scoped data, **—** denied by server-side guard.

| Capability | Applicant | Student | Lecturer | Admissions | Registrar | Admin | Employer | Alumni |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Submit/track application | M | — after registration | — | V/M decisions | V | V/M | — | — |
| Convert applicant to student | — | — | — | M | M | M | — | — |
| Student profile/programme | — | V own | V assigned class | V admissions scope | M | M | authorised verification only | V own |
| Course registration | — | V/M own eligible periods | V assigned | — | M | M | — | historic own |
| Official assessments/results | — | V published own | M assigned courses | — | M/moderate | M | — | V published own |
| eLearning content | — | V enrolled courses | M assigned courses | — | oversight | oversight | — | only if separately granted |
| Attendance | — | V own summary | M assigned courses | — | M/oversight | M | — | historic own |
| Fees/payments | — | V own | — | setup view where required | oversight | M | — | historic own |
| Skills/career guidance | — | V own evidence | V assigned learner context | — | oversight | aggregate | authorised evidence only | V own |
| Employer placements | — | V relevant | — | — | oversight | M | M own records | V own profile |
| Graduate certificate | — | — until approved | — | — | M/approve | M | public approved verification | V/share own |
| Users/roles/configuration | — | — | — | — | limited academic | M | — | — |
| Management analytics | — | — | assigned scope | pipeline scope | academic scope | institution | own placements | own data |
| AI assistant | application context | own authorised data | assigned courses | admissions read/support | academic read/support | authorised admin extracts | authorised public/owned data | own authorised data |

## Portal grants

| Role | Default portal grants |
|---|---|
| Applicant | Applicant only |
| Student | Academic; eLearning when registered work exists |
| Lecturer | Academic + eLearning |
| Admissions Officer | Academic |
| Registrar | Academic + eLearning oversight |
| Systems Administrator | Academic + eLearning; other portals require explicit grant or legacy admin override |
| Employer | Employer only |
| Alumni | Alumni plus retained student portals only when explicitly granted |

## Record-level rules

- Student queries bind the session `Sid`; request parameters cannot select another student.
- Lecturer academic/eLearning access is derived from `course_lecturer` or `lecturer_course_assignments`.
- Admissions mutations use the admissions guard and record the officer.
- Employer placement lists bind `logged_by_user_id`; public graduate search returns only Approved/Graduated records.
- Alumni uses the linked student identity and cannot substitute a staff ID.
- Notification state transitions require recipient ID and role scope; opening a target also checks portal access.
- AI context builders reuse these identity, role, portal, and assignment boundaries.

## Exhibition identities

Exhibition records are clearly prefixed `EXH-` or use `@exhibition.test`. When Exhibition Mode is enabled, protected deletion handlers reject non-demo targets.
