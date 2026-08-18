# Skills and Enterprise Portal — Implementation Audit

## Existing systems reused
- Authentication / sessions (`auth_helpers.php`, secure session start)
- Database connection (`db/connect.php`)
- Portal access + portal switch (`portal_access.php`, `portal_switch.php`, `portal_selection.php`)
- Permissions / roles (`permissions.php`, `role_helpers.php`, `user_roles`)
- CSRF (`wuc_csrf_token` / `wuc_validate_csrf`)
- Audit logging (`audit.php` via `ep_audit`)
- Notifications (`notification_integrations.php` / `portal_alerts`)
- Upload validation (`upload_validator.php`)
- QR helper (`qr_helper.php`)
- Cost/readiness math from `enterprise_hub` calculators (shared formulas, new tables)

## Conflicts found
- Legacy exhibition **Skills-to-Trade Hub** already uses tables such as `enterprise_profiles`, `enterprise_items`, `enterprise_costs` under `students/enterprise`, `lecturers/enterprise`, `admin/enterprise`, `showcase/`.
- **Decision:** keep legacy hub tables untouched. New permanent portal uses distinct tables (`enterprise_memberships`, `enterprise_member_profiles`, `enterprise_opportunities`, …) and dedicated `/enterprise/` + `/opportunities/` routes.

## Duplicate code consolidated
- Pricing math reuses `eh_calculate_costs`
- Readiness math reuses `eh_calculate_readiness`
- No second DB connection or auth stack

## Schema decisions
- Portal code: `enterprise`
- Internal key: `enterprise_portal`
- Soft-archive via status/archived_at; no destructive cascades on consent/review/lead/outcome history
- Public identifiers: non-sequential `ENT-XXXXXXXX` codes

## Added / modified (high level)
- Migration: `migrations/20260721_enterprise_portal.php`
- Services: `includes/enterprise_portal/*`
- Participant/reviewer/management UI: `enterprise/*`
- Public directory: `opportunities/*`
- Portal selection membership-aware card
- Direct landing for `enterprise` portal

## Security findings
- Server-side membership + permission guards on `/enterprise/*`
- Public queries filter `status = 'published'`
- Draft media not web-executable (`storage/.../.htaccess` deny)
- Interest rate limiting + duplicate detection + CSRF
- Reviewers blocked from reviewing own records

## Remaining risks
- Low: non-index legacy hub deep links (`students/enterprise/*.php` etc.) may still load until retired; index entry points redirect
- Resolved: RBAC grants via `migrations/20260721_enterprise_portal_rbac.php`
- Resolved: academic nav + legacy indexes redirect to permanent portal; isolation tests in `scripts/test_enterprise_portal_isolation.php`
- Low: AI assistance intentionally disabled by default (`ENTERPRISE_AI_ENABLED=false`)
- Low: browser E2E across all roles still recommended before production cutover

## Gap closures (post-initial build)
- Stale/expiry processor (`stale_service.php`, management/stale.php, CLI script)
- Fair featured rotation (`ep_feature_opportunity_fairly`)
- Public preview before publish (`opportunities/preview.php`)
- Optional AI writing assist (disabled by default)
- Expanded management dashboard and lead/outcome-distinguished reports
- Dev seed gated by `ENTERPRISE_SEED_ENABLED`
- Smoke tests expanded to 47 assertions
