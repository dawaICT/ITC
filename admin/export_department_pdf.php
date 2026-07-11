<?php
// export_department_pdf.php
require_once "includes/admin.php";
require_once dirname(__DIR__) . '/includes/department_schema_helpers.php';

// Check if TCPDF exists, otherwise fail gracefully
if (!file_exists("../lib/tcpdf/tcpdf.php")) {
    die("Error: TCPDF library not found in ../lib/tcpdf/");
}
require_once "../lib/tcpdf/tcpdf.php";

$deptId = trim((string)($_GET['id'] ?? ''));

if ($deptId === '') {
    die("Invalid Department ID");
}

// Fetch Department & HOS Info (schema-aware)
$dept = wuc_department_fetch_detail($db, $deptId);

if (!$dept) {
    die("Department not found");
}

// Fetch Programs
$programRows = [];
$progStmt = wuc_department_programs_stmt($db, $deptId);
if ($progStmt) {
    $progRes = $progStmt->get_result();
    while ($row = $progRes->fetch_assoc()) {
        $programRows[] = $row;
    }
    $progStmt->close();
}

function formatProgramDurationForPdf($years): string {
    if ($years === null || $years === '' || (float)$years <= 0) {
        return 'N/A';
    }
    $years = round((float)$years, 2);
    if ($years < 1) {
        $months = max(1, (int)round($years * 12));
        return $months . ' mo';
    }
    $label = rtrim(rtrim(number_format($years, 2, '.', ''), '0'), '.');
    return $label . ' yr' . ($years == 1.0 ? '' : 's');
}

// Extend TCPDF to create custom Header and Footer
class DeptReportPDF extends TCPDF {

    //Page header
    public function Header() {
        // Logo
        $image_file = '../images/itc_logo.png';
        if (file_exists($image_file)) {
             $this->Image($image_file, 15, 10, 55, '', 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        
        $this->SetY(15);
        $this->SetX(45);
        $this->SetFont('helvetica', 'B', 20);
        $this->Cell(0, 10, 'Industrial training college', 0, 1, 'L', 0);
        
        $this->SetX(45);
        $this->SetFont('helvetica', '', 12);
        $this->Cell(0, 6, 'Official Department Report', 0, 1, 'L', 0);
        
        // Line break
        $this->Ln(15);
        $this->SetLineWidth(0.5);
        $this->Line(15, 42, 195, 42); // Horizontal line
    }

    // Page footer
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages().' - Generated on '.date('d M Y, H:i').' by ITC Portal', 0, 0, 'C');
    }
}

// Create new PDF document
$pdf = new DeptReportPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('ITC Portal');
$pdf->SetAuthor('Admin System');
$pdf->SetTitle($dept['department_name'] . ' Report');
$pdf->SetSubject('Department Details and Programs');

// Set margins
$pdf->SetMargins(15, 45, 15);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// Add a page
$pdf->AddPage();

// ---------------------------------------------------------

// DEPARTMENT DETAILS SECTION
$pdf->SetFont('helvetica', 'B', 18);
$pdf->Cell(0, 10, mb_strtoupper($dept['department_name']), 0, 1, 'L');

$pdf->SetFont('helvetica', '', 11);
$pdf->SetFillColor(245, 247, 250); // Light blue-grey
$pdf->SetDrawColor(200, 200, 200);

// Info Table Construction
$html = '
<table border="0" cellpadding="8" cellspacing="0">
    <tr>
        <td width="50%" style="background-color:#f8f9fa; border:1px solid #dee2e6;">
            <b>Department Code:</b><br/>'.htmlspecialchars($dept['department_id']).'
        </td>
        <td width="50%" style="background-color:#f8f9fa; border:1px solid #dee2e6;">
            <b>Faculty:</b><br/>'.htmlspecialchars($dept['faculty']).'
        </td>
    </tr>
    <tr>
        <td width="50%" style="background-color:#f8f9fa; border:1px solid #dee2e6;">
            <b>Head of Section:</b><br/>';
            
if (!empty($dept['Fname'])) {
    $hodName = trim(($dept['title'] ?? '') . " " . $dept['Fname'] . " " . $dept['Lname']);
    $html .= htmlspecialchars($hodName);
    if (!empty($dept['email'])) {
        $html .= '<br/><span style="font-size:9pt; color:#666;">' . htmlspecialchars($dept['email']) . '</span>';
    }
} else {
    $html .= '<span style="color:#dc3545;">Not Assigned</span>';
}

$html .= '
        </td>
        <td width="50%" style="background-color:#f8f9fa; border:1px solid #dee2e6;">
            <b>Status:</b><br/>
            ' . ($dept['status'] == 'Active' ? '<span style="color:#28a745;">Active</span>' : '<span style="color:#6c757d;">Inactive</span>') . '
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

$pdf->Ln(10);

// PROGRAMS LIST SECTION
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'Offered Programs (' . count($programRows) . ')', 0, 1, 'L');
$pdf->Ln(2);

// Programs Table Header
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(52, 58, 64); // Dark background
$pdf->SetTextColor(255, 255, 255); // White text

// Custom Table Header
$pdf->Cell(30, 8, 'Code', 1, 0, 'C', 1);
$pdf->Cell(90, 8, 'Program Name', 1, 0, 'L', 1); // Wider for name
$pdf->Cell(25, 8, 'Type', 1, 0, 'C', 1);
$pdf->Cell(20, 8, 'Duration', 1, 0, 'C', 1);
$pdf->Cell(15, 8, 'Active', 1, 1, 'C', 1);

// Reset font for data
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(245, 245, 245); // Zebra striping color

$fill = false;

if (count($programRows) > 0) {
    foreach ($programRows as $p) {
        // Code
        $pdf->Cell(30, 7, (string)($p['program_code'] ?? ''), 1, 0, 'C', $fill);
        
        // Name (truncate if too long to avoid huge rows)
        $pName = (string)($p['program_name'] ?? '');
        if (strlen($pName) > 50) $pName = substr($pName, 0, 47) . '...';
        $pdf->Cell(90, 7, $pName, 1, 0, 'L', $fill);
        
        // Type
        $pdf->Cell(25, 7, ucfirst((string)($p['program_type'] ?? '')), 1, 0, 'C', $fill);
        
        // Duration
        $pdf->Cell(20, 7, formatProgramDurationForPdf($p['program_duration'] ?? null), 1, 0, 'C', $fill);
        
        // Status
        $activeMark = $p['is_active'] ? 'Yes' : 'No';
        $pdf->Cell(15, 7, $activeMark, 1, 1, 'C', $fill);
        
        $fill = !$fill; // Toggle zebra striping
    }
} else {
    $pdf->Cell(180, 10, 'No programs assigned to this department yet.', 1, 1, 'C');
}

// ---------------------------------------------------------

// Close and output PDF document
$pdf->Output('Department_Report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $dept['department_id']) . '.pdf', 'I');
?>
