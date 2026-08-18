# Permissions

Permission keys seeded by migration (examples):
- `enterprise.portal.join|access|withdraw`
- `enterprise.profile.manage_own`, `enterprise.skills.manage_own`
- `enterprise.opportunity.create|edit_own|submit|archive_own`
- `enterprise.interests.view_own`
- `enterprise.review.*`
- `enterprise.memberships.manage`, `enterprise.approve`, `enterprise.publish|unpublish|feature`
- `enterprise.interests.manage`, `enterprise.leads.assign`, `enterprise.outcomes.manage`
- `enterprise.reports.view`, `enterprise.settings.manage`, `enterprise.audit.view`

Runtime checks: `ep_can()` / `ep_staff_can()` with systems-admin bypass and role shortcuts for lecturer (review) and registrar/HOD/dean (management) when RBAC rows are incomplete.
