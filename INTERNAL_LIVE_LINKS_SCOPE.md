# Scope: System-Generated Live Session Links (no external meeting provider)

**Goal:** Every live-session link is generated *and hosted within the WUC portal* — no
external Google Meet/Zoom/Teams URLs, no third-party OAuth, no placeholder rooms. The
portal owns the room identity, the join page, and access control end-to-end.

**Status:** ✅ **Phase 1 IMPLEMENTED (2026-06-22)** on the decisions taken — engine = Jitsi
via public `meet.jit.si` (config-swappable to a self-hosted server), external providers
**kept as options**, recording **not needed**. Live sessions now default to a
system-generated "Portal Live Room"; the portal owns the room name, hosts the join page,
and gates entry with the existing per-student secure token. Phases 2–3 remain (see §5).
Original scope below.

**Implemented in Phase 1:**
- `config/elearning.php` → new `live_meeting` block (engine/domain/embed/jwt).
- Migration `migrations/20260622_live_session_internal_platform.sql` → `platform` enum gains
  `'internal'`.
- `includes/elearning_live_sessions.php` → `elearningLiveMeetingConfig()`,
  `elearningGenerateInternalRoomName()`, `elearningInternalRoomUrl()`, and an `'internal'`
  branch in `elearningAllowedMeetingHost()`.
- `includes/elearning_room_embed.php` (new) → shared Jitsi IFrame API renderer.
- `admin/elearning/sessions.php` → "Portal Live Room" session type (default), auto room-name
  generation, `platform`/`external_meeting_id` bound on insert, recorded-vs-live list
  disambiguated by `session_source`, host "Launch" → `room.php`.
- `admin/elearning/room.php` (new) → lecturer/admin moderator entry (ownership-gated).
- `students/elearning/join_meeting.php` → renders the in-portal embed for `internal`
  sessions instead of a 302 to an external URL.
- **Both** live-session creation surfaces default to the portal room: the admin/lecturer
  console (`admin/elearning/sessions.php`) **and** the per-course lecturer scheduler
  (`elearning/sessions.php`). The lecturer join gateway `elearning/join_session.php` renders
  the same in-portal room (host) that students join, so they share one system-owned room.

---

## 1. Current state (as built)

- Live sessions are stored in `el_live_sessions`; per-student access is a hashed,
  expiring token in `el_live_session_links` (see `includes/elearning_live_sessions.php`).
- `admin/elearning/sessions.php` creates a session with an **external** `join_url`
  (`https://meet.google.com/xxx-xxxx-xxx`). `elearningGenerateGoogleMeetUrl()` can
  auto-generate that code, but it is a **non-functional placeholder** — a real Meet room
  needs the Google Calendar/Meet API + OAuth, which is not configured.
- `students/elearning/join_meeting.php` validates the student's token, then
  **302-redirects to the external `join_url`**, gated by `elearningAllowedMeetingHost()`
  (allow-list: `meet.google.com`, `teams.microsoft.com`, `zoom.us`).
- `config/elearning.php` has Zoom and Teams blocks, both `enabled => false`.
- Access control already works and is the part we keep: enrollment (`course_registration`)
  + paid-up + token expiry on generation *and* validation, attendance logged to
  `el_attendance`. See [[live-session-access-control]].

**The gap:** the portal can generate a *link*, but not a *room*. The room lives on an
external provider. To generate links "within the system," the portal must host the room.

---

## 2. What "within the system" actually requires

A meeting link has three parts. Two are pure PHP we already control; the third is the
real decision:

| Part | In our control today? | Work |
|------|----------------------|------|
| Room **identifier** (unguessable room name) | Yes (generate in PHP) | trivial |
| Join **page** on the portal domain | Yes (new `room.php`) | small |
| Real-time **media** (audio/video/screenshare) | **No** | needs a media engine |

Browsers cannot run a multi-party class (>2–3 people) peer-to-peer; you need a server-side
SFU/MCU to mix/route streams. So the only meaningful decision is **which media engine**,
and whether we self-host it (truly "within the system") or embed a hosted one (portal owns
the link + identity, media routed by a service).

