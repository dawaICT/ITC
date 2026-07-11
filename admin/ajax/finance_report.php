<?php
require_once "../../includes/finance_helpers.php";
@require_once "../../vendor/autoload.php";
ensure_roles($db, ['Accountant','Systems Admin','Dean']);

$type = $_GET['type'] ?? 'program_profitability';

if ($type === 'program_profitability') {
    // Compute revenue vs costs per program (simplified)
    $sql = "SELECT p.program_code, p.program_name,
                   COALESCE(SUM(i.amount),0) AS revenue,
                   COALESCE(SUM(b.allocated_amount),0) AS costs
            FROM programs p
            LEFT JOIN student_program sp ON sp.program_code = p.program_code
            LEFT JOIN invoices i ON i.student_id = sp.Sid
            LEFT JOIN finance_cost_centers cc ON cc.center_type='program' AND cc.code = p.program_code
            LEFT JOIN finance_budgets b ON b.cost_center_id = cc.id
            GROUP BY p.program_code, p.program_name
            ORDER BY p.program_code";
    $res = $db->query($sql);

    // Output PDF using TCPDF if available; otherwise CSV
    if (class_exists('TCPDF')) {
        $pdf = new TCPDF();
        $pdf->AddPage();
        $html = '<h2>Program Profitability</h2><table class="table table-hover align-middle"><tr><th>Program</th><th>Revenue</th><th>Costs</th><th>Profit</th></tr>';
        while ($r = $res->fetch_assoc()) {
            $profit = (float)$r['revenue'] - (float)$r['costs'];
            $html .= sprintf('<tr><td>%s - %s</td><td align="right">%0.2f</td><td align="right">%0.2f</td><td align="right">%0.2f</td></tr>',
                             htmlspecialchars($r['program_code']), htmlspecialchars($r['program_name']), $r['revenue'], $r['costs'], $profit);
        }
        $html .= '</table>';
        $pdf->writeHTML($html);
        $pdf->Output('program_profitability.pdf', 'I');
        exit;
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="program_profitability.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Program Code', 'Program Name', 'Revenue', 'Costs', 'Profit']);
    while ($r = $res->fetch_assoc()) {
        $profit = (float)$r['revenue'] - (float)$r['costs'];
        fputcsv($out, [
            $r['program_code'],
            $r['program_name'],
            number_format((float)$r['revenue'], 2, '.', ''),
            number_format((float)$r['costs'], 2, '.', ''),
            number_format($profit, 2, '.', ''),
        ]);
    }
    fclose($out);
    exit;
}

if ($type === 'fee_analytics') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fee_analytics.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric', 'Value']);
    $tot = $db->query("SELECT SUM(amount) AS total FROM fee_structure");
    $total = $tot ? ($tot->fetch_assoc()['total'] ?? 0) : 0;
    fputcsv($out, ['Total Fees Configured', number_format((float)$total, 2, '.', '')]);
    fclose($out);
    exit;
}

json_error('Unknown report type', 400);


