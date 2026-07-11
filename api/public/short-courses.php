<?php
declare(strict_types=1);
define('WUC_PUBLIC_API', true);
require __DIR__ . '/_bootstrap.php';

$db = wuc_public_db();

$rows = wuc_public_select($db,
    "SELECT course_code    AS code,
            course_name    AS name,
            description,
            duration_value,
            duration_unit,
            fee,
            delivery_mode,
            prerequisites
     FROM short_courses
     WHERE status = 'active'
     ORDER BY course_name"
);

wuc_public_json([
    'data'  => $rows,
    'count' => count($rows),
]);
