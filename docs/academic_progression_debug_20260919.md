# Academic progression repair — 19 September 2026

Annual progression previously set `current_term_number` even for semester programmes, while the registration triggers overwrote period type from legacy `period_mode`. Both now use `programs.structure_type` as the authority. Term/semester registration clears the other period counter and synchronizes the enrolment academic year. Registration locks the enrolment and rejects stale academic years or mismatched years of study; a failed position update rolls back registration.

The period ledger stores `academic_year` as `varchar(4)`. Progression normalizes a single year or consecutive year range to the next starting calendar year (`2026/2027` → `2027`). Invalid or missing years are rejected. CSE staged progression uses the same enrolment year and calculation as the audited annual progression service instead of choosing an unrelated latest registration.

Existing target-period registrations retain their status and fee clearance. Conflicting year-of-study records are rejected. Only outgoing year-of-study courses are retired; target-year courses remain active. The obsolete post-rollback delete was removed because the verified registration table is InnoDB and rollback already restores it.

## Installation

On MariaDB/XAMPP, run with an account allowed to create triggers:

```powershell
php migrations/20260919_fix_registration_period_triggers.php
```

This idempotent migration replaces the insert and update triggers. It was applied to the local database. It does not modify historical registration rows or advance actual students. Other environments need the migration as well. Progression transactions require InnoDB tables; the regression harness refuses nontransactional tables.

## Verification

```powershell
php tests/academic_progression_periods/run.php
php tests/student_year_progression/run.php
php tests/cse_progression/run.php
php scripts/test_student_academic_workflow.php
```

The new harness uses random fixture identifiers in a transaction and rolls them back. It covers semester and term rollover, CSE stages, single/ranged/invalid years, canonical structure precedence over conflicting legacy metadata, both triggers, preservation of existing registration/fee state and target-year courses, conflicting registrations, later-period synchronization, and final-year limits. Existing suites cover HOS scope, audit references, CA evidence, and pending registration creation.

The live candidate and clearance queries also execute successfully. Browser navigation to the progression page correctly reaches staff login when signed out; authenticated UI submission was not exercised. No real student was progressed during debugging.
