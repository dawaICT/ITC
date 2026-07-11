<?php
// update_department.php — edit an existing department (AJAX, JSON)
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

define('IS_SCRIPT', true);
require_once "includes/admin.php";
require_once dirname(__DIR__) . '/includes/department_schema_helpers.php';

$response = ['success' => false, 'message' => ''];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new Exception('Invalid request method.');
    }
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        throw new Exception('Invalid security token. Please refresh the page.');
    }

    $id        = trim((string)($_POST['department_pk'] ?? ''));
    $deptCode  = strtoupper(trim((string)($_POST['departmentId'] ?? '')));
    $deptName  = trim((string)($_POST['departmentName'] ?? ''));
    $faculty   = trim((string)($_POST['faculty'] ?? ''));
    $sectionId = trim((string)($_POST['section_id'] ?? ''));

    if ($id === '') {
        throw new Exception('Department id is required.');
    }
    if (!preg_match('/^[A-Z][A-Z0-9]{1,19}$/', $deptCode)) {
        throw new Exception('Invalid department code. Use 2-20 letters/digits (e.g. CS, ENG01).');
    }
    if ($deptName === '' || mb_strlen($deptName) < 2 || mb_strlen($deptName) > 100) {
        throw new Exception('Department name must be between 2 and 100 characters.');
    }

    $cols = wuc_department_columns($db);
    $pkCol      = wuc_department_pk_column($db);
    $codeCol    = $cols['department_code'] ?? ($cols['code'] ?? null);
    $nameCol    = $cols['department_name'] ?? ($cols['name'] ?? 'department_name');
    $facultyCol = $cols['faculty'] ?? null;
    $sectionCol = $cols['section_id'] ?? null;

    // Ensure department exists
    $chk = $db->prepare("SELECT `{$pkCol}` FROM departments WHERE `{$pkCol}` = ? LIMIT 1");
    $chk->bind_param('s', $id);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        $chk->close();
        throw new Exception('Department not found.');
    }
    $chk->close();

    // Unique code (excluding self)
    if ($codeCol) {
        $dup = $db->prepare("SELECT `{$pkCol}` FROM departments WHERE `{$codeCol}` = ? AND `{$pkCol}` <> ? LIMIT 1");
        $dup->bind_param('ss', $deptCode, $id);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $dup->close();
            throw new Exception('Another department already uses that code.');
        }
        $dup->close();
    }

    // Validate section if provided
    if ($sectionId !== '' && $sectionCol) {
        $s = $db->prepare("SELECT section_id FROM sections WHERE section_id = ? AND status = 'active' LIMIT 1");
        $s->bind_param('s', $sectionId);
        $s->execute();
        if (!$s->get_result()->fetch_assoc()) {
            $s->close();
            throw new Exception('Selected section does not exist.');
        }
        $s->close();
    }

    // Build dynamic UPDATE
    $set = ["`{$nameCol}` = ?"];
    $types = 's';
    $values = [$deptName];
    if ($codeCol) {
        $set[] = "`{$codeCol}` = ?";
        $types .= 's';
        $values[] = $deptCode;
    }
    if ($facultyCol) {
        $set[] = "`{$facultyCol}` = ?";
        $types .= 's';
        $values[] = $faculty;
    }
    if ($sectionCol) {
        $set[] = "`{$sectionCol}` = ?";
        $types .= 's';
        $values[] = ($sectionId !== '' ? $sectionId : null);
    }

    $types .= 's';
    $values[] = $id;

    $sql = "UPDATE departments SET " . implode(', ', $set) . " WHERE `{$pkCol}` = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database error: ' . $db->error);
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }
    $stmt->close();

    $response = [
        'success'         => true,
        'message'         => 'Department updated successfully!',
        'department_id'   => $id,
        'department_code' => $deptCode,
        'department_name' => $deptName,
    ];
} catch (Throwable $e) {
    error_log('update_department error: ' . $e->getMessage());
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
