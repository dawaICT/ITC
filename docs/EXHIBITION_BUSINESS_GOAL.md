# WUCPortal Exhibition Business Goal

## Purpose

WUCPortal demonstrates how one database-backed digital campus can support the learner lifecycle from application through graduation and employment:

**Applicant → Admission → Registration → Learning → Assessment → Skills → Career → Employer Verification → Alumni**

The exhibition is successful when a visitor can follow one coherent story, see that every dashboard reflects live records, and understand the operational value to learners, lecturers, administrators, management, and employers.

## Business outcomes

- Applicants submit documents once, receive a protected tracking account, and see their status.
- Admissions converts an approved application atomically into a student, programme, course, login, and fee setup.
- Students see only their own programme, registration, fees, learning, results, alerts, and evidence-backed skills.
- Lecturers see assigned courses and learners, with no special privileges tied to a hard-coded staff ID.
- Registrar and management see operational data from the same academic records.
- Employers verify approved graduate credentials without accessing protected student records.
- Alumni maintain an employment profile and share a locally generated verification QR.
- AI assists within the logged-in user’s existing permissions and never performs protected academic decisions.

## Exhibition principles

1. MySQL is the source of truth; no dashboard statistic is a scripted exhibition value.
2. Sample people use `EXH-*` identifiers and `@exhibition.test` addresses.
3. Academic records and e-learning content stay separate, joined only by identity, course assignment, and explicit links.
4. Server-side roles, portal grants, record ownership, transactions, audit logs, and safe uploads protect every critical workflow.
5. The demonstration must remain usable on the local XAMPP installation if internet services are unavailable.

## Success measures

- A complete five-to-seven-minute demonstration can be run without editing the database.
- Every demonstration role can authenticate and open its scoped workspace.
- Applicant conversion commits or rolls back as one operation.
- Skills shown to the learner cite course or assessment evidence.
- Notifications retain their owning portal and cannot redirect a user into an unauthorised portal.
- Exhibition data can be reset and reseeded without targeting non-demo identities.
