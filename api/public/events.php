<?php
declare(strict_types=1);
define('WUC_PUBLIC_API', true);
require __DIR__ . '/_bootstrap.php';

$db = wuc_public_db();

// Optional table — returns an empty dataset until 'events' exists.
if (!wuc_public_table_exists($db, 'events')) {
    wuc_public_json(['data' => [], 'count' => 0]);
}

$rows = wuc_public_select($db,
    "SELECT title, description, location, starts_at, ends_at
     FROM events
     WHERE status = 'published'
     ORDER BY starts_at DESC
     LIMIT 50"
);

wuc_public_json(['data' => $rows, 'count' => count($rows)]);
