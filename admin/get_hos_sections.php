<?php
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once "includes/admin.php";

$response = ['data' => []];

try {
    $sql = "
        SELECT
            s.section_id,
            s.section_name,
            s.section_type,
            s.department_id,
            s.status,
            CONCAT(st.Fname, ' ', st.Lname) AS hos_name,
            ssa.staff_id AS hos_staff_id
        FROM sections s
        LEFT JOIN staff_section_assignments ssa
            ON ssa.section_id = s.section_id
           AND ssa.role_key = 'head_of_department'
           AND ssa.status = 'active'
        LEFT JOIN staff st ON st.staff_id = ssa.staff_id
        WHERE s.status = 'active'
        ORDER BY FIELD(s.section_id, 'TRANSPORT', 'ENGICT') DESC, s.section_name ASC
    ";
    $result = $db->query($sql);
    if (!$result) {
        throw new Exception($db->error);
    }

    while ($row = $result->fetch_assoc()) {
        $label = $row['section_name'];
        if (!empty($row['hos_name'])) {
            $label .= ' - current HOS: ' . $row['hos_name'];
        }
        $response['data'][] = [
            'section_id' => $row['section_id'],
            'section_name' => $row['section_name'],
            'section_type' => $row['section_type'],
            'department_id' => $row['department_id'],
            'hos_staff_id' => $row['hos_staff_id'],
            'label' => $label,
        ];
    }
} catch (Throwable $e) {
    http_response_code(500);
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
exit;
