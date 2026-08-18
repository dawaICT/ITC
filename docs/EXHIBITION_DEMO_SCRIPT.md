# Five-to-Seven-Minute Exhibition Demo

## Demonstration accounts

All accounts use the shared password **`Exhibition@2026`** and contain fictional data.

| Persona | Username | Login |
|---|---|---|
| Applicant | `exh-applicant@exhibition.test` | `/wucportal/applicant_login.php` |
| Student | `EXH-STU-001` | `/wucportal/student_login.php` |
| Lecturer | `EXH-LEC-001` | `/wucportal/staff_login.php` |
| Admissions Officer | `EXH-ADM-001` | `/wucportal/staff_login.php` |
| Registrar | `EXH-REG-001` | `/wucportal/staff_login.php` |
| Administrator | `EXH-ADMIN-001` | `/wucportal/staff_login.php` |
| Employer | `EXH-EMP-001` | `/wucportal/staff_login.php` |
| Alumni | `EXH-ALU-001` | `/wucportal/student_login.php` |

## Presenter flow

### 0:00–0:50 — Applicant

1. Open the public application page and point out programme selection, required documents, and account password.
2. Sign in as the Applicant and show the pending ICT-001 application loaded automatically.
3. Explain that the applicant identity is separate from staff and student identities.

### 0:50–1:50 — Admissions and conversion

1. Sign in as Admissions Officer.
2. Open Applicants, review the pending exhibition application, and explain the document/status controls.
3. For the live conversion segment, submit a fresh fictional application first, then accept it. The system returns the generated student number.
4. State that application movement, student creation, login, programme, courses, fee setup, tracking, and audit logging share one transaction.

### 1:50–3:10 — Student and e-learning

1. Sign in as `EXH-STU-001` and choose Academic Portal.
2. Show programme, active registration, eight mapped courses, the ZMW 8,500 invoice, ZMW 5,000 paid, ZMW 3,500 balance, attendance summary, published results, skills/career quick link, and notification.
3. Switch to eLearning and open the seeded DCSE-101 material (“Exhibition: Network Fundamentals Reading”).
4. Open the AI Tutor and ask: “Explain the difference between a network switch and a router using my current course context.”
5. Point out the source/context and that the assistant cannot change marks, fees, admissions, or registration.

### 3:10–4:15 — Lecturer and updated academics

1. Sign in as `EXH-LEC-001`.
2. Show only assigned courses `DCSE-101` and `DCSE-103`.
3. Open the class list and assessment/material tools. Explain CSRF, assignment scope, MIME validation, and audit logging.
4. Return to the student account to show the same published assessment data from the academic source of truth.

### 4:15–5:10 — Skills and career evidence

1. Open Student → Skill Discovery.
2. Show skill cards derived from registered Computer Systems courses and published marks.
3. Expand evidence, proficiency, next step, and career relevance.
4. Show the ZedTech placement record as the employer/career bridge. Do not describe any inferred skill as a formal certification.

### 5:10–6:00 — Employer and alumni

1. Sign in as Employer and show the employer-scoped placement plus approved graduate verification search.
2. Sign in as Alumni and show graduated clearance, employment profile, and certificate `EXH-CERT-2025-001`.
3. Scan or click the locally generated QR. The public verification page should show an approved registry match without exposing private academic records.

### 6:00–7:00 — Management value

1. Sign in as Administrator and open Analytics Dashboard.
2. Highlight applicant pipeline, enrolment, fee collection, learner risk, outcomes, and lecturer workload.
3. Close with the lifecycle: one source of truth, role-scoped workspaces, auditable decisions, and evidence-backed outcomes.

## Live conversion note

The fixed seed deliberately keeps `exh-applicant@exhibition.test` pending so it is reusable after every reset. For a live approval, submit a second fictional application with a unique email/NRC and strong password. Never reuse a real visitor’s personal information.

## Recorded fallback

Before the event, record this exact flow at 1080p while XAMPP is running locally. Store the recording on the presentation laptop and a second offline drive. The recording is the fallback for projector, browser, or network failure; the application itself does not require internet for core workflows or QR generation.
