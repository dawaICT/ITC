<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication Guard
require_once __DIR__ . '/includes/guard.php';

// Include DB and Services
require_once __DIR__ . '/includes/DatabaseConnection.php';
require_once __DIR__ . '/includes/InvoiceService.php';
require_once __DIR__ . '/includes/StudentDataService.php';
if (function_exists('wuc_should_show_error_details') && wuc_should_show_error_details()) {
    ini_set('display_errors', '0');
}

// Initialize services
try {
    $dbConnection = DatabaseConnection::getInstance();
    $mysqli = $dbConnection->getMysqli();
    
    $invoiceService = new InvoiceService($mysqli);
    $studentDataService = new StudentDataService($mysqli);
} catch (Exception $e) {
    error_log('Invoice page initialization failed: ' . $e->getMessage());
    die('We could not load the invoice right now. Please try again later or contact support.');
}

$studentId = $_SESSION['Sid'] ?? '';
$error = '';

// Retrieve Invoice Data
try {
    $invoiceNumber = $_GET['invoice'] ?? null;
    $invoice = null;

    if ($invoiceNumber) {
        $invoice = $invoiceService->getInvoiceByNumber($invoiceNumber);
        // Security check: ensure invoice belongs to student
        if ($invoice && $invoice['student_id'] !== $studentId) {
            throw new Exception("Unauthorized access to invoice.");
        }
    } else {
        // Fallback: Get most recent invoice
        $invoices = $invoiceService->getStudentInvoices($studentId);
        if (!empty($invoices)) {
            $invoice = $invoices[0];
            $invoiceNumber = $invoice['invoice_number'];
        }
    }

    if (!$invoice) {
        throw new Exception("No invoice found.");
    }

    // Get Line Items
    // Requires registration_id (which corresponds to semester_registration_id in our logic)
    $lineItems = $invoiceService->getInvoiceLineItems($invoice['registration_id']);

    // Get Student Details if missing from invoice join
    if (!isset($invoice['first_name'])) {
        $student = $studentDataService->getStudentWithProgram($studentId);
        $invoice['first_name'] = $student['first_name'] ?? '';
        $invoice['last_name'] = $student['last_name'] ?? '';
        $invoice['program_name'] = $student['program_name'] ?? '';
        $invoice['email'] = $student['email'] ?? '';
        $invoice['phone'] = $student['phone'] ?? '';
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice | <?php echo htmlspecialchars($invoiceNumber ?? 'Error'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 14mm;
        }
        body {
            background-color: #f5f7fa;
            font-family: 'Inter', sans-serif;
            color: #333;
            -webkit-print-color-adjust: exact;
        }
        .invoice-container {
            max-width: 850px;
            margin: 40px auto;
            background: white;
            padding: 50px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            border-radius: 8px;
        }
        .invoice-header {
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .logo-area img {
            max-height: 80px;
        }
        .university-info h2 {
            font-weight: 700;
            color: #1a3a8f;
            margin-bottom: 5px;
        }
        .invoice-title {
            text-align: right;
        }
        .invoice-title h1 {
            font-weight: 800;
            text-transform: uppercase;
            color: #ccc;
            letter-spacing: 2px;
            margin: 0;
            font-size: 2.5rem;
        }
        .status-badge {
            display: inline-block;
            margin-top: 10px;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-paid { background: #d1e7dd; color: #0f5132; }
        .status-pending { background: #fff3cd; color: #664d03; }
        .status-partial { background: #cfe2ff; color: #084298; }
        .status-overdue { background: #f8d7da; color: #842029; }
        
        .bill-to {
            margin-bottom: 30px;
        }
        .bill-to h5 {
            color: #666;
            text-transform: uppercase;
            font-size: 0.85rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .table-invoice th {
            background-color: #f8f9fa;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            border-top: none;
        }
        .table-invoice td {
            padding: 15px 10px;
            vertical-align: middle;
        }
        .amount-col {
            text-align: right;
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        .total-section {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .total-box {
            width: 300px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-weight: 500;
        }
        .grand-total {
            border-top: 2px solid #333;
            border-bottom: 2px solid #333;
            padding: 10px 0;
            margin-top: 10px;
            font-weight: 800;
            font-size: 1.2rem;
            color: #1a3a8f;
        }
        .footer-note {
            margin-top: 50px;
            border-top: 1px solid #eee;
            padding-top: 20px;
            text-align: center;
            font-size: 0.85rem;
            color: #999;
        }
        
        @media print {
            body { background: white; margin: 0; }
            .invoice-container { box-shadow: none; margin: 0; padding: 20px; border: none; }
            .no-print { display: none !important; }
            .btn { display: none !important; }
        }
    </style>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
    
    <div class="container mb-4 no-print mt-4">
        <div class="d-flex justify-content-between">
            <a href="registration.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Registration
            </a>
            <div>
                <button onclick="window.print()" class="btn btn-primary me-2">
                    <i class="fas fa-print me-2"></i>Print Invoice
                </button>
                <a href="make_payment.php" class="btn btn-success">
                    <i class="fas fa-credit-card me-2"></i>Make Payment
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="container mt-5">
            <div class="alert alert-danger shadow-sm text-center p-5">
                <i class="fas fa-exclamation-circle fa-3x mb-3 text-danger"></i>
                <h4>Invoice Unavailable</h4>
                <p class="mb-4"><?php echo htmlspecialchars($error); ?></p>
                <a href="registration.php" class="btn btn-primary">Go Back</a>
            </div>
        </div>
    <?php else: ?>

    <div class="invoice-container">
        <div class="invoice-header">
            <div class="university-info">
                <h2>Rockview University</h2>
                <p class="mb-0 text-muted">123 University Drive, Lusaka</p>
                <p class="mb-0 text-muted">finance@rockview.ac.zm | +260 97 000 0000</p>
            </div>
            <div class="invoice-title">
                <h1>INVOICE</h1>
                <p class="mb-0 text-dark fw-bold">#<?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                
                <?php 
                $statusClass = 'status-pending';
                if ($invoice['status'] === 'paid') $statusClass = 'status-paid';
                elseif ($invoice['status'] === 'partial') $statusClass = 'status-partial';
                elseif ($invoiceService->isOverdue($invoice['invoice_number'])) $statusClass = 'status-overdue';
                ?>
                <span class="status-badge <?php echo $statusClass; ?>">
                    <?php echo htmlspecialchars($invoice['status']); ?>
                </span>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 bill-to">
                <h5>Bill To:</h5>
                <h4 class="mb-1"><?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?></h4>
                <p class="mb-1">Student ID: <strong><?php echo htmlspecialchars($invoice['student_id']); ?></strong></p>
                <p class="mb-1"><?php echo htmlspecialchars($invoice['program_name'] ?? ''); ?></p>
                <p class="mb-1"><?php echo htmlspecialchars($invoice['email']); ?></p>
            </div>
            <div class="col-md-6 text-end">
                <div class="row">
                    <div class="col-6 text-muted">Invoice Date:</div>
                    <div class="col-6 fw-bold"><?php echo date('M d, Y', strtotime($invoice['invoice_date'])); ?></div>
                </div>
                <div class="row mt-2">
                    <div class="col-6 text-muted">Due Date:</div>
                    <div class="col-6 fw-bold"><?php echo date('M d, Y', strtotime($invoice['due_date'])); ?></div>
                </div>
                <div class="row mt-2">
                    <div class="col-6 text-muted">Term:</div>
                    <div class="col-6">
                        <?php echo htmlspecialchars($invoice['academic_year']); ?> 
                        (Sem <?php echo htmlspecialchars($invoice['semester']); ?>)
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <table class="table table-hover align-middle table-invoice">
                <thead class="table-light">
                    <tr>
                        <th style="width: 15%">Code</th>
                        <th style="width: 45%">Description</th>
                        <th style="width: 15%" class="text-center">Credits</th>
                        <th style="width: 25%" class="text-end">Amount (ZMW)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $subtotal = 0;
                    foreach ($lineItems as $item): 
                        $amount = $item['amount'] ?? 0; // Assuming 'amount' alias in query
                        // If amount is not in result, calculate standard rate (fallback)
                        if ($amount == 0) $amount = ($item['credit_hours'] ?? 3) * 350; // default rate
                        
                        $subtotal += $amount;
                    ?>
                    <tr>
                        <td class="fw-bold text-muted"><?php echo htmlspecialchars($item['course_code']); ?></td>
                        <td><?php echo htmlspecialchars($item['course_name']); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($item['credit_hours']); ?></td>
                        <td class="amount-col"><?php echo number_format($amount, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Fixed Registration Fee -->
                    <tr>
                        <td class="fw-bold text-muted">REG-FEE</td>
                        <td>Semester Registration Fee</td>
                        <td class="text-center">-</td>
                        <td class="amount-col">150.00</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="total-section">
            <div class="total-box">
                <div class="total-row">
                    <span class="text-muted">Subtotal:</span>
                    <span><?php echo number_format($subtotal + 150, 2); ?></span>
                </div>
                <div class="total-row text-success">
                    <span class="text-muted">Amount Paid:</span>
                    <span>- <?php echo number_format($invoice['amount_paid'], 2); ?></span>
                </div>
                <div class="total-row grand-total">
                    <span>Balance Due:</span>
                    <span>ZMW <?php echo number_format($invoice['balance'], 2); ?></span>
                </div>
            </div>
        </div>

        <div class="footer-note">
            <p>Thank you for your timely payment.<br>
            Please quote <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong> when making payments.<br>
            For inquiries, contact the Finance Office.</p>
        </div>
    </div>
    
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-print if param present
        if (new URLSearchParams(window.location.search).has('print')) {
            window.print();
        }
    </script>
</body>
</html>
