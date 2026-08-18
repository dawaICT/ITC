# Full Debug Security Report

## Summary

No **critical** open defects found in agriculture MVP after fixes. Residual risk is configuration and incomplete institution-led partner stack.

## Controls verified

| Control | Status |
|---------|--------|
| CSRF on agriculture POST forms | Pass |
| Server-side `ep_staff_can` on agriculture pages | Pass |
| Farmer list scoping for agents | Pass (post-fix) |
| Verified buyer gate for matches | Pass |
| Consent before contact share flag | Pass (`EnterpriseReferralService`) |
| SMS callback signature | Required when secret set; 503 when unset |
| USSD signature | Required when live creds configured |
| Prepared statements (new agriculture SQL in services) | Mixed: services use sprintf with escape — recommend migrating hot paths to prepared statements (medium debt) |
| SQL injection on agriculture UI | Low risk (staff-only, escaped literals) |

## Findings

| ID | Severity | Finding | Status |
|----|----------|---------|--------|
| SEC-01 | High | Unscoped farmer directory for agents | **Fixed** — scoped lists + listing guard |
| SEC-02 | Medium | SMS callback open when secret empty | **Fixed** — 503 |
| SEC-03 | Medium | `agriculture.*` role shortcut grants all agri perms to registrar/HOD/dean | Accepted MVP; tighten RBAC per officer role in pilot |
| SEC-04 | High | Institution-led referral consent for students/employers | **Open** — not implemented |

## Recommendations before public pilot

1. Assign granular `agriculture.*` permissions per job function.
2. Enable SMS/USSD webhook secrets in staging and pen-test callbacks.
3. Complete institution-led partner tables or formally defer with UI hiding.
