---
name: wuc-reports
description: >
  Build, extend, or debug the WUC portal's reports subsystem — the shared
  training-reports engine (trx_* functions), CSV export, role-scoped wrapper
  pages, AI narrative summaries, and the transport/admin/admissions report
  registry pattern. Use whenever a task involves reports, data export, tabular
  analytics, or AI-generated summaries for any module.
---

# WUC Portal — Reports Developer

## The two report systems

| System | Files | Used by |
|--------|-------|---------|
| Training reports engine | `includes/training_reports_engine.php` | Transport, Admin, Admissions |
| Ad-hoc module reports | Per-module (e.g. `transport/reports.php`) | Single module |

This skill covers the **shared engine** and the patterns for building on top of it.

## How the training reports engine works

`includes/training_reports_engine.php` owns:
- A **registry** of report definitions (`trx_report_defs()`)
- A **CSV exporter** (`trx_handle_csv()`)
- A **renderer** (`trx_render()`) that outputs pills + date/campus filters + table + AI button
- An **AI summary** trigger (`trx_ai_summary()`)
- Helper functions prefixed `trx_`

The engine is shared; role-scoped wrappers include it and pass an `$opts` array:

```php
// Caller (e.g. transport/training_reports.php)
require_once dirname(__DIR__) . '/includes/training_reports_engine.php';

$opts = [
    'allowed'  => ['recruitment_funnel', 'training_throughput'],  // which reports this role sees
    'base_url' => 'training_reports.php',
    'role'     => 'transport',
    'heading'  => 'Transport Training Reports',
];

trx_handle_csv($db, $opts);   // MUST be called before any HTML output (sends headers+data, then exits)
// ... include nav chrome ...
trx_render($db, $opts);       // outputs the full UI
// ... include footer ...
```

## CSV export — always call before HTML

`trx_handle_csv($db, $opts)` inspects `$_GET['export']` + `$_GET['report']`.
If the request is a CSV export, it:
1. Runs the report builder for the chosen report
2. Sets `Content-Type: text/csv` and `Content-Disposition: attachment` headers
3. Streams the CSV and calls `exit`

**Because it calls `exit`, it must run before any HTML is emitted.** Call it
right after the guard include and `$opts` definition.

## Report definitions — registry pattern

Each report is a keyed entry in the array returned by `trx_report_defs()`.
A definition has:

```php
'my_report' => [
    'title'   => 'My Report Title',
    'icon'    => 'fas fa-chart-bar',
    'note'    => 'One-line description shown below the title.',
    'columns' => [
        // Each column: [db_key, display_header, format_type]
        ['program_code', 'Program', 'text'],
        ['total',        'Total',   'int'],
        ['amount',       'Amount',  'money'],
        ['rate',         'Rate %',  'num1'],
        ['status',       'Status',  'badge'],
    ],
    'builder' => function (mysqli $db, string $from, string $to, int $campusId): array {
        // Returns an array of associative rows, one per table row.
        // $from/$to are YYYY-MM-DD date range strings (from the filter UI).
        // $campusId is 0 for "all campuses".
        $rows = trx_query($db,
            "SELECT program_code, COUNT(*) AS total FROM transport_enrollments
             WHERE enroll_date BETWEEN ? AND ?",
            'ss', [$from, $to]
        );
        return $rows;
    },
],
```

### Format types for columns

| Type | Rendered as |
|------|-------------|
| `text` | Plain escaped string |
| `int` | `number_format()` — whole number with commas |
| `num1` | `number_format(x, 1)` — one decimal place |
| `money` | `ZMW X,XXX.XX` |
| `badge` | Bootstrap `<span class="badge bg-secondary">` |

In CSV export, `money` and `num1` columns are stripped to raw floats.

## Adding a new report

1. **Define it** — add a new key to the array in `trx_report_defs()` following
   the pattern above.
2. **Verify the SQL** — run the builder query via PHP CLI against the live DB
   before adding it (see [[wuc-schema-debug]]).
3. **Expose it** — add its key to the `'allowed'` array in whichever wrapper
   page(s) should show it.
4. **No new wrapper needed** — if the report belongs to an existing role
   (transport, admin, admissions), just add the key to that role's `$opts['allowed']`.

