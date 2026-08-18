# WUCPortal to Laravel — Audited Implementation Plan

**Status:** Revised after repository and live-schema audit  
**Audit date:** 2026-07-26  
**Migration style:** Incremental strangler migration with reversible route cutovers  
**Target framework:** Laravel 13 on PHP 8.4

## Implementation status

The safety-critical foundation from Phases 0–2 and the first Phase 3 read-only
pilot slice were implemented on 2026-07-26 in the sibling project
`C:\xampp\htdocs\wucportal-modern`:

- isolated Laravel 13.22 application and dependency lock;
- full and schema-only live backups outside the web root;
- restored 363-table schema-only test database;
- SELECT-only live runtime account and hard test-database guard;
- versioned schema contract for critical tables;
- corrected identity/RBAC/domain models;
- bcrypt authentication with weak-hash containment;
- security headers, role/permission middleware, and WUC design system;
- dashboard, profile, programme catalogue, and course catalogue;
- automated schema, authentication, security, and read-only portal tests.

The remaining Phase 0–2 operational gates (CI, static analysis, restore drill,
complete role/credential remediation, and parity sign-off) and Phases 4–9 remain
gated. Write access must not be enabled by broadening the read-only runtime account
without the phase-specific characterization, transaction, idempotency,
reconciliation, and rollback tests described below.

## 1. Executive decision

Do not replace the legacy application in place and do not run the current pending Laravel migrations.

Build a clean Laravel 13 application in a dedicated directory such as `laravel/`, with its own `composer.json`, `vendor/`, `public/`, tests, and frontend toolchain. Serve it from a separate Apache virtual host during migration. Keep the current legacy root and root Composer dependencies operational until every migrated route has passed parity, security, and rollback gates.

Laravel will initially read the existing `wucportal` database. It will map the current identity and authorization tables instead of creating a second `users`/roles system. Writes will be enabled one vertical slice at a time.

The three decisions left open by the earlier plan are therefore resolved:

1. **Coexistence:** yes, through separate document roots and route-level cutovers.
2. **Identity:** keep and adapt the existing `users`, `roles`, `permissions`, `user_roles`, and `role_permissions` tables.
3. **Brand:** retain Bootstrap 5, Inter, Font Awesome, and WUC purple `#6f42c1`.

## 2. Evidence from the current system

### Runtime and repository

- PHP CLI: 8.4.23 with `pdo_mysql` and `mysqli`.
- Database: MariaDB 10.4.32.
- Live database: 363 base tables, not “110+”.
- Legacy surface: approximately 2,280 PHP files outside framework/vendor directories.
- The worktree already has extensive unrelated modifications; migration work must begin on an isolated branch or worktree after ownership of current changes is reviewed.
- Root `app/` is not empty: it already contains legacy PHP such as `app/Csrf.php` and `app/ExamRegistration/`. Using it simultaneously as Laravel's PSR-4 application directory creates avoidable collision risk.

### A partial Laravel installation already exists

The earlier plan's “Laravel is not installed” premise is stale:

- Laravel 11.55.0 is installed in the root vendor tree.
- `artisan`, `bootstrap/app.php`, configuration files, models, and migrations exist.
- Only `/` is currently registered as a non-vendor route.
- The `/` route references a missing `welcome` view.
- `routes/api.php` exists but is not registered by `bootstrap/app.php`.
- No Laravel tests are discovered.
- `composer.json` and `composer.lock` are inconsistent.
- Loading `App\Models\User` fatals because `Spatie\Permission\Traits\HasRoles` is referenced but the package is not installed in the lock file.
- `php artisan migrate --pretend` fails in the pending permission migration because `config/permission.php` and the installed package are absent.
- The Vite scaffold and the root React/Webpack `package.json` are incompatible toolchains.

### Two migrations have already run on the live database

The database is not untouched:

1. `2025_01_01_000001_create_users_table` is marked as ran, but silently did nothing because `users` already existed.
2. `2025_01_01_000002_add_user_id_to_legacy_tables` added nullable `user_id` columns to `students` and `staff`.

Both new columns currently have zero populated values. They also duplicate the established link direction in `users.student_id` and `users.staff_id`.

Do not run `migrate:rollback`: the migration's `down()` method drops columns. Leave these nullable columns quarantined and unused until a separately reviewed cleanup can prove that no code depends on them.