---

## 3. Media-engine options

**Option A — Self-hosted Jitsi Meet + JWT (recommended).**
Open-source SFU (Jitsi Videobridge), deployed on institution infra (Docker quick-start).
The portal generates the room name, signs a **JWT** (lecturer/admin = `moderator`,
student = `participant`, claim bound to the one room), and embeds the room via the Jitsi
**IFrame API** inside an internal `room.php`. Fully self-owned, no per-room provisioning,
no OAuth. JWT + lobby means only the portal can admit a user and only into their room.
*Cost:* one Linux server (+ TURN for NAT traversal). *Best fit for "within the system."*

**Option B — Embedded Jitsi via public `meet.jit.si` (zero-infra dev path).**
Same PHP integration as A, but `domain = meet.jit.si` (no JWT on the public instance).
Links are system-generated and immediately functional. *Caveat:* rooms sit on a public
domain with no SLA/privacy guarantee — protect with an unguessable room name + lobby, but
not suitable as the long-term production home for private classes. Ideal to ship Phase 1
and validate UX, then point `domain` at the self-hosted server (Option A) by config.

**Option C — JaaS (8x8-managed Jitsi).**
Managed Jitsi, JWT via an API key, minimal infra, billed per monthly active user. Portal
still owns room IDs + access via JWT. "Within the system" in the identity/links sense;
media hosted by 8x8.

**Option D — Self-hosted BigBlueButton.**
Purpose-built for teaching (whiteboard, breakout rooms, polls, built-in recording). Portal
calls its API to create/join rooms. Heavier server footprint than Jitsi; strongest
classroom feature set.

**Option E — Custom WebRTC SFU (LiveKit / mediasoup).**
Maximum control, custom UI. Largest build (weeks–months) + real-time ops ownership. Only
worth it if live conferencing becomes a core product surface.

**Recommendation:** **Option A** as the target, with **Option B as Phase 1** so the
system-generated link flow ships immediately and the engine is swapped later by **config
only** (no code change). The `moderator`/`participant` split maps cleanly onto our existing
lecturer-owns-session / enrolled-student model.

---

## 4. Architecture changes (codebase-specific)

**Config — `config/elearning.php`** (new block):
```php
'live_meeting' => [
    'engine'  => getenv('WUC_LIVE_ENGINE') ?: 'jitsi',
    'domain'  => getenv('WUC_JITSI_DOMAIN') ?: 'meet.jit.si', // → self-hosted host later
    'embed'   => true,                                          // render in-portal iframe
    'jwt'     => [                                              // self-hosted/JaaS only
        'enabled' => (getenv('WUC_JITSI_JWT') ?: '') === '1',
        'app_id'  => getenv('WUC_JITSI_APP_ID') ?: '',
        'secret'  => getenv('WUC_JITSI_APP_SECRET') ?: '', // from wucportal-var, never repo
    ],
],
```

**Schema — `el_live_sessions`** (migration, applied as `wucportal_migrator`, see
[[db-least-privilege-schema-guard]]):
- Add `'internal'` to the `platform` enum: `enum('internal','google_meet','zoom','teams')`.
- Reuse the existing `external_meeting_id` column to store the generated **room name**
  (no new column strictly required). Optionally add `room_name VARCHAR(128)` for clarity.

**Helpers — `includes/elearning_live_sessions.php`:**
- `elearningGenerateInternalRoomName(string $courseCode, int $sessionId): string` — an
  unguessable slug, e.g. `wuc-<course>-<8 hex>`. Replaces `elearningGenerateGoogleMeetUrl()`
  as the default generator.
- `elearningBuildRoomContext($db, array $session, string $userId, string $role): array` —
  returns `{ domain, room, jwt|null, displayName, isModerator }`. Lecturer/admin →
  moderator; student → participant.
