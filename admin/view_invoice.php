<?php
require_once 'includes/admin.php';
require_once 'includes/header.php';
if (!isset($_GET['invoice'])) {
    header('Location: students_by_admin.php');
    exit();
}

$invoice_number = $db->real_escape_string($_GET['invoice']);

// Get invoice details
$invoice_query = "SELECT i.*, s.Fname, s.Lname, s.email, COALESCE(s.phone, s.mobile) AS phone, p.program_name 
                 FROM invoices i 
                 INNER JOIN students s ON i.student_id = s.SID
                 INNER JOIN student_program sp ON s.SID = sp.Sid
                 INNER JOIN programs p ON sp.program_code = p.program_code
                 WHERE i.invoice_number = ?";

$stmt = $db->prepare($invoice_query);
$stmt->bind_param("s", $invoice_number);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_object();

if (!$invoice) {
    $_SESSION['error_msg'] = "Invoice not found.";
    header('Location: students_by_admin.php');
    exit();
}

// Get course details if student_courses exists
$courses_query = null;
if ($db->query("SHOW TABLES LIKE 'student_courses'")->num_rows > 0) {
    $courses_query = "SELECT c.* 
                     FROM student_courses sc
                     INNER JOIN courses c ON sc.course_code = c.course_code
                     WHERE sc.student_id = ? AND sc.academic_year = ? AND sc.semester = ?";
}

$courses_result = null;
if ($courses_query) {
    $stmt = $db->prepare($courses_query);
    $stmt->bind_param("sss", $invoice->student_id, $invoice->academic_year, $invoice->semester);
    $stmt->execute();
    $courses_result = $stmt->get_result();
}
?>

<div class="container-fluid px-4">
    <div class="dashboard-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Invoice Details</h1>
                <p class="text-muted">View and print student invoice</p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print Invoice
                    </button>
                    <a href="students_by_admin.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Students
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($_SESSION['success_msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['success_msg'];
            unset($_SESSION['success_msg']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <div class="invoice-container">
                <!-- Invoice Header -->
                <div class="text-center mb-4 pb-3 border-bottom">
                    <span class="wuc-logo-frame d-block mb-2">
                        <img src="../assets/images/logo.png" alt="University Logo" class="wuc-logo-img report-logo" height="60">
                    </span>
                    <h4 class="mt-2 mb-0">Industrial training college</h4>
                    <h2 class="text-primary mb-1 mt-3">INVOICE</h2>
                    <p class="mb-0"><?php echo $invoice_number; ?></p>
                </div>

                <!-- Invoice Info -->
                <div class="row mb-4">
                    <div class="col-6">
                        <h5 class="text-muted mb-2">Bill To:</h5>
                        <h6 class="mb-1"><?php echo $invoice->Fname . ' ' . $invoice->Lname; ?></h6>
                        <p class="mb-1">Student ID: <?php echo $invoice->student_id; ?></p>
                        <p class="mb-1">Email: <?php echo $invoice->email; ?></p>
                        <p class="mb-1">Phone: <?php echo $invoice->phone; ?></p>
                    </div>
                    <div class="col-6 text-end">
                        <h5 class="text-muted mb-2">Invoice Details:</h5>
                        <p class="mb-1">Date: <?php echo date('d/m/Y'); ?></p>
                        <p class="mb-1">Academic Year: <?php echo $invoice->academic_year; ?></p>
                        <p class="mb-1">Semester: <?php echo $invoice->semester; ?></p>
                        <p class="mb-1">Program: <?php echo $invoice->program_name; ?></p>
                    </div>
                </div>

                <!-- Courses Table -->
                <div class="table-responsive mb-4">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Credits</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total = 0.0;
                            if ($courses_result) {
                                while ($course = $courses_result->fetch_object()): 
                                    $fee = isset($course->course_fee) ? (float)$course->course_fee : 0.0;
                                    $total += $fee;
                            ?>
                                    <tr>
                                        <td><?php echo $course->course_code; ?></td>
                                        <td><?php echo $course->course_name; ?></td>
                                        <td><?php echo $course->credits; ?></td>
                                        <td class="text-end"><?php echo number_format($fee, 2); ?></td>
                                    </tr>
                            <?php 
                                endwhile; 
                            } else {
                                // Fallback row using invoice amount when no course breakdown available
                                $total = (float)$invoice->amount;
                            ?>
                                <tr>
                                    <td colspan="3">Tuition and fees</td>
                                    <td class="text-end"><?php echo number_format($total, 2); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end fw-bold">Total Amount:</td>
                                <td class="text-end fw-bold"><?php echo number_format($total, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Payment Status -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="alert alert-<?php echo $invoice->status === 'Paid' ? 'success' : 'warning'; ?>">
                            Payment Status: <strong><?php echo $invoice->status; ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Terms and Notes -->
                <div class="row">
                    <div class="col-12">
                        <h6 class="text-muted">Terms & Notes:</h6>
                        <ul class="small text-muted">
                            <li>Payment is due within 30 days of registration</li>
                            <li>Late payments may incur additional charges</li>
                            <li>For payment inquiries, contact the finance office</li>
                            <li>All fees are non-refundable once classes begin</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .dashboard-header, .header-actions, .alert {
        display: none !important;
    }
    .card {
        box-shadow: none !important;
        border: none !important;
    }
    .invoice-container {
        padding: 0 !important;
    }
}
</style>

<?php require 'includes/footer.php'; ?> 