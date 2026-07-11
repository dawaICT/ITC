<?php
require_once "includes/admin.php";

// Get search parameters
$search = $_POST['search'] ?? '';
$period_id = $_POST['period_id'] ?? null;

// Build base query
$query = "
    SELECT 
        p.student_id AS Sid,
        p.amount AS amount_paid,
        0 AS balance,
        p.payment_date,
        p.receipt_no,
        s.Fname,
        s.Lname,
        s.email,
        sprg.program_code,
        p.academic_year,
        p.semester AS semester_term
    FROM payments p
    JOIN students s ON s.SID = CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_general_ci
    LEFT JOIN student_program sprg ON sprg.Sid COLLATE utf8mb4_unicode_ci = CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
    WHERE 1=1
";

// Add period filter
if ($period_id) {
    $stmtPeriod = $db->prepare("SELECT academic_year, period_number FROM academic_periods WHERE id = ? LIMIT 1");
    $periodId = (int)$period_id;
    $stmtPeriod->bind_param('i', $periodId);
    $stmtPeriod->execute();
    $periodRow = $stmtPeriod->get_result()->fetch_object();
    $stmtPeriod->close();
    if ($periodRow) {
        $query .= " AND p.academic_year = '" . $db->real_escape_string((string)$periodRow->academic_year) . "'";
        $query .= " AND p.semester = '" . $db->real_escape_string((string)$periodRow->period_number) . "'";
    }
}

// Add search filter
if (!empty($search)) {
    $search = $db->real_escape_string($search);
    $query .= " AND (
        s.SID LIKE '%$search%' OR 
        s.Fname LIKE '%$search%' OR 
        s.Lname LIKE '%$search%' OR 
        sprg.program_code LIKE '%$search%'
    )";
}

// Add order by
$query .= " ORDER BY p.payment_date DESC, p.id DESC";

// Execute query
$result = $db->query($query);
$data = [];

if ($result) {
    while ($row = $result->fetch_object()) {
        $data[] = [
            $row->Sid,
            "<div class='d-flex align-items-center'>
                <div class='avatar-circle me-2 bg-primary text-white'>
                    " . strtoupper(substr($row->Fname, 0, 1)) . "
                </div>
                <div>
                    <div class='fw-bold'>" . htmlspecialchars($row->Fname . ' ' . $row->Lname) . "</div>
                    <small class='text-muted'>" . htmlspecialchars($row->email) . "</small>
                </div>
            </div>",
            "<span class='badge bg-primary'>" . htmlspecialchars($row->program_code) . "</span>",
            htmlspecialchars($row->academic_year),
            htmlspecialchars($row->semester_term),
            "<span class='text-success fw-bold'>ZMK " . number_format($row->amount_paid, 2) . "</span>",
            "<span class='text-danger fw-bold'>ZMK " . number_format($row->balance, 2) . "</span>",
            $row->payment_date,
            "<div class='btn-group'>
                <button class='btn btn-sm btn-outline-info view-details' 
                        data-id='" . htmlspecialchars($row->receipt_no) . "' 
                        title='View Details'>
                    <i class='fas fa-eye'></i>
                </button>
                <button class='btn btn-sm btn-outline-success print-receipt' 
                        data-id='" . htmlspecialchars($row->receipt_no) . "'
                        title='Print Receipt'>
                    <i class='fas fa-print'></i>
                </button>
            </div>"
        ];
    }
    $result->free();
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'draw' => $_POST['draw'] ?? 1,
    'recordsTotal' => count($data),
    'recordsFiltered' => count($data),
    'data' => $data
]); 
