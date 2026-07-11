<?php
// get_department.php — return raw fields for a single department (edit prefill)
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

define('IS_SCRIPT', true);
require_once "includes/admin.php";
require_once dirname(__DIR__) . '/includes/department_schema_helpers.php';

$response = ['success' => false, 'message' => ''];

try {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        throw new Exception('Department id is required.');
    }

    $cols = wuc_department_columns($db);
    $pkCol      = wuc_department_pk_column($db);
    $codeCol    = $cols['department_code'] ?? ($cols['code'] ?? null);
    $nameCol    = $cols['department_name'] ?? ($cols['name'] ?? 'department_name');
    $facultyCol = $cols['faculty'] ?? null;
    $statusCol  = $cols['status'] ?? null;
    $sectionCol = $cols['section_id'] ?? null;

    $select = [
        "d.`{$pkCol}` AS id",
        "d.`{$nameCol}` AS department_name",
        ($codeCol ? "d.`{$codeCol}`" : "CAST(d.`{$pkCol}` AS CHAR)") . " AS department_code",
        ($facultyCol ? "d.`{$facultyCol}`" : "''") . " AS faculty",
        ($statusCol ? "d.`{$statusCol}`" : "'active'") . " AS status",
        ($sectionCol ? "d.`{$sectionCol}`" : "''") . " AS section_id",
    ];

    $sql = 'SELECT ' . implode(', ', $select) . " FROM departments d WHERE d.`{$pkCol}` = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database error: ' . $db->error);
    }
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('Department not found.');
    }

    $response = ['success' => true, 'data' => $row];
} catch (Throwable $e) {
    error_log('get_department error: ' . $e->getMessage());
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
