# Full Debug Test Matrix

| ID | Area | Type | Command / Steps | Expected | Status |
|----|------|------|-----------------|----------|--------|
| T-01 | Smoke | Auto | `test_enterprise_portal_smoke.php` | 0 failures | Pass |
| T-02 | Agriculture | Auto | `test_agriculture_market_access.php` | 0 failures | Pass |
| T-03 | Isolation | Auto | `test_enterprise_portal_isolation.php` | 0 failures | Pass |
| T-04 | Schema | Auto | `verify_debug_baseline.php` | 0 failed checks | Pass |
| T-05 | Farmer no login | Auto | Agri test register farmer | ok without user_id | Pass |
| T-06 | Price governance | Auto | No source rejected | fail create | Pass |
| T-07 | Unverified buyer | Auto | suggest matches | empty | Pass |
| T-08 | SMS idempotency | Auto | duplicate provider ref | replay ok | Pass |
| T-09 | USSD session | Auto | menu option 1 | price path | Pass |
| T-10 | Agent scope | Manual | Agent login, farmers list | only assigned | **Verify in browser** |
| T-11 | CSRF | Manual | POST without token | rejected | **Verify in browser** |
| T-12 | Institution employer match | Manual | N/A | workflow | **Blocked — not built** |
| T-13 | SMS live | Manual | Provider creds | delivery | **Blocked — external** |
| T-14 | USSD live | Manual | Shortcode + secret | gateway | **Blocked — external** |
| T-15 | Backup restore | Ops | mysqldump + restore staging | documented | **Ops drill required** |

Manual browser entry (staff login): http://localhost/wucportal/enterprise/agriculture/index.php
