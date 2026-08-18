# Agriculture Market Access — Architecture

## Product
Upgrade of **Skills and Enterprise Portal** — not a separate app.

## Deployment modes
- `ENTERPRISE_DEPLOYMENT_MODE=integrated` (default): WUCPortal hosts auth, notifications, audit.
- `ENTERPRISE_DEPLOYMENT_MODE=standalone` (future): swap adapters only.

## Domain boundary
Business logic lives under `includes/enterprise_portal/`:

| Concern | Module |
|---------|--------|
| Config / mode | `deployment.php` |
| Adapters | `adapters.php` (identity, student, notify, audit, SMS, USSD) |
| Farmers | `agriculture_farmer_service.php` |
| Prices | `agriculture_price_service.php` |
| Produce / demand / match / consent | `agriculture_marketplace_service.php` |
| USSD | `agriculture_ussd_service.php` |
| Existing membership/opportunities | unchanged procedural services |

Pages must call these services — not embed SQL rules.

## Channels
```text
Web / Agent UI ──► Domain services ──► MySQL
USSD simulator/live ──► UssdProviderAdapter ──► same services
SMS mock/live ──► SmsProviderAdapter (queued) ──► enterprise_sms_messages
```

Missing USSD credentials: simulator mode; portal continues.

## Navigation areas (planned UI)
Skills & Employment | Enterprise & Business | Agriculture & Market Access  
Academic / eLearning sidebars remain isolated.
