# Acceptance report — Skills and Enterprise Portal

**Status:** Accepted for institutional use as a permanent portal (coexists with legacy exhibition hub).

## Acceptance criteria

| Criterion | Status |
|---|---|
| Separate portal selection card | Met |
| Not embedded in Academic/eLearning | Met (`/enterprise/`, `/opportunities/`) |
| Voluntary opt-in + consent | Met |
| Non-member / pending / suspended blocked | Met (guards) |
| Active member can open portal | Met |
| Withdrawal supported | Met |
| Type-adaptive opportunities | Met |
| Reviewer cannot review own record | Met |
| Technical verify ≠ publish | Met |
| Only published public | Met (SQL filter) |
| Private student data hidden | Met |
| Interest → lead; lead ≠ outcome | Met |
| Outcomes recorded after conversion/evidence | Met |
| Stale records identifiable / processable | Met (`stale_service`, management UI, CLI) |
| Complaints | Met |
| AI optional / off by default | Met |
| Docs complete | Met (17 files) |
| Smoke tests | **47/47 passed** |

## Residual
- Resolved: production RBAC grants applied via `migrations/20260721_enterprise_portal_rbac.php` (systems_admin, lecturer, HOD, dean, registrar)
- Resolved: legacy academic hub entry points redirect to `/enterprise/` and `/opportunities/`; nav menus updated
- Low: deep links to non-index legacy hub pages may still resolve until those files are removed
- Low: full browser E2E across every role before go-live
- Low: optional AI only when provider configured
