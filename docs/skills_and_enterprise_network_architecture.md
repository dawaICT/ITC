# Skills and Enterprise Network — Architecture

**Working name:** Skills and Enterprise Network  
**Tagline:** Connecting Skills, Farmers, Businesses and Markets  
**Current codebase path:** `/enterprise/` (upgrade of Skills and Enterprise Portal; not a second app)

## Product shape

One **multi-organization, multi-channel** platform with three service pillars:

| Pillar | Purpose | Code module today |
|--------|---------|-------------------|
| Skills and Careers | Students/graduates ↔ employers | `enterprise/` opportunities, membership, profiles |
| Enterprise Marketplace | Producers/services ↔ customers | Products, services, innovations, public `/opportunities/` |
| Agriculture Market Access | Farmers ↔ buyers + verified prices | `enterprise/agriculture/`, `agriculture_*` services |

## Deployment modes

```env
ENTERPRISE_DEPLOYMENT_MODE=integrated   # WUCPortal hosts auth, student records, first org
ENTERPRISE_DEPLOYMENT_MODE=standalone   # Same codebase; adapters swap host integrations
```

| Layer | Location |
|-------|----------|
| Access channels | Web (`/enterprise/`), public directory, SMS/USSD APIs, agent UI |
| Application services | `includes/enterprise_portal/*_service.php`, `domain_services.php` |
| Adapters | `includes/enterprise_portal/adapters.php` — identity, student, notify, audit, SMS, USSD |
| Infrastructure | MySQL `enterprise_*`, queue rows for SMS, session + RBAC |

WUCPortal is an **integration**, not the owner of the domain model.

## Multi-organization model (Stage 2)

```text
enterprise_organizations
        |
        +-- enterprise_organization_users (user ↔ org ↔ role_in_org)
        |
        +-- organization_id on memberships, opportunities, farmer_profiles (nullable → backfilled)
```

- **Integrated:** `DEFAULT` host org; all legacy rows tagged to primary org.
- **Standalone:** new tenants register as `enterprise_organizations`; officers scoped by `organization_id`.
- Cooperatives/crop buyers become org types, not separate apps.

Functions: `organization_service.php` — `ep_current_organization_id()`, `ep_user_organization_ids()`.

## Identity and multi-role

| Concept | Implementation today | Target |
|---------|----------------------|--------|
| User account | Host `users` table + session | Standalone auth adapter |
| Multiple roles | Multiple `enterprise_memberships` by `membership_type` | + workspace switcher |
| Farmer without email | `enterprise_farmer_profiles.user_id` NULL, `farmer_code`, channels | Unchanged |
| Farmer PIN | Channel user + secure reset flow | USSD PIN hash (extend) |
| Phone as PK | **Never** — `communication_channels.phone_e164` | Unchanged |

## Access channels (single business engine)

| Channel | Status |
|---------|--------|
| Web portal | Live |
| Mobile web / PWA | Partial (responsive Bootstrap; PWA manifest TBD) |
| Public directory | Live (`/opportunities/`) |
| USSD | Simulator + gateway stub; live = external |
| SMS | Mock queue + callback; live = external |
| Agent portal | Live (`/enterprise/agriculture/farmers.php` etc.) |
| Call centre | Manual (same services via officer UI) |
| IVR | Planned (after SMS/USSD stable) |

Config helpers: `platform_identity.php` — `ep_platform_access_channels()`.

## Consent and controlled connection

Shared pattern for employment, enterprise, and agriculture:

```text
Requirement → Verification → Match suggestion → Officer review → Participant consent → Controlled share → Outcome
```

Agriculture: `enterprise_produce_matches` + `EnterpriseReferralService::canShareFarmerContact()`.  
Institution-led employer partners: **documented**; full PHP stack still phase-2.

## Governance split

| Central platform | Tenant organization |
|------------------|---------------------|
| Security, adapters, price types, abuse | Participants, officers, local reports |
| Global audit standards | Assigned farmers / members |
| SMS/USSD contracts | Cooperative/agent operations |

## Branding

- Integrated default UI title: **Skills and Enterprise Portal** (backward compatible).
- Standalone or explicit env: **Skills and Enterprise Network**.

```env
ENTERPRISE_PLATFORM_NAME=
ENTERPRISE_PLATFORM_TAGLINE=
```

## Related docs

- `docs/skills_and_enterprise_network_roadmap.md` — stages vs current
- `docs/agriculture_market_access_architecture.md`
- `docs/enterprise_portal_architecture.md`
- `docs/full_debug_production_readiness.md`
