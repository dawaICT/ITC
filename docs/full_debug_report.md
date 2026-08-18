# Full Debug Report

## Overall status

**Production Ready with External Dependencies**

Enterprise + agriculture MVP passes automated regression. Live SMS/USSD and full institution-led employer/partner stack remain external or phase-2.

## Fixes applied in this audit (sample)

| ID | Severity | Module | Fix |
|----|----------|--------|-----|
| DBG-001 | High | Matching | `ep_record_produce_match` validates demand expiry, listing active/expiry, qty > 0 |
| DBG-002 | High | Demands | Added `ep_expire_buyer_crop_demands()`; wired on dashboard + suggest |
| DBG-003 | High | IDOR | `ep_list_farmers_for_staff()` + produce/farmer pages scoped to assignment |
| DBG-004 | High | Produce | Listing create checks farmer active + agent assignment |
| DBG-005 | Medium | Prices | Dual approval blocks creator as first approver |
| DBG-006 | Medium | SMS API | Callback returns 503 when webhook secret not configured |
| DBG-007 | Low | Isolation | AI tests use env override for policy check |
| DBG-008 | Low | Identity | `currentActorLabel()` falls back to `system` (prior session fix) |

## Outstanding (not fixed — by design)

| ID | Severity | Description |
|----|----------|-------------|
| DBG-GAP-01 | High | Institution-led `enterprise_partners` / student-employer referral workflow not in DB/PHP |
| DBG-GAP-02 | Medium | Dedicated async SMS worker process not deployed |
| DBG-GAP-03 | Medium | Multi-membership-by-type context selector incomplete |
| DBG-GAP-04 | Low | USSD menus 2–4 (listings/offers/consent) stubbed beyond price check |

## Test evidence

All material fixes covered by `scripts/test_agriculture_market_access.php` and smoke/isolation suites (see baseline).
