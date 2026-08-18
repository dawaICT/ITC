# Skills-to-Trade and Investment Hub — Implementation Audit

**Module name:** Skills-to-Trade and Investment Hub  
**Internal namespace:** `enterprise_hub`  
**Codebase:** `c:\xampp\htdocs\wucportal`  
**Audit date:** 2026-07-21  
**Primary migration:** `migrations/20260721_enterprise_hub.php`

This audit records what was inspected, reused, added, and left as non-blocking follow-up after integrating the hub into the existing WUCPortal (PHP + MySQLi + XAMPP) stack.

---

## 1. Existing components reused

The module deliberately does **not** introduce a second auth stack, database connection, notification system, or role framework. Shared portal services are loaded from `includes/enterprise_hub/bootstrap.php`.

| Component | Path / symbol | How it is used |
|-----------|---------------|----------------|
| Database connection | `db/connect.php` → `$db` (mysqli) | All hub pages and the migration require the existing connection; prepared statements only |
| Session / student guard | `students/includes/guard.php` | Student enterprise pages |
| Session / lecturer guard | `lecturers/includes/guard.php` | Lecturer enterprise pages |
| Session / admin guard | `admin/includes/admin.php` | Admin enterprise pages |
| Secure session helpers | `includes/auth_helpers.php` (`wuc_secure_session_start`, related) | Public showcase + streamer start sessions safely |
| Role helpers | `includes/role_helpers.php` (`hasRole`, `isAdmin`, `isSystemsAdmin`, role constants) | Capability shortcuts when RBAC seed is incomplete |
| Permissions | `includes/permissions.php` (`wuc_has_permission_unified`, `wuc_permission_denied`) | Staff capability checks via `user_roles` / `role_permissions` / `permissions` |
| Audit logging | `includes/audit.php` → `audit_log_current_user()` wrapped as `eh_audit()` | Profile, item, media, cost, readiness, status, interest, settings, AI events |
| Portal notifications | `includes/notification_integrations.php` → `wuc_notify_portal()` | Status-change and interest notifications to students/staff |
| Upload validation | `includes/upload_validator.php` → `wucValidateUpload($file, 'image')` | JPEG/PNG/WebP MIME + extension checks before store |
| AI generation | `includes/ai_portal.php` → `wuc_ai_generate()` | Controlled drafts via `eh_ai_assist()`; never auto-saves or approves |
| QR codes | `includes/qr_helper.php` → `wuc_qr_svg_data_uri()`, `wuc_public_app_url()` | Printable labels and public item URLs |
| Exhibition mode helper | `includes/exhibition_mode.php` → `wuc_exhibition_mode_enabled()` | Fallback when `ENTERPRISE_EXHIBITION_MODE` / DB setting not set |
| CSRF | `wuc_csrf_token()` / `wuc_validate_csrf()` via `eh_require_post_csrf()` | All state-changing POSTs on student, lecturer, admin, and public interest forms |
| Flash messages | `includes/helpers/flash_helper.php` | Success/error feedback after redirects |
| Nav patterns | Existing student section titles, lecturer nav arrays, admin expandable sidebar groups | Module links added under each portal’s established chrome |
| Security headers | `wuc_security_headers()` where available | Showcase and media streamer |
| Modules / RBAC tables | `modules`, `permissions`, `roles`, `role_permissions` | Migration registers module key `enterprise_hub` and capability keys |

Public showcase pages use the same `$db` connection and hub bootstrap; they do **not** require portal login.

---

## 2. Problems discovered

These issues were identified during audit and implementation. Mitigations are in place where noted.

### 2.1 Student `user_id` is an SID string vs `user_id_db`

Portal sessions expose:

- `$_SESSION['Sid']` — student number string (primary academic identity for students)
- `$_SESSION['user_id_db']` — integer primary key in the users table (used for staff RBAC)

`enterprise_profiles.owner_user_id` is an `INT` and is filled from `eh_current_owner_user_id()` (`user_id_db`). Ownership checks also accept a matching `student_id` / `Sid` via `eh_assert_owns_profile()` / `eh_get_profile_for_owner()`.

**Risk if ignored:** treating `Sid` as an integer owner ID would break ownership and RBAC joins.  
**Mitigation:** dual lookup (SID first, then `owner_user_id`); staff permission checks always use `user_id_db`.

Notifications to students correctly pass `user_id` as the SID string with `user_role => student`, matching existing portal notification conventions.

### 2.2 Mixed primary-key styles across the portal

Legacy tables use mixed conventions (`id`, `SID`/`Sid`, `student_id`, `user_id`, `module_id`, etc.). New hub tables use `BIGINT UNSIGNED AUTO_INCREMENT` `id` keys with explicit foreign keys between hub entities only. Cross-links to students are stored as nullable `VARCHAR(50) student_id` rather than assuming a single FK into a student PK that varies by table.