- `elearningAllowedMeetingHost()` — add the configured internal domain to the allow-list,
  or short-circuit to `true` when `platform === 'internal'` (the URL is portal-generated,
  not user-supplied, so host-spoofing isn't a risk).

**New page — `students/elearning/room.php` (shared renderer for lecturer/admin too):**
- Student entry: validate the secure token via `elearningValidateSecureSessionToken()`
  (unchanged gate). Lecturer/admin entry: verify `created_by` ownership.
- Build the Jitsi context, render the **Jitsi IFrame API** embed. This is where "Join"
  lands instead of an external 302 redirect.

**Flow rewiring:**
- `admin/elearning/sessions.php` (create): for `platform='internal'`, generate the room
  name, set `join_url` to the internal `room.php?session=<id>` (or store room in
  `external_meeting_id`). The manual "Google Meet URL" field becomes unnecessary; keep it
  only if Google Meet is retained as an optional platform (§7 decision 2).
- `students/elearning/join_meeting.php`: when `platform='internal'`, redirect to
  `room.php?token=…` (embed) instead of an external URL. Token validation unchanged.
- `students/elearning/live_sessions.php` + the lecturer session list: "Join/Launch"
  buttons point at the internal room.
- `admin/elearning/manage_session.php`: drop the random Google-Meet-code generator; show
  the internal room link instead.

**Access-control mapping (reuses what exists):**
- Student → existing per-student token (enrolled + paid-up + expiry) → JWT `participant`.
- Lecturer/admin (session owner) → JWT `moderator` (start, mute, end, admit from lobby).
- JWT `room` claim binds each user to exactly one room → no link-sharing / no cross-room.

**Security:**
- JWT secret lives in `wucportal-var/config/environment.php`, never the repo.
- Enable Jitsi **lobby** + "moderator must start" so a class can't open before the lecturer.
- **HTTPS is mandatory** for camera/mic (`getUserMedia`). The embedded Jitsi iframe is
  itself HTTPS; the *portal* should also be served over TLS in production.

---

## 5. Phasing & rough effort

| Phase | Deliverable | Effort* |
|-------|-------------|---------|
| 0 | **Decision:** engine (A/B/C/D) + retain external providers? + recording? | — (you) |
| 1 | ✅ **DONE** — System-generated links, no external dep: enum + room-name generator + `room.php` (Jitsi IFrame on configurable `domain`, default `meet.jit.si`) + reroute `join_meeting`/`sessions`/`live_sessions`. Working internal link flow. | ~0.5–1.5 days |
| 2 | Self-host Jitsi (Docker) + JWT moderator/participant + lobby + config wiring. | ~1–2 days + infra |
| 3 | Hardening: recording (Jibri) if needed; in-portal mic/cam pre-check; retire `google_meet` platform + legacy `google_meet_integration.php` and the `?code=` path in `join_meeting.php`. | ~1–2 days |

*Dev effort only; excludes server provisioning, TURN, and load testing.

---

## 6. Out of scope / risks

- **Real-time ops:** bandwidth, TURN/STUN for NAT, and scaling are infra responsibilities,
  not application code.
- **Recording** (Jibri for Jitsi, built-in for BBB) is an add-on, not in Phase 1.
- **TLS:** localhost XAMPP is HTTP; Phase 1 via `meet.jit.si` works because the iframe is
  HTTPS, but a self-hosted engine and the portal both need certificates.
- **Browser/mobile** camera-permission and compatibility testing required.
- Retiring Google Meet touches the legacy `?code=` path and `google_meet_integration.php`;
  do it only after Phase 2 is proven.

---

## 7. Decisions needed from you

1. **Engine:** self-hosted Jitsi (recommended, fully in-house), JaaS (managed/paid), or
   start on public `meet.jit.si` (free, dev-grade) and self-host later?
2. **Retain** Google Meet/Zoom/Teams as optional alternatives, or go **internal-only**?
3. **Session recording** — required now, later, or not needed?

Once 1–3 are answered, Phase 1 can start immediately; it is engine-swappable by config, so
choosing "start on meet.jit.si, self-host later" costs no rework.
