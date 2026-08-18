# Skills-to-Trade and Investment Hub — Deployment Guide

**Module:** Skills-to-Trade and Investment Hub (`enterprise_hub`)  
**Local stack:** XAMPP (Apache + MySQL/MariaDB + PHP)  
**Project root:** `c:\xampp\htdocs\wucportal`

---

## 1. Local XAMPP deployment

### 1.1 Start Apache and MySQL

1. Open **XAMPP Control Panel**.
2. Start **Apache**.
3. Start **MySQL**.
4. Confirm both show a running (green) state.
5. Smoke-check: open `http://localhost/wucportal/` in a browser.

If MySQL is stopped, the migration and every hub page that touches the database will fail. Offline calculator unit tests can still run without MySQL (see §1.6).

### 1.2 Environment flags

Copy or edit your local `.env` (never commit secrets). Ensure these keys exist (see `.env.example`):

```env
ENTERPRISE_AI_ENABLED=true
ENTERPRISE_EXHIBITION_MODE=false
```

Optional but recommended for correct absolute QR URLs:

```env
WUC_PUBLIC_BASE_URL=http://localhost/wucportal
```

On an exhibition LAN, set this to the machine’s reachable hostname, for example:

```env
WUC_PUBLIC_BASE_URL=http://192.168.1.50/wucportal
```

or

```env
WUC_PUBLIC_BASE_URL=http://itc-show-pc.local/wucportal
```

Notes:

- `ENTERPRISE_AI_ENABLED=false` disables AI assist; all other hub features continue.
- `ENTERPRISE_EXHIBITION_MODE=true` enables `/showcase/exhibition.php` and exhibition behaviour. You can also toggle exhibition mode from **Admin → Skills-to-Trade Hub → Settings** (`enterprise_settings.exhibition_mode`).
- Database connection continues to use the existing portal `db/connect.php` credentials — do not hard-code passwords into the hub.

### 1.3 Run the migration

From an elevated or normal PowerShell / CMD prompt:

```text
C:\xampp\php\php.exe C:\xampp\htdocs\wucportal\migrations\20260721_enterprise_hub.php
```

Expected output:

```text
enterprise_hub migration completed successfully.
```

The migration is additive and transactional. It creates the `enterprise_*` tables, seeds categories and default settings, and registers RBAC module/permissions when `modules` / `permissions` / `roles` / `role_permissions` exist.

### 1.4 Load demonstration seed data

After a successful migration, seed fictional demo records (all marked `is_demo = 1`):

```text
C:\xampp\mysql\bin\mysql.exe -u root -p wucportal < C:\xampp\htdocs\wucportal\database\enterprise_hub_seed.sql
```

If your local MySQL root has no password:

```text
C:\xampp\mysql\bin\mysql.exe -u root wucportal < C:\xampp\htdocs\wucportal\database\enterprise_hub_seed.sql
```

Adjust the database name if your local schema is not `wucportal`.

The seed is written to be re-runnable for demo items (it deletes previous `is_demo = 1` items and related rows before re-inserting).

### 1.5 Storage directory permissions

Media is stored under:

```text
C:\xampp\htdocs\wucportal\storage\enterprise_hub\
  media\
  thumbnails\
  .htaccess          ← Deny from all (no direct URL access)
```

On first upload, `eh_media_root()` creates `media/` and `thumbnails/` if missing and writes a deny-all `.htaccess`.

Checklist:

- [ ] Apache/PHP process can create files under `storage/enterprise_hub/`
- [ ] Directory is writable by the web server user (on Windows XAMPP this is typically the service account running Apache)
- [ ] Direct browser access to `/wucportal/storage/enterprise_hub/...` is denied
- [ ] Images are only served via `/wucportal/showcase/media.php?id={media_id}` (optional `&thumb=1`)

### 1.6 Automated verification

```text
C:\xampp\php\php.exe C:\xampp\htdocs\wucportal\scripts\test_enterprise_hub_calculators.php
C:\xampp\php\php.exe C:\xampp\htdocs\wucportal\scripts\test_enterprise_hub_workflow.php
C:\xampp\php\php.exe C:\xampp\htdocs\wucportal\scripts\test_enterprise_hub_e2e.php
```

Expect all three scripts to report zero failures (costs/readiness/transitions; DB smoke; full draft→publish→interest journey).

### 1.7 Local URL map

| Surface | URL |
|---------|-----|
| Student hub | `http://localhost/wucportal/students/enterprise/index.php` |
| Lecturer hub | `http://localhost/wucportal/lecturers/enterprise/index.php` |
| Admin hub | `http://localhost/wucportal/admin/enterprise/index.php` |
| Public catalogue | `http://localhost/wucportal/showcase/index.php` |
| Exhibition kiosk | `http://localhost/wucportal/showcase/exhibition.php` (requires exhibition mode) |
| Sample public item | `http://localhost/wucportal/showcase/item.php?code=ENT-DEMO0001` (after seed) |

### 1.8 Backup instructions (local)

**Database**

```text
C:\xampp\mysql\bin\mysqldump.exe -u root -p wucportal > C:\xampp\wucportal-var\backups\wucportal_%DATE:~-4%%DATE:~4,2%%DATE:~7,2%.sql
```

Prefer the portal’s configured `WUC_BACKUP_DIR` if operations scripts are already in use.

**Files**

Back up at least:

- `storage/enterprise_hub/` (uploaded images and thumbnails)
- `.env` (store offline; never in git)

**Before risky changes**

1. Dump the database.
2. Copy `storage/enterprise_hub/`.
3. Note the current git commit / working tree state.

### 1.9 Local deployment checklist

- [ ] Apache started
- [ ] MySQL started
- [ ] `.env` has `ENTERPRISE_AI_ENABLED` and `ENTERPRISE_EXHIBITION_MODE`
- [ ] Optional: `WUC_PUBLIC_BASE_URL` set for correct QR absolute URLs
- [ ] Migration `20260721_enterprise_hub.php` completed successfully
- [ ] Seed `database/enterprise_hub_seed.sql` loaded (for demos)
- [ ] `storage/enterprise_hub` writable; direct web access denied
- [ ] Calculator unit script passes (30/0)
- [ ] Student / lecturer / admin can open hub dashboards when logged in
- [ ] Public showcase lists published items only
- [ ] Exhibition page returns content only when exhibition mode is enabled

---

## 2. Production notes

### 2.1 No secrets in the repository

- Keep real credentials in server-local `.env` or the operations config path (`WUC_OPERATIONS_CONFIG`), not in git.
- `.env.example` may document keys with placeholder values only.
- Do not commit `storage/enterprise_hub/media` uploads or real visitor interest dumps.

### 2.2 Public base URL for QR codes

QR labels call `eh_public_item_url()` → `wuc_public_app_url()`, which prefers:

```env
WUC_PUBLIC_BASE_URL=https://portal.example.edu
```

If unset, the helper falls back to the current HTTP host. For production and exhibition, set an explicit public base so printed QR codes remain stable across kiosks and staff laptops.

Related production URL used elsewhere in the portal:

```env
WUC_PUBLIC_URL=https://portal.example.edu
```

Use both consistently for your deployment; QR generation specifically reads `WUC_PUBLIC_BASE_URL`.

### 2.3 Prepared statements already used

All hub services and page controllers bind parameters through mysqli prepared statements for user-supplied values. Do not introduce raw interpolated SQL when extending the module. Report SQL uses allowlisted report keys only (`eh_report_rows`).

### 2.4 Production checklist (additional)

- [ ] HTTPS enabled end-to-end
- [ ] `APP_ENV=production` and `WUC_DEBUG=0`
- [ ] Database user least privilege (app user vs migrator vs backup — see `.env.example`)
- [ ] Migration applied on production with a maintenance window / backup first
- [ ] Seed data **not** loaded on production unless an explicit demo environment is intended
- [ ] `ENTERPRISE_EXHIBITION_MODE=false` unless actively exhibiting
- [ ] File permissions on `storage/enterprise_hub` hardened (writable by app, not world-executable)
- [ ] Backup job includes DB + `storage/enterprise_hub`
- [ ] Role grants verified for lecturers and enterprise officers
- [ ] Smoke-test publish → public page → interest → admin follow-up after deploy

### 2.5 Rollback posture

The migration is additive. Rollback of a failed *deploy* of code can be a code revert; dropping tables should only be done deliberately after backup, and never against unrelated portal tables.

---

## 3. Quick reference commands

```text
REM Start services via XAMPP Control Panel (Apache + MySQL)

REM Migrate
C:\xampp\php\php.exe C:\xampp\htdocs\wucportal\migrations\20260721_enterprise_hub.php

REM Seed (demo only)
C:\xampp\mysql\bin\mysql.exe -u root wucportal < C:\xampp\htdocs\wucportal\database\enterprise_hub_seed.sql

REM Unit tests (no DB)
C:\xampp\php\php.exe C:\xampp\htdocs\wucportal\scripts\test_enterprise_hub_calculators.php
```

For exhibition-specific steps, see `docs/enterprise_hub_exhibition_setup.md`.
