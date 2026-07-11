<?php
// DPO Pay callback receiver
// This endpoint accepts POST callbacks from DPO Pay and marks invoices paid.
// It logs raw payloads and attempts to update invoice and student payment records.

require_once '../db/connect.php';

$raw = file_get_contents('php://input');
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/dpopay_callbacks.log';
file_put_contents($logFile, "[" . date('c') . "] " . $raw . PHP_EOL, FILE_APPEND);

$data = $_POST ? $_POST : json_decode($raw, true);

// Expected fields may include: invoice, transaction_id, status, amount, student_id
$invoiceRef = $data['invoice'] ?? $data['invoice_id'] ?? $data['reference'] ?? null;
$transactionId = $data['transaction_id'] ?? $data['tx_id'] ?? $data['transaction'] ?? null;
$status = strtolower($data['status'] ?? $data['payment_status'] ?? '');
$amount = $data['amount'] ?? $data['value'] ?? null;
$studentId = $data['student_id'] ?? $data['Sid'] ?? null;

if (!$invoiceRef) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing invoice reference']);
    exit;
}

// Normalize status
$paidStatuses = ['paid', 'completed', 'success'];

try {
    // Find invoice row if available
    $escRef = $db->real_escape_string($invoiceRef);
    $q = "SELECT * FROM invoices WHERE invoice = '$escRef' OR invoice_number = '$escRef' OR reference = '$escRef' OR id = '$escRef' LIMIT 1";
    $res = $db->query($q);
    $invoiceRow = ($res && $res->num_rows) ? $res->fetch_assoc() : null;

    if (in_array($status, $paidStatuses, true)) {
        // mark invoice as paid if table has a status-like column
        if ($invoiceRow) {
            $updateCols = [];
            if (array_key_exists('status', $invoiceRow)) {
                $updateCols[] = "status = 'paid'";
            }
            if (array_key_exists('paid_at', $invoiceRow)) {
                $updateCols[] = "paid_at = NOW()";
            }
            if (count($updateCols)) {
                $upd = "UPDATE invoices SET " . implode(', ', $updateCols) . " WHERE id = '" . $db->real_escape_string($invoiceRow['id']) . "'";
                $db->query($upd);
            }
        }

        // Insert into student_payments if not present
        $sid = $studentId ?: ($invoiceRow['Sid'] ?? $invoiceRow['student_id'] ?? null);
        $amt = $amount ?: ($invoiceRow['amount'] ?? $invoiceRow['total'] ?? null);

        // Duplicate prevention
        $dup = null;
        if (!empty($transactionId)) {
            $dup = $db->query("SELECT id FROM student_payments WHERE reference_number = '" . $db->real_escape_string($transactionId) . "' LIMIT 1");
        }
        if ((!$dup || ($dup && $dup->num_rows == 0))) {
            $dup2 = $db->query("SELECT id FROM student_payments WHERE reference_number = '" . $db->real_escape_string($invoiceRef) . "' LIMIT 1");
            if ((!$dup2 || ($dup2 && $dup2->num_rows == 0))) {
                $colsRes = $db->query("SHOW COLUMNS FROM student_payments");
                $cols = [];
                if ($colsRes) {
                    while ($c = $colsRes->fetch_assoc()) {
                        $cols[] = $c['Field'];
                    }
                }
                $insertCols = [];
                $insertVals = [];
                if (in_array('Sid', $cols)) { $insertCols[] = 'Sid'; $insertVals[] = $db->real_escape_string($sid); }
                if (in_array('amount', $cols)) { $insertCols[] = 'amount'; $insertVals[] = $db->real_escape_string($amt); }
                if (in_array('reference_number', $cols)) { $insertCols[] = 'reference_number'; $insertVals[] = $db->real_escape_string($transactionId ?: $invoiceRef); }
                if (in_array('narration', $cols)) { $insertCols[] = 'narration'; $insertVals[] = $db->real_escape_string($data['description'] ?? ($invoiceRow['description'] ?? 'DPO Pay')); }
                if (in_array('channel', $cols)) { $insertCols[] = 'channel'; $insertVals[] = $db->real_escape_string('DPO Pay'); }
                if (in_array('dte_time', $cols)) { $insertCols[] = 'dte_time'; $insertVals[] = "NOW()"; }

                if (count($insertCols)) {
                    $colsSql = implode(', ', $insertCols);
                    $valsSql = implode(', ', array_map(function($v){ return is_string($v) && $v !== 'NOW()' ? "'" . $v . "'" : $v; }, $insertVals));
                    $ins = "INSERT INTO student_payments ($colsSql) VALUES ($valsSql)";
                    $db->query($ins);
                }
            }
        }
    }

    echo json_encode(['success' => true]);
    exit;

} catch (Exception $e) {
    file_put_contents($logFile, "[" . date('c') . "] ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

?>
