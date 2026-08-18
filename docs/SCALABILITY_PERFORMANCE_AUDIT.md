# Scalability, Performance, Traffic & Uptime Audit

**Date:** 2026-08-04  
**Scope:** Legacy PHP + MySQLi portal (`wucportal`), excluding `wuc-nextjs-portal/.next` vendor trees.  
**Method:** Live schema inspection, query timing, EXPLAIN, code path review, implemented fixes, before/after verification, concurrent HTTP load against `api/health.php`.

---

## Critical Issues

1. **Student dashboard risk engine on every load** — `wuc_academic_risk_analyze_student()` ran ~8 metric query groups on each `students/index.php` hit (**~550–2200 ms** measured). Under concurrent student logins this saturates PHP workers and MySQL first.
2. **Session file lock held through heavy work** — default PHP file sessions serialize parallel tabs/AJAX for the same user while dashboards still held the lock.
3. **Unbounded admin student roster** — `admin/students_by_admin.php` loaded every student into HTML + client DataTables (same anti-pattern previously fixed for registrar).
4. **Lookup cache built but not wired** — `includes/lookup_cache.php` existed but was never required from the DB bootstrap, so programs/departments/roles were still re-queried.
5. **Health check failed on local/dev empty DB password** — `api/health.php` required a non-empty password via raw `getenv()`, making uptime probes report 503 on standard XAMPP.

---

## Performance Bottlenecks

| Hotspot | Evidence |
|---------|----------|
| Academic risk scoring | 555–2191 ms full analyze; thresholds/attendance/assessment/activity each 100–320 ms |
| Admin dashboard COUNT fan-out + 10× SHOW TABLES | Every admin home load |
| Enterprise dashboard | Loaded all opportunities + re-fetched interests with LIMIT 500 only to count |
| Schema probes | Repeated `SHOW TABLES` / `information_schema` without memoization |
| Permission path | Already memoized (2026-07-09) — not re-broken |
| Core indexes | Present on payments, student_program, semester_registration, portal_alerts, student_risk_summary |

Dataset at audit time: students=11, payments=2, course_registration=36 (small). Bottlenecks are **algorithmic / per-request work**, not row volume — they will worsen linearly or worse with enrolment growth.

---

## Fixes Implemented

| Area | Change |
|------|--------|
| Student dashboard | `wuc_academic_risk_for_dashboard()` uses `student_risk_summary` when &lt;15 min old; `session_write_close` before heavy work |
| Risk engine | Memoized `wuc_risk_table_exists` + threshold load; dashboard cache helpers |
| Schema guard | Request + APCu memo for `wuc_table_exists` |
| Lookup cache | Required from `db/connect.php`; file-tier fallback when APCu absent |
| Academic years | `wuc_academic_year_options()` uses `wuc_cache_remember` (600s) |
| Admin dashboard | Aggregate stats cached 60s; session lock released; table probes via `wuc_table_exists` |
| Admin students | `admin/students_data.php` + AJAX pagination/search (debounced 300ms) |
| Enterprise dashboard | Bounded opportunity list; COUNT helpers; no double interest fetch |
| Opportunity service | Optional LIMIT + narrow columns for dashboard; `ep_count_opportunities_for_profile()` |
| DB connect | Dropped redundant `SET NAMES` round-trip; keep charset + collation |
| Monitoring | `includes/perf_monitor.php` — slow request logging (≥800ms default) |
| Health | Uses `wuc_portal_env` + APP_ENV-aware credentials; connect timeout 3s |
| Config | `.env.example` — `WUC_CACHE_DIR`, `WUC_PERF_LOG`, `WUC_PERF_SLOW_MS` |

**Scripts (CLI only, blocked from HTTP):**  
`scripts/perf_audit_baseline.php`, `scripts/perf_profile_risk.php`, `scripts/perf_verify_fixes.php`, `scripts/perf_concurrent_load.php`

---

## Database Improvements

- No destructive schema changes.
- Confirmed useful indexes already present (`idx_payments_student_status_date`, `idx_student_program_student_status`, `idx_risk_student_generated`, etc.).
- `course_registration` uses column `Sid` with `idx_sid` (not `student_id`) — prior “missing student_id index” was a naming false positive.
- Query-side: pagination, COUNT aggregates instead of materializing lists, risk summary reuse.

