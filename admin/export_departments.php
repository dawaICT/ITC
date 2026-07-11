<?php
require "includes/admin.php";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="departments_' . date('Y-m-d') . '.csv"');

// Query to get all departments with program counts
$query = "SELECT d.department_id, d.department_name, d.faculty, d.status,
                 COUNT(p.program_code) AS program_count
          FROM departments d
          LEFT JOIN programs p ON p.department_id = d.department_id
          GROUP BY d.department_id, d.department_name, d.faculty, d.status";

$result = $db->query($query);

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, ['Department ID', 'Department Name', 'Faculty', 'Status', 'Programs']);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['department_id'],
            $row['department_name'],
            $row['faculty'] ?? '',
            $row['status'] ?? '',
            (int)$row['program_count'],
        ]);
    }
    $result->free();
}

fclose($output);
$db->close(); 
