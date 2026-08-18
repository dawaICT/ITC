# Full Debug Baseline

**Date:** 2026-07-22  
**Branch:** `master` (many modified/untracked enterprise + agriculture files)  
**Base URL:** http://localhost/wucportal/

## Environment

| Item | Value |
|------|--------|
| PHP | 8.0.30 (XAMPP CLI) |
| Database | MySQL/MariaDB via `db/connect.php` |
| Deployment | `ENTERPRISE_DEPLOYMENT_MODE=integrated` |
| Agriculture | `agriculture_enabled` true (settings seed) |
| SMS | `ENTERPRISE_SMS_PROVIDER=mock` |
| USSD | `ENTERPRISE_USSD_PROVIDER=simulator` |
| Live USSD/SMS | **Unavailable** (no webhook secrets / provider creds) |

## Schema status

Agriculture migration applied (verified by `scripts/verify_debug_baseline.php`):

- `enterprise_farmer_profiles`, produce/demand/match/price tables, SMS/USSD tables
- Core enterprise: `enterprise_memberships`, `enterprise_opportunities`

## Automated test baseline (post-audit run)

| Script | Result |
|--------|--------|
| `scripts/test_enterprise_portal_smoke.php` | 46/46 pass |
| `scripts/test_agriculture_market_access.php` | 39/39 pass |
| `scripts/test_enterprise_portal_isolation.php` | 24/24 pass |
| `scripts/verify_debug_baseline.php` | 0 failed checks |

## Known configuration notes

- **Enterprise AI:** DB may have `ai_enabled=true` from legacy hub seed; production policy expects off unless `ENTERPRISE_AI_ENABLED=true`. Isolation tests force env `false` for AI checks.
- **Institution-led partners/referrals (employment):** Documented in `docs/enterprise_portal_institution_led_connections.md` — **not fully implemented in PHP**; agriculture uses `enterprise_produce_matches` + consent for MVP.

## External integrations

| Integration | Dev status | Production |
|-------------|------------|------------|
| SMS outbound | Mock queue | Requires provider + `ENTERPRISE_SMS_WEBHOOK_SECRET` |
| USSD | Simulator + API stub | Requires provider + `ENTERPRISE_USSD_WEBHOOK_SECRET` |
| Ollama/local AI | Separate ops issue | Optional |

## Initial risk classification

| Area | Level |
|------|--------|
| Agriculture domain services | Medium (MVP complete, needs field pilot) |
| Institution-led employer matching | High gap (docs-only) |
| SMS callback without secret | Mitigated (503 if secret unset) |
| Agent farmer scoping | Medium (fixed in services + UI lists) |

## Probe command

```bash
c:\xampp\php\php.exe scripts\verify_debug_baseline.php
```

No secrets are stored in this document.
