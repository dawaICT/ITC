# Agriculture and Market Access Upgrade — Codebase Audit

**Portal:** Skills and Enterprise Portal (`enterprise`)  
**Date:** 2026-07-22  
**Mode:** Upgrade of existing portal — not a new platform, not exhibition-only.

---

## 1. Executive summary

The Skills and Enterprise Portal already provides voluntary membership, profiles, opportunities (skills/products/services), public directory leads, outcomes, in-app notifications, RBAC, CSRF on portal forms, and audit hooks. Institution-led **partners / matching / referrals** are specified in documentation but **not implemented in PHP or migrations**.

Agriculture and Market Access must **extend** this stack: multi-membership roles (farmer without web login), produce/demand as opportunity types or focused tables, verified commodity prices, SMS/USSD via provider adapters, and deployment mode `integrated` | `standalone`.

---

## 2. Existing components to reuse

| Area | Location | Reuse approach |
|------|----------|----------------|
| Auth / sessions | `includes/auth_helpers.php`, login flows, `$_SESSION` | Keep; add phone+PIN channel identity adapter for farmers without web login |
| Portal context | `includes/portal_context.php`, `portal_selection.php` | Keep enterprise context isolation from academic/eLearning |
| Membership | `enterprise_memberships`, `membership_service.php` | Extend `membership_type`; allow multiple memberships per user |
| Consent | `enterprise_consents`, `consent_service.php` | Extend consent types for farmer referral / market access |
| Profiles | `enterprise_member_profiles`, `profile_service.php` | Keep 1:1 with membership; farmer details in `farmer_profiles` extension (`user_id` nullable) |
| Opportunities | `enterprise_opportunities`, `opportunity_service.php` | Add `produce_listing`, `crop_demand` types + detail side-tables |
| Leads | `enterprise_opportunity_interests`, `interest_service.php` | Reuse for public enquiries; agriculture referrals need consent workflow |
| Outcomes | `enterprise_outcomes`, `outcome_service.php` | Add agriculture outcome stages; never equate match to sale |
| Notifications | `notifications.php` → `wuc_notify_portal` | In-app only today; wrap via NotificationAdapter; SMS separate queue |
| Audit | `ep_audit` → `audit_log_current_user` | Wrap via AuditAdapter |
| Permissions | `permissions.php`, `20260721_enterprise_portal_rbac.php` | Add `agriculture.*` keys; do not grant to students/farmers by default |
| Public directory | `/opportunities/` | Filter new types; price pages separate |
| Settings | `enterprise_portal_settings`, `settings.php` | Add market_access / SMS / USSD / price approval flags |
| Media / uploads | `media_service.php`, `upload_validator.php` | Reuse for price source documents |
| CSRF | Portal forms / `wuc_verify_csrf` patterns | Required on all new POSTs |
| Config | `.env` / `portal_config.php` | Add `ENTERPRISE_DEPLOYMENT_MODE`, SMS/USSD env keys |
| Smoke tests | `scripts/test_enterprise_portal_smoke.php`, `test_enterprise_portal_isolation.php` | Regression baseline |

Already useful enums without new tables:

- Participation goal: `seek_market_access`
- Interest types: `market_linkage`, `product_purchase`, `distribution`
- Outcome types: `market_linkage`, `product_order`, `product_order_completed`
- Category seed: Agriculture and Agro-processing
- Opportunity type `product` (interim produce until dedicated type ships)

---

## 3. Components that require refactoring

1. **`ep_get_membership_for_user()`** — returns latest membership only; must select by `membership_type` / active context once multi-membership is allowed.
2. **`UNIQUE (membership_id)` on profiles** — keep; multi-role = multi-membership, not multi-profile per membership.
3. **`membership_type`** — currently student/graduate oriented; extend for farmer, cooperative, crop_buyer, field_agent, entrepreneur.
4. **Opportunity validation** — `ep_validate_opportunity_payload()` must gain agriculture type rules without breaking existing types.
5. **Bootstrap coupling** — pages call procedural `ep_*` helpers; introduce service classes that wrap the same logic for standalone-capable domain boundary without breaking call sites.
6. **Institution-led partners** — docs only; crop buyers need either early thin partner tables or membership-based `crop_buyer` until partner services exist. MVP: membership + profile + demand tables; thin `EnterprisePartnerService` stub that can later bind to `enterprise_partners`.

---

## 4. Schema conflicts and naming