### Actual identity and authorization schema

| Concern | Live source of truth | Important columns |
|---|---|---|
| Accounts | `users` | `user_id`, `username`, `password`, `primary_role`, `staff_id`, `student_id`, `status` |
| Student profile | `students` | `SID`, profile and academic fields |
| Student fallback credentials | `student_login` | `Sid`, `Password`, `must_change_password` |
| Staff profile | `staff` | `staff_id`, profile, role, status, lockout fields |
| Staff fallback credentials | `user_credentials` | `staff_id`, `pass` |
| Canonical roles | `roles` | `role_id`, `role_name`, `role_label`, `status` |
| Canonical permissions | `permissions` | `permission_id`, `permission_key`, `status` |
| User-role assignments | `user_roles` | `user_id`, `role_id`, `portal_id`, `status` |
| Role permissions | `role_permissions` | `role_id`, `module_id`, `permission_id`, `portal_id`, `status` |
| Legacy staff role compatibility | `staff_positions`, `positions`, `access_right` | position/access labels |

The live `users` table has 44 rows. It uses `user_id`, not Laravel's default `id`, and it has no `name`, `email`, `user_type`, `legacy_id`, or Laravel timestamp layout assumed by the generated model.

`access_right` does not store passwords, and there is no `admin` table. The proposed new Spatie tables would duplicate a populated RBAC system: 17 roles, 283 permissions, 46 user-role assignments, and 384 role-permission assignments already exist.

The role data also contains aliases that need a deliberate compatibility map, including:

- `admission_officer` and `admissions_officer`
- `head_of_department` and `head_of_section`

### Credential condition at audit time

- `users.password`: 42 bcrypt hashes, one 32-character legacy hash, and one plaintext test value.
- `student_login.Password`: six bcrypt hashes and one plaintext test value.
- `user_credentials.pass`: 18 legacy 32-character hashes.

No plaintext or legacy hash should be copied into a new table. Laravel authentication must support a tightly scoped transition verifier, rehash valid legacy credentials to bcrypt/Argon on successful login, update the account source of truth, and record the upgrade. Plaintext test credentials must be repaired before any production cutover.

### Corrected schema facts

The live schema, not old documentation or generated models, is authoritative:

- `programs.program_duration` exists; `duration_months` does not.
- `programs.study_mode` and `programs.period_mode` both currently exist.
- `fee_structure.entity_type` currently exists.
- `approved_assessments` does not exist.
- `online_applicants.status` allows `pending`, `accepted`, and `rejected`; generated model constants such as `reviewing`, `approved`, and `converted` are invalid.
- Many generated model attributes and keys do not exist. Examples include `CourseLecturer.course_lecturer_id`, `CourseRegistration.student_id`, `Programme.duration_months`, and several course/profile fields.

Every model must therefore be produced from a checked schema contract, not guessed from legacy page code.

## 3. Immediate containment and recovery

Complete these actions before feature implementation:

1. Freeze all root-level `artisan migrate*` commands against `wucportal`.
2. Take a logical database backup, a schema-only dump, and a restore-tested copy.
3. Export the current `information_schema` contract: tables, columns, types, nullability, indexes, foreign keys, engines, and collations.
4. Preserve the dirty worktree. Identify which partial Laravel files and database changes belong to this migration before relocating or superseding them.
5. Record the two already-run migration rows and the two added nullable columns in a migration incident note.
6. Do not delete or roll back those columns during the conversion.
7. Restore a coherent legacy root Composer manifest and lock file. Preserve dependencies actually used by legacy routes, including PHPMailer, Dompdf, and Vonage, while upgrading them to patched versions.
8. Remove blanket Composer advisory ignores. The audit found nine ignored advisories affecting the locked Laravel and Dompdf versions, plus an abandoned package.
9. Create the clean Laravel application in a dedicated directory and give it a separate lock file.
10. Create separate least-privilege database accounts:
    - runtime read-only for early migration phases;
    - runtime read/write with only the privileges required by enabled slices;
    - migration account used only during controlled deployment;
    - test account restricted to isolated test databases.

**Containment exit gate:** backups restore successfully; no pending root migration can run accidentally; dependency locks validate; the clean application boots without touching the live schema.

## 4. Target architecture

### Application boundary

Implemented coexistence layout:

```text
C:\xampp\htdocs\
├── wucportal\                 # legacy application
│   ├── legacy PHP files and directories
│   ├── composer.json
│   └── vendor\
└── wucportal-modern\          # isolated Laravel application
    ├── app\
    ├── bootstrap\
    ├── config\
    ├── database\
    ├── public\
    ├── resources\
    ├── routes\
    ├── tests\
    ├── composer.json
    └── package.json
```

Use two development hosts:

- Legacy: the existing `/wucportal` application under the XAMPP document root.
- Laravel: `http://localhost:8081`, whose document root is
  `C:\xampp\htdocs\wucportal-modern\public`.

Use distinct session cookie names and domains. Do not point the only document root at Laravel's `public/` while claiming legacy root PHP remains directly accessible; those statements are mutually incompatible without a separate alias or virtual host.

### Framework version

Use Laravel 13 with a current patched constraint and a freshly resolved lock file. Laravel 11's security support ended on 2026-03-12. Laravel 13 supports PHP 8.3–8.5 and receives security fixes through 2028-03-17.

Official references:

- <https://laravel.com/docs/13.x/releases>
- <https://laravel.com/docs/13.x/upgrade>
- <https://laravel.com/docs/13.x/database>

MariaDB 10.4 is accepted by Laravel's documented MariaDB 10.3+ floor, but the database upgrade lifecycle must be handled as a separate infrastructure project and tested on a restored clone. Do not combine a major database upgrade with the first application cutover.

### Database ownership

Use the existing database for domain data and avoid bidirectional duplicate identity links.

- Existing tables are **adopted**, not recreated by Laravel migrations.
- Set an isolated migration repository table such as `laravel_migrations`.
- Prefix framework-owned tables, for example `laravel_jobs`, `laravel_failed_jobs`, `laravel_cache`, `laravel_sessions`, and `laravel_notifications`.
- Start with file sessions/cache and synchronous jobs so foundation work requires no schema mutation.
- Introduce framework-owned tables only when an implemented feature requires them.
- Never use `Schema::hasTable()` to silently turn an incompatible table into a successful migration. A migration must either create its known table or fail with an explicit precondition message.
- Never use `migrate:fresh`, `db:wipe`, or destructive rollback on a shared or production database.
- Do not add foreign keys until orphan counts, column types, collations, and legacy write paths are compatible.

### Identity and RBAC

Create explicit Eloquent models for the existing schema:

- `User`: table `users`, primary key `user_id`, username authentication, explicit timestamps and casts.
- `Student`: table `students`, primary key `SID`, string/non-incrementing.
- `Staff`: table `staff`, primary key `staff_id`, string/non-incrementing.
- `Role`, `Permission`, `UserRole`, and `RolePermission`: existing custom keys and tables.

Relationships flow from `users.student_id` to `students.SID` and from `users.staff_id` to `staff.staff_id`. The newly added `students.user_id` and `staff.user_id` remain unused.

Do not install Spatie Permission during the compatibility migration. Implement Gates and Policies over the existing RBAC tables. This prevents two role systems from drifting. Treat `primary_role`, `access_right`, and `staff_positions` as compatibility sources, with a documented normalization map and reconciliation report. New Laravel authorization should prefer active `user_roles` plus `role_permissions`.

### Authentication coexistence

1. Authenticate against `users.username` and `users.password`.
2. Fall back to `student_login` or `user_credentials` only for a linked legacy account whose main hash is absent or stale.
3. On successful legacy verification, rehash and update `users.password`; update the compatible legacy store only where active legacy login still requires it.
4. Enforce account/profile status and lockout rules.
5. Rate-limit login and password-reset endpoints.
6. Regenerate the Laravel session after authentication.
7. Preserve legacy session keys only through a dedicated, tested handoff adapter.

Do not share raw PHP and Laravel session files/cookies. If cross-application single sign-on is required, use a short-lived, signed, one-time handoff token containing the account ID, intended destination, issue/expiry times, and nonce. Store used nonces to prevent replay.

### UI and frontend

Use one frontend toolchain in the Laravel application: Vite plus Blade and Bootstrap 5. Do not carry the unrelated React/Webpack root package into the Laravel build unless a specific migrated module proves that React is required.

