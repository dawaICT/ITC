<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Accountant','Systems Admin']);

$rows = [];
// Be tolerant of schema variants: prefer is_active, then status, then all.
$programCols = [];
$colRes = $db->query("SHOW COLUMNS FROM `programs`");
if (!$colRes) {
    json_error('Unable to inspect programs schema', 500);
}
while ($col = $colRes->fetch_assoc()) {
    $programCols[] = (string)$col['Field'];
}
$colRes->free();

$hasCol = static function (string $name) use ($programCols): bool {
    return in_array($name, $programCols, true);
};

$sql = "SELECT program_code, program_name FROM programs";
if ($hasCol('is_active')) {
    $sql .= " WHERE is_active = 1";
} elseif ($hasCol('status')) {
    $sql .= " WHERE LOWER(status) = 'active'";
}
$sql .= " ORDER BY program_code";

$res = $db->query($sql);
if (!$res) {
    error_log('finance_get_programs.php query failed: ' . $db->error);
    json_error('Unable to load programs', 500);
}

while ($row = $res->fetch_assoc()) {
    $rows[] = [
        'program_code' => (string)($row['program_code'] ?? ''),
        'program_name' => (string)($row['program_name'] ?? ''),
    ];
}
$res->free();

json_success($rows);


