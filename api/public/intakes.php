<?php
declare(strict_types=1);
define('WUC_PUBLIC_API', true);
require __DIR__ . '/_bootstrap.php';

$db = wuc_public_db();

$rows = wuc_public_select($db,
    "SELECT intake_name,
            intake_year,
            intake_type,
            start_date,
            end_date,
            application_open_date,
            application_close_date,
            status
     FROM intakes
     ORDER BY start_date DESC"
);

// Derive a public-friendly 'applications_open' flag from the date window so the
// website never has to interpret internal status strings.
$today = date('Y-m-d');
foreach ($rows as &$row) {
    $open  = $row['application_open_date'] ?? null;
    $close = $row['application_close_date'] ?? null;
    $row['applications_open'] = ($open !== null && $open <= $today)
        && ($close === null || $close >= $today);
}
unset($row);

wuc_public_json([
    'data'  => $rows,
    'count' => count($rows),
]);
