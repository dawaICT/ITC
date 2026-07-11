# WUCPortal Core Rules

This project is WUCPortal, a PHP and MySQLi-based academic, admissions, staff, student, lecturer, and eLearning portal for Industrial Training Centre.

Always inspect the existing codebase before making changes.

Do not assume table names, column names, paths, or roles. Verify them from actual files and database queries already used in the project.

Do not rewrite the whole project unless explicitly requested.

Preserve the current PHP/MySQLi structure unless the user asks for Laravel or framework migration.

When fixing bugs:
1. Identify the exact file.
2. Explain the root cause.
3. Make the smallest safe fix.
4. Check related files that may use the same logic.
5. Avoid breaking existing working modules.# PHP and MySQLi Security Rules

Use secure PHP practices.

For database operations:
- Prefer prepared statements.
- Never concatenate raw user input into SQL.
- Validate and sanitize request data.
- Use CSRF protection for forms that create, update, or delete records.
- Check session role before allowing access to protected pages.
- Do not expose database errors to normal users.
- Log technical errors where appropriate.

For authentication:
- Keep student, lecturer, staff, registrar, HOD, admin, and super admin permissions separate.
- Do not allow access only because a user is logged in.
- Always verify the role and module permission.
- Logout should destroy the session properly.

For destructive actions:
- Require confirmation.
- Use POST, not GET.
- Validate CSRF token.

Never mix academic portal logic with eLearning portal logic unless there is an intentional bridge.