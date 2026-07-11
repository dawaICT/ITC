<?php
declare(strict_types=1);
/**
 * Printable receipt for a transport enrolment (§10 / BR011).
 * A receipt is only available once payment has been verified. Calling this when
 * the enrolment is verified will issue the receipt (idempotent); otherwise 403.
 */
require_once __DIR__ . '/includes/transport.php';            // auth + $db
require_once __DIR__ . '/includes/transport_invoicing.php';
require_once __DIR__ . '/includes/document_ui.php';

$enrollmentId = (int)($_GET['enrollment_id'] ?? 0);
if ($enrollmentId <= 0) {
    tdoc_message_page(400, 'Invalid enrolment', 'No trainee enrolment was specified. Open the receipt from the Payments & Booking screen.', 'warning');
}

// BR011: issue-on-demand only if payment is verified; else block with the reason.
$result = tinv_issue_receipt($db, $enrollmentId);
if (empty($result['issued'])) {
    tdoc_message_page(403, 'Receipt not yet available', (string)$result['reason'], 'warning');
}
$receipt = tinv_get_receipt($db, $enrollmentId);
if (!$receipt) {
    tdoc_message_page(500, 'Receipt unavailable', 'The receipt could not be loaded. Please try again; if the problem persists, contact the systems administrator.', 'danger');
}

$stmt = $db->prepare(
    "SELECT t.first_name, t.last_name, t.student_id,
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
    http_response_code(404);
    exit('Enrolment not found.');
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v) => 'ZMW ' . number_format((float)$v, 2);
$fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receipt <?php echo $h($receipt['receipt_number']); ?></title>
<link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">
<script src="/wucportal/js/wuc-print-fit.js"></script>
<style>
  @page { size: A4 portrait; margin: 12mm; }
  @media print { .noprint { display:none !important; } body { background:#fff !important; margin:0; } }
  body { font-family: Helvetica, Arial, sans-serif; color:#1f2a44; margin:0; background:#f4f5f7; }
  .sheet { max-width: 680px; margin: 24px auto; background:#fff; padding: 40px 48px; box-shadow:0 2px 12px rgba(0,0,0,.08); border-top:6px solid #1a7f37; }
  .top { display:flex; justify-content:space-between; align-items:flex-start; }
  .brand { font-size:18px; font-weight:bold; color:#1B2A4A; }
  .brand small { display:block; font-weight:normal; color:#6b7280; font-size:11px; letter-spacing:1px; text-transform:uppercase; }
  h1 { margin:0; font-size:24px; color:#1a7f37; letter-spacing:2px; text-align:right; }
  .meta { font-size:12px; color:#4b5563; text-align:right; }
  .paid-stamp { margin:22px 0; text-align:center; }
  .paid-stamp span { display:inline-block; border:3px solid #1a7f37; color:#1a7f37; padding:6px 22px; border-radius:8px; font-size:22px; font-weight:bold; letter-spacing:4px; transform:rotate(-4deg); }
  .rows { width:100%; border-collapse:collapse; font-size:14px; margin-top:8px; }
  .rows td { padding:9px 4px; border-bottom:1px solid #eef0f4; }
  .rows td.r { text-align:right; }
  .amount { font-size:22px; font-weight:bold; color:#1a7f37; }
  .foot { margin-top:26px; font-size:11px; color:#6b7280; border-top:1px solid #eef0f4; padding-top:12px; }
  .btn { background:#1a7f37; color:#fff; border:none; padding:10px 18px; border-radius:6px; cursor:pointer; font-size:13px; }
</style>
</head>
<body class="single-page-document no-auto-print">
  <div class="noprint" style="max-width:680px;margin:16px auto 0;text-align:right;">
    <button class="btn" onclick="wucPrintSinglePage()">Print / Save as PDF</button>
  </div>
  <div class="sheet wuc-a4-sheet">
    <div class="top">
      <div class="brand">ITC Industrial Training Centre<small>Transport Training &amp; Driver Education</small></div>
      <div>
        <h1>RECEIPT</h1>
        <div class="meta"><strong><?php echo $h($receipt['receipt_number']); ?></strong></div>
        <div class="meta">Date: <?php echo $h(date('j M Y', strtotime((string)$receipt['issued_at']))); ?></div>
      </div>
    </div>

    <div class="paid-stamp"><span>PAID</span></div>

    <table class="rows">
      <tr><td>Received from</td><td class="r"><strong><?php echo $h($fullName); ?></strong> (<?php echo $h($rec['student_id']); ?>)</td></tr>
      <tr><td>Course</td><td class="r"><?php echo $h($rec['program_name']); ?> (<?php echo $h($rec['program_code']); ?>)</td></tr>
      <tr><td>Cohort</td><td class="r"><?php echo $h($rec['cohort_name']); ?></td></tr>
      <?php if (!empty($receipt['invoice_id'])): ?>
        <tr><td>Against invoice</td><td class="r"><?php echo $h(tinv_invoice_number($enrollmentId, (int)date('Y', strtotime((string)$receipt['issued_at'])))); ?></td></tr>
      <?php endif; ?>
      <tr><td>Amount received</td><td class="r"><span class="amount"><?php echo $money($receipt['amount']); ?></span></td></tr>
    </table>

    <div class="foot">
      Issued after accounts verification (BR011). This receipt confirms verified payment and clears the
      trainee for booking (BR001). &nbsp;|&nbsp; Issued by <?php echo $h($receipt['issued_by']); ?>.
    </div>
  </div>
</body>
</html>
