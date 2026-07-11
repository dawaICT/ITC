---
name: wuc-frontend
description: >
  Build and edit the WUC portal's user interface — dashboards, profile/stat
  cards, navbars, forms, and error pages — consistently with its existing design
  system (Bootstrap 5, Font Awesome, Inter font, the purple #6f42c1 theme). Use
  whenever the task involves the look or layout of any portal page: adding/editing
  a card, page, modal, table or form; restyling something; making a page
  responsive; or "make this match the rest of the portal". Follow the established
  patterns here rather than inventing new styling, so the portal stays visually
  coherent.
---

# WUC Portal — Frontend Developer

## What this covers

The portal is server-rendered PHP that emits HTML styled with Bootstrap 5 plus a
custom purple design system. Your job when building UI is to make new screens
look like they always belonged — reuse the existing tokens, components, and class
names instead of bolting on bespoke styles.

## The design system (use these exact tokens)

**CDN stack** — every page head should pull the same versions:

```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
```

Plus the shared portal CSS where applicable:
`/wucportal/css/portal-dashboard.css` and the page-local `css/dashboard.css`.

**Brand color** — purple is the primary identity:

- Primary: `#6f42c1`
- Hover / darker: `#5a32a3`, deepest `#4a2b9c`
- Use a gradient for headers/buttons: `linear-gradient(135deg, #6f42c1, #5a32a3)`

**Typography** — `Inter`, falling back to `'Segoe UI', system-ui, sans-serif`.

**Icons** — Font Awesome 6 (`<i class="fas fa-...">`). Pair every section
heading and stat with an icon; the portal leans on iconography heavily.

**Shape & depth** — rounded corners (cards `border-radius: 12–20px`), soft
shadows (`0 8px 40px rgba(80,60,180,0.10)`), generous padding.

## Established components — reuse, don't reinvent

### Stat card (dashboard top row)

Status uses semantic color classes: `red`, `green`, `amber`, `purple`,
`neutral`. The icon wrap and value share the class.

```html
<article class="stat-card">
    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
    <div>
        <div class="stat-label">Status</div>
        <div class="stat-value">Registered</div>
    </div>
</article>
```

### Content card

```html
<article class="card">
    <div class="card-hdr">
        <h3><i class="fas fa-bullhorn"></i> Announcements</h3>
        <span class="badge bg-primary">Latest</span>
    </div>
    <div class="card-body"> ... </div>
</article>
```

### Empty state (always provide one)

Lists must degrade gracefully — when there's no data, show an empty state, never
a blank gap:

```html
<div class="empty-state">
    <i class="fas fa-bell-slash"></i>
    <p>No announcements available</p>
</div>
```

### Error / interstitial page

Full-page errors use `students/includes/error_template.php` — a centered card
with a gradient purple header, FA icon, highlighted message block, and
primary/secondary action buttons. Reuse it (set `$error_title` and
`$error_message`, include it, `exit`) instead of hand-rolling an error page.

## Non-negotiable rules

### Escape everything dynamic

This is server-rendered HTML — every value that reaches the page must be escaped
to prevent XSS:

```php
<?= htmlspecialchars($studentName) ?>
```

The **only** exception is content that is intentionally pre-built HTML (e.g.
`$error_message` containing a `<strong>` tag) — and that HTML must itself have
been assembled from `htmlspecialchars()`-escaped pieces. When in doubt, escape.

### Validate untrusted attributes

Don't interpolate raw user data into `src`/`href`/filenames. Follow the existing
pattern of allow-list validation, e.g. profile images:

```php
$safeProfileImage = preg_match('/^[A-Za-z0-9._-]+$/', $profileImage) ? $profileImage : '';
```

### Responsive by default

Use Bootstrap's grid/utilities and the portal's `*-grid` layouts
(`stats-grid`, `dash-grid`). Verify the layout holds on a narrow viewport —
cards should stack, not overflow.

### Icons in the head

Set the favicon to the ITC logo: `images/itc_logo.png` (path is relative to the
page's directory — `../images/...` from `students/includes/`).

## Working method

1. Find a sibling page that already has the component you need and mirror its
   markup and classes — consistency beats cleverness here.
2. Reuse the semantic color classes; don't introduce new ad-hoc hex values
   except the brand palette above.
3. The data comes from PHP/mysqli — when a card needs new data, that's a
   backend change too (see [[wuc-backend]]); make sure the query is verified
   against the real schema (see [[wuc-schema-debug]]).
4. After changes, load the page in a browser and check the golden path plus the
   empty/error states. Static type checks won't tell you the layout is right.
