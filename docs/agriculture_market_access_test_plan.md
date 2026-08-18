# Agriculture and Market Access — Test Plan

## A. Regression baseline (existing portal)

Run before and after every agriculture migration:

```bash
c:\xampp\php\php.exe scripts\test_enterprise_portal_smoke.php
c:\xampp\php\php.exe scripts\test_enterprise_portal_isolation.php
c:\xampp\php\php.exe scripts\test_agriculture_market_access.php
```

### Manual regression checklist

| # | Flow | Expected |
|---|------|----------|
| R1 | Student opt-in `/enterprise/join.php` | Pending membership + consent row |
| R2 | Management approve membership | Active + portal access grant |
| R3 | Profile create/edit | Saves under membership |
| R4 | Opportunity create (product/service) | Draft → submit → review → publish |
| R5 | Public directory interest | Lead row + owner notification |
| R6 | Outcome record | Outcome row; not treated as sale unless type says so |
| R7 | Academic nav | No agriculture bleed; enterprise via portal selection |
| R8 | eLearning permissions | Unchanged |
| R9 | CSRF on join / opportunity forms | Reject bad token |
| R10 | Withdraw / suspend | Access blocked |

### Automated baseline snapshot (2026-07-22)

- Smoke: **46/46 pass**
- Isolation: **22/24 pass** (2 AI-default failures — known; restore AI default-off for production)

---

## B. Agriculture tests (implement as code lands)

### Farmers
- Agent-assisted registration without web account (`user_id` null).
- Duplicate phone flagged (normalized MSISDN).
- Agent cannot open unassigned farmer.
- Consent recorded with channel + actor.

### Produce
- Quantity > 0; invalid unit rejected.
- Expired listing excluded from match suggestions.
- Major quantity change → reconfirm flag.

### Buyers / demands
- Unverified / suspended buyer cannot receive referrals.
- Demand expiry excludes from matching.

### Prices
- Missing source / effective date → cannot publish.
- Expired prices not returned by current-price API.
- Buyer offer never labelled official/government.
- Correction creates new version.
- Dual approval when `AGRICULTURE_OFFICIAL_PRICE_APPROVAL_MODE=dual`.

### SMS
- Mock provider enqueue success.
- Retry / permanent failure paths.
- Duplicate delivery callback idempotent.
- Rate limit.

### USSD
- Simulator session start/menu/price.
- Expired session.
- Duplicate request id ignored.
- Invalid PIN.
- Signature failure on production adapter.
- Missing credentials: portal still boots.

### Privacy
- Buyer cannot list all farmers.
- Contact shared only after consent.
- Internal notes private.

### Reporting integrity
- Price check ≠ sale.
- Match ≠ transaction.
- Accepted offer ≠ delivery.
- Promised ≠ delivered.
