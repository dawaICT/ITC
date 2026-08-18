# Skills-to-Trade and Investment Hub — Exhibition Setup

**Theme:** Fostering Trade Investment · 2026  
**Module:** Skills-to-Trade and Investment Hub (`enterprise_hub`)  
**Kiosk page:** `/wucportal/showcase/exhibition.php`

This guide prepares a stable, offline-friendly demonstration on the local XAMPP host or an exhibition LAN.

---

## 1. Enable exhibition mode

Choose one (env wins over unset DB defaults when set):

### Option A — Environment

In `.env`:

```env
ENTERPRISE_EXHIBITION_MODE=true
ENTERPRISE_AI_ENABLED=true
WUC_PUBLIC_BASE_URL=http://YOUR-LAN-HOST/wucportal
```

Restart Apache after changing `.env` if your loader caches environment at process start.

### Option B — Admin settings UI

1. Log in as systems admin / registrar (or a role with `enterprise.settings.manage`).
2. Open **Admin → Skills-to-Trade Hub → Settings**.
3. Set exhibition mode to enabled and save (CSRF-protected).

When exhibition mode is **off**, `showcase/exhibition.php` returns a friendly unavailable message (HTTP 404) and links back to the catalogue. The normal public catalogue remains available.

---

## 2. Public URLs

| Purpose | Path |
|---------|------|
| Catalogue home | `/wucportal/showcase/index.php` |
| Search | `/wucportal/showcase/search.php` |
| Category browse | `/wucportal/showcase/category.php?slug=...` |
| Item (QR target) | `/wucportal/showcase/item.php?code=ENT-XXXXXXXX` |
| Express interest | `/wucportal/showcase/express_interest.php?code=ENT-XXXXXXXX` |
| Interest success | `/wucportal/showcase/interest_success.php` |
| Media (authorized stream) | `/wucportal/showcase/media.php?id={id}` |
| Exhibition kiosk | `/wucportal/showcase/exhibition.php` |
| Live stats API | `/wucportal/showcase/api/stats.php` |

Only rows with `enterprise_items.status = 'published'` are returned by public item lookups.

---

## 3. QR codes on a LAN hostname

Printed QR codes must not point at `localhost` when visitors use phones on Wi‑Fi.

1. Give the exhibition PC a stable LAN IP or mDNS/hostname (example: `192.168.10.20` or `itc-show-pc`).
2. Set:

```env
WUC_PUBLIC_BASE_URL=http://192.168.10.20/wucportal
```

3. Confirm Apache is listening on that interface (Windows Firewall allow inbound TCP 80 if needed).
4. From a phone on the same Wi‑Fi, open:

```text
http://192.168.10.20/wucportal/showcase/index.php
```

5. In admin, open a published item → **QR Label** (`admin/enterprise/qr_label.php?id=...`), print labels. The QR payload is generated with `wuc_qr_svg_data_uri(eh_public_item_url($public_code))`.

Public codes are cryptographically random `ENT-` + 8 hex characters (demo seed uses stable `ENT-DEMO####` codes for rehearsal).

---

## 4. Kiosk / TV page

Open full-screen on the display PC:

```text
http://YOUR-LAN-HOST/wucportal/showcase/exhibition.php
```

Behaviour when exhibition mode is on:

- High-contrast theme banner: **Fostering Trade Investment · 2026**
- Stats: published opportunities, investment sought (ZMW), potential jobs, expressions of interest
- Featured / recent published opportunities grid
- Auto-refresh of stats via `showcase/api/stats.php` (page also has a 5-minute meta refresh)
- No administrative controls exposed on the public screen

Recommended display: full-screen browser (F11), hide bookmarks bar, disable sleep on AC power.

---

## 5. Demo seed

With MySQL running:

```text
C:\xampp\php\php.exe C:\xampp\htdocs\wucportal\migrations\20260721_enterprise_hub.php
C:\xampp\mysql\bin\mysql.exe -u root wucportal < C:\xampp\htdocs\wucportal\database\enterprise_hub_seed.sql
```

