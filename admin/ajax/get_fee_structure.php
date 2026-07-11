<?php
// Hardened JSON endpoint for DataTables - Fee Structure list
error_reporting(E_ALL);
// Do not print notices/warnings to output to avoid corrupting JSON
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/../logs/error.log');

// Define root path
$root_path = dirname(dirname(dirname(__FILE__)));

// Include database connection
require_once $root_path . '/db/connect.php';

// Set JSON header
header('Content-Type: application/json');

try {
    // Log incoming request for debugging
    if (!headers_sent()) {
        error_log("[get_fee_structure] POST: " . print_r($_POST, true));
    }

    // DataTables parameters
    $draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;
    $start = isset($_POST['start']) ? (int)$_POST['start'] : 0;
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 10;
    $search = $_POST['search']['value'] ?? '';
    $order = $_POST['order'] ?? [];

    // Detect whether programs.period_mode exists (actual column name in DB)
    $hasPeriodMode = false;
    if ($checkRes = $db->query("SHOW COLUMNS FROM programs LIKE 'period_mode'")) {
        $hasPeriodMode = $checkRes->num_rows > 0;
        $checkRes->free();
    }

    // Build base query parts - use period_mode as alias period_type for frontend compatibility
    $selectFields = "fs.*, p.program_name" . ($hasPeriodMode ? ", p.period_mode AS period_type" : ", 'semester' AS period_type");
    $query = "SELECT $selectFields FROM fee_structure fs LEFT JOIN programs p ON fs.program_code = p.program_code WHERE 1=1";

    // Filters
    $filterProgram = $_POST['filterProgram'] ?? '';
    $filterYear = $_POST['filterYear'] ?? '';
    $filterStatus = $_POST['filterStatus'] ?? '';

    if ($filterProgram !== '') {
        $query .= " AND fs.program_code = '" . $db->real_escape_string($filterProgram) . "'";
    }
    if ($filterYear !== '') {
        $query .= " AND fs.year_of_study = " . intval($filterYear);
    }
    if ($filterStatus !== '') {
        $query .= " AND fs.status = '" . $db->real_escape_string($filterStatus) . "'";
    }

    // Search across program name, fee description, course code
    if ($search !== '') {
        $like = $db->real_escape_string($search);
        $query .= " AND (p.program_name LIKE '%$like%' OR fs.fee_description LIKE '%$like%' OR fs.course_code LIKE '%$like%')";
    }

    // Count (filtered)
    $countQuery = "SELECT COUNT(*) AS total FROM ($query) AS t";
    $countResult = $db->query($countQuery);
    if (!$countResult) {
        throw new Exception("Count query failed: " . $db->error);
    }
    $totalRecords = (int)$countResult->fetch_assoc()['total'];
    $countResult->free();

    // Ordering (map DataTables columns to DB columns)
    $columns = ['id', 'program_name', 'year_of_study', 'semester', 'fee_description', 'amount', 'status'];
    if (!empty($order)) {
        $requestedIndex = (int)$order[0]['column'];
        $orderDir = strtoupper($order[0]['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        // DataTable shows row-number at index 0; map to id
        // Our mapping ignores the action column which is not orderable.
        $columnMap = [0 => 'id', 1 => 'program_name', 2 => 'year_of_study', 3 => 'semester', 4 => 'fee_description', 5 => 'amount', 6 => 'status'];
        $orderColumn = $columnMap[$requestedIndex] ?? 'id';
        $query .= " ORDER BY $orderColumn $orderDir";
    } else {
        $query .= " ORDER BY p.program_name ASC, fs.year_of_study ASC, fs.semester ASC";
    }

    // Pagination
    $query .= " LIMIT $start, $length";

    // Run main query
    $result = $db->query($query);
    if (!$result) {
        throw new Exception("Main query failed: " . $db->error);
    }

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
    error_log("[get_fee_structure] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching fee structures',
    ]);
}