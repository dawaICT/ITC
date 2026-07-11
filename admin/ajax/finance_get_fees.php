<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Accountant', 'Systems Admin']);

$program_code = $_GET['program_code'] ?? null;
$year = $_GET['year'] ?? null;

$sql = "SELECT id, program_code, year_of_study, semester, fee_description, amount, status FROM fee_structure WHERE 1=1";
$params = [];
$types = "";

if ($program_code) {
    $sql .= " AND program_code = ?";
    $params[] = $program_code;
    $types .= "s";
}
if ($year) {
    $sql .= " AND year_of_study = ?";
    $params[] = $year;
    $types .= "i";
}

$sql .= " ORDER BY program_code, year_of_study, semester";

$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_all(MYSQLI_ASSOC);

json_success($data);
?>