---
name: wuc-transport
description: >
  Build and maintain the WUC portal's Transport & Driver Training module —
  TEVETA curriculum, fleet management, trainee assessments, compliance
  renewals, booking gate (BR001), payment guards, and certificates. Use
  whenever a task involves any file under transport/ or transport-scoped
  tables. Covers the auth guard, nav chrome, helper library, and the key
  business rules from the ITC transport spec.
---

# WUC Portal — Transport Module Developer

## Module layout

```
transport/
  transport_management.php   ← monolith: cohorts, enrollments, sessions, fuel/maint forms
  curriculum.php             ← TEVETA syllabus & outcome coverage
  trainee_assessments.php    ← per-trainee assessment records → cert eligibility
  certificate.php            ← completion certificate (dompdf or printable HTML)
  compliance.php             ← instructor RTSA/TEVETA, vehicle fitness, trainee licences
  fleet_dashboard.php        ← KPIs, fuel/maint spend, incidents, scorecards
  fleet.php                  ← fleet management (CRUD)
  policies.php               ← policy register + audit log
  attendance.php             ← per-session attendance → "reported" metric
  reports.php                ← cohort/throughput/allocation/progress reports
  training_reports.php       ← role-scoped training reports wrapper
  fees.php / payments.php    ← fee schedule + payment recording
  invoice.php / receipt.php  ← BR011 invoicing and receipt engine
  ai_assessment_bank.php     ← AI-generated TEVETA question banks
  includes/
    transport.php            ← AUTH GUARD — include first on every transport page
    teveta_helpers.php       ← shared TEVETA business logic
    transport_eligibility.php
    transport_fees.php
    transport_invoicing.php
    transport_payment_guard.php
    transport_certificates.php
    nav.php / footer.php / header.php
```

## Step 1 — include the auth guard

Every transport page must start with:

```php
<?php
require_once __DIR__ . '/includes/transport.php';
// $base_url = '/wucportal/transport'  (set by the guard)
// $root_url = '/wucportal'
```

`transport.php` does, in order:
1. `session_guard.php` → `wuc_enforce_session_guard()` (1800s idle timeout)
2. `db/connect.php`
3. `config/auth_check.php` → `hydrateStaffRolesFromDatabase()`
4. `canAccessTransport()` check → 403 + safe redirect if denied

`canAccessTransport()` returns `true` for `systems_admin` OR any staff with
`transport_view`/`transport_manage` permissions (Transport Officer position).

After the guard, output the nav:
```php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
// ... page content ...
require_once __DIR__ . '/includes/footer.php';
```

## Key database tables

| Table | Purpose |
|-------|---------|
| `transport_programs` | Training programmes (TEVETA-aligned) |
| `transport_cohorts` | Training batches (start/end dates, capacity) |
| `transport_enrollments` | Trainee ↔ cohort; tracks `payment_status`, `booking_status`, `certificate_issued` |
| `transport_payments` | Verified payment ledger (BR001: booking requires `payment_status='verified'`) |
| `transport_sessions` | Scheduled training sessions |
| `transport_session_attendance` | Per-session attendance records |
| `transport_assessments` | Theory/practical assessment results |
| `transport_curriculum_outcomes` | TEVETA learning outcomes per programme |
| `transport_curriculum_modules` | Theory/practical modules mapped to outcomes |
| `transport_vehicles` | Fleet (plate, type, fitness/insurance dates, cost fields) |
| `transport_fuel_logs` | Fuel records (unit_cost, total_cost) |
| `transport_maintenance_logs` | Maintenance (cost) |
| `transport_trainee_licences` | Trainee licence dates (for compliance dashboard) |
| `transport_policies` | Policy register |
| `transport_audit_log` | Immutable audit trail |
| `transport_campuses` | Campus / training site |
| `transport_clients` | Sponsoring organisations |
| `transport_instructors` | Instructor profiles (RTSA/TEVETA expiry dates) |

## BR001 — Booking Gate

**No trainee may be booked into a cohort before a verified payment exists.**

The guard is `transport_payment_guard.php` (functions prefixed `tpay_`):

```php
require_once __DIR__ . '/includes/transport_payment_guard.php';

// Check before allowing enrolment:
if (!tpay_trainee_may_book($db, $traineeId, $cohortId)) {
    $errors[] = 'Booking requires a verified payment. Please record payment first.';
}
```

Payment flow:
1. Payment recorded in `transport_payments` with `payment_status = 'pending'`.
2. Accountant/admin verifies → `payment_status = 'verified'`.
3. `amount_paid` for a trainee is computed from **verified payments only**
   (never from `transport_enrollments.amount` directly).
4. Booking status (`transport_enrollments.booking_status`) progresses:
   `pending` → `confirmed` (only after verified payment) → `cancelled`.

## TEVETA curriculum helpers

```php
require_once __DIR__ . '/includes/teveta_helpers.php';

// Fetch outcomes + module coverage for a programme:
$outcomes = teveta_get_programme_outcomes($db, $programmeId);
$coverage = teveta_get_outcome_coverage($db, $programmeId, $cohortId);

// Check if a trainee is cert-eligible (passed all required assessments):
$eligible = teveta_trainee_cert_eligible($db, $traineeId, $cohortId);
```

Eligibility requires:
- Enrolment `status = 'completed'` in `transport_enrollments`
- All required assessment outcomes passed in `transport_assessments`
- Payment `status = 'verified'`
- Certificate not yet issued (`certificate_issued IS NULL`)

## Certificate generation

`certificate.php` calls `transport_generate_certificate()` from
`transport_certificates.php`. It tries dompdf if available; otherwise falls back
to printable HTML. **dompdf requires PHP ≥8.1** but this XAMPP runs PHP 8.0 —
always wrap the composer autoload:

```php
$dompdfAvailable = false;
try {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $dompdfAvailable = class_exists('Dompdf\Dompdf');
} catch (Throwable $e) {
    // PHP 8.0 — dompdf unavailable, HTML fallback will be used
}
```

## Fleet management

Fleet KPIs live in `fleet_dashboard.php`. When writing new fleet queries:

- `transport_vehicles` extended columns: `acquisition_date`, `acquisition_cost`,
  `replacement_mileage`
- `transport_fuel_logs` cost columns: `unit_cost`, `total_cost`
- `transport_maintenance_logs.cost`
- Fuel efficiency = `SUM(distance_km) / SUM(litres)` for the period
- Service due = vehicles where `next_service_date <= DATE_ADD(NOW(), INTERVAL 30 DAY)`

## Compliance dashboard

`compliance.php` tracks three licence types:
1. **Instructor licences**: `transport_instructors.rtsa_expiry`, `teveta_expiry`
2. **Vehicle fitness/insurance**: `transport_vehicles.fitness_expiry`, `insurance_expiry`
3. **Trainee licences**: `transport_trainee_licences.licence_date`, `expiry_date`

"Expired" = expiry date in the past. "Expiring soon" = within 30 days.

## Reports engine (transport-specific)

`transport/reports.php` uses the shared `includes/training_reports_engine.php`.
The four transport report types:

| Key | Description |
|-----|-------------|
| `recruitment_funnel` | Recruited → reported → trained, by programme |
| `training_throughput` | Per-cohort outcomes (pass/fail/withdraw/cert rate) |
| `instructor_allocation` | Who trains what (hours, headcount) |
| `cohort_progress` | Enrolled/reported/attendance%/passes/certified |

Use the engine via:
```php
require_once dirname(__DIR__) . '/includes/training_reports_engine.php';
trx_handle_csv($db, $opts);   // call BEFORE any HTML
// ... nav ...
trx_render($db, $opts);       // renders pills + filters + table + AI summary
```

See [[wuc-reports]] for full engine details.

## Access-control facts

| Role | `canAccessTransport()` | Comment |
|------|----------------------|---------|
| `systems_admin` | ✓ always | Full access |
| Transport Officer (`transport_view`/`transport_manage`) | ✓ | Module-scoped |
| All other staff | ✗ | 403 |

`isTransportOnlyUser()` → `true` when the staff member has transport permissions
but NO admin/academic role. These users see a focused **Dashboard + Transport
Section** menu instead of the full admin sidebar.

## CSRF for transport forms

Transport pages use `$_SESSION['transport_csrf']` (separate namespace):

```php
if (empty($_SESSION['transport_csrf'])) {
    $_SESSION['transport_csrf'] = bin2hex(random_bytes(32));
}
// In POST handler:
if (!hash_equals($_SESSION['transport_csrf'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    $errors[] = 'Request verification failed.';
}
```

## Schema verification before any transport query

The transport tables are managed by installer scripts under
`transport/scripts/` (e.g. `install_teveta_modules.php`,
`install_attendance.php`, `install_fleet_management.php`). These are
idempotent — safe to re-run. If a page fatals with "Table doesn't exist",
check which installer adds that table and run it.

Always verify a new transport query against the live schema first (see
[[wuc-schema-debug]]) — transport tables also drift.

## Adding a new transport page — checklist

- [ ] First line: `require_once __DIR__ . '/includes/transport.php';`
- [ ] CSRF token generated and checked
- [ ] All queries use prepared statements
- [ ] All echoed values escaped with `htmlspecialchars()`
- [ ] Nav/footer chrome included via `includes/nav.php` and `includes/footer.php`
- [ ] Table existence verified (especially for new installer-created tables)
- [ ] BR001 gate checked before any booking write
- [ ] Certificate generation has dompdf try/catch fallback
- [ ] New page linked in `transport/includes/nav.php`
