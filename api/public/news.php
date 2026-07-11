<?php
declare(strict_types=1);
define('WUC_PUBLIC_API', true);
require __DIR__ . '/_bootstrap.php';

$db = wuc_public_db();

// The news table is optional. Until it is created the endpoint returns an empty
// dataset (a valid contract) rather than an error, so the website can wire the
// "News" feed now and have it fill in automatically later.
if (!wuc_public_table_exists($db, 'news')) {
    wuc_public_json(['data' => [], 'count' => 0]);
}

$rows = wuc_public_select($db,
    "SELECT title, summary, body, published_at
     FROM news
     WHERE status = 'published'
     ORDER BY published_at DESC
     LIMIT 50"
);

wuc_public_json(['data' => $rows, 'count' => count($rows)]);
