<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/Database.php';

$invoiceNo = $_GET['invoice'] ?? '';
if ($invoiceNo === '') { http_response_code(400); echo 'Missing invoice number'; exit; }

try {
    $db = new Database();
    $conn = $db->getConnection();
    $stmt = $conn->prepare("SELECT * FROM invoices WHERE invoice_number = ? LIMIT 1");
    $stmt->execute([$invoiceNo]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { http_response_code(404); echo 'Invoice not found'; exit; }
    // Extract just the year part from academic_year if it contains a dash
    if (isset($inv['academic_year'])) {
        $inv['academic_year'] = explode('-', $inv['academic_year'])[0] ?? $inv['academic_year'];
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Invoice <?php echo htmlspecialchars($invoiceNo); ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body class="p-4">
  <div class="container">
    <div class="data-table-card">
      <div class="card-header"><div class="d-flex justify-content-between align-items-center"><h5 class="mb-0"><i class="fas fa-file-invoice me-2"></i>Registration Invoice</h5></div></div>
      <div class="card-body">
        <div class="row">
          <div class="col-md-6">
            <p><strong>Invoice:</strong> <?php echo htmlspecialchars($inv['invoice_number']); ?></p>
            <p><strong>Student:</strong> <?php echo htmlspecialchars($inv['student_id']); ?></p>
          </div>
          <div class="col-md-6">
            <p><strong>Academic Year:</strong> <?php echo htmlspecialchars((string)$inv['academic_year']); ?></p>
            <p><strong>Semester:</strong> <?php echo htmlspecialchars((string)$inv['semester']); ?></p>
          </div>
        </div>
        <hr>
        <p><strong>Base Tuition:</strong> ZMW <?php echo number_format((float)($inv['base_tuition'] ?? 0), 2); ?></p>
        <p><strong>Registration Fee:</strong> ZMW <?php echo number_format((float)($inv['registration_fee'] ?? 0), 2); ?></p>
        <p><strong>Additional Fees:</strong> ZMW <?php echo number_format((float)($inv['additional_fees'] ?? 0), 2); ?></p>
        <p class="fs-5"><strong>Total:</strong> ZMW <?php echo number_format((float)$inv['amount'], 2); ?></p>
        <p><strong>Due Date:</strong> <?php echo htmlspecialchars($inv['due_date'] ?? 'Not set'); ?></p>
        <p><strong>Status:</strong> <?php echo htmlspecialchars($inv['status']); ?></p>
        
        <div class="mt-4">
          <a href="../accounts/balanceStatement.php" class="btn btn-primary me-2">
            <i class="fas fa-file-invoice"></i> View Balance Statement
          </a>
          <button onclick="window.print()" class="btn btn-secondary">
            <i class="fas fa-print"></i> Print Invoice
          </button>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

