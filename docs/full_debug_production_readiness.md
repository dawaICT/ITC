# Full Debug Production Readiness

## Decision

**Proceed to controlled agriculture pilot** within Skills and Enterprise Portal.  
**Do not** claim full institution-led employer/partner product complete.  
**Do not** claim live SMS/USSD until credentials and webhook secrets are provisioned.

## Gates

| Gate | Met |
|------|-----|
| Enterprise regression | Yes |
| Portal isolation | Yes |
| Agriculture domain tests | Yes |
| Farmer without web account | Yes |
| Agent scoping (code) | Yes |
| Verified buyer before referral | Yes |
| Listing/demand expiry helpers | Yes |
| Price type/source/expiry rules | Yes |
| SMS queue + mock | Yes |
| USSD simulator | Yes |
| Server-side permissions on agri UI | Yes |
| Critical security fixes in audit | Yes |
| Institution-led employment/partners | **No** |
| Live SMS/USSD | **No** |
| Tested backup restore | **Ops pending** |

## Deployment checklist

1. Run migrations on staging/production clone.
2. Run all three automated test scripts.
3. Set `.env`: deployment mode, agriculture enabled, SMS/USSD mock/simulator until go-live.
4. Grant granular `agriculture.*` RBAC (avoid over-broad registrar shortcuts if policy requires).
5. Configure cron for listing/demand expiry if not using page-triggered expiry.
6. Provision SMS/USSD provider + secrets; re-test callbacks.
7. Execute backup restore drill; record RPO/RTO.

## External dependencies

- SMS aggregator contract and delivery callback URL
- USSD shortcode and HMAC secret
- Human price-source governance process
- Field pilot staffing (agents, price officers, market-access officers)