---

## Scalability Improvements

- Cross-request cache for admin dashboard aggregates (APCu or file).
- Session lock release on student/admin dashboards reduces tab contention.
- Admin student list work scales with page size (≤100), not table size.
- Enterprise dashboard no longer pulls full opportunity sets for KPI cards.

---

## Reliability Improvements

- Health endpoint usable on local XAMPP and production (env-aware).
- Slow-request logging without dumping secrets/PII.
- Risk/dashboard failures remain try/catch guarded; cache miss falls back to full analyze.
- Schema existence probes fail soft and are memoized (fewer metadata storms).

---

## Performance Comparison

Measured on this host (PHP 8.4.23, MariaDB/MySQL via XAMPP, APCu=no):

| Metric | BEFORE | AFTER |
|--------|--------|-------|
| Risk full analyze | 555–2191 ms | 930 ms when forced (warm DB) |
| Risk dashboard path | = full analyze | **18.8 ms cached** (**49.5×**) |
| Lookup programs 2nd call/request | ~150 ms | **0.01 ms** |
| Shared cache hit (file tier) | n/a | **0.01 ms** after 24 ms miss |
| Admin students payload | all rows | page ≤25–100 rows via JSON |
| DB connect SQL setup | charset + SET NAMES + collation | charset + 1 collation |

Concurrent HTTP (`scripts/perf_concurrent_load.php`, Apache up):

| Endpoint | n | errors | codes |
|----------|---|--------|-------|
| `/api/health.php` | 1 / 10 / 50 / **100** | **0** | all 200 |
| static CSS | 1 / 10 / 50 / 100 | **0** | all 200 |

Health p95 at n=100 ≈ 676 ms (includes DB check + 2s cache layer after warm).

---

## Remaining Risks

1. **PHP file sessions** still default — hundreds of concurrent users need Redis/Memcached sessions or sticky + careful `session_write_close`.
2. **No connection pooling** — each request opens mysqli; Apache `MaxRequestWorkers` × DB `max_connections` is the hard ceiling.
3. **Batch risk reports** (`wuc_academic_risk_*_summary`) still N+1 over students — keep off the interactive path; use cron (`scripts/batch_risk_rescore.php`).
4. **Admissions pending applicants** and **accounts fee pickers** still unbounded.
5. **Analytics / predictive dashboard** still heavy for large datasets.
6. **Enterprise bootstrap** still requires ~25 service files per request (autoload/lazy-load opportunity).
7. **Ollama/AI** long requests can exhaust PHP workers — already unlocks session; consider queue for production.
8. Small local dataset understates list-page pain; re-test with ≥5k students.

---

## Recommended Next Steps

### P0 — Critical
1. Enable APCu in production PHP (or keep file cache on a fast local disk via `WUC_CACHE_DIR`).
2. Configure MySQL `slow_query_log` (1s) and rotate `WUC_LOG_DIR` logs.
3. Cap Apache/PHP-FPM workers to leave headroom under `max_connections`.

### P1 — High
4. Roll server-side pagination to admissions applicant queues and accounts student pickers.
5. Move risk rescoring fully to cron; dashboard never recomputes synchronously except on cache miss/expiry.
6. Lazy-load enterprise agriculture/AI services instead of monolithic bootstrap.
7. Adopt Redis sessions before targeting 500+ concurrent authenticated users.

### P2 — Medium
8. JSON-hydrate admin/student dashboard KPI cards after first paint.
9. Narrow remaining hot `SELECT *` list queries (verify schema first).
10. Add synthetic uptime check hitting `/api/health.php` with `X-Health-Token`.

### P3 — Optional
11. HTTP/2 + CDN for static assets in production.
12. Read replica for heavy reports only after write path is stable.

---

## Security Notes

- Caching is limited to non-auth, non-PII aggregates and reference lookups.
- Perf logs omit query strings with tokens and never log bodies/passwords.
- Admin/student pagination endpoints retain existing auth guards.
- Health detail payload still requires `WUC_HEALTH_TOKEN`.
