<?php
declare(strict_types=1);
/**
 * Printable invoice for a transport enrolment (§4.5). Lazily creates the invoice
 * from the assessed fee if one does not exist yet (e.g. legacy enrolments).
 */
require_once __DIR__ . '/includes/transport.php';            // auth + $db
require_once __DIR__ . '/includes/transport_invoicing.php';
require_once __DIR__ . '/includes/document_ui.php';

$enrollmentId = (int)($_GET['enrollment_id'] ?? 0);
if ($enrollmentId <= 0) {
    tdoc_message_page(400, 'Invalid enrolment', 'No trainee enrolment was specified. Open the invoice from the Payments & Booking screen.', 'warning');
}

$stmt = $db->prepare(
    "SELECT e.id, e.fee_amount, e.amount_paid, e.payment_status, e.enrollment_date,
            t.first_name, t.last_name, t.student_id,
            c.cohort_name, p.program_name, p.program_code
     FROM transport_enrollments e
     JOIN transport_trainees t ON t.id = e.trainee_id
     JOIN transport_cohorts c ON c.id = e.cohort_id
     JOIN transport_programs p ON p.id = c.program_id
     WHERE e.id = ? LIMIT 1"
);
$stmt->bind_param('i', $enrollmentId);
$stmt->execute();
$rec = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$rec) {
    tdoc_message_page(404, 'Enrolment not found', 'The requested trainee enrolment does not exist. It may have been withdrawn or removed.', 'warning');
}

$invoice = tinv_ensure_invoice($db, $enrollmentId);
if (!$invoice) {
    tdoc_message_page(500, 'Invoice unavailable', 'The invoice could not be prepared. Please try again; if the problem persists, contact the systems administrator.', 'danger');
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v) => 'ZMW ' . number_format((float)$v, 2);
$fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
$statusBadge = $invoice['status'] === 'paid' ? '#1a7f37' : '#b54708';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice <?php echo $h($invoice['invoice_number']); ?></title>
<link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">
<script src="/wucportal/js/wuc-print-fit.js"></script>
<style>
  @page { size: A4 portrait; margin: 12mm; }
  @media print { .noprint { display: none !important; } body { background: #fff !important; margin: 0; } }
  body { font-family: Helvetica, Arial, sans-serif; color:#1f2a44; margin:0; background:#f4f5f7; }
  .sheet { max-width: 800px; margin: 24px auto; background:#fff; padding: 40px 48px; box-shadow:0 2px 12px rgba(0,0,0,.08); }
  .top { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #1B2A4A; padding-bottom:16px; }
  .brand { font-size:20px; font-weight:bold; color:#1B2A4A; }
  .brand small { display:block; font-weight:normal; color:#6b7280; font-size:12px; letter-spacing:1px; text-transform:uppercase; }
  .doc-title { text-align:right; }
  .doc-title h1 { margin:0; font-size:26px; color:#1B2A4A; letter-spacing:2px; }
  .meta { margin-top:6px; font-size:12px; color:#4b5563; }
  .status { display:inline-block; margin-top:6px; padding:3px 10px; border-radius:12px; color:#fff; font-size:11px; text-transform:uppercase; background:<?php echo $statusBadge; ?>; }
  .parties { display:flex; justify-content:space-between; margin:24px 0; font-size:13px; }
  .parties .label { color:#6b7280; font-size:11px; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px; }
  table.items { width:100%; border-collapse:collapse; margin-top:8px; font-size:13px; }
  table.items th { text-align:left; background:#f1f3f7; padding:10px; border-bottom:2px solid #d7dbe4; }
  table.items td { padding:10px; border-bottom:1px solid #eef0f4; }
  table.items td.amt, table.items th.amt { text-align:right; }
  .totals { margin-top:14px; display:flex; justify-content:flex-end; }
  .totals table td { padding:6px 10px; font-size:14px; }
  .totals .grand { font-size:18px; font-weight:bold; color:#1B2A4A; border-top:2px solid #1B2A4A; }
  .foot { margin-top:28px; font-size:11px; color:#6b7280; border-top:1px solid #eef0f4; padding-top:12px; }
  .btn { background:#1B2A4A; color:#fff; border:none; padding:10px 18px; border-radius:6px; cursor:pointer; font-size:13px; }
</style>
</head>
<body class="single-page-document no-auto-print">
  <div class="noprint" style="max-width:800px;margin:16px auto 0;text-align:right;">
    <button class="btn" onclick="wucPrintSinglePage()">Print / Save as PDF</button>
  </div>
  <div class="sheet wuc-a4-sheet">
    <div class="top">
      <div class="brand">ITC Industrial Training Centre<small>Transport Training &amp; Driver Education</small></div>
      <div class="doc-title">
        <h1>INVOICE</h1>
        <div class="meta"><strong><?php echo $h($invoice['invoice_number']); ?></strong></div>
        <div class="meta">Date: <?php echo $h(date('j M Y', strtotime((string)$invoice['created_at']))); ?></div>
        <div class="status"><?php echo $h($invoice['status']); ?></div>
      </div>
    </div>

    <div class="parties">
      <div>
        <div class="label">Billed to</div>
        <div><strong><?php echo $h($fullName); ?></strong></div>
        <div><?php echo $h($rec['student_id']); ?></div>
      </div>
      <div style="text-align:right;">
        <div class="label">Course</div>
        <div><strong><?php echo $h($rec['program_name']); ?></strong> (<?php echo $h($rec['program_code']); ?>)</div>
        <div>Cohort: <?php echo $h($rec['cohort_name']); ?></div>
        <?php if (!empty($invoice['training_mode'])): ?><div>Mode: <?php echo $h($invoice['training_mode']); ?></div><?php endif; ?>
      </div>
    </div>

    <table class="items">
      <thead><tr><th>Description</th><th>Category</th><th class="amt">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($invoice['items'] as $it): ?>
          <tr>
            <td><?php echo $h($it['item_name']); ?></td>
            <td><?php echo $h($it['category']); ?></td>
            <td class="amt"><?php echo $money($it['amount']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals">
      <table>
        <tr><td>Subtotal</td><td class="amt"><?php echo $money($invoice['subtotal']); ?></td></tr>
        <tr><td>Verified paid</td><td class="amt"><?php echo $money($rec['amount_paid']); ?></td></tr>
        <tr class="grand"><td>Total due</td><td class="amt"><?php echo $money(max(0, (float)$invoice['total'] - (float)$rec['amount_paid'])); ?></td></tr>
      </table>
    </div>

    <div class="foot">
      Payment must be verified by an accounts officer before training booking (BR001). A receipt is issued
      after payment verification (BR011). &nbsp;|&nbsp; Generated <?php echo $h(date('j M Y H:i')); ?>.
    </div>
  </div>
</body>
</html>
