<?php
/**
 * Payment Receipt - View, Print, and Download as PDF
 * Supports both browser viewing and PDF download via Dompdf
 */

// Check if Dompdf is available
$dompdfAvailable = false;
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    $dompdfAvailable = class_exists('Dompdf\Dompdf');
}

// 1. Security and session management - must be at the very top
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';

// Check if user is logged in
if (!isset($_SESSION['Sid'])) {
    header('Location: ../student_login.php');
    exit();
}
$currentStudentId = (string)$_SESSION['Sid'];

// Ensure $db is defined
if (!isset($db) || !$db) {
    die('<div class="alert alert-danger">Database connection failed.</div>');
}

// Helper function fallback
if (!function_exists('wuc_column_exists')) {
    function wuc_column_exists($db, $table, $column) {
        $result = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $result && $result->num_rows > 0;
    }
}

// 2. Determine mode: 'pdf' for download, default for browser view
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'view';
$referenceNumber = isset($_GET['view']) ? trim($_GET['view']) : (isset($_GET['invoice']) ? trim($_GET['invoice']) : '');

// 3. Fetch receipt data
$record = null;
$errorMessage = '';

if (!empty($referenceNumber)) {
    $sql = "SELECT
            p.student_id,
            p.receipt_no AS reference_number,
            p.amount AS amount_paid,
            p.payment_date,
            p.description AS narration,
            p.method AS channel,
            p.semester,
            p.academic_year AS year_of_study,
            p.posted_by AS received_by,
            p.status AS payment_status,
            s.Fname, s.Lname, s.SID,
            stp.program_code, pr.program_name
            FROM payments p
            INNER JOIN students s ON s.SID = CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_general_ci
            LEFT JOIN student_program stp ON stp.Sid COLLATE utf8mb4_unicode_ci = CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            LEFT JOIN programs pr ON stp.program_code = pr.program_code
            WHERE p.receipt_no = ?
              AND p.student_id = ?
            LIMIT 1";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ss", $referenceNumber, $currentStudentId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $record = $result->fetch_object();
        } else {
            $errorMessage = "Receipt not found for reference: " . htmlspecialchars($referenceNumber);
        }
        $stmt->close();
    } else {
        $errorMessage = "Database query error.";
    }
} else {
    $errorMessage = "No receipt reference provided.";
}

