<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant','Systems Admin']);

$student_id = trim($_POST['student_id'] ?? '');
$amount = (float)($_POST['amount'] ?? 0);
$channel = trim($_POST['channel'] ?? 'cash');
$reference = trim($_POST['reference_number'] ?? '');
$description = trim($_POST['description'] ?? 'Payment');
$academic_year = trim($_POST['academic_year'] ?? '1');  // Year of study (1, 2, 3, or 4)
$semester_term = trim($_POST['semester_term'] ?? 'Semester 1');

if ($student_id === '' || $amount <= 0) {
  json_error('Invalid student or amount', 422);
}

// Record payment
if ($reference === '') {
  $reference = 'PAY-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

if ($db->query("SHOW TABLES LIKE 'payments'")->num_rows > 0) {
  $postedBy = (string)($_SESSION['staff_id'] ?? 'system');
  $stmt = $db->prepare("INSERT INTO payments (student_id, receipt_no, amount, method, payment_date, status, description, posted_by, academic_year, semester) VALUES (?, ?, ?, ?, NOW(), 'completed', ?, ?, ?, ?)");
  if (!$stmt) { json_error('Failed to prepare payment'); }
  $stmt->bind_param('ssdsssss', $student_id, $reference, $amount, $channel, $description, $postedBy, $academic_year, $semester_term);
  if (!$stmt->execute()) { json_error('Failed to record payment'); }
  $stmt->close();
}

if ($db->query("SHOW TABLES LIKE 'student_payments'")->num_rows > 0) {
  $paymentColumns = [];
  if ($cols = $db->query("SHOW COLUMNS FROM student_payments")) {
    while ($row = $cols->fetch_assoc()) {
      $paymentColumns[] = (string)$row['Field'];
    }
    $cols->free();
  }
  $fields = [];
  $placeholders = [];
  $types = '';
  $params = [];
  $add = static function(string $column, string $type, $value, bool $raw = false) use (&$fields, &$placeholders, &$types, &$params, $paymentColumns): void {
    if (!in_array($column, $paymentColumns, true)) {
      return;
    }
    $fields[] = "`{$column}`";
    if ($raw) {
      $placeholders[] = (string)$value;
      return;
    }
    $placeholders[] = '?';
    $types .= $type;
    $params[] = $value;
  };
  $add('Sid', 's', $student_id);
  $add('SID', 's', $student_id);
  $add('student_id', 's', $student_id);
  $add('amount_paid', 'd', $amount);
  $add('amount', 'd', $amount);
  $add('balance', 'd', 0.0);
  $add('channel', 's', $channel);
  $add('payment_method', 's', $channel);
  $add('payment_date', '', 'NOW()', true);
  $add('academic_year', 's', $academic_year);
  $add('semester_term', 's', $semester_term);
  $add('semester', 's', $semester_term);
  $add('reference_number', 's', $reference);
  $add('reference', 's', $reference);
  $add('description', 's', $description);
  $add('status', 's', 'completed');
  $add('payment_status', 's', 'completed');
  $add('created_at', '', 'NOW()', true);
  if (!empty($fields)) {
    $stmt = $db->prepare("INSERT INTO student_payments (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
    if (!$stmt) { json_error('Failed to prepare payment mirror'); }
    if ($types !== '') {
      $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) { json_error('Failed to record payment mirror'); }
    $stmt->close();
  }
}

// Allocate to pending installments FIFO
$remaining = $amount;
$sel = $db->prepare("SELECT id, amount FROM finance_student_installments WHERE student_id=? AND status='pending' ORDER BY due_date ASC, id ASC");
$sel->bind_param('s', $student_id);
$sel->execute();
$rs = $sel->get_result();
while ($remaining > 0 && ($row = $rs->fetch_assoc())) {
  $instAmount = (float)$row['amount'];
  if ($remaining + 0.0001 >= $instAmount) {
    // mark paid
    $upd = $db->prepare("UPDATE finance_student_installments SET status='paid', updated_at=CURRENT_TIMESTAMP WHERE id = ?");
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $remaining -= $instAmount;
  } else {
    // partial payment not tracked at installment level in current schema
    break;
  }
}

log_audit($db, $_SESSION['staff_id'] ?? 'system', 'payment.record', json_encode(['student'=>$student_id,'amount'=>$amount,'channel'=>$channel]));
json_success(['recorded' => true, 'allocated_remaining' => round($remaining,2)]);


