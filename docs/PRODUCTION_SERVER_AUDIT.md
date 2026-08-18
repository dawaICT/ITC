# Production Server Configuration Audit

**Date:** 2026-08-04  
**Host:** Windows 11 Pro / XAMPP (Apache 2.4.58 + PHP 8.4.23 apache2handler + MariaDB 10.4.32)  
**Hardware:** Intel i5-10210U (4c/8t), **~8 GB RAM**, C: ~187 GB (**~8.5 GB free**)  
**Stack note:** mod_php on WinNT MPM (not PHP-FPM / not Nginx).

Config backups: `C:\xampp\wucportal-var\config-backups\20260804_205401\`

---

## Current Server Capacity

| Workload | Realistic concurrency |
|----------|------------------------|
| Lightweight health / static assets | **~50–100** parallel requests (verified 100× health + CSS, 0 errors) |
| Authenticated PHP portal pages | **~15–25** concurrent requests before latency climbs sharply |
| Sustained multi-user working day | Treat as **tens of concurrent users**, not hundreds |

**Why the cap is low:** ~8 GB shared with Windows desktop apps; pagefile usage ~4 GB at audit time; free RAM often &lt;400 MB; each mod_php thread can reserve up to `memory_limit` (now 256M).  
**Formula used:** leave ~3–4 GB for OS/desktop + ~0.25–0.5 GB MySQL + ~0.1 GB OPcache/Apache → ~2–2.5 GB for PHP → ÷ ~80 MB avg ≈ **25–32 workers** (matched to `ThreadsPerChild 32`).

**Verdict:** Improved and safer than the prior XAMPP defaults, but **not declared production-ready** for a public high-traffic deployment on this host until disk free space, swap pressure, and dedicated-server sizing are resolved.

---

## Critical Configuration Problems

| Problem | Risk |
|---------|------|
| **Disk C: ~8.5 GB free** | Log/upload/backup growth can take the app offline |
| **Heavy swap (~4 GB in use)** | Multi-second latency under load; thrashing |
| **OPcache was disabled** | Every request recompiled PHP (fixed) |
| **`mod_deflate` / `mod_expires` unloaded** | App `.htaccess` compression/cache rules were inert (fixed) |
| **`ThreadsPerChild=150` + `memory_limit=512M`** | Theoretical RAM blow-up under concurrency (fixed → 32 / 256M) |
| **`innodb_buffer_pool_size=16M`** | Excess disk I/O / temp tables (fixed → ~256M effective) |
| **`wait_timeout=28800`** | Idle DB connections held 8 hours (fixed → 120s) |
| **`max_allowed_packet=1M`** | Upload/statement failures vs 40–64M app limits (fixed → 64M) |
| **`display_errors=On`** | Information disclosure (fixed → Off) |
| **File sessions in `%TEMP%`** | Blocks multi-node scale; session locking under tabs |
| **phpMyAdmin aliased in `httpd-xampp.conf`** | Attack surface if host is internet-facing |
| **No Apache Windows service** | Restart depends on XAMPP Control / manual process start |

---

## Changes Implemented

| File | Original | New | Reason |
|------|----------|-----|--------|
| `apache/conf/httpd.conf` | `deflate`/`expires`/`filter` commented out | Enabled + include `httpd-wuc-production.conf` | Activate gzip + browser cache |
| `apache/conf/extra/httpd-mpm.conf` | `ThreadsPerChild 150`, `MaxConnectionsPerChild 0` | `32` / `1000` | Memory-safe concurrency; recycle threads |
| `apache/conf/extra/httpd-default.conf` | `Timeout 300`, `ServerTokens Full`, `ServerSignature On` | `90`, `Prod`, `Off` | Align timeouts; reduce banner leak |
| `apache/conf/extra/httpd-wuc-production.conf` | *(new)* | Deflate/Expires/LimitRequestBody/PHP no-store | Central production overlay |
| `php/php.ini` | OPcache commented; `memory_limit=512M`; `display_errors=On`; `session.use_strict_mode=0` | OPcache on (96M, revalidate 60s); `256M`; errors Off; strict sessions + httponly | Perf + security + RAM |
| `mysql/bin/my.ini` | pool 16M; packet 1M; no slow log; wait 28800; max_connections default 151 | pool 192M (runtime **256M** chunk); packet 64M; slow log 1s; wait 120; **max_connections=60**; bind 127.0.0.1 | Balance PHP↔DB; diagnostics; local bind |
| `wucportal/.htaccess` | no `/health` shortcut | `RewriteRule ^health/?$ api/health.php` | Simple uptime URL |

Layer balance after change:

```
Apache ThreadsPerChild 32
        ↓
