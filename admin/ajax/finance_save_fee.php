<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant', 'Systems Admin']);

$id = (int)($_POST['id'] ?? 0);
$program_code = trim($_POST['program_code'] ?? '');
$year_of_study = (int)($_POST['year_of_study'] ?? 1);
$semester = (int)($_POST['semester'] ?? 1);
$fee_description = trim($_POST['fee_description'] ?? '');
$amount = (float)($_POST['amount'] ?? 0);
$status = trim($_POST['status'] ?? 'active');

if ($program_code === '' || $fee_description === '' || $amount < 0) {
    json_error('Missing or invalid fields');
}

if ($id > 0) {
    $stmt = $db->prepare("UPDATE fee_structure SET program_code=?, year_of_study=?, semester=?, fee_description=?, amount=?, status=? WHERE id=?");
    $stmt->bind_param('siisdsi', $program_code, $year_of_study, $semester, $fee_description, $amount, $status, $id);
} else {
    $stmt = $db->prepare("INSERT INTO fee_structure (program_code, year_of_study, semester, fee_description, amount, status) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('siisds', $program_code, $year_of_study, $semester, $fee_description, $amount, $status);
}

if ($stmt->execute()) {
    log_audit($db, $_SESSION['staff_id'] ?? 'system', 'fee.save', json_encode($_POST));
    json_success(['id' => $id ?: $db->insert_id]);
} else {
    json_error('Database error: ' . $db->error);
}
?>