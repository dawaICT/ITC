**Purpose**

This file gives concise, project-specific guidance to AI coding agents working on the WUC Portal codebase (PHP + React assets + SQL). Focus on immediate tasks: local dev, build, DB updates, and common code locations.

**Big picture**
- **Stack:** procedural PHP served by XAMPP/Apache + MySQL; React-based frontend assets built with `webpack` and consumed by PHP pages.
- **Runtime:** PHP pages live under the repository root (serve via `htdocs`), e.g., visit `http://localhost/wucportal` after placing the folder in XAMPP.
- **Where to look:** main entry points: `index.php`, `layout.php`; DB-related scripts: `database_setup.sql`, `execute_updates.php`; front-end build: `package.json` (scripts `dev`/`build`).

**Developer workflows (concrete commands)**
- Install PHP deps: run `composer install` in the project root (see `composer.json`).
- Install JS deps and build frontend assets:
  - `npm install`
  - `npm run dev` (watch; uses `webpack --mode development --watch`)
  - `npm run build` (production build)
  - The webpack output is consumed by PHP pages (assets appear in `dist/`). See `package.json` for exact scripts.
- Apply DB updates (use on a dev DB only): backup the DB, then run `php execute_updates.php` from the project root to create/drop tables and insert sample data. See `execute_updates.php` and `database_setup.sql` for SQL used.

**Project-specific conventions & patterns**
- Naming:
  - CLI-style utilities start with `cli_` (e.g., `cli_add_column.php`).
  - Database fixes/migrations use `fix_*.php` and SQL files like `create_fee_structure.sql` / `fix_tables.sql`.
- Auth & sessions: pages start with `session_start()` and rely on session keys (see `index.php`, `layout.php`). Use the `?debug=true` query param on `index.php` for minimal debug output.
- Mixed assets: front-end React code is built by webpack and then referenced by PHP templates; edits to React require `npm run build` to be visible to PHP pages.

**Integration points & important files**
- `index.php` — public entry page and debug flag. Check for session usage and included CSS/JS.
- `layout.php` — site-wide wrapper, sidebar and main content injection point.
- `db/connect.php` — central DB connection (required by `execute_updates.php` and many pages).
- `execute_updates.php` and `database_setup.sql` — canonical DB creation/seed operations.
- `package.json` — JS dev/build scripts; look here for how frontend is compiled.
- `composer.json` — PHP package dependencies (e.g., `phpmailer`, `vonage/client`).

**What to avoid / assumptions to check**
- Don't assume an ORM or migration framework — DB migrations are ad hoc SQL and PHP scripts; always inspect `execute_updates.php` and SQL files before making automated changes.
- No unit test framework found; do not add tests that require infrastructure not present without consulting the maintainer.

**Examples of actionable changes an agent can do safely**
- Implement small UI tweaks: update CSS in `css/` or rebuild the React assets and run `npm run build`.
- Add a new DB column: create a `fix_*.sql` or `fix_*.php` script, and update `execute_updates.php` style scripts. Provide explicit rollback instructions in the PR description.
- Update third-party PHP libs: modify `composer.json` then run `composer update` locally and test via the web UI.

**If you need more context ask for**
- Which environment the maintainer uses (XAMPP/MAMP/docker) and the DB credentials to run `execute_updates.php` locally.
- Where built frontend artifacts are deployed in production (so we don't change output paths accidentally).

Please review this file and tell me which sections you want expanded (DB migration examples, webpack config mapping, or common PHP patterns to refactor).
