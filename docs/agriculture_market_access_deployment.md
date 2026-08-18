# Agriculture Market Access — Deployment

## Modes
```env
ENTERPRISE_DEPLOYMENT_MODE=integrated
ENTERPRISE_AGRICULTURE_ENABLED=true
AGRICULTURE_OFFICIAL_PRICE_APPROVAL_MODE=dual
ENTERPRISE_SMS_PROVIDER=mock
ENTERPRISE_USSD_PROVIDER=simulator
# ENTERPRISE_SMS_WEBHOOK_SECRET=
# ENTERPRISE_USSD_WEBHOOK_SECRET=
```

Future: `ENTERPRISE_DEPLOYMENT_MODE=standalone` swaps adapters only — no second codebase.

## Staging steps
1. Backup DB.
2. Run `migrations/20260722_enterprise_agriculture_market_access.php`.
3. Confirm smoke + agri tests:
   - `php scripts/test_enterprise_portal_smoke.php`
   - `php scripts/test_agriculture_market_access.php`
4. Set `ENTERPRISE_AGRICULTURE_ENABLED=true`.
5. Grant `agriculture.*` only to market-access staff roles.
6. Exercise UI under `/enterprise/agriculture/`.
7. Use USSD simulator; do not claim live USSD without secrets.

## Production steps
1. Repeat staging with production backup + restore drill documented.
2. Configure live SMS provider + webhook secret when ready.
3. Configure USSD provider + signature secret when ready.
4. Keep Academic / eLearning permissions unchanged.
5. Monitor `enterprise_sms_messages` queue and failed deliveries.

## Backup / restore
- Daily mysqldump of enterprise_* agriculture tables with the full portal DB.
- Test restore on staging before go-live. Document restore time and verification queries (farmer count, published prices, open matches).
