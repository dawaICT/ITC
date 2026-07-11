<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant','Systems Admin']);

// Identify overdue installments (due_date < today and status pending)
$sql = "SELECT si.id, si.student_id, si.amount, si.due_date, s.phone, s.email
        FROM finance_student_installments si
        JOIN students s ON s.SID = si.student_id
        WHERE si.status = 'pending' AND si.due_date < CURDATE()
        LIMIT 200";

$sent = 0; $failed = 0;
$res = $db->query($sql);
if ($res) {
  while ($r = $res->fetch_assoc()) {
    $message = sprintf('Dear student %s, your installment due on %s of amount %0.2f is overdue. Please pay promptly.', $r['student_id'], $r['due_date'], $r['amount']);
    $stmt = $db->prepare("INSERT INTO finance_ar_reminders (student_id, channel, message, status) VALUES (?, 'sms', ?, 'queued')");
    if ($stmt) { $stmt->bind_param('ss', $r['student_id'], $message); $ok = $stmt->execute(); $ok ? $sent++ : $failed++; }
  }
}

log_audit($db, $_SESSION['staff_id'] ?? 'system', 'ar.reminders.queue', json_encode(['queued'=>$sent, 'failed'=>$failed]));
json_success(['queued' => $sent, 'failed' => $failed], 200);


