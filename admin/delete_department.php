<?php
// delete_department.php — delete a department by primary key (AJAX, JSON)
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

define('IS_SCRIPT', true);
require_once "includes/admin.php";
require_once dirname(__DIR__) . '/includes/department_schema_helpers.php';

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        throw new Exception('Security token mismatch. Please refresh the page.');
    }

    $deptId = trim((string)($_POST['id'] ?? ''));
    if ($deptId === '') {
        throw new Exception('Department ID is required.');
    }

    $cols  = wuc_department_columns($db);
    $pkCol = wuc_department_pk_column($db);
    $codeCol = $cols['department_code'] ?? ($cols['code'] ?? null);

    // Resolve the numeric PK whether the caller sent the id or the code.
    $pkValue = null;
    $lookupSql = "SELECT `{$pkCol}` AS pk FROM departments WHERE `{$pkCol}` = ?";
    if ($codeCol) {
        $lookupSql .= " OR `{$codeCol}` = ?";
    }
    $lookupSql .= " LIMIT 1";
    $lk = $db->prepare($lookupSql);
    if ($codeCol) {
        $lk->bind_param('ss', $deptId, $deptId);
    } else {
        $lk->bind_param('s', $deptId);
    }
    $lk->execute();
    if ($row = $lk->get_result()->fetch_assoc()) {
        $pkValue = (string)$row['pk'];
    }
    $lk->close();

    if ($pkValue === null) {
        throw new Exception('Department not found or already deleted.');
    }

    // Integrity check: block deletion when programs still reference this department.
    if (wuc_table_exists_simple($db, 'programs')) {
        $progHasDept = false;
        if ($c = @$db->query("SHOW COLUMNS FROM programs LIKE 'department_id'")) {
            $progHasDept = $c->num_rows > 0;
            $c->free();
        }
        if ($progHasDept) {
            $pc = $db->prepare("SELECT COUNT(*) AS cnt FROM programs WHERE department_id = ?");
            $pc->bind_param('s', $pkValue);
            $pc->execute();
            $cnt = (int)($pc->get_result()->fetch_assoc()['cnt'] ?? 0);
            $pc->close();
            if ($cnt > 0) {
                throw new Exception("Cannot delete. This department still has {$cnt} linked program(s). Reassign or remove them first.");
            }
        }
    }

    $stmt = $db->prepare("DELETE FROM departments WHERE `{$pkCol}` = ? LIMIT 1");
    $stmt->bind_param('s', $pkValue);
    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    if ($ok) {
        $response = ['success' => true, 'message' => 'Department deleted successfully.'];
    } else {
        throw new Exception('Department not found or already deleted.');
    }
} catch (Throwable $e) {
    error_log('delete_department error: ' . $e->getMessage());
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