| Issue | Detail | Resolution |
|-------|--------|------------|
| 1:1 profile ↔ membership | Cannot attach farmer + professional under one membership | Multiple memberships per `user_id` by type |
| Farmer without `user_id` | Spec requires nullable `user_id` | `farmer_profiles.user_id` nullable; internal `farmer_code`; channels table for MSISDN |
| Spec table names vs portal prefix | Spec uses `farmer_profiles`; portal uses `enterprise_*` | Prefer `enterprise_farmer_profiles` (and siblings) for consistency with existing DDL; document aliases in architecture |
| Institution-led tables missing | `enterprise_partners`, referrals, matches not migrated | Do not pretend they exist; implement agriculture match/consent tables for MVP |
| Legacy hub tables | `enterprise_profiles` / `enterprise_items` | Do not use; keep legacy redirect |

---

## 5. Security findings (pre-upgrade)

| Topic | Status | Upgrade requirement |
|-------|--------|---------------------|
| Prepared statements | Generally used in portal services | Mandatory for all new SQL |
| CSRF | Used on major forms | All agriculture POSTs |
| XSS | `htmlspecialchars` / helpers | Escape all outputs |
| RBAC | `ep_can` / `ep_staff_can` | Agriculture permissions server-side; agent assignment checks |
| IDOR | Ownership checks on opportunities | Farmer assignment + buyer verification gates |
| Phone as PK | Not used today | Never use MSISDN as PK |
| AI default | Isolation test expects AI off; live DB may have `ai_enabled=true` | Not blocking agriculture; restore default-off for production policy |
| SMS/USSD | Not present | Signature verification, idempotency, rate limits, mocks |

---

## 6. Duplicate / parallel code

| Item | Risk |
|------|------|
| Legacy Skills-to-Trade Hub (`enterprise_hub`, `/students/enterprise`) | Parallel UI — keep redirects; do not extend hub for agriculture |
| `employer_profiles` (internship employer login) | Separate from enterprise partners — do not merge casually |
| Vonage SMS in accounts reminders | Do not call ad hoc from pages; use `SmsProviderAdapter` |
| Airtel Money gateway | Payments only — out of MVP; not SMS/USSD |

---

## 7. External dependencies

| Dependency | Status | MVP handling |
|------------|--------|--------------|
| SMS provider (e.g. Vonage) | Credentials may be absent | Mock provider + queue + docs |
| USSD shortcode / aggregator | Unavailable in dev | Simulator + interface; no claim of live USSD |
| Official / government price feeds | Must not scrape | Human-controlled entry + source docs + dual approval config |
| Cooperative / field partnerships | Operational | Required for pilot, not code blockers |

---

## 8. Upgrade risks

1. Breaking student membership flows if multi-membership query is wrong.  
2. Exposing farmer phone via public directory or interest payload.  
3. Labelling buyer offers as official prices.  
4. Counting matches as sales in reports.  
5. Blocking portal boot on missing USSD credentials.  
6. Cascade deletes wiping consent/audit history.  
7. Academic/eLearning sidebar or permission bleed.

---

## 9. Recommended migration order

1. Audit + regression baseline (this document + smoke scripts).  
2. `ENTERPRISE_DEPLOYMENT_MODE` + adapters (identity, notify, audit, SMS, USSD).  
3. Agriculture permissions migration.  
4. Commodities / units / communication_channels.  
5. Farmer + cooperative + agent assignment tables.  
6. Produce listings + buyer demands (+ opportunity type extensions).  
7. Produce matches + farmer consent channels.  
8. Price sources + prices (versioned, expiry).  
9. SMS queue + mock; USSD simulator.  
10. Management/participant UI (after services).  
11. Reports that distinguish enquiry / match / delivery / sale.  
12. Security pass + pilot docs + acceptance report.

---

## 10. Regression baseline (2026-07-22)

Command results:

```text
scripts/test_enterprise_portal_smoke.php     → Passed: 46, Failed: 0
scripts/test_enterprise_portal_isolation.php → Passed: 22, Failed: 2
```

Known isolation failures (pre-existing relative to AI settings, not membership/opportunity logic):

- `ENTERPRISE AI disabled by default`
- `AI assist blocked while disabled`

These reflect `ai_enabled` currently true in DB / env from earlier AI work. Agriculture work must not weaken AI default-off policy; re-assert `ai_enabled=false` for production unless explicitly enabled.

See also: `docs/agriculture_market_access_test_plan.md` (regression section).

---

## 11. Standalone capability (target)

`ENTERPRISE_DEPLOYMENT_MODE=integrated` (default): use WUCPortal adapters for users, students, notifications, audit.

`ENTERPRISE_DEPLOYMENT_MODE=standalone` (future): swap IdentityAdapter / StudentDataAdapter / NotificationAdapter / AuditAdapter implementations without rewriting agriculture services.

SMS and USSD always go through provider adapters regardless of mode.