### 2.3 MySQL may need starting for local demo

Local XAMPP demos fail if Apache or MySQL is not running. During build/verification, calculator unit tests ran offline successfully (`scripts/test_enterprise_hub_calculators.php` — 30 passed), but full end-to-end page flows require MySQL to be started in the XAMPP Control Panel before running the migration and seed.

### 2.4 Additional notes observed

- Demo seed `public_code` values such as `ENT-DEMO0001` match the public URL pattern `ENT-[A-Z0-9]{8}` used by `showcase/item.php`.
- `WUC_PUBLIC_BASE_URL` (used by `wuc_public_app_url()` for QR absolute links) is distinct from `WUC_PUBLIC_URL` in `.env.example`; set `WUC_PUBLIC_BASE_URL` for exhibition LAN/host QR codes.
- Legacy `includes/enterprise_services.php` and `admin/enterprise_audit.php` pre-exist and are **not** the Skills-to-Trade Hub; the new module lives under `includes/enterprise_hub/` and `*/enterprise/`.

---

## 3. Duplicate functions removed or consolidated

**None duplicated.** The hub reuses existing upload validation, AI generation, QR encoding, audit logging, notifications, CSRF, permissions, and role helpers rather than copying them.

New logic is namespaced with the `eh_` prefix inside `includes/enterprise_hub/` and is additive only. No second mysqli connector, auth guard, or notification writer was introduced.

---

## 4. New tables added

Created by `migrations/20260721_enterprise_hub.php` (all prefixed `enterprise_`):

| Table | Purpose |
|-------|---------|
| `enterprise_categories` | Trade/category taxonomy for catalogue and filtering |
| `enterprise_profiles` | Student/graduate/SME enterprise identity |
| `enterprise_items` | Products, services, innovations, ideas, investment opportunities + workflow status |
| `enterprise_item_media` | Image metadata; files on disk under `storage/enterprise_hub/` |
| `enterprise_costs` | Server-side cost/profit calculation snapshot per item |
| `enterprise_readiness_assessments` | Weighted readiness scores and recommendations |
| `enterprise_reviews` | Lecturer/admin decision history |
| `enterprise_interests` | Public expressions of interest + follow-up |
| `enterprise_settings` | Module settings (`ai_enabled`, `exhibition_mode`, limits, theme, etc.) |
| `enterprise_interest_rate_limits` | Hashed IP/email rate-limit window for public form |

**Not created:** `enterprise_audit_logs` — the existing portal `audit_log_current_user()` path is used instead (`eh_audit()`).

Migration also seeds 14 categories, default settings, module row `enterprise_hub`, permission keys, and role grants for `systems_admin`, `lecturer`, `head_of_department`, and `registrar` when those RBAC tables exist.

---

## 5. New files added (major paths)

### Shared services

- `includes/enterprise_hub/bootstrap.php`
- `includes/enterprise_hub/helpers.php`
- `includes/enterprise_hub/permissions.php`
- `includes/enterprise_hub/settings.php`
- `includes/enterprise_hub/status_service.php`
- `includes/enterprise_hub/cost_calculator.php`
- `includes/enterprise_hub/readiness.php`
- `includes/enterprise_hub/repository.php`
- `includes/enterprise_hub/media_service.php`
- `includes/enterprise_hub/interest_service.php`
- `includes/enterprise_hub/notifications.php`
- `includes/enterprise_hub/ai_assistant.php`
- `includes/enterprise_hub/reports.php`

### Database / scripts / storage

- `migrations/20260721_enterprise_hub.php`
- `database/enterprise_hub_seed.sql`
- `scripts/test_enterprise_hub_calculators.php`
- `scripts/test_enterprise_hub_workflow.php`
- `scripts/test_enterprise_hub_e2e.php`
- `storage/enterprise_hub/.htaccess` (deny direct web access)
- `storage/enterprise_hub/media/` and `storage/enterprise_hub/thumbnails/` (created at runtime)

### Student portal (`students/enterprise/`)

- `index.php`, `profile.php`, `profile_edit.php`, `items.php`, `item_create.php`, `item_edit.php`, `item_view.php`, `cost_calculator.php`, `readiness_assessment.php`, `submissions.php`, `interests.php`

### Lecturer portal (`lecturers/enterprise/`)

- `index.php`, `review_queue.php`, `review.php`, `verified_items.php`, `rejected_items.php`

### Admin portal (`admin/enterprise/`)

- `index.php`, `pending_approvals.php`, `review.php`, `published_items.php`, `interests.php`, `interest_view.php`, `reports.php`, `categories.php`, `settings.php`, `qr_label.php`

### Public showcase (`showcase/`)

