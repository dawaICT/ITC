# Skills and Enterprise Portal — Architecture

## Separation
The Skills and Enterprise Portal is a **separate portal context** (`enterprise`), not embedded in Academic or eLearning.

| Concern | Location |
|---|---|
| Participant / reviewer / management | `/enterprise/` |
| Public directory | `/opportunities/` |
| Portal selection card | `portal_selection.php` |
| Legacy exhibition hub (coexists) | `students/enterprise`, `admin/enterprise`, `showcase` |

## Portal key
- Public name: **Skills and Enterprise Portal**
- Portal code: `enterprise`
- Module key: `enterprise_portal`

## Request flow
1. Login (existing auth)
2. Portal selection — membership-aware enterprise card
3. Voluntary opt-in (`/enterprise/join.php`) → pending
4. Management approval → active + `user_portal_access` grant
5. Participant tools under `/enterprise/` with enterprise sidebar only
6. Review → admin approve → publish
7. Public directory → interest → lead → outcome
8. Institution-led connections (partners → external opportunities → match → consent → referral → outcome) — see [`enterprise_portal_institution_led_connections.md`](enterprise_portal_institution_led_connections.md)

## Layout
`enterprise/includes/layout.php` renders a dedicated sidebar. Switch Portal returns to `portal_selection.php`. Academic nav is not loaded.