Retain:

- primary purple `#6f42c1`;
- darker purple `#5a32a3`;
- Inter;
- Font Awesome 6;
- Bootstrap 5.3;
- established card, stat, empty-state, alert, form, table, and responsive patterns.

Build components as slices need them. “40+ components” is not an early milestone and should not delay a usable vertical slice. Escape all dynamic values and validate URL/file attributes.

## 5. Migration method: one vertical slice at a time

Create a route-parity register before migrating pages. Each row records:

- legacy URL and owning module;
- users/roles allowed;
- session keys and authorization checks;
- all tables and columns read or written;
- files/uploads touched;
- emails, SMS, payment gateways, callbacks, or scheduled tasks invoked;
- Laravel route/controller/service/policy/view;
- characterization, feature, and browser tests;
- feature flag and traffic cohort;
- cutover and rollback steps;
- owner and sign-off.

Every slice follows the same lifecycle:

1. Characterize the legacy route with tests and representative fixtures.
2. Verify every query against the schema snapshot and live clone.
3. Implement the Laravel route with Form Requests, services, policies, and explicit transactions.
4. Run old/new read-only output comparisons.
5. Enable the new route for staff/test cohorts behind a feature flag.
6. Compare errors, results, query counts, performance, and audit records.
7. Switch the route while retaining an immediate routing rollback.
8. Observe through the agreed stability window.
9. Mark the legacy route read-only, then retire it only after sign-off.

Schema downgrades are not the rollback mechanism. Roll back traffic to the legacy route while keeping additive schema compatible.

## 6. Implementation phases and gates

### Phase 0 — Clean foundation

- Scaffold a clean Laravel 13 application in the sibling `wucportal-modern`
  project.
- Pin PHP and Composer platform requirements.
- Select one Node LTS version and one package manager.
- Configure `legacy` database access with a read-only credential.
- Configure distinct app URL, session cookie, logs, cache namespace, and storage path.
- Register web routes only; add API routing when an API consumer exists.
- Add Pint, Larastan/PHPStan, PHPUnit, and browser testing.
- Add CI for Composer validation/audit, frontend build, lint, static analysis, unit/feature tests, and secret scanning.
- Add `/up` plus a deeper authenticated health check for DB, storage, mail, and queue dependencies.

**Exit gate:** clean install, deterministic lock files, zero dependency advisories accepted without a documented exception, green CI, no live DB writes.

### Phase 1 — Schema contract and model layer

- Generate a versioned schema contract from `information_schema`.
- Model only tables needed by the first slices.
- Explicitly define table, primary key, key type, incrementing behavior, timestamps, casts, connection, and allowed fields.
- Add schema-contract tests that fail when a model references a missing table, key, timestamp, fillable field, or relationship column.
- Add query services for complicated legacy joins rather than forcing every relation into Eloquent.
- Enable Eloquent strictness outside production to catch missing attributes, lazy loading, and silently discarded fields.

**Exit gate:** every introduced model passes the schema contract and read-only queries match legacy outputs for representative records.

### Phase 2 — Authentication, authorization, and portal shell

- Implement the existing-user Laravel auth provider.
- Implement legacy hash verification and rehash-on-login with audit records.
- Repair plaintext test credentials and produce a report of remaining weak hashes.
- Implement Gates/Policies over the existing RBAC tables.
- Reconcile role aliases and mismatches across all four role sources.
- Add the signed one-time handoff only if cross-app SSO is required.
- Build the shared authenticated layout, portal selector, navigation service, error views, and accessible responsive components.

**Exit gate:** student and every staff role can log in; suspended/locked accounts cannot; authorization parity tests pass; no plaintext credentials remain; session fixation, CSRF, open-redirect, and replay tests pass.

### Phase 3 — Read-only pilot slices

Migrate low-risk pages first:

- portal selection;
- student profile and dashboard;
- staff profile and dashboard;
- programme/course catalogue;
- timetable and read-only academic views.

Use production-like sanitized fixtures and old/new data-diff tests. Avoid broad dashboard “services” that query guessed optional tables; each widget owns a verified query and a defined empty/error state.

**Exit gate:** pilot users complete the same read-only tasks with matching data and acceptable performance; routing rollback is demonstrated.