- `index.php`, `item.php`, `category.php`, `search.php`, `express_interest.php`, `interest_success.php`, `exhibition.php`, `media.php`
- `api/stats.php`
- `includes/helpers.php`, `includes/public_header.php`, `includes/public_footer.php`

### Documentation (this set)

- `docs/skills_trade_hub_implementation_audit.md`
- `docs/enterprise_hub_deployment.md`
- `docs/enterprise_hub_exhibition_setup.md`
- `docs/enterprise_hub_test_plan.md`
- `docs/enterprise_hub_acceptance_report.md`

---

## 6. Existing files modified

| File | Change |
|------|--------|
| `students/includes/navbar.php` | Added **Enterprise and Innovation** section with hub links |
| `lecturers/includes/nav.php` | Added Enterprise Hub dashboard, review queue, verified, rejected links |
| `admin/includes/nav.php` | Added **Skills-to-Trade Hub** sidebar group (dashboard, approvals, published, interests, reports, categories, settings, public showcase) |
| `.env.example` | Added `ENTERPRISE_AI_ENABLED` and `ENTERPRISE_EXHIBITION_MODE` |

No database connection file was changed.

---

## 7. Security controls implemented

- **Prepared statements** for all hub SQL that binds user input.
- **Server-side authorization** via `eh_can()` / `eh_require()` on every protected page; nav visibility is not trusted.
- **Ownership / IDOR guards** (`eh_assert_owns_profile`, `eh_assert_owns_item`) for student mutations.
- **CSRF** on all state-changing POSTs (`eh_require_post_csrf`).
- **XSS** mitigation via `eh_h()` / htmlspecialchars on output; AI text stripped of tags before display.
- **Centralized status transitions** (`eh_transition_item`) — client cannot set arbitrary status.
- **Upload security:** `wucValidateUpload`, dimension limits, polyglot/executable byte checks, random filenames, `chmod` 0640, storage outside web-executable tree, `.htaccess` deny-all, media served only through `showcase/media.php` with status/ownership checks and path-jail (`realpath`).
- **Public interest form:** validation, honeypot, duplicate window, rate limits (hashed IP/email), consent required, CSRF.
- **CSV export:** formula-injection prefixing via `eh_csv_safe()`.
- **AI guardrails:** allowlisted tasks only; no auto-save; no approve/publish; personal identifiers stripped from context; `ENTERPRISE_AI_ENABLED` kill switch; offline fallback text.
- **Audit:** material actions logged through `eh_audit()` → existing audit service.
- **Public query filter:** unpublished items are excluded in SQL (`eh_get_item_by_code(..., publishedOnly: true)`), not merely hidden in the UI.
- **Financial disclaimer** shown with calculator/projections (`eh_disclaimer_finance()`).

---

## 8. Remaining non-blocking recommendations

1. **Set `WUC_PUBLIC_BASE_URL`** for exhibition QR codes so phones resolve the LAN hostname, not `localhost`.
2. **Confirm `user_id_db` is always populated** on student login for every environment; SID fallback covers ownership, but staff-style joins still expect the integer where used.
3. **Optional:** broaden lecturer “review assigned” notifications beyond student-facing status alerts (submission currently surfaces via lecturer queue counts).
4. **Storage backups:** include `storage/enterprise_hub/` in backup jobs alongside the database.
5. **RBAC completeness:** if custom roles need hub access, grant the seeded `enterprise.*` / `enterprise_hub.*` permission keys explicitly.
6. **CDN independence:** public header already aims for local assets; re-check any remaining external font/CDN references before offline exhibition days.
7. **On-site exhibition walkthrough:** confirm projector exhibition mode and live staff/student logins on the show network.

### Verification completed during this cycle (2026-07-21)

- Migration present; 10 `enterprise_*` tables live; 14 categories; demo items seeded.
- Calculator unit tests: 30/30 pass.
- Workflow smoke: 21/21 pass.
- Full E2E (profile → publish → interest → unpublish → AI draft): 33/33 pass.
- Apache HTTP: public showcase pages 200; draft public code 404; anonymous portals redirect to login.
- Systems-admin **demo reset** added on `admin/enterprise/settings.php` (`eh_reset_demo_data()`).

---

## 9. Summary

The Skills-to-Trade and Investment Hub is integrated as an additive `enterprise_hub` module: ten new `enterprise_*` tables, shared `eh_*` services, student/lecturer/admin/public UIs, QR labels, calculators, readiness, interests, reports, exhibition mode (with systems-admin demo reset), and AI assist — all wired through existing WUCPortal auth, CSRF, audit, notifications, upload, QR, and permission infrastructure.

Live verification on 2026-07-21: calculator 30/30, workflow 21/21, E2E 33/33, public HTTP showcase Pass, auth redirects Pass.
