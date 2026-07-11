# WUC / ITC Portal — Student Admission Process

_Last updated: 2026-06-17. Covers both the **admin** module (`/admin`) and the
**admissions** module (`/admissions`)._

There are **three** ways a person becomes an enrolled, login-capable student.
All three now converge on the same shared backend helpers so they produce an
identical, complete result (student record + programme enrolment + login + fees).

---

## Scenario A — Brand-new student (walk-in / manual capture)

Used when an officer types in a student who never applied online.

- **Entry pages:** `admissions/regNewStud.php` and `admin/regNewStud.php`
  (both render the *same* shared UI — `admissions/includes/registration_*`).
  `admin/add_student.php` and `admin/register_student.php` are thin redirects to
  `regNewStud.php`.
- **Backend:** `handleNewStudentRegistration()` in
  `admissions/includes/registration_handlers.php`.

**Steps**
1. Officer opens **Register New Student**.
2. Fills personal details, programme, intake, mode; uploads documents.
3. On submit the handler, in one transaction:
   - generates the **student number** (`ITC<YY>T<period><NRC4>` — see
     `includes/student_id_generator.php`);
   - inserts the `students` row (`status = 'active'`);
   - inserts the `student_program` enrolment (status, academic_year, term, dates);
   - creates the `student_login` row (initial password = NRC, must change on
     first sign-in);
   - assigns programme courses and raises an invoice (with bursary if TEVETA/CDF).

---

## Scenario B — Online applicant → student

Used for people who applied through the **public ITC website** (`itc-website/apply.php`)
or the portal's own form (`online_services/index.php`). Both write to the shared
`online_applicants` table and store uploads in `wucportal/online_services/uploads/`.

**Steps**
1. **Review applications** — `admissions/applicants.php` lists `online_applicants`
   where `status = 'pending'`.
2. Officer clicks **Accept** (or Reject). Handler:
   `admissions/includes/applicant_handlers.php::handleProcessApplication()` moves
   the row into `processed_applicants` (`status = 'accepted'`, with
   `processed_by` / `processed_date` audit) and updates the original row.
3. **Convert to student** — `admissions/processedApp.php` (or `admin/processedApp.php`)
   lists accepted applicants. There are two ways to admit:
   - **One-click "Add as Student"** → `admitProcessedApplicant()` in
     `includes/applicant_admission.php` — full transaction (record + enrolment +
     login + courses + invoice). **Idempotent**: blocks a second admit when the
     NRC/email/mobile already matches an existing student.
   - **Manual form** → `admin/addonlineStudent.php` (prefilled from the processed
     applicant). Builds the student number from the **real NRC** on submit and
     **aborts with a clear message** if a valid number can't be generated (it no
     longer fabricates a non-ITC fallback number).

The academic **period digit** in the student number comes from the applicant's
intake via `admissionsPeriodFromIntake()` (term vs semester aware).

---

## Scenario C — Existing student → assign a programme

Used when a `students` row already exists (e.g. captured without a programme) and
needs to be enrolled into a programme so they can register and be invoiced.

- **Entry pages:**
  - `admin/admitEnrolled_student.php` / `admissions/admitEnrolled_student.php` —
    search by SID, then a verification modal with the admission form.
  - `admin/admitStudent.php` / `admissions/admitStudent.php` — admit a specific
    `?sid=` (also serves the bulk modal).
- **Backends (all now route to one helper):**
  `admin/processAdmit_student.php`, `admissions/processAdmit_student.php`,
  `admin/admitStudent.php`, `admissions/admitStudent.php`.
- **Canonical helper:** `admissionsEnrollExistingStudent()` in
  `includes/applicant_admission.php`.

**Steps**
1. Officer searches for the student by **Student ID**.
2. Verifies the details in the modal, picks **programme**, **intake**, **mode**,
   **start year** (the completion year is derived from programme duration).
3. On submit the helper, in one transaction:
   - validates the SID exists and is **not already enrolled in that programme**
     (idempotent per programme);
   - inserts a fully-populated `student_program` row (`status = 'active'`,
     `academic_year`, `term`, `term_start_date`, `term_end_date`, integer
     `startYear`/`endYear`);
   - activates the `students` row (`status = 'active'`, `enrollment_date`);
   - guarantees a `student_login` row;
   - assigns courses and raises an invoice **when the programme has fees**.

---

## After admission — registration

Once admitted (any scenario) the student is registered per academic period via
`admin/semester_registration.php` (supports both **semester** and **term**
programmes, driven by `programs.period_mode`), writing to `semester_registration`.

---

## Key tables

| Table | Role |
|-------|------|
| `online_applicants` | Raw online applications (`status = 'pending'`) |
| `processed_applicants` | Accepted/rejected applications (audit: `processed_by`, `processed_date`, `original_id`) |
| `students` | Master student record (`status = 'active'` once admitted) |
| `student_program` | Programme enrolment (status, academic_year, term, dates) |
| `student_login` | Portal credentials (initial password = NRC) |
| `invoices` | Fees raised at admission |
| `semester_registration` | Per-period registration after admission |

## Inconsistencies fixed (2026-06-17)

- The admit-existing-student paths each hand-wrote a `student_program` INSERT and
  had drifted: some omitted `status` (NULL rows), `admissions/admitStudent.php`
  never created a `student_login` (students locked out), all fed `type="month"`
  values into the `smallint` `startYear`/`endYear` columns, and none set
  `academic_year`/`term`/term dates. They now all call
  `admissionsEnrollExistingStudent()`.
- Admit forms (`admin/admitStudent.php`, `admin/admitEnrolled_student.php`)
  restyled to match `admin/semester_registration.php`, with year **selects**
  instead of month inputs and the end year derived from programme duration.
