<?php
// process_department.php — create a department (AJAX, JSON)
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

define('IS_SCRIPT', true);
require_once "includes/admin.php"; // starts session, auth guard, provides $db
require_once dirname(__DIR__) . '/includes/department_schema_helpers.php';

$response = ['success' => false, 'message' => ''];

try {
    // 1. CSRF
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        throw new Exception('Invalid security token. Please refresh the page.');
    }

    // 2. Collect + sanitise
    $deptCode  = strtoupper(trim((string)($_POST['departmentId'] ?? '')));
    $deptName  = trim((string)($_POST['departmentName'] ?? ''));
    $faculty   = trim((string)($_POST['faculty'] ?? ''));
    $sectionId = trim((string)($_POST['section_id'] ?? ''));

    // 3. Validation — code is 2-20 uppercase letters/digits (matches live data
    //    like "CS", "ENG", "ICT" as well as "CS01"); name 2-100 chars.
    if (!preg_match('/^[A-Z][A-Z0-9]{1,19}$/', $deptCode)) {
        throw new Exception('Invalid department code. Use 2-20 letters/digits (e.g. CS, ENG01).');
    }
    if ($deptName === '' || mb_strlen($deptName) < 2 || mb_strlen($deptName) > 100) {
        throw new Exception('Department name must be between 2 and 100 characters.');
    }

    $cols = wuc_department_columns($db);
    $codeCol    = $cols['department_code'] ?? ($cols['code'] ?? null);
    $nameCol    = $cols['department_name'] ?? ($cols['name'] ?? 'department_name');
    $pkCol      = wuc_department_pk_column($db);
    $facultyCol = $cols['faculty'] ?? null; // free-text faculty (faculty_id is a numeric FK we cannot populate)
    $statusCol  = $cols['status'] ?? null;
    $sectionCol = $cols['section_id'] ?? null;

    if (!$codeCol) {
        throw new Exception('This installation has no department_code column to store the code.');
    }

    // Validate section against live sections (optional but must be real if given)
    if ($sectionId !== '' && $sectionCol) {
        $chk = $db->prepare("SELECT section_id FROM sections WHERE section_id = ? AND status = 'active' LIMIT 1");
        $chk->bind_param('s', $sectionId);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            $chk->close();
            throw new Exception('Selected section does not exist.');
        }
        $chk->close();
    } else {
        $sectionId = '';
    }

    // 4. Duplicate code check
    $dup = $db->prepare("SELECT `{$pkCol}` FROM departments WHERE `{$codeCol}` = ? LIMIT 1");
    $dup->bind_param('s', $deptCode);
    $dup->execute();
    if ($dup->get_result()->fetch_assoc()) {
        $dup->close();
        throw new Exception('Department code already exists.');
    }
    $dup->close();

    // 5. Build INSERT from the columns that actually exist
    $insertCols = [$codeCol, $nameCol];
    $placeholders = ['?', '?'];
    $types = 'ss';
    $values = [$deptCode, $deptName];

    if ($facultyCol) {
        $insertCols[] = $facultyCol;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $faculty;
    }
    if ($statusCol) {
        $insertCols[] = $statusCol;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = 'active';
    }
    if ($sectionCol && $sectionId !== '') {
        $insertCols[] = $sectionCol;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $sectionId;
    }

    $colSql = '`' . implode('`, `', $insertCols) . '`';
    $sql = "INSERT INTO departments ({$colSql}) VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database error: ' . $db->error);
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }
    $newId = $db->insert_id;
    $stmt->close();

    $response = [
        'success'         => true,
        'message'         => 'Department created successfully!',
        'department_id'   => $newId,     // numeric PK — used to link programs
        'department_code' => $deptCode,
        'department_name' => $deptName,
    ];
} catch (Throwable $e) {
    error_log('process_department error: ' . $e->getMessage());
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