## Role-scoped wrappers

Three wrappers exist; each restricts which reports are visible:

| Wrapper | Role | Reports allowed |
|---------|------|----------------|
| `admin/training_reports.php` | `systems_admin` | All 4 + all campuses |
| `admissions/training_reports.php` | `canAccessAdmissions()` | `recruitment_funnel` (subset) |
| `transport/training_reports.php` | `canAccessTransport()` | All 4, own campus filter |

To create a new wrapper for a different module:

```php
<?php
// 1. Auth guard for the module
require_once __DIR__ . '/includes/my_module.php';

// 2. Engine
require_once dirname(__DIR__) . '/includes/training_reports_engine.php';

$opts = [
    'allowed'  => ['my_report', 'other_report'],
    'base_url' => 'training_reports.php',
    'role'     => 'my_module',
    'heading'  => 'My Module Reports',
];

trx_handle_csv($db, $opts);   // before HTML

require_once __DIR__ . '/includes/nav.php';
trx_render($db, $opts);
require_once __DIR__ . '/includes/footer.php';
```

## AI narrative summaries

`trx_render()` appends an "Generate AI Summary" button to each report table.
Clicking it posts to `trx_ai_summary($db, $reportKey, $rows, $opts)` which:
1. Serialises the top 50 rows to JSON context
2. Calls `wuc_ai_generate()` with a narrative prompt
3. Returns rendered Markdown via `wuc_ai_output_block()`

The AI summary is always optional — the button only appears, and the fetch
is async. If AI is offline the button shows an error message; the report
table is always visible regardless.

## Ad-hoc module reports (non-engine)

For simpler reports that don't need the engine (e.g. a single table for
a specific module), follow this pattern directly:

```php
// 1. Determine requested report
$report = $_GET['report'] ?? 'default';
$allowed = ['default', 'summary'];
if (!in_array($report, $allowed, true)) { $report = 'default'; }

// 2. CSV export before HTML
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Column A', 'Column B']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['col_a'],
            $row['col_b'],
        ]);
    }
    fclose($out);
    exit;
}

// 3. Render table normally after nav include
```

## Date filter pattern

The engine exposes a `$from`/`$to` pair (YYYY-MM-DD). For ad-hoc reports,
use the same pattern so the UI stays consistent:

```php
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-01-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
```

Never pass raw `$_GET` date strings into SQL — always validate the format first.

## Helper functions inside the engine

| Function | Purpose |
|----------|---------|
| `trx_h($v)` | `htmlspecialchars()` alias |
| `trx_query($db, $sql, $types, $params)` | Prepared-statement query → array of rows |
| `trx_fmt($type, $v)` | Format a value for HTML display (int/num1/money/badge/text) |
| `trx_csv($type, $v)` | Format a value for CSV (strips formatting) |
| `trx_report_defs()` | Returns the full report registry |
| `trx_handle_csv($db, $opts)` | Handles CSV export request; exits if triggered |
| `trx_render($db, $opts)` | Renders the full report UI |
| `trx_ai_summary($db, $key, $rows, $opts)` | Generates AI narrative via `wuc_ai_generate()` |

## Security in reports

- All date/filter inputs validated with `preg_match` before use in SQL.
- Campus filter validated against a whitelist (integer ≥ 0).
- Report key validated against `$opts['allowed']` — never trust raw `$_GET['report']`.
- CSV export uses `fputcsv()` — safe against formula injection via a leading
  quote (consider prefixing numeric-looking values with `'` if the CSV will
  be opened in Excel with untrusted string data).
- AI context strips student names and IDs — only aggregates sent to AI.

## Checklist when adding a report

- [ ] SQL verified against live schema (`DESCRIBE` or `SHOW COLUMNS`)
- [ ] Date strings validated before interpolation into queries
- [ ] Campus / scope filter applied in the `builder` closure
- [ ] `trx_handle_csv()` called before any HTML
- [ ] Report key added to each wrapper's `$opts['allowed']` that should expose it
- [ ] Empty-state handled (builder returns `[]` → engine shows "No data" message)
- [ ] AI summary is optional — table renders even when AI is offline