### Phase 4 — Controlled academic writes

- profile edits and password change;
- semester/course registration;
- lecturer-course assignment;
- teaching plans and learning materials;
- atomic registration and progression workflows.

Use Form Requests, policies, database transactions, idempotency keys where users may resubmit, and synchronous audit records.

**Exit gate:** success, validation failure, concurrency, duplicate submission, authorization, and transaction rollback tests pass; legacy and Laravel clients can coexist without data divergence.

### Phase 5 — Assessments and results

- CA entry/import;
- CA submission/approval;
- examination results;
- publication controls and student result views.

Model the workflow states from the real tables. Do not invent `approved_assessments`; locate the actual approval/result mechanism first. Protect lecturer assignment, approval separation, publication state, and student ownership with policies.

**Exit gate:** marks totals and approvals reconcile exactly; unauthorized cross-course/student access is impossible; imports are idempotent and produce reject reports.

### Phase 6 — Admissions and registrar workflows

- application review;
- acceptance/rejection;
- applicant-to-student conversion;
- student and programme maintenance;
- transfer and progression workflows.

Applicant conversion must be one explicit transaction that creates or links all required profile, programme, login, and account rows. Re-running a request must not create another student.

**Exit gate:** end-to-end conversion and rollback tests pass; generated IDs are collision-safe; no student is created without the rows needed to log in and see the assigned programme.

### Phase 7 — Finance and payment integrations

Migrate finance after identity and academic contracts are stable:

- fee structures and invoices;
- statements, receipts, sponsorships, and manual payments;
- Airtel, DPO, SchoolPay, and other callbacks;
- reconciliation and finance reports.

Each callback must verify signatures/secrets, preserve raw request evidence safely, enforce an idempotency key or unique provider transaction ID, and return the provider-required response even when processing is queued. Financial posting and receipt generation require transactions and immutable audit trails.

Upgrade Dompdf to a patched release and restrict remote/local resource loading and filesystem access.

**Exit gate:** replay, duplicate, out-of-order, invalid-signature, partial-failure, timeout, refund/reversal, and reconciliation tests pass; finance owners sign off on totals.

### Phase 8 — eLearning, notifications, integrations, and APIs

- eLearning courses/enrolments/materials;
- email/SMS notifications;
- queued jobs with retry/idempotency policy;
- reports/exports;
- external APIs.

Add Sanctum only when an actual SPA/mobile/third-party consumer and token lifecycle are defined. Version APIs from their first public release. Use an outbox pattern for critical notifications triggered by domain transactions.

**Exit gate:** queue retry does not duplicate side effects; API authorization and rate limits pass; notification failures are observable and recoverable.

### Phase 9 — Final cutover and decommission

- Confirm every in-scope route in the parity register is migrated or explicitly retired.
- Freeze legacy feature development except emergency fixes.
- Perform a final data and permission reconciliation.
- Switch the primary host to Laravel's `public/`.
- Keep route-level rollback available through the agreed observation period.
- Archive legacy source and operational runbooks; remove web execution only after retention and audit approval.
- Remove obsolete schema or compatibility code only as a later, separately approved cleanup.

**Exit gate:** no production links or callbacks target legacy routes; restore and routing rollback drills pass; operations, security, academic, admissions, and finance owners sign off.

## 7. Test and verification strategy

### Test databases

- Create a sanitized clone from the real 363-table schema.
- Use an unmistakable test database name and fail boot if tests point to `wucportal`.
- Use a separate database per parallel worker; otherwise do not run DB tests in parallel.
- Test all migrations on a restored clone before controlled deployment.
- Maintain fixtures for every role and representative programme type: semester, term, short course, trade test, and transport exceptions.

### Required test layers

1. **Schema contract tests:** model/table/key/column/index compatibility.
2. **Characterization tests:** current legacy behavior before replacement.
3. **Unit tests:** pure business rules and role normalization.
4. **Feature tests:** routes, validation, policies, transactions, and sessions.
5. **Parity/data-diff tests:** legacy and Laravel results for the same fixture.
6. **Browser tests:** golden path, mobile layout, empty/error states, and accessibility.
7. **Integration/contract tests:** mail, SMS, PDF, storage, queues, and payment callbacks.
8. **Security tests:** authentication, authorization, CSRF, XSS, uploads, redirects, rate limits, session fixation, token replay, and mass assignment.
9. **Performance tests:** dashboard/query counts, slow queries, exports, imports, and concurrent registration/payment requests.
10. **Restore/rollback drills:** database restore plus route switch back to legacy.

