<?php
// AR Summary PDF export for Receivables
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/role_helpers.php';
require_once __DIR__ . '/../lib/tcpdf/tcpdf.php';

wuc_apply_security_headers(false);
if (session_status() === PHP_SESSION_NONE) {
    wuc_configure_session_cookie();
    session_start();
}
if (!isset($_SESSION['user_id']) && isset($_SESSION['staff_id'])) {
    $_SESSION['user_id'] = $_SESSION['staff_id'];
}
if (!isset($_SESSION['user_id']) || (function_exists('canAccessFinance') && !canAccessFinance())) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// Inputs
$program = isset($_GET['program']) ? trim($_GET['program']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$semesterFilter = isset($_GET['semester']) ? trim($_GET['semester']) : '';

// Determine if semester is available
$hasSemester = false;
if ($check = $db->query("SHOW COLUMNS FROM student_program LIKE 'semester'")) {
    if ($check->num_rows > 0) { $hasSemester = true; }
    $check->free();
}

// Build query similar to UI
$selectExtra = $hasSemester ? ', student_program.semester' : '';
$sql = "SELECT student_payments.Sid, students.Fname, students.Lname,
        student_program.program_code, student_payments.balance, student_payments.created_at" . $selectExtra .
        " FROM student_payments INNER JOIN student_program
        ON student_payments.Sid COLLATE utf8mb4_general_ci = student_program.Sid COLLATE utf8mb4_general_ci
        INNER JOIN students ON student_program.Sid COLLATE utf8mb4_general_ci = students.SID COLLATE utf8mb4_general_ci
        WHERE (student_payments.Sid, student_payments.created_at) IN (
            SELECT student_payments.Sid, MAX(student_payments.created_at) AS latest_entry_date
            FROM student_payments
            GROUP BY student_payments.Sid
        ) AND student_payments.balance > 0";

$params = [];
if ($program !== '') {
    $sql .= " AND student_program.program_code = ?";
    $params[] = $program;
}

// We'll filter status and semester after fetch for simplicity
$stmt = $db->prepare($sql);
if ($stmt === false) {
    http_response_code(500);
    echo 'Failed to prepare query';
    exit;
}
if (!empty($params)) {
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    // Compute aging and status
    $createdAt = !empty($row['created_at']) ? new DateTime($row['created_at']) : new DateTime();
    $today = new DateTime();
    $agingDays = $today->diff($createdAt)->days;
    $statusComputed = ($agingDays > 30) ? 'Overdue' : 'Pending';
    if ($status !== '' && $statusComputed !== $status) continue;
    if ($semesterFilter !== '' && $hasSemester) {
        if (!isset($row['semester']) || strval($row['semester']) !== strval($semesterFilter)) continue;
    }
    $row['aging_days'] = $agingDays;
    $row['status'] = $statusComputed;
    $rows[] = $row;
}
$stmt->close();

// Prepare PDF
$pdf = new TCPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
$pdf->SetCreator('ITC Portal');
$pdf->SetAuthor('ITC Finance');
$pdf->SetTitle('Accounts Receivables Summary');
$pdf->SetMargins(10, 12, 10);
$pdf->AddPage();

$pdfCss = '<style>
.pdf-center { text-align: center; }
.pdf-head-row th { background-color: #f1f1f1; }
.pdf-total { font-size: 14px; font-weight: bold; }
</style>';
$title = '<h2 class="pdf-center">Industrial training college</h2>';
$subtitle = '<h3 class="pdf-center">Accounts Receivables - Latest Balances</h3>';
$filters = '<p class="pdf-center">Filters: '
    . 'Program: ' . htmlspecialchars($program ?: 'All') . ' | '
    . 'Status: ' . htmlspecialchars($status ?: 'All') . ($hasSemester ? (' | Semester: ' . htmlspecialchars($semesterFilter ?: 'All')) : '')
    . '</p>';
$pdf->writeHTML($pdfCss . $title . $subtitle . $filters, true, false, true, false, '');

// Table header
$html = '<table border="1" cellpadding="4" cellspacing="0" width="100%">'
      . '<thead><tr class="pdf-head-row">'
      . '<th width="6%">#</th>'
      . '<th width="12%">SID</th>'
      . '<th width="16%">Name</th>'
      . '<th width="14%">Program</th>'
      . '<th width="14%" align="right">Latest Balance (ZMW)</th>'
      . '<th width="12%">Aging</th>'
      . '<th width="10%">Status</th>'
      . '</tr></thead><tbody>';

$grand = 0.0;
$i = 1;
foreach ($rows as $r) {
    $grand += floatval($r['balance']);
    $name = $r['Fname'] . ' ' . $r['Lname'];
    $html .= '<tr>'
          . '<td>' . $i++ . '</td>'
          . '<td>' . htmlspecialchars($r['Sid']) . '</td>'
          . '<td>' . htmlspecialchars($name) . '</td>'
          . '<td>' . htmlspecialchars($r['program_code']) . '</td>'
          . '<td align="right">' . number_format((float)$r['balance'], 2) . '</td>'
          . '<td>' . intval($r['aging_days']) . ' days</td>'
          . '<td>' . htmlspecialchars($r['status']) . '</td>'
          . '</tr>';
}
$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');

$pdf->Ln(3);
$pdf->writeHTML('<h4 class="pdf-total">Total Receivables: ZMW ' . number_format($grand, 2) . '</h4>', true, false, true, false, '');

$pdf->Output('receivables_summary.pdf', 'I');
exit;
?>


