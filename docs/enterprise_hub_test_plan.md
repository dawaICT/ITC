# Skills-to-Trade and Investment Hub — Test Plan

**Module:** Skills-to-Trade and Investment Hub (`enterprise_hub`)  
**Date:** 2026-07-21  
**Scope:** Authentication/authorization, workflow, calculations, uploads, public interest form, AI

Use this as a repeatable checklist. Mark each case Pass / Fail / Blocked and note the environment (local XAMPP host, MySQL status, exhibition mode).

**Automated (no DB):**

```text
C:\xampp\php\php.exe C:\xampp\htdocs\wucportal\scripts\test_enterprise_hub_calculators.php
```

Expected: `Passed: 30  Failed: 0` (costs, readiness, transitions, investment simulator).

---

## Prerequisites

- [ ] Apache + MySQL running
- [ ] Migration `migrations/20260721_enterprise_hub.php` applied
- [ ] Optional: `database/enterprise_hub_seed.sql` loaded for public/demo cases
- [ ] Test accounts: student, lecturer, admin/registrar (or systems admin)
- [ ] Browser with a second window (or phone) for public + QR checks

---

## A. Authentication and authorization

| ID | Case | Steps | Expected | Result |
|----|------|-------|----------|--------|
| A1 | Anonymous blocked from student hub | Log out. Open `/wucportal/students/enterprise/index.php` | Redirect to login / access denied; no data | ☐ |
| A2 | Anonymous blocked from lecturer hub | Open `/wucportal/lecturers/enterprise/review_queue.php` | Guard rejects unauthenticated user | ☐ |
| A3 | Anonymous blocked from admin hub | Open `/wucportal/admin/enterprise/index.php` | Admin guard rejects | ☐ |
| A4 | Student cannot open lecturer review | As student, open `/wucportal/lecturers/enterprise/review.php?id=1` | Access denied | ☐ |
| A5 | Lecturer cannot publish without permission | As lecturer-only role, open admin publish/approve actions or POST publish | Denied; item stays non-published | ☐ |
| A6 | Student cannot edit another student’s item | As student B, open `/students/enterprise/item_edit.php?id={item_owned_by_A}` | 403 / flash error; no save | ☐ |
| A7 | Public cannot view unpublished | Create draft item; open `/showcase/item.php?code={its_public_code}` while status ≠ published | 404 / not available | ☐ |
| A8 | Direct URL / IDOR on media | As anonymous, request `/showcase/media.php?id={draft_media_id}` | 403 Forbidden | ☐ |
| A9 | Nav is not auth | Hide or guess URLs; confirm server-side `eh_require` still enforces | Capability check independent of menu | ☐ |

---

## B. Workflow (draft → market)

| ID | Case | Steps | Expected | Result |
|----|------|-------|----------|--------|
| B1 | Create draft | Student: profile → create item → save | Item status `draft`; appears on dashboard | ☐ |
| B2 | Incomplete draft cannot submit | Draft missing image and/or costs and/or readiness → Submit | `eh_item_submission_errors` blocks; clear messages | ☐ |
| B3 | Complete and submit | Add primary image, costs, readiness, profile complete → Submit | Status `submitted`; review row logged; student notified | ☐ |
| B4 | Lecturer reviews | Lecturer opens Review Queue → item | Sees identity, media, costs, readiness, history, checklist | ☐ |
| B5 | Request changes | Lecturer decision `request_changes` with comments | Status `changes_requested`; comments required; audited | ☐ |
| B6 | Student resubmits | Student edits → submit again | Status returns to `submitted` | ☐ |
| B7 | Lecturer verifies | Decision `verify` | Status `lecturer_verified`; `lecturer_verified_at` set | ☐ |
| B8 | Admin approves | Admin Pending Approvals → Approve | Status `approved`; `approved_at` set | ☐ |
| B9 | Admin publishes | Publish action | Status `published`; appears in showcase; QR label works | ☐ |
| B10 | Unpublish | Admin unpublish | Status `unpublished`; public URL 404; can re-publish | ☐ |
| B11 | Illegal transition blocked | Attempt draft → published (or rejected → published) via forged POST | Rejected by `eh_can_transition` / `eh_transition_item` | ☐ |
| B12 | Audit trail | After B3–B10, check audit log / review history | Each transition recorded (`enterprise_reviews` + `eh_audit`) | ☐ |

---

## C. Calculations

Run automated script first, then spot-check UI.

| ID | Case | Steps | Expected | Result |
|----|------|-------|----------|--------|
| C1 | Normal positive | Materials 100, labour 50, transport 10, utilities 5, packaging 5, marketing 10, other 20, units 10, sell 30 | total 200; CPU 20; profit/unit 10; revenue 300; profit 100; margin ≈ 33.33% | ☐ Pass via unit script |
| C2 | Zero units | units = 0 | Rejected (`ok=false`) | ☐ Pass via unit script |
| C3 | Negative cost | material = -1 | Rejected | ☐ Pass via unit script |
| C4 | Selling below cost | total cost 100, units 1, sell 50 | Warning: selling price below cost | ☐ Pass via unit script |
| C5 | Decimals | Fractional inputs | Rounded money strings to 2 dp; no crash | ☐ Pass via unit script |
| C6 | Large values | High ZMW amounts | Correct revenue; no overflow errors in PHP | ☐ Pass via unit script |
| C7 | Margin formula | Known set → margin | `(profit/revenue)*100` | ☐ Pass via unit script |
| C8 | Readiness weights | Scores 80,70,60,50,40,30 | total 58.5 → Development Required | ☐ Pass via unit script |
| C9 | Readiness bounds | Score 101 | Rejected | ☐ Pass via unit script |
| C10 | Investment simulator | Current qty/price/cost vs proposed | Projection flags set; jobs/revenue computed | ☐ Pass via unit script |
| C11 | UI disclaimer | Open student cost calculator | Disclaimer text present | ☐ |
| C12 | Persist costs | Save costs for an item | `enterprise_costs` row; version increments on recalculate | ☐ |

