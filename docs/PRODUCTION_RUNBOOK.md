# WUC Portal production runbook

## Release gate

Production requires PHP 8.2+, HTTPS, MariaDB/MySQL, a least-privilege database
account, and storage outside the Apache document root. Copy `.env.example` into
the deployment platform's secret manager; do not place a populated `.env` in
`htdocs`.

Before every release:

1. Run `composer install --no-dev --classmap-authoritative` and
   `composer audit --locked`.
2. Create a database backup with `php scripts/backup_database.php`.
3. Run `php scripts/migrate.php` once from the release host.
4. Run `php scripts/production_preflight.php`.
5. Smoke-test staff login, student login, registration, payments, document
   download, and the `/api/health.php` endpoint.
6. Deploy atomically and retain the previous application release for rollback.

Never run migration, seed, check, or debug scripts through Apache. The root
`.htaccess` intentionally returns 403 for these paths.

## Backup and recovery

Schedule `php scripts/backup_database.php` daily. The configured backup folder
must be outside `htdocs`, restricted to the backup operator, encrypted at rest,
and replicated to a second location. Default retention is 30 days.

On Windows, run `scripts/install_operations_tasks.ps1` from an elevated
PowerShell prompt to install the daily backup and hourly operations checks.
Confirm both tasks run successfully under a dedicated service identity; merely
registering the tasks is not sufficient evidence.

Test restoration at least quarterly in an isolated database:

`php scripts/restore_database.php <backup.sql> --confirm=wucportal`

For an automated non-destructive drill that creates and removes an isolated
database, run `php scripts/recovery_drill.php <backup.sql> --confirm-drill`
with administrative database credentials supplied to the process.

After restoration, run `database_health_check.php`, reconcile row counts, and
perform login/registration/payment smoke tests. Record the measured recovery
time and data-loss window. Initial targets: RPO 24 hours and RTO 4 hours; system
owners must approve or revise them.

## Monitoring and alerting

- Probe `/api/health.php` every minute and alert after three consecutive 503s.
- Alert on HTTP 5xx rate, authentication failure spikes, response latency,
  database connections, disk usage, backup age, and certificate expiry.
- Runtime logs belong in `WUC_LOG_DIR`; the application bounds each file to
  approximately 10 MB and retains five local rotations. Forward logs to the
  organisation's central log store and restrict access.
- Review failed logins and privileged changes daily. Do not log passwords,
  session cookies, payment secrets, or uploaded document content.

## Incident response

1. Declare an incident, record UTC start time, scope, and incident commander.
2. Preserve logs and database snapshots; do not edit evidence in place.
3. Contain compromised accounts or endpoints and rotate affected secrets.
4. Restore the last known-good release/database only after evidence capture.
5. Notify the privacy/compliance owner when personal or financial data may be
   involved, following the organisation's approved breach process.
6. Complete a post-incident review with owners and deadlines.

## Rollback

Stop traffic, restore the previous immutable application release, and restore a
database backup only when the migration cannot be rolled forward safely. Never
manually alter an applied migration: checksums are enforced by the migration
ledger.

## Privacy and operations

Maintain documented data owners, lawful-use purpose, retention period, deletion
procedure, quarterly access review, joiner/mover/leaver process, and audit-log
review for student, staff, academic, admissions, and financial records. Obtain
legal review for the applicable Zambian privacy, education, finance, and records
requirements before go-live.