Seed includes fictional profiles and items across products, services, innovations, and investment ideas, multiple readiness levels and workflow statuses, plus sample interests — all marked `is_demo = 1`. Safe emails use `@exhibition.test`.

### Demo reset (systems admin)

Between rehearsal runs, systems administrators can reset only demo rows from **Admin → Skills-to-Trade Hub → Settings → Exhibition demo reset**. Type `RESET DEMO` to confirm. This deletes `is_demo = 1` records and reloads `database/enterprise_hub_seed.sql`. Live student/staff hub records are not removed.

Useful rehearsal codes after seed (examples):

- `ENT-DEMO0001` — published product (dried vegetable packs)
- `ENT-DEMO0003` — published automotive service
- `ENT-DEMO0005` — published fabrication product

---

## 6. Three-minute demo sequence

Use this script at the stand:

| Time | Action | What visitors see |
|------|--------|-------------------|
| 0:00–0:30 | Open exhibition kiosk URL on the TV | Theme banner, live counts, featured enterprises |
| 0:30–1:00 | On a tablet, open catalogue → pick a featured product | Verified public profile: price, capacity, investment ask, readiness, disclaimer |
| 1:00–1:30 | Scan the printed QR for that item | Phone lands on the same public item page |
| 1:30–2:15 | Tap **Express interest**, submit a short enquiry (use a test email) | Confirmation page; no funding promise |
| 2:15–2:45 | (Staff laptop) Admin → Interests → open the new row → set follow-up to *Acknowledged* | Shows institutional follow-up capability |
| 2:45–3:00 | Optional: show student hub workflow checklist (profile → item → cost → readiness → submit) on a logged-in demo account | Explains the training → market journey |

Talking point: *Vocational skills → verified product → market visibility → investment/employment interest.*

---

## 7. Network configuration

### Minimum exhibition LAN

1. Exhibition PC runs XAMPP (Apache + MySQL).
2. Access point / switch isolates guests if required by venue policy.
3. Guest devices need only HTTP to the exhibition host (port 80).
4. DNS optional; IP in `WUC_PUBLIC_BASE_URL` is enough for QR.
5. Do **not** depend on external CDNs for the demo — keep assets local.
6. If venue Wi‑Fi blocks client-to-client traffic, put phones and the server on a network that allows access to the host IP.

### Firewall (Windows)

- Allow inbound TCP 80 (and 443 if you terminate TLS locally) for Apache.
- Keep MySQL bound to localhost only (default XAMPP) — do not expose 3306 to the guest Wi‑Fi.

### Failure modes to rehearse

- Unplug WAN cable: catalogue, QR, interest form, and exhibition stats must still work.
- Disable AI (`ENTERPRISE_AI_ENABLED=false` or stop Ollama): student/admin pages must still save and publish.

---

## 8. AI offline fallback

AI is optional for exhibition day.

- Controlled via `ENTERPRISE_AI_ENABLED` and/or admin setting `ai_enabled`.
- `eh_ai_assist()` never auto-saves, never verifies/approves/publishes.
- On timeout or provider failure, a local draft/fallback message is returned (`status: fallback`).
- When disabled, the UI continues; assist buttons report that AI is disabled.

For maximum reliability at the show, set:

```env
ENTERPRISE_AI_ENABLED=false
```

unless you have rehearsed a local Ollama (or configured) provider end-to-end.

---

## 9. Exhibition day checklist

- [ ] Apache + MySQL running
- [ ] Migration applied; demo seed loaded (or curated published real items)
- [ ] `ENTERPRISE_EXHIBITION_MODE=true`
- [ ] `WUC_PUBLIC_BASE_URL` uses LAN hostname/IP (verified from a phone)
- [ ] Kiosk URL opens and stats populate
- [ ] At least 3 featured/published items with images
- [ ] QR labels printed and tested with Android and iOS
- [ ] Interest form submission succeeds; appears under Admin → Interests
- [ ] AI either rehearsed or explicitly disabled
- [ ] Backup dump taken the morning of the event
- [ ] Spare offline copy of seed SQL and this guide on a USB stick

See also: `docs/enterprise_hub_deployment.md` and `docs/enterprise_hub_test_plan.md`.
