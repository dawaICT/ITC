# Skills and Enterprise Network — Implementation Roadmap

Maps your 8-stage sequence to **current codebase status** (2026-07-22).

## Stage 1 — Separate platform core

| Item | Status | Notes |
|------|--------|-------|
| Identity adapter | Done | `EnterpriseIdentityAdapter` |
| Notifications adapter | Done | Wraps `wuc_notify_portal` |
| Audit adapter | Done | Wraps `ep_audit` |
| Membership / consent / profiles | Done | Existing services |
| Opportunities / outcomes | Done | Existing services |
| Domain service facades | Done | `domain_services.php` |
| Partners (institution-led) | **Not done** | Docs only |

## Stage 2 — Multi-organization support

| Item | Status | Notes |
|------|--------|-------|
| `enterprise_organizations` | **Done** | Migration `20260723_enterprise_organizations_foundation.php` |
| Org ↔ user links | **Done** | `enterprise_organization_users` |
| `organization_id` on key rows | **Done** | memberships, opportunities, farmers, produce, demands |
| Workspace switcher UI | **Done** | Sidebar + `enterprise/switch_workspace.php` |
| Organization admin UI | **Done** | `enterprise/management/organizations.php` |
| Row-level org scoping (agriculture) | **Done** | Farmers, listings, demands, matches |
| Tenant onboarding self-service | **Not done** | Invitation codes / public signup |
| Row-level org scoping (opportunities) | **Partial** | Column exists; query filters TBD |

## Stage 3 — Standalone authentication

| Item | Status |
|------|--------|
| `ENTERPRISE_DEPLOYMENT_MODE=standalone` | Config only |
| Standalone login / registration | **Not done** — use adapter extension |
| WUCPortal as optional integration | **Done (integrated default)** |

## Stage 4 — Agriculture web workflows

| Item | Status |
|------|--------|
| Farmer profiles | Done |
| Produce listings | Done |
| Buyer demands | Done |
| Price management | Done |
| Manual matching + consent | Done |
| Officer UI | `/enterprise/agriculture/*` |

## Stage 5 — Agent access

| Item | Status |
|------|--------|
| Assisted registration | Done |
| Assigned farmer scoping | Done (services + lists) |
| Offline agent PWA | **Not done** |

## Stage 6 — SMS

| Item | Status |
|------|--------|
| Provider interface + mock | Done |
| Queue + idempotency | Done |
| Live provider | External |
| Structured offer replies | **Partial** (design in vision; implement next) |

## Stage 7 — Limited USSD

| Item | Status |
|------|--------|
| Simulator + adapter | Done |
| Price check + SMS follow-up | Done |
| Listings / offers / accept-decline menus | **Partial** (menus 2–4 stub) |
| Live shortcode | External |

## Stage 8 — IVR and advanced ops

| Item | Status |
|------|--------|
| IVR | Not started |
| Aggregation / logistics / payments | Out of MVP scope |

## Recommended MVP checklist (your §26)

| # | Capability | Status |
|---|------------|--------|
| 1 | Multi-organization accounts | Foundation only |
| 2 | Standalone user registration | Not yet |
| 3 | Institution/cooperative workspaces | Foundation + UI TBD |
| 4–7 | Profiles (student, farmer, employer, buyer) | Partial (farmer strong; buyer via demands) |
| 8–10 | Listings, requirements, matching, consent | Done (agri); employment partners TBD |
| 11 | Verified prices | Done |
| 12–13 | SMS + USSD adapter/simulator | Done (mock/sim) |
| 14–16 | Leads, outcomes, reports, audit | Done (enterprise); agri reports |
| 17 | WUCPortal integration adapter | Done |

## Next engineering priorities (order)

1. Enforce `organization_id` in read/write queries for officers and cooperatives.
2. Organization registration + invitation flows (web).
3. Workspace switcher for multi-role users.
4. Standalone auth registration path (no WUC `users` dependency for farmers/buyers).
5. USSD/SMS structured offer accept/decline (reference codes).
6. Institution-led partner tables aligned with `enterprise_portal_institution_led_connections.md`.
7. PWA manifest + offline agent drafts (Stage 5).

## How to run organization migration

```bash
c:\xampp\php\php.exe migrations\20260723_enterprise_organizations_foundation.php
```

## How to preview standalone branding locally

```env
ENTERPRISE_DEPLOYMENT_MODE=standalone
ENTERPRISE_PLATFORM_NAME=Skills and Enterprise Network
```

Integrated WUCPortal keeps **Skills and Enterprise Portal** as default product name unless env overrides are set.