---

## D. Uploads

| ID | Case | Steps | Expected | Result |
|----|------|-------|----------|--------|
| D1 | Valid JPEG | Upload `.jpg` under size limit | Stored under `storage/enterprise_hub/media/`; DB row; thumbnail | ☐ |
| D2 | Valid PNG | Upload `.png` | Accepted | ☐ |
| D3 | Oversized file | Exceed configured max bytes | Rejected with message | ☐ |
| D4 | Fake extension | Rename `.exe` / PHP to `.jpg` | `wucValidateUpload` / inspection rejects | ☐ |
| D5 | Executable / polyglot markers | File with `MZ` or `<?php` head | Rejected (“security inspection”) | ☐ |
| D6 | Duplicate / second upload | Upload another allowed image | Second media row; random filename (no overwrite) | ☐ |
| D7 | Path traversal filename | `../../evil.jpg` as client name | Basename sanitized; stays inside media root | ☐ |
| D8 | Delete image | Delete non-final / allowed media | DB row gone; file unlinked; audit | ☐ |
| D9 | Primary selection | Set primary on media B | Only one `is_primary=1` | ☐ |
| D10 | Draft media not public | Anonymous `media.php?id=` for draft | 403 | ☐ |
| D11 | Max images | Upload beyond `max_images_per_item` (default 8) | Rejected | ☐ |

---

## E. Public expression-of-interest form

| ID | Case | Steps | Expected | Result |
|----|------|-------|----------|--------|
| E1 | Valid submission | Published item → Express interest → fill required + consent | Success page; row in `enterprise_interests`; student notified | ☐ |
| E2 | Missing required fields | Clear name/message | Validation errors; no insert | ☐ |
| E3 | Invalid email | `not-an-email` | Rejected | ☐ |
| E4 | Invalid phone | Too short / letters | Rejected | ☐ |
| E5 | Missing consent | Uncheck consent | Rejected | ☐ |
| E6 | Duplicate submission | Same email + item within 24h | Duplicate message; no second meaningful insert | ☐ |
| E7 | Rate limit | > `interest_rate_limit_count` (default 3) in window | “Too many submissions” | ☐ |
| E8 | CSRF failure | POST without / with bad `csrf_token` | 403 / security token error | ☐ |
| E9 | Stored XSS attempt | Message / name with `<script>alert(1)</script>` | Stored escaped; no script execution on admin/student views | ☐ |
| E10 | Honeypot | Fill hidden `website` field | Silent success response; no real interest (or drop) | ☐ |
| E11 | Admin follow-up | Admin interest_view → change status / assign | Updates persist; audited | ☐ |

---

## F. AI assistant

| ID | Case | Steps | Expected | Result |
|----|------|-------|----------|--------|
| F1 | AI enabled | `ENTERPRISE_AI_ENABLED=true`; run improve_description | Draft text in review area; not auto-saved to item | ☐ |
| F2 | AI disabled | Set `false`; retry assist | Clear disabled message; rest of hub works | ☐ |
| F3 | Provider timeout / offline | Stop Ollama or force failure | Fallback text; `status` fallback/ok; page usable | ☐ |
| F4 | Invalid / unknown task | Call disallowed task if exposed | Rejected “Unknown AI task” | ☐ |
| F5 | User rejects content | Generate then discard without accepting | Item description unchanged | ☐ |
| F6 | Never auto-publish | Generate investor summary while draft | Status unchanged; no publish side-effect | ☐ |
| F7 | No sensitive leakage | Ensure SID/NRC not in AI context payload | Context uses title/type/descriptions only | ☐ |

---

## G. Exhibition and QR smoke tests

| ID | Case | Steps | Expected | Result |
|----|------|-------|----------|--------|
| G1 | Exhibition off | Open `/showcase/exhibition.php` | Unavailable message | ☐ |
| G2 | Exhibition on | Enable mode; reopen | Stats + featured grid; stats API responds | ☐ |
| G3 | QR opens correct page | Print/scan label for published code | Lands on matching `item.php?code=` | ☐ |
| G4 | LAN base URL | Set `WUC_PUBLIC_BASE_URL` to LAN host; regenerate QR | Phone on Wi‑Fi opens item | ☐ |

---

## H. Regression

| ID | Case | Steps | Expected | Result |
|----|------|-------|----------|--------|
| H1 | Existing portals | Open student home, lecturer home, admin home, fees/admissions smoke pages used locally | No new PHP fatals from hub includes | ☐ |
| H2 | No console/PHP noise | Walk happy path with display_errors off as in production | No warnings/notices in logs for hub pages | ☐ |

---

## Sign-off

| Role | Name | Date | Notes |
|------|------|------|-------|
| Implementer | | | |
| Reviewer | | | |
| Exhibition owner | | | |

Related documents: `docs/enterprise_hub_acceptance_report.md`, `docs/enterprise_hub_deployment.md`, `docs/enterprise_hub_exhibition_setup.md`.
