<?php
declare(strict_types=1);
define('WUC_PUBLIC_API', true);
require __DIR__ . '/_bootstrap.php';

$db = wuc_public_db();

$rows = wuc_public_select($db,
    "SELECT p.program_code        AS code,
            p.program_name        AS name,
            p.program_type        AS award,
            p.qualification_level AS level,
            p.program_duration    AS duration_years,
            p.duration_value      AS duration_value,
            p.duration_unit       AS duration_unit,
            p.study_mode          AS study_mode,
            p.program_description AS description,
            d.department_name     AS field
     FROM programs p
     LEFT JOIN departments d ON p.department_id = d.id
     WHERE COALESCE(p.is_active, 1) = 1
       AND p.program_code <> 'TEST-PROG'
       AND p.program_name NOT LIKE '%seed%'
     ORDER BY p.program_name"
);

wuc_public_json([
    'data'  => $rows,
    'count' => count($rows),
]);
