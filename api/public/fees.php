<?php
declare(strict_types=1);
define('WUC_PUBLIC_API', true);
require __DIR__ . '/_bootstrap.php';

$db = wuc_public_db();

// Optional ?program_code= filter (validated: alnum, dash, slash, max 32).
$programCode = isset($_GET['program_code']) ? trim((string)$_GET['program_code']) : '';
if ($programCode !== '' && !preg_match('/^[A-Za-z0-9\-\/]{1,32}$/', $programCode)) {
    wuc_public_error('Invalid program_code.', 422);
}

// Only programme-level active fees are public marketing data.
$sql = "SELECT program_code,
               year_of_study,
               semester,
               fee_type,
               fee_description,
               amount
        FROM fee_structure
        WHERE entity_type = 'program'
          AND status = 'active'";
$types = '';
$params = [];
if ($programCode !== '') {
    $sql   .= ' AND program_code = ?';
    $types  = 's';
    $params = [$programCode];
}
$sql .= ' ORDER BY program_code, year_of_study, semester';

$rows = wuc_public_select($db, $sql, $types, $params);

wuc_public_json([
    'data'  => $rows,
    'count' => count($rows),
]);