// 4. Generate receipt HTML (used for both view and PDF)
function generateReceiptHTML($record, $forPdf = false) {
    date_default_timezone_set('Africa/Lusaka');
    $printDate = date('d M Y, h:i A');
    
    // Inline styles for PDF compatibility (Dompdf requires inline/embedded CSS)
    $css = '
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 14px; color: #1e293b; background: #fff; }
        .receipt-container { max-width: 800px; margin: 0 auto; padding: 40px; }
        .receipt-card { background: #fff; border: 2px solid #e2e8f0; border-radius: 12px; padding: 40px; position: relative; }
        
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 80px; color: rgba(37, 99, 235, 0.06); font-weight: 900; z-index: 0; pointer-events: none; white-space: nowrap; letter-spacing: 8px; }
        .receipt-content { position: relative; z-index: 1; }
        
        .receipt-header { text-align: center; padding-bottom: 30px; border-bottom: 2px solid #e2e8f0; margin-bottom: 30px; }
        .wuc-logo-frame { display: flex; align-items: center; justify-content: center; width: 100%; min-height: 78px; margin: 0 auto 15px; text-align: center; }
        .wuc-logo-img, .wuc-logo-frame img { display: block; max-width: 220px; width: auto; height: auto; max-height: 72px; margin: 0 auto; object-fit: contain; object-position: center center; }
        .receipt-header h1 { font-size: 24px; font-weight: 700; color: #1e293b; margin: 0 0 5px 0; }
        .receipt-header .subtitle { color: #64748b; font-size: 14px; margin: 5px 0; }
        .receipt-number { display: inline-block; background: #eff6ff; color: #2563eb; padding: 8px 20px; border-radius: 8px; font-family: "Monaco", "Consolas", monospace; font-size: 16px; font-weight: 600; margin-top: 15px; }
        
        .info-grid { display: table; width: 100%; margin-bottom: 30px; }
        .info-row { display: table-row; }
        .info-cell { display: table-cell; padding: 12px 15px; vertical-align: top; }
        .info-cell.left { width: 50%; border-right: 1px solid #f1f5f9; }
        .info-cell.right { width: 50%; padding-left: 30px; }
        .info-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 600; margin-bottom: 4px; }
        .info-value { font-size: 15px; color: #1e293b; font-weight: 500; }
        
        .section-title { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; font-weight: 700; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
        
        .payment-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .payment-table th { background: #f8fafc; padding: 12px 15px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; font-weight: 600; border-bottom: 2px solid #e2e8f0; }
        .payment-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #334155; }
        .payment-table .amount { font-size: 18px; font-weight: 700; color: #059669; }
        
        .total-row { background: #f0fdf4; }
        .total-row td { font-weight: 700; color: #166534; font-size: 16px; padding: 18px 15px; }
        
        .receipt-footer { border-top: 2px solid #e2e8f0; padding-top: 25px; margin-top: 20px; }
        .footer-grid { display: table; width: 100%; }
        .footer-cell { display: table-cell; vertical-align: middle; }
        .footer-cell.left { width: 60%; }
        .footer-cell.right { width: 40%; text-align: right; }
        .footer-note { font-size: 12px; color: #64748b; line-height: 1.6; }
        .print-time { font-size: 11px; color: #94a3b8; }
        
        .signature-line { border-top: 1px solid #cbd5e1; width: 200px; margin-top: 40px; padding-top: 8px; font-size: 11px; color: #64748b; text-align: center; }
        
        .status-badge { display: inline-block; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        
        @page { size: A4 portrait; margin: 12mm; }
        @media print {
            body { 
                print-color-adjust: exact; 
                -webkit-print-color-adjust: exact; 
                background: #fff !important;
                height: 100vh !important;
                max-height: 100vh !important;
                overflow: hidden !important;
            }
            .receipt-container { 
                padding: 10px !important; 
                max-height: 100vh !important;
                overflow: hidden !important;
            }
            .receipt-card {
                padding: 20px !important;
                border: 1px solid #cbd5e1 !important;
                border-radius: 8px !important;
            }
            .info-grid { margin-bottom: 15px !important; }
            .payment-table { margin-bottom: 15px !important; }
            .receipt-footer { margin-top: 10px !important; padding-top: 15px !important; }
            .no-print { display: none !important; }
        }
    </style>';
    
    // Find logo
    $logoPath = '';
    $possiblePaths = ['images/itc_logo.png', '../images/itc_logo.png'];
    foreach ($possiblePaths as $path) {
        if (file_exists(__DIR__ . '/' . $path)) {
            $logoPath = $forPdf ? __DIR__ . '/' . $path : $path;
            break;
        }
    }
    
    // For PDF, embed logo as base64
    $logoHtml = '';
    if ($logoPath && $forPdf && file_exists($logoPath)) {
        $logoData = base64_encode(file_get_contents($logoPath));
        $logoHtml = '<span class="wuc-logo-frame"><img src="data:image/png;base64,' . $logoData . '" alt="ITC Logo" class="wuc-logo-img report-logo"></span>';
    } elseif ($logoPath && !$forPdf) {
        $logoHtml = '<span class="wuc-logo-frame"><img src="' . htmlspecialchars($logoPath) . '" alt="ITC Logo" class="wuc-logo-img report-logo"></span>';
    }
    
    $status = isset($record->payment_status) ? $record->payment_status : 'completed';
    $statusClass = $status === 'completed' ? 'status-completed' : 'status-pending';
    $statusText = ucfirst($status);
    
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt - ' . htmlspecialchars($record->reference_number) . '</title>
    ' . $css . '
</head>
<body class="single-page-document no-auto-print">
    <div class="receipt-container wuc-a4-sheet">
        <div class="receipt-card">
            <div class="watermark">PAID</div>
            
            <div class="receipt-content">
                <div class="receipt-header">
                    ' . $logoHtml . '
                    <h1>Industrial Training Centre</h1>
                    <p class="subtitle">Official Payment Receipt</p>
                    <div class="receipt-number">' . htmlspecialchars($record->reference_number) . '</div>
                </div>
                
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-cell left">
                            <div class="section-title">Student Information</div>
                            <div style="margin-bottom: 12px;">
                                <div class="info-label">Student ID</div>
                                <div class="info-value">' . htmlspecialchars($record->SID) . '</div>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <div class="info-label">Full Name</div>
                                <div class="info-value">' . htmlspecialchars($record->Fname . ' ' . $record->Lname) . '</div>
                            </div>
                            <div>
                                <div class="info-label">Program</div>
                                <div class="info-value">' . htmlspecialchars(($record->program_name ?? 'N/A')) . '</div>
                            </div>
                        </div>
                        <div class="info-cell right">
                            <div class="section-title">Payment Information</div>
                            <div style="margin-bottom: 12px;">
                                <div class="info-label">Payment Date</div>
                                <div class="info-value">' . date('d M Y', strtotime($record->payment_date)) . '</div>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <div class="info-label">Academic Period</div>
                                <div class="info-value">Year ' . htmlspecialchars($record->year_of_study ?? 'N/A') . ', Semester ' . htmlspecialchars($record->semester ?? 'N/A') . '</div>
                            </div>
                            <div>
                                <div class="info-label">Status</div>
                                <div class="info-value"><span class="status-badge ' . $statusClass . '">' . $statusText . '</span></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th style="width: 45%;">Description</th>
                            <th style="width: 25%;">Payment Channel</th>
                            <th style="width: 30%; text-align: right;">Amount (ZMW)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>' . htmlspecialchars($record->narration ?? 'Tuition Payment') . '</td>
                            <td>' . htmlspecialchars(ucfirst($record->channel ?? 'N/A')) . '</td>
                            <td style="text-align: right;">' . number_format($record->amount_paid, 2) . '</td>
                        </tr>
                        <tr class="total-row">
                            <td colspan="2">Total Amount Paid</td>
                            <td style="text-align: right;" class="amount">ZMW ' . number_format($record->amount_paid, 2) . '</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="receipt-footer">
                    <div class="footer-grid">
                        <div class="footer-cell left">
                            <p class="footer-note">
                                <strong>Received by:</strong> ' . htmlspecialchars($record->received_by ?? 'Finance Office') . '<br>
                                This is an official receipt from Industrial Training Centre.<br>
                                Please retain this document for your records.
                            </p>
                        </div>
                        <div class="footer-cell right">
                            <div class="signature-line">Authorized Signature</div>
                            <p class="print-time" style="margin-top: 15px;">Generated: ' . $printDate . '</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';

    return $html;
}

// 5. Handle PDF download
if ($mode === 'pdf' && $record) {
    if (!$dompdfAvailable) {
        // Fallback: If Dompdf is not installed, redirect to print view with message
        header('Location: ?view=' . urlencode($referenceNumber) . '&pdf_error=1');
        exit();
    }
    
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Helvetica');
    
    $dompdf = new \Dompdf\Dompdf($options);
    
    $html = generateReceiptHTML($record, true);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Generate filename
    $filename = 'WUC_Receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $record->reference_number) . '.pdf';
    
    // Stream the PDF
    $dompdf->stream($filename, ['Attachment' => true]);
    exit();
}

// Check for PDF error message
$pdfError = isset($_GET['pdf_error']) && $_GET['pdf_error'] === '1';

// 6. Browser view mode - render full page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt - ITC</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/wuc-premium.css">
    <link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">
    <script src="/wucportal/js/wuc-print-fit.js" defer></script>
    <style>
        :root { --primary-blue: #2563eb; --success-green: #059669; }
        
        body { background: #f1f5f9; }
        .content-wrapper { padding: 2rem; max-width: 900px; margin: 0 auto; }
        .page-header { margin-bottom: 1.5rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 0.25rem; }
        .page-subtitle { color: #64748b; font-size: 0.9rem; margin-bottom: 0; }
        
        .action-bar { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .btn-action { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-weight: 500; font-size: 0.9rem; transition: all 0.2s; text-decoration: none; }
        .btn-pdf { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: white; border: none; }
        .btn-pdf:hover { background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%); color: white; transform: translateY(-1px); }
        .btn-print { background: white; color: #475569; border: 1px solid #e2e8f0; }
        .btn-print:hover { background: #f8fafc; color: #1e293b; }
        .btn-back { background: white; color: #475569; border: 1px solid #e2e8f0; }
        .btn-back:hover { background: #f8fafc; color: #1e293b; }
        
        .receipt-frame { background: white; border-radius: 16px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); overflow: hidden; }
        .receipt-frame iframe { width: 100%; height: 700px; border: none; }
        
        .alert-custom { border-radius: 12px; border: none; padding: 1.25rem 1.5rem; }
        
        @media print {
            .no-print { display: none !important; }
            .content-wrapper { padding: 0; max-width: 100%; }
            .receipt-frame { box-shadow: none; border-radius: 0; }
        }
        
        @media (max-width: 768px) {
            .content-wrapper { padding: 1rem; }
            .action-bar { flex-direction: column; }
            .btn-action { justify-content: center; }
        }
    </style>
</head>
<body class="single-page-document no-auto-print">
    <div class="no-print"><?php require_once __DIR__ . '/includes/navbar.php'; ?></div>
    
    <div class="content-wrapper">
        <div class="page-header no-print">
            <h1 class="page-title">Payment Receipt</h1>
            <p class="page-subtitle">View, print, or download your payment receipt</p>
        </div>
        
        <?php if ($pdfError): ?>
            <div class="alert alert-warning alert-custom no-print mb-3">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>PDF download not available.</strong> Please use the Print button and select "Save as PDF" from your browser's print dialog.
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger alert-custom">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($errorMessage) ?>
            </div>
            <div class="text-center mt-4">
                <a href="fees.php" class="btn-action btn-back">
                    <i class="fas fa-arrow-left"></i> Return to Financial Records
                </a>
            </div>
        <?php else: ?>
            <div class="action-bar no-print">
                <?php if ($dompdfAvailable): ?>
                <a href="?view=<?= urlencode($referenceNumber) ?>&mode=pdf" class="btn-action btn-pdf">
                    <i class="fas fa-file-pdf"></i> Download PDF
                </a>
                <?php else: ?>
                <button onclick="wucPrintSinglePage()" class="btn-action btn-pdf" title="Use browser's Save as PDF option">
                    <i class="fas fa-file-pdf"></i> Save as PDF
                </button>
                <?php endif; ?>
                <button onclick="wucPrintSinglePage()" class="btn-action btn-print">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="fees.php" class="btn-action btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
            
            <div class="receipt-frame">
                <?php echo generateReceiptHTML($record, false); ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
