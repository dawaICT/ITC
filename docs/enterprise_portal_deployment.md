# Deployment

## Local (XAMPP)
1. Ensure DB connection works via `db/connect.php`
2. Run migration:
   `c:\xampp\php\php.exe migrations\20260721_enterprise_portal.php`
3. Confirm portal row `enterprise` exists in `portals`
4. Optional env (or use `enterprise_portal_settings`):
   - `ENTERPRISE_PORTAL_ENABLED=true`
   - `ENTERPRISE_MEMBERSHIP_APPROVAL_MODE=manual`
   - `ENTERPRISE_ALLOW_CURRENT_STUDENTS=true`
   - `ENTERPRISE_ALLOW_GRADUATES=true`
   - `ENTERPRISE_PUBLIC_DIRECTORY=true`
   - `ENTERPRISE_AI_ENABLED=false`
5. Smoke test:
   `c:\xampp\php\php.exe scripts\test_enterprise_portal_smoke.php`
6. Open `http://localhost/wucportal/portal_selection.php` and `/opportunities/`

## Production
- Run the same migration during a maintenance window
- Set public base URL if needed (`WUC_PUBLIC_BASE_URL`) for QR/canonical links
- Assign enterprise permissions to reviewer/management roles
- Keep legacy exhibition hub paths only if still required; communicate that `/enterprise/` is the permanent portal
- Verify `storage/enterprise_portal/media/.htaccess` denies direct execution
