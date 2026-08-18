# Test plan

## Automated
`scripts/test_enterprise_portal_smoke.php` — membership transitions, opportunity transition rules, calculator, readiness, validation, schema, landing URL.

## Manual
1. Portal isolation: academic login cannot open `/enterprise/index.php` without active membership
2. Opt-in with unchecked consents fails; with consents → pending
3. Pending blocked from tools; active can open dashboard
4. Suspended/withdrawn blocked; public records unpublished
5. Product requires costing + primary image before submit; employment does not require costing
6. Reviewer cannot verify own opportunity; verify does not publish
7. Only published appear in `/opportunities/`
8. Interest creates lead; convert then record outcome
9. CSRF failure rejected; XSS payloads escaped in forms
10. Upload spoofing rejected by MIME checks
