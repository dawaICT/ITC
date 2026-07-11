<?php
declare(strict_types=1);
define('WUC_PUBLIC_API', true);
require __DIR__ . '/_bootstrap.php';

$db = wuc_public_db();

$general = wuc_public_select($db,
    "SELECT requirement_name,
            description,
            is_required,
            applies_to
     FROM application_requirements
     WHERE status = 'active'
     ORDER BY id"
);

$byLevel = wuc_public_select($db,
    "SELECT programme_level,
            minimum_requirement,
            additional_notes
     FROM programme_entry_requirements
     WHERE status = 'active'
     ORDER BY id"
);

wuc_public_json([
    'data' => [
        'general'            => $general,
        'by_programme_level' => $byLevel,
    ],
]);
