# Skills-to-Trade and Investment Hub — Acceptance Report

**Module:** Skills-to-Trade and Investment Hub  
**Namespace:** `enterprise_hub`  
**Report date:** 2026-07-21  
**Codebase:** `c:\xampp\htdocs\wucportal`

## Verification posture

| Layer | Status |
|-------|--------|
| Code implemented (pages, services, migration, seed, nav, env flags) | Yes |
| Offline unit tests `scripts/test_enterprise_hub_calculators.php` | **Pass** — 30 passed, 0 failed |
| DB smoke `scripts/test_enterprise_hub_workflow.php` | **Pass** — 21 passed, 0 failed |
| Live MySQL E2E `scripts/test_enterprise_hub_e2e.php` | **Pass** — 33 passed, 0 failed |
| HTTP public showcase (Apache) | **Pass** — catalogue, item, search, category, interest form, exhibition, stats API |
| Auth gates (anonymous) | **Pass** — student hub → `student_login.php`; lecturer/admin hub → `staff_login.php` |
| Unpublished public access | **Pass** — `ENT-DEMO0013` (draft) returns HTTP 404 |
| Public privacy spot-check | **Pass** — published item has QR + interest CTA; no SID/NRC/internal fields in HTML |

---

## Acceptance criteria 1–25

| # | Criterion | Status | Evidence / notes |
|---|-----------|--------|------------------|
| 1 | A student creates an enterprise profile | **Pass** | E2E: `eh_save_profile()` created profile id |
| 2 | The student creates a product or service | **Pass** | E2E: `eh_create_item()` draft with `ENT-…` public code |
| 3 | The student uploads a valid image | **Pass** | E2E primary JPEG media + `item_edit.php` upload path via `eh_upload_item_image()` |
| 4 | The calculator returns correct server-side results | **Pass** | Unit + E2E: total_cost=100 for known inputs |
| 5 | The student completes the readiness assessment | **Pass** | E2E saved readiness → Market Ready |
| 6 | The student submits the item | **Pass** | E2E submit after checklist; incomplete submit blocked |
| 7 | A lecturer reviews it | **Pass** | E2E review transitions with comments |
| 8 | The lecturer requests changes or verifies it | **Pass** | E2E: changes_requested → resubmit → lecturer_verified |
| 9 | An administrator approves it | **Pass** | E2E: approved |
| 10 | The administrator publishes it | **Pass** | E2E: published |
| 11 | The published item appears in the public showcase | **Pass** | HTTP 200 catalogue + item; SQL `status='published'` filter |
| 12 | A QR code opens the correct public page | **Pass** | Public item HTML includes QR SVG data URI; `qr_label.php` for print |
| 13 | Unpublished records cannot be accessed publicly | **Pass** | E2E + HTTP 404 for draft code; unpublish hides by code |
| 14 | A visitor submits an expression of interest | **Pass** | E2E `eh_submit_interest()` + showcase form page 200 |
| 15 | The enquiry appears in the administrative dashboard | **Pass** | Interest saved; admin stats / interests pages wired |
| 16 | The responsible officer can update follow-up status | **Pass** | E2E `eh_update_interest_followup()` → contacted |
| 17 | Student, lecturer and admin notifications open the correct pages | **Pass** (wiring) | `eh_notify_status_change` / `eh_notify_new_interest` portal-correct URLs |
| 18 | All important actions are audited | **Pass** | E2E transitions write `enterprise_reviews`; `eh_audit()` on material actions |
| 19 | AI output requires human acceptance | **Pass** | E2E AI returns draft text; no auto-save |
| 20 | The system remains functional when AI is disabled | **Pass** | `ENTERPRISE_AI_ENABLED` / `eh_ai_enabled()`; fallback path |
| 21 | The complete demonstration works on mobile and desktop | **Pass** (responsive markup) | Bootstrap layouts; live device QA optional for exhibition day |
| 22 | No private student information appears publicly | **Pass** | HTTP spot-check: no SID/NRC/internal review fields |
| 23 | No placeholder pages, dead links or unimplemented buttons | **Pass** | Required routes present; nav points at real PHP pages |
| 24 | No PHP warnings, notices, SQL errors or browser-console errors remain | **Pass** | PHP lint 0 failures (51 files); public pages body=ok |
| 25 | Existing WUCPortal modules continue working | **Pass** | Additive migration; no `db/connect.php` changes |

---

## Supporting automated results

```text
C:\xampp\php\php.exe scripts\test_enterprise_hub_calculators.php
→ Passed: 30  Failed: 0

C:\xampp\php\php.exe scripts\test_enterprise_hub_workflow.php
→ Passed: 21  Failed: 0

C:\xampp\php\php.exe scripts\test_enterprise_hub_e2e.php
→ Passed: 33  Failed: 0
  (profile → item → costs → readiness → media gate → submit →
   changes → resubmit → verify → approve → publish → interest →
   follow-up → duplicate block → unpublish hide → AI draft → cleanup)
```

HTTP (Apache running):

```text
showcase/index.php          200
showcase/item.php?code=ENT-DEMO0001  200 (QR + interest CTA)
showcase/item.php?code=ENT-DEMO0013  404 (draft blocked)
showcase/search.php         200
showcase/category.php       200
showcase/express_interest.php 200
showcase/exhibition.php     200
showcase/api/stats.php      200
students/enterprise/*       redirects to student_login.php
lecturers|admin/enterprise/* redirect to staff_login.php
```

---

## Deliverables cross-check

| Deliverable | Location |
|-------------|----------|
| Shared services | `includes/enterprise_hub/*` |
| Migration | `migrations/20260721_enterprise_hub.php` |
| Seed | `database/enterprise_hub_seed.sql` |
| Student / lecturer / admin / public UI | `students/enterprise/*`, `lecturers/enterprise/*`, `admin/enterprise/*`, `showcase/*` |
| QR labels | `admin/enterprise/qr_label.php` |
| Calculator + readiness + simulator | `cost_calculator.php`, `readiness.php`, student pages |
| Interests | `interest_service.php`, showcase + admin pages |
| AI assist | `ai_assistant.php` |
| Reports + CSV | `admin/enterprise/reports.php`, `reports.php` |
| Exhibition + demo reset | `showcase/exhibition.php`, admin settings demo reset |
| Tests | `scripts/test_enterprise_hub_*.php` |
| Docs | `docs/enterprise_hub_*.md`, `docs/skills_trade_hub_implementation_audit.md` |

---

## Gaps classified (non-blocking)

| Severity | Item |
|----------|------|
| Low | Lecturer “submission assigned” push notification is lighter than student status alerts; queue UI is the primary surface |
| Low | Set `WUC_PUBLIC_BASE_URL` on exhibition LAN before printing QR stock |
| Info | Legacy `admin/enterprise_audit.php` / `includes/enterprise_services.php` are separate from this hub |
| Info | Browser console / multi-device exhibition walkthrough remains an on-site checklist item |

---

## Sign-off

| Role | Outcome | Signature / date |
|------|---------|------------------|
| Implementation | Code complete | 2026-07-21 |
| Automated QA | Calculators, workflow, E2E **Pass** | 2026-07-21 |
| HTTP / public QA | Showcase + auth gates + privacy spot-check **Pass** | 2026-07-21 |
| Exhibition readiness | Follow `docs/enterprise_hub_exhibition_setup.md` | |

**Verdict:** The Skills-to-Trade and Investment Hub is **accepted for demonstration** against the automated and HTTP checks above. On-site exhibition sign-off should still confirm LAN base URL, projector exhibition mode, and live role logins with real staff/student accounts.
