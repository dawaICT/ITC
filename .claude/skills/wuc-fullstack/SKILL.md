---
name: wuc-fullstack
description: >
  Build complete end-to-end features in the WUC portal — both backend (PHP/MySQL)
  and frontend (Bootstrap 5 + vanilla JS) together. Use when a task requires
  creating or extending a full page: controller logic, database queries, and the
  rendered UI, all in one pass. Combines [[wuc-backend]] conventions, [[wuc-frontend]]
  design patterns, and [[wuc-schema-debug]] schema safety.
---

# WUC Portal — Fullstack Developer

This skill guides building a **complete portal page** from scratch. It combines
the backend, frontend, and schema safety rules into one workflow.

## The standard page anatomy

Every portal page follows this structure (order matters):

```
1. PHP controller (top of file, before any HTML output)
   a. require portal_config.php
   b. require guard.php (student) or role check (staff)
   c. require db/connect.php
   d. Process POST → validate → query DB → set $result/$errors
   e. Require navbar include
2. HTML output
   a. <main class="content-wrapper"> wrapper
   b. dashboard-header section
   c. Error/success alerts
   d. Feature content (cards, tables, forms)
   e. </main> + scripts
```

## Step-by-step for a new student page

### Step 1 — Schema verification (ALWAYS first)

Before writing a single query, verify every table and column exists:

```bash
"E:\xampp\php\php.exe" -r "
require_once 'db/connect.php';
\$r = \$db->query('DESCRIBE students');
while(\$row = \$r->fetch_assoc()) echo \$row['Field'].\"\n\";
"
```

See [[wuc-schema-debug]] for the full list of phantom tables to avoid.

### Step 2 — Controller skeleton

```php
<?php
declare(strict_types=1);

$page_title = 'My Feature';
require_once __DIR__ . '/includes/guard.php';          // session + auth
require_once dirname(__DIR__) . '/includes/ai_portal.php'; // if using AI
// any other includes...

function my_feature_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$studentId = (string)($_SESSION['Sid'] ?? '');
$errors = [];
$result = null;

// POST handler
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $errors[] = 'Request verification failed. Refresh and try again.';
    }
    // validate, query, set $result...
}

// GET data queries (always use prepared statements)
$stmt = $db->prepare("SELECT col1 FROM table WHERE student_id = ?");
if ($stmt) {
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

require_once __DIR__ . '/includes/navbar.php';
?>
```

### Step 3 — HTML shell

```html
<main class="content-wrapper pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4 portal-dashboard">

    <!-- Page header -->
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title">My Feature</h1>
                <p class="text-muted mb-0">Brief description of what this page does.</p>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach (array_unique($errors) as $e): ?>
            <div><?php echo my_feature_h($e); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Content row -->
    <div class="row g-4">
        <div class="col-xl-5">
            <section class="data-table-card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-icon-name me-2"></i>Card title</h5>
                </div>
                <div class="card-body">
                    <!-- form or content here -->
                </div>
            </section>
        </div>
        <div class="col-xl-7">
            <section class="data-table-card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-other-icon me-2"></i>Result</h5>
                </div>
                <div class="card-body">
                    <!-- results here -->
                </div>
            </section>
        </div>
    </div>

</div>
</main>
```

## Form pattern (with CSRF)

```html
<form method="post" action="my_feature.php">
    <input type="hidden" name="csrf_token"
           value="<?php echo my_feature_h($_SESSION['csrf_token'] ?? ''); ?>">
    <div class="mb-3">
        <label class="form-label" for="field_name">Label</label>
        <input class="form-control" type="text" id="field_name" name="field_name"
               value="<?php echo my_feature_h($old['field_name'] ?? ''); ?>"
               required maxlength="200">
    </div>
    <button class="btn btn-primary" type="submit">
        <i class="fas fa-check me-2"></i>Submit
    </button>
</form>
```

## Table pattern

```html
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Column A</th>
                <th>Column B</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="2" class="text-muted text-center py-4">No records found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo my_feature_h($row['col_a']); ?></td>
                    <td><?php echo my_feature_h($row['col_b']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
```

## Empty-state card

```html
<div class="text-center py-5 text-muted">
    <i class="fas fa-inbox fa-3x mb-3 opacity-50"></i>
    <p class="mb-0">No data found.</p>
</div>
```

## Design tokens (must use)

| Purpose | Class / value |
|---------|--------------|
| Primary action button | `btn btn-primary` |
| Secondary/cancel | `btn btn-outline-secondary` |
| Danger | `btn btn-danger` |
| Badge — success | `badge bg-success` |
| Badge — warning | `badge bg-warning text-dark` |
| Content card | `data-table-card` (defined in portal CSS) |
| Page wrapper | `content-wrapper pt-3 pb-5` |
| Dashboard title | `<h1 class="dashboard-title">` |
| Section label | `<div class="nav-section-title">` |
| Purple accent | `#6f42c1` (defined as `--bs-primary` override in wuc-premium.css) |
| Font | Inter (loaded in navbar) |
| Icons | Font Awesome 6.4 `fas fa-*` |

## Adding to the navbar

To link a new page in the student sidebar, edit `students/includes/navbar.php`
inside the appropriate `<div class="nav-section">` block:

```php
<a href="my_feature.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'my_feature.php' ? 'active' : ''; ?>">
    <i class="fas fa-wand-magic-sparkles"></i>
    <span>My Feature</span>
</a>
```

For staff pages the pattern is the same but inside the role's nav include
(e.g. `lecturers/nav_unified.php`, `admin/navbar.php`).

## AJAX / fetch pattern (if needed)

For async AI chat or dynamic updates, use plain `fetch`:

```js
async function submitQuestion(payload) {
    const resp = await fetch('my_feature_ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    return resp.json();
}
```

The PHP endpoint must:
```php
<?php
require_once __DIR__ . '/includes/guard.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{}'; exit; }
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
// validate csrf from payload['csrf_token']...
// process...
echo json_encode(['ok' => true, 'text' => $responseText]);
```

## Quality checklist before marking done

- [ ] Every query verified against real schema (`DESCRIBE table` or [[wuc-schema-debug]])
- [ ] All user input escaped with `htmlspecialchars()` before echoing
- [ ] POST handler checks CSRF token with `hash_equals()`
- [ ] All queries use prepared statements (no string concat with user data)
- [ ] Page tested with a real student session (see [[wuc-seed-test-accounts]])
- [ ] Navbar link added if needed
- [ ] AI features have a fallback (see [[wuc-ai]])
- [ ] No raw SQL errors shown to user