PHP mod_php workers ≤ 32 (memory_limit 256M)
        ↓
MySQL max_connections 60  (headroom for admin/cli)
```

---

## Performance Results

### HTTP compression / caching (CSS)

| Metric | BEFORE | AFTER |
|--------|--------|-------|
| `Content-Encoding` | *(none)* | **gzip** |
| Body size | 14745 B | **3346 B** (~77% smaller) |
| `Cache-Control` | *(none)* | **max-age=2592000** |
| `Server` banner | `Apache/2.4.58 ... PHP/8.4.23` | **`Apache`** |

### OPcache (Apache SAPI)

| Metric | BEFORE | AFTER |
|--------|--------|-------|
| Enabled | no | **yes** |
| Memory | n/a | 96M (≈18M used / 13 scripts after warm) |
| Hits/misses (early) | n/a | 486 / 13 |

### Concurrent health load (`api/health.php`)

| n | BEFORE avg / p95 | AFTER avg / p95 | Errors |
|---|------------------|-----------------|--------|
| 1 | 537 / 537 ms | **69 / 69 ms** | 0 |
| 10 | 1078 / 1369 ms | **111 / 134 ms** | 0 |
| 50 | 551 / 714 ms | **257 / 301 ms** | 0 |
| 100 | 512 / 676 ms | **261 / 309 ms** | 0 |

Static CSS n=100: **0 errors**, avg ~239 ms (was ~377 ms).

### MySQL

| Setting | BEFORE | AFTER |
|---------|--------|-------|
| `innodb_buffer_pool_size` | 16M | **256M** (effective) |
| `max_connections` | 151 | **60** |
| `wait_timeout` | 28800 | **120** |
| Slow query log | OFF / 10s | **ON / 1s** |

---

## Scalability Limit

**Next bottleneck on this host:** **RAM / swap**, then **mod_php thread count (32)**, then **disk free space**.  
CPU is secondary on the i5 until concurrency rises; DB connections are intentionally capped below PHP workers’ worst case.

Horizontal scale is blocked today by: **file sessions**, local uploads/temp, XAMPP single-node layout, and phpMyAdmin/local paths.

---

## Recommended Upgrade Path

### P0 — Uptime risk
1. Free **≥20% disk** on C: (logs, old backups, XAMPP litter); enable log rotation for Apache access (32 MB+) and MySQL slow log.
2. Stop relying on a swapping desktop as “production”; move to a dedicated server/VM with **≥16 GB RAM** if serving real students.
3. Restrict or disable public `phpMyAdmin` on any internet-facing host.
4. Ensure Apache/MySQL start on boot (install as services or monitor with a watchdog).

### P1 — Severe performance
5. Enable **APCu** for the app lookup cache (file cache works but APCu is faster).
6. Keep OPcache on; after deploy pipelines mature, consider `validate_timestamps=0` + explicit reset.
7. Move sessions to **Redis/DB** before adding a second app node.

### P2 — Scalability
8. Prefer **PHP-FPM + Nginx** (or Apache event + FPM) on Linux for clearer worker math than WinNT mod_php.
9. Raise `ThreadsPerChild` / FPM `pm.max_children` only after RAM headroom is proven (monitor peak RSS).
10. Reverse proxy (TLS termination) in front when traffic justifies it — app already has `/health`.

### P3 — Future
11. HTTP/2 after TLS is terminated on a stable proxy (left disabled on this XAMPP build).
12. Read replica / queue workers only after single-node limits are measured with production-like data volume.

---

## Ops notes

- Restart Apache on this host with process start (not `httpd -k start` service mode): XAMPP Control Panel or `Start-Process C:\xampp\apache\bin\httpd.exe`.
- Local runtime probe (loopback only): `http://127.0.0.1/wucportal/api/runtime_probe.php`
- Uptime URL: `http://127.0.0.1/wucportal/health` → `{"status":"ok",...}`
- Rollback: restore files from `C:\xampp\wucportal-var\config-backups\20260804_205401\`