### Baseline commands

Run from the clean Laravel directory with the explicit XAMPP PHP binary until PATH is standardized:

```powershell
C:\xampp\php\php.exe composer.phar validate --strict
C:\xampp\php\php.exe composer.phar audit
C:\xampp\php\php.exe artisan about
C:\xampp\php\php.exe artisan route:list
C:\xampp\php\php.exe artisan migrate:status
C:\xampp\php\php.exe artisan migrate --pretend --database=testing
C:\xampp\php\php.exe artisan test
C:\xampp\php\php.exe vendor\bin\pint --test
C:\xampp\php\php.exe vendor\bin\phpstan analyse
```

Run deployment cache commands separately in staging, then smoke test the cached application:

```powershell
C:\xampp\php\php.exe artisan config:cache
C:\xampp\php\php.exe artisan route:cache
C:\xampp\php\php.exe artisan view:cache
```

## 8. Observability and operations

- Use structured application logs with correlation IDs and the authenticated `user_id`.
- Never log passwords, reset tokens, payment secrets, full card/payment payloads, or sensitive documents.
- Record permission decisions and critical state transitions in an append-only audit trail.
- Track route-level old/new traffic, errors, latency, DB time, query count, queue lag, failed jobs, email/SMS failures, and payment reconciliation differences.
- Configure Laravel scheduler execution every minute and supervise queue workers as services before enabling asynchronous production work.
- Document storage ownership, backup, malware scanning, allowed file types, size limits, and private download authorization.
- Provide health checks and runbooks for Apache/PHP, MariaDB, storage, queues, scheduler, mail, SMS, and payment providers.

## 9. Principal risks and controls

| Risk | Control |
|---|---|
| Silent migrations mark incompatible schema as successful | Explicit preconditions; isolated `laravel_migrations`; clone rehearsal |
| Competing user/role stores drift | Adapt existing RBAC; one normalization/reconciliation service; no Spatie duplication |
| Legacy and Laravel sessions collide | Separate hosts/cookies; signed one-time handoff |
| Generated models write nonexistent columns | Versioned schema contract and model tests |
| Legacy dependency removal breaks active pages | Separate Composer roots; usage inventory; upgrade instead of delete |
| Duplicate registrations, admissions, or payments | Transactions, unique constraints, locks, and idempotency keys |
| Payment callback replay or forgery | Signature verification, nonce/transaction uniqueness, immutable audit |
| Tests mutate live data | Hard environment/database guard and isolated clones |
| Document-root switch removes fallback | Dual virtual hosts and route-level proxy/cutover |
| “Big bang” scope prevents completion | Vertical slices with explicit exit gates and business sign-off |
| Rollback drops production columns/data | Traffic rollback; additive compatibility; no destructive production `down()` |

## 10. Definition of done

The Laravel conversion is complete only when:

- all in-scope legacy routes are accounted for in the route-parity register;
- every migrated model is verified against the current schema;
- authentication and RBAC work for every real role and portal;
- no plaintext credentials or undocumented legacy hash paths remain;
- academic, admissions, assessment, and finance reconciliations are exact;
- all payment and external callbacks target Laravel and are idempotent;
- automated tests, static analysis, dependency audit, browser tests, and performance gates pass;
- backup restore, route rollback, queue, scheduler, and incident runbooks are proven;
- the Laravel public directory is the only production web root;
- legacy web execution is disabled only after the observation period and owner sign-off.

## 11. Work explicitly deferred

These are not foundation prerequisites and should be added only when justified by a migrated slice:

- Sanctum and public APIs;
- database-backed sessions/cache/queues;
- large event/listener catalogs;
- dozens of speculative Blade components;
- a new role/permission package;
- schema normalization or deletion of legacy columns;
- major MariaDB upgrade;
- replacement of every library/integration in the first release.

This ordering keeps the conversion deployable throughout, protects the existing portal, and turns each migration step into a testable business outcome rather than an all-or-nothing rewrite.
