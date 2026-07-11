<?php
require_once "../includes/admin.php";
header('Content-Type: application/json');

try {
    // Check if table exists
    $table_exists = $db->query("SHOW TABLES LIKE 'fee_structure'");
    if ($table_exists->num_rows == 0) {
        throw new Exception("Fee structure table does not exist");
    }
    $table_exists->free();

    // First get total records (before filtering)
    $total_result = $db->query("SELECT COUNT(*) as total FROM fee_structure");
    $total_records = $total_result->fetch_object()->total;
    $total_result->free();

    // Build the query
    $query = "
        SELECT fs.*, p.program_name 
        FROM fee_structure fs
        INNER JOIN programs p ON fs.program_code = p.program_code 
        WHERE 1=1
    ";

    $params = [];
    $types = "";

    // Apply filters
    if (!empty($_POST['academic_year'])) {
        $query .= " AND fs.academic_year = ?";
        $params[] = $_POST['academic_year'];
        $types .= "s";
    }

    if (!empty($_POST['program_code'])) {
        $query .= " AND fs.program_code = ?";
        $params[] = $_POST['program_code'];
        $types .= "s";
    }

    if (!empty($_POST['status'])) {
        $query .= " AND fs.status = ?";
        $params[] = $_POST['status'];
        $types .= "s";
    }

    // Get filtered count
    $count_query = "SELECT COUNT(*) as filtered_total FROM (" . $query . ") as filtered";
    $stmt = $db->prepare($count_query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $filtered_total = $stmt->get_result()->fetch_object()->filtered_total;
    $stmt->close();

    // Add ordering
    $query .= " ORDER BY fs.academic_year DESC, fs.semester ASC, fs.year_of_study ASC";

    // Prepare and execute the main query
    $stmt = $db->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception("Failed to execute query: " . $stmt->error);
    }

    $result = $stmt->get_result();

    $fees = [];
    while ($row = $result->fetch_assoc()) {
        $fees[] = [
            'id' => $row['id'],
            'program_code' => $row['program_code'],
            'program_name' => $row['program_name'] . ' (' . $row['program_code'] . ')',
            'year_of_study' => $row['year_of_study'],
            'semester' => $row['semester'],
            'fee_description' => $row['fee_description'],
            'amount' => $row['amount'],
            'status' => $row['status'],
            'updated_at' => date('Y-m-d H:i:s', strtotime($row['updated_at']))
        ];
    }

    $result->free();
    $stmt->close();

    echo json_encode([
        'draw' => isset($_POST['draw']) ? intval($_POST['draw']) : 0,
        'recordsTotal' => $total_records,
        'recordsFiltered' => $filtered_total,
        'data' => $fees
    ]);

} catch (Exception $e) {
    error_log("Fee Structure Error: " . $e->getMessage());
    echo json_encode([
        'draw' => isset($_POST['draw']) ? intval($_POST['draw']) : 0,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => $e->getMessage()
    ]);
} 