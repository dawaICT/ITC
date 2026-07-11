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
5. Avoid breaking existing working modules.

Never mix academic portal logic with eLearning portal logic unless there is an intentional bridge.