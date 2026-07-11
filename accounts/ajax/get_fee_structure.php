<?php
// Accounts-local proxy of admin/ajax/get_fee_structure.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$root_path = dirname(dirname(dirname(__FILE__)));
require_once $root_path . '/db/connect.php';

header('Content-Type: application/json');

try {
    $draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;
    $start = isset($_POST['start']) ? (int)$_POST['start'] : 0;
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 10;
    $search = $_POST['search']['value'] ?? '';
    $order = $_POST['order'] ?? [];

    $feeColumns = [];
    if ($feeRes = $db->query("SHOW COLUMNS FROM fee_structure")) {
        while ($row = $feeRes->fetch_assoc()) {
            $feeColumns[] = (string)$row['Field'];
        }
        $feeRes->free();
    }
    $hasCourseCode = in_array('course_code', $feeColumns, true);

    $hasPeriodType = false;
    $hasPeriodMode = false;
    if ($checkRes = $db->query("SHOW COLUMNS FROM programs")) {
        while ($row = $checkRes->fetch_assoc()) {
            if ($row['Field'] === 'period_type') {
                $hasPeriodType = true;
            }
            if ($row['Field'] === 'period_mode') {
                $hasPeriodMode = true;
            }
        }
        $checkRes->free();
    }

    $periodSelect = $hasPeriodType ? ", p.period_type" : ($hasPeriodMode ? ", p.period_mode AS period_type" : ", 'semester' AS period_type");
    $selectFields = "fs.*, p.program_name" . $periodSelect;
    $query = "SELECT $selectFields FROM fee_structure fs LEFT JOIN programs p ON fs.program_code = p.program_code WHERE 1=1";

    $filterProgram = $_POST['filterProgram'] ?? '';
    $filterYear = $_POST['filterYear'] ?? '';
    $filterStatus = $_POST['filterStatus'] ?? '';
    if ($filterProgram !== '') { $query .= " AND fs.program_code = '" . $db->real_escape_string($filterProgram) . "'"; }
    if ($filterYear !== '') { $query .= " AND fs.year_of_study = " . intval($filterYear); }
    if ($filterStatus !== '') { $query .= " AND fs.status = '" . $db->real_escape_string($filterStatus) . "'"; }

    if ($search !== '') {
        $like = $db->real_escape_string($search);
        $searchParts = ["p.program_name LIKE '%$like%'", "fs.fee_description LIKE '%$like%'"];
        if ($hasCourseCode) {
            $searchParts[] = "fs.course_code LIKE '%$like%'";
        }
        $query .= " AND (" . implode(' OR ', $searchParts) . ")";
    }

    $countQuery = "SELECT COUNT(*) AS total FROM ($query) AS t";
    $countResult = $db->query($countQuery);
    if (!$countResult) { throw new Exception("Count query failed: " . $db->error); }
    $totalRecords = (int)$countResult->fetch_assoc()['total'];
    $countResult->free();

    if (!empty($order)) {
        $requestedIndex = (int)$order[0]['column'];
        $orderDir = strtoupper($order[0]['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $columnMap = [0 => 'id', 1 => 'program_name', 2 => 'year_of_study', 3 => 'semester', 4 => 'fee_description', 5 => 'amount', 6 => 'status'];
        $orderColumn = $columnMap[$requestedIndex] ?? 'id';
        $query .= " ORDER BY $orderColumn $orderDir";
    } else {
        $query .= " ORDER BY id ASC";
    }

    $query .= " LIMIT $start, $length";
    $result = $db->query($query);
    if (!$result) { throw new Exception("Main query failed: " . $db->error); }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'id' => (int)$row['id'],
            'program_name' => $row['program_name'],
            'year_of_study' => (int)$row['year_of_study'],
            'semester' => (int)$row['semester'],
            'fee_description' => $row['fee_description'],
            'amount' => (float)$row['amount'],
            'status' => $row['status'],
            'period_type' => $row['period_type'] ?? 'semester'
        ];
    }
    $result->free();

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $totalRecords,
        'data' => $data,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while fetching fee structures']);
}
?>


