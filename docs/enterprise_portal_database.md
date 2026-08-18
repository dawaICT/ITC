# Skills and Enterprise Portal — Database

## Migration
`migrations/20260721_enterprise_portal.php`

## Core tables
- `enterprise_memberships` — opt-in lifecycle
- `enterprise_consents` — versioned consent history
- `enterprise_member_profiles` — professional/enterprise profile
- `enterprise_skills`
- `enterprise_categories` (shared/created if missing)
- `enterprise_opportunities` — type-specific opportunity records + `public_code`
- `enterprise_media`
- `enterprise_opportunity_costs`
- `enterprise_opportunity_readiness`
- `enterprise_opportunity_reviews`
- `enterprise_opportunity_interests` — leads
- `enterprise_outcomes`
- `enterprise_complaints`
- `enterprise_portal_settings`
- `enterprise_interest_throttle`

## Indexes
Status, owner/profile, public_code (unique), lead_status, assignment, publication indexes are created in the migration.

## Notes
Legacy `enterprise_profiles` / `enterprise_items` remain for the exhibition hub and are not used by this portal.
