<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant','Systems Admin']);

$student_id = trim($_POST['student_id'] ?? '');
if ($student_id === '') { json_error('student_id required', 422); }

// A simple flag table to block exams. Created by migrations; the app DB user
// is DML-only, so only attempt DDL when the table is truly absent.
$ebCheck = $db->query("SHOW TABLES LIKE 'exam_blocks'");
if (!$ebCheck || $ebCheck->num_rows === 0) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS exam_blocks (id INT AUTO_INCREMENT PRIMARY KEY, student_id VARCHAR(50) UNIQUE, reason VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    } catch (Throwable $e) {
        json_error('Exam block table is not provisioned. Run migrations.', 500);
    }
}

$reason = 'Fees unpaid';
$stmt = $db->prepare("INSERT INTO exam_blocks (student_id, reason) VALUES (?, ?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)");
$stmt->bind_param('ss', $student_id, $reason);
$ok = $stmt->execute();
if (!$ok) { json_error('Failed to block'); }

log_audit($db, $_SESSION['staff_id'] ?? 'system', 'exam.block', json_encode(['student'=>$student_id]));
json_success(['student_id' => $student_id]);


