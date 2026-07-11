<?php
require_once "includes/admin.php";
require_once dirname(__DIR__) . '/includes/legacy_access_helpers.php';

$format = strtolower((string)($_REQUEST['format'] ?? 'csv'));
if (!in_array($format, ['csv', 'excel', 'pdf'], true)) {
    $format = 'csv';
}
$fields = [
    'field_name' => isset($_REQUEST['field_name']),
    'field_department' => isset($_REQUEST['field_department']),
    'field_roles' => isset($_REQUEST['field_roles']),
    'field_permissions' => isset($_REQUEST['field_permissions'])
];
if (!in_array(true, $fields, true)) {
    $fields = [
        'field_name' => true,
        'field_department' => true,
        'field_roles' => true,
        'field_permissions' => true,
    ];
}

// Helper to detect columns (Reused)
$__detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        $colEsc = $db->real_escape_string($col);
        if ($res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$colEsc}'")) {
            if ($res->num_rows > 0) { $res->free(); return $col; }
            $res->free();
        }
    }
    return null;
};

// Resolve Column Logic
$staffDeptCol = $__detectColumn($db, 'staff', ['DeptID', 'deptId', 'department_id']);
$deptIdNumericCol = $__detectColumn($db, 'departments', ['DeptID', 'id', 'department_id']);
$deptNameCol = $__detectColumn($db, 'departments', ['DeptName', 'deptName', 'department_name', 'name']);

$deptJoin = '';
$deptNameExpr = "NULL";
$groupByExpr = "sp.staff_id";

if ($staffDeptCol && $deptNameCol) {
    if ($deptIdNumericCol) {
        $deptJoin = "LEFT JOIN departments d ON s.`{$staffDeptCol}` = d.`{$deptIdNumericCol}`";
        $deptNameExpr = "d.`{$deptNameCol}`";
        $groupByExpr = "d.`{$deptNameCol}`";
    }
}

// Build Query
$query = "SELECT DISTINCT sp.staff_id AS St_id,
          s.staff_id,
          s.title,
          s.Fname,
          s.Lname,
          {$deptNameExpr} AS DeptName,
          GROUP_CONCAT(DISTINCT p.PosName ORDER BY p.PosName SEPARATOR ', ') AS PosName
          FROM staff_positions sp
          JOIN staff s ON sp.staff_id = s.staff_id
          JOIN positions p ON sp.PosID = p.PosID
          {$deptJoin}
          GROUP BY sp.staff_id, s.staff_id, s.title, s.Fname, s.Lname, {$groupByExpr}
          ORDER BY s.Fname";

$result = $db->query($query);
if (!$result) {
    error_log('export_access_data query failed: ' . $db->error);
    http_response_code(500);
    die("Error fetching data");
}

$data = [];
while ($row = $result->fetch_object()) {
    $item = [];
    if($fields['field_name']) $item['Name'] = trim($row->title . ' ' . $row->Fname . ' ' . $row->Lname);
    if($fields['field_department']) $item['Department'] = $row->DeptName ?? 'N/A';
    if($fields['field_roles']) $item['Roles'] = $row->PosName;
    if($fields['field_permissions']) $item['Permissions'] = wuc_staff_permission_labels($db, (string)$row->staff_id);
    $data[] = $item;
}

// ---------------------------------------------------------
// EXPORT HANDLERS
// ---------------------------------------------------------

$filename = "access_report_" . date('Y-m-d');

if ($format === 'pdf') {
    // PDF Export
    if (file_exists("../lib/tcpdf/tcpdf.php")) {
        require_once "../lib/tcpdf/tcpdf.php";
        
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('ITC Portal');
        $pdf->SetTitle('Access Report');
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();
        
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'User Access Rights Report', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 10, 'Generated on ' . date('d M Y H:i'), 0, 1, 'C');
        $pdf->Ln(5);
        
        $pdf->SetFont('helvetica', '', 9);
        
        $html = '<table class="table table-hover align-middle">';
        $html .= '<tr style="background-color:#eee; font-weight:bold;">';
        if($data) {
            foreach(array_keys($data[0]) as $header) {
                $html .= '<th>' . htmlspecialchars((string)$header, ENT_QUOTES, 'UTF-8') . '</th>';
            }
        }
        $html .= '</tr>';
        
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output($filename . '.pdf', 'D');
        exit;
    } else {
        die("PDF Library missing. Please select CSV/Excel.");
    }

} elseif ($format === 'excel') {
    // Simple Excel (HTML Table)
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename.xls\"");
    
    echo '<table class="table table-hover align-middle">';
    echo '<tr>';
    if($data) {
        foreach(array_keys($data[0]) as $header) {
            echo '<th style="background-color:#f0f0f0;">' . htmlspecialchars((string)$header, ENT_QUOTES, 'UTF-8') . '</th>';
        }
    }
    echo '</tr>';
    
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    exit;

} else {
    // CSV Export (Default)
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    
    if($data) {
        fputcsv($output, array_keys($data[0]));
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit;
}
?>
