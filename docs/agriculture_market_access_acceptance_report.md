# Agriculture Market Access — Acceptance Report

**Date:** 2026-07-22  
**Product:** Skills and Enterprise Portal (upgraded)  
**Deployment mode:** `integrated` (standalone-capable via adapters)

## Verdict
MVP domain layer, schema, staff UI, SMS/USSD mocks, and documentation are in place. Existing enterprise smoke tests pass. Live SMS/USSD provisioning remains an external dependency.

## Acceptance checklist

| # | Criterion | Status |
|---|-----------|--------|
| 1 | Existing Skills and Enterprise features still work | Pass — smoke 46/46 |
| 2 | Agriculture is an upgrade, not a disconnected app | Pass — under `/enterprise/agriculture` |
| 3 | Farmers without web accounts | Pass — `user_id` nullable |
| 4 | Agents access only assigned farmers | Pass — service helper `ep_agent_can_access_farmer` |
| 5 | Crop buyers verified before referral | Pass — match gate |
| 6 | Produce listings expire | Pass — `ep_expire_produce_listings` |
| 7 | Buyer demands expire | Pass — match filter on `expires_at` |
| 8 | Matching requires officer review | Pass |
| 9 | Farmer consent before sharing | Pass — `EnterpriseReferralService::canShareFarmerContact` |
| 10 | Current prices have source + date | Pass — publish gates |
| 11 | Expired prices not returned | Pass — `ep_current_commodity_prices` |
| 12 | Buyer offers ≠ official prices | Pass — typed labels |
| 13 | SMS queued + tracked | Pass — mock queue + callback API |
| 14 | USSD idempotent | Pass — `provider_request_id` |
| 15 | Missing USSD credentials do not block portal | Pass — simulator default |
| 16 | Match ≠ sale | Pass — reports separate |
| 17 | Promised ≠ delivered | Pass — outcome_status fields |
| 18 | Academic/eLearning permissions unchanged | Pass — isolated nav/guards |
| 19 | No critical security defects known | Pass for MVP controls; continue pen-test |
| 20 | Backup restoration documented | Pass — deployment doc; restore drill ops-owned |
| 21 | No PHP/SQL errors on domain tests | Pass — agri 36/36 |
| 22 | Required documentation complete | Pass — docs suite |

## Automated tests
- `scripts/test_enterprise_portal_smoke.php` — 46/46
- `scripts/test_agriculture_market_access.php` — 36/36
- Isolation suite may still show 2 known AI-default failures unrelated to agriculture

## Remaining (non-blocking)
- Live SMS/USSD provider credentials and shortcode provisioning
- Async SMS worker process beyond enqueue + mock deliver
- Full multi-membership-by-type selector UX
- Institution-led partner tables (docs-only historically) still deferred; crop buyers use demand + verification status
- Payments/loans/escrow/transport intentionally out of scope
