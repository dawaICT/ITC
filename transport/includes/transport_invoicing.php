<?php
/**
 * Transport invoicing & receipts (spec §4.5 / §10 / BR011).
 *
 * - An invoice is generated from the assessed fee at enrolment (one per enrolment).
 * - A receipt is generated ONLY after an accounts officer verifies payment (BR011),
 *   i.e. when transport_enrollments.payment_status = 'verified'.
 *
 * Pure functions taking a `mysqli $db`. Numbers are deterministic + unique per
 * enrolment so generation is idempotent.
 */

function tinv_actor(): string
{
    return (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'system');
}

function tinv_invoice_number(int $enrollmentId, int $year): string
{
    return sprintf('ITC/INV/%d/%05d', $year, $enrollmentId);
}

function tinv_receipt_number(int $enrollmentId, int $year): string
{
    return sprintf('ITC/RCT/%d/%05d', $year, $enrollmentId);
}

/** Fetch an enrolment's invoice with its line items, or null. */
function tinv_get_invoice(mysqli $db, int $enrollmentId): ?array
{
    $stmt = $db->prepare("SELECT * FROM transport_invoices WHERE enrollment_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $inv = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$inv) {
        return null;
    }
    $items = [];
    $iStmt = $db->prepare("SELECT item_name, category, amount FROM transport_invoice_items WHERE invoice_id = ? ORDER BY id ASC");
    $iStmt->bind_param('i', $inv['id']);
    $iStmt->execute();
    $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $iStmt->close();
    $inv['items'] = $items;
    return $inv;
}

/**
 * Ensure an invoice exists for an enrolment; returns it (with items). Idempotent.
 * Builds line items from a fee breakdown ($feeCalc from tf_calculate) when given,
 * otherwise from the enrolment's stored fee_amount (legacy / lazy creation).
 */
function tinv_ensure_invoice(mysqli $db, int $enrollmentId, ?array $feeCalc = null): ?array
{
    $existing = tinv_get_invoice($db, $enrollmentId);
    if ($existing) {
        return $existing;
    }

    // Pull the enrolment so we have a fallback fee + a guard that it exists.
    $eStmt = $db->prepare("SELECT id, fee_amount FROM transport_enrollments WHERE id = ? LIMIT 1");
    if (!$eStmt) {
        return null;
    }
    $eStmt->bind_param('i', $enrollmentId);
    $eStmt->execute();
    $enr = $eStmt->get_result()->fetch_assoc();
    $eStmt->close();
    if (!$enr) {
        return null;
    }

    $year = (int)date('Y');
    $mode = null;
    $lines = [];
    if ($feeCalc && !empty($feeCalc['ok']) && !empty($feeCalc['lines'])) {
        $mode  = (string)($feeCalc['mode'] ?? '');
        $total = (float)$feeCalc['total'];
        foreach ($feeCalc['lines'] as $l) {
            $lines[] = ['name' => (string)$l['name'], 'category' => (string)($l['category'] ?? 'standard'), 'amount' => (float)$l['amount']];
        }
    } else {
        $total = (float)$enr['fee_amount'];
        $lines[] = ['name' => 'Course fee', 'category' => 'base', 'amount' => $total];
    }

    $invoiceNo = tinv_invoice_number($enrollmentId, $year);
    $actor = tinv_actor();

    $ins = $db->prepare(
        "INSERT INTO transport_invoices (enrollment_id, invoice_number, fee_year, training_mode, subtotal, total, currency, status, created_by)
         VALUES (?,?,?,?,?,?, 'ZMW', 'issued', ?)"
    );
    if (!$ins) {
        return null;
    }
    $ins->bind_param('isisdds', $enrollmentId, $invoiceNo, $year, $mode, $total, $total, $actor);
    try {
        $ins->execute();
    } catch (mysqli_sql_exception $e) {
        $ins->close();
        // Race: another request created it first.
        return tinv_get_invoice($db, $enrollmentId);
    }
    $invoiceId = (int)$db->insert_id;
    $ins->close();

    $itStmt = $db->prepare("INSERT INTO transport_invoice_items (invoice_id, item_name, category, amount) VALUES (?,?,?,?)");
    foreach ($lines as $l) {
        $name = $l['name']; $cat = $l['category']; $amt = $l['amount'];
        $itStmt->bind_param('issd', $invoiceId, $name, $cat, $amt);
        $itStmt->execute();
    }
    $itStmt->close();

    return tinv_get_invoice($db, $enrollmentId);
}

/** BR011 gate: can a receipt be issued for this enrolment? */
function tinv_can_issue_receipt(mysqli $db, int $enrollmentId): array
{
    $stmt = $db->prepare("SELECT payment_status, amount_paid FROM transport_enrollments WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['allowed' => false, 'reason' => 'Unable to load the enrolment.', 'amount' => 0.0];
    }
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $enr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$enr) {
        return ['allowed' => false, 'reason' => 'Enrolment not found.', 'amount' => 0.0];
    }
    if ((string)$enr['payment_status'] !== 'verified') {
        return ['allowed' => false, 'reason' => 'A receipt can only be generated after payment is verified (BR011).', 'amount' => 0.0];
    }
    return ['allowed' => true, 'reason' => 'Payment verified.', 'amount' => (float)$enr['amount_paid']];
}

/** Fetch an enrolment's receipt, or null. */
function tinv_get_receipt(mysqli $db, int $enrollmentId): ?array
{
    $stmt = $db->prepare("SELECT * FROM transport_receipts WHERE enrollment_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Issue (or return the already-issued) receipt for an enrolment. Honours BR011.
 * @return array{issued:bool,reused:bool,receipt_number:?string,reason:string}
 */
function tinv_issue_receipt(mysqli $db, int $enrollmentId): array
{
    $existing = tinv_get_receipt($db, $enrollmentId);
    if ($existing) {
        return ['issued' => true, 'reused' => true, 'receipt_number' => (string)$existing['receipt_number'], 'reason' => 'Receipt already issued.'];
    }
    $gate = tinv_can_issue_receipt($db, $enrollmentId);
    if (!$gate['allowed']) {
        return ['issued' => false, 'reused' => false, 'receipt_number' => null, 'reason' => $gate['reason']];
    }

    $invoice = tinv_ensure_invoice($db, $enrollmentId);
    $invoiceId = $invoice ? (int)$invoice['id'] : null;

    $year = (int)date('Y');
    $receiptNo = tinv_receipt_number($enrollmentId, $year);
    $amount = (float)$gate['amount'];
    $actor = tinv_actor();

    $ins = $db->prepare(
        "INSERT INTO transport_receipts (invoice_id, enrollment_id, receipt_number, amount, issued_by)
         VALUES (?,?,?,?,?)"
    );
    if (!$ins) {
        return ['issued' => false, 'reused' => false, 'receipt_number' => null, 'reason' => 'Unable to record the receipt.'];
    }
    $ins->bind_param('iisds', $invoiceId, $enrollmentId, $receiptNo, $amount, $actor);
    try {
        $ins->execute();
    } catch (mysqli_sql_exception $e) {
        $ins->close();
        $existing = tinv_get_receipt($db, $enrollmentId);
        if ($existing) {
            return ['issued' => true, 'reused' => true, 'receipt_number' => (string)$existing['receipt_number'], 'reason' => 'Receipt already issued.'];
        }
        return ['issued' => false, 'reused' => false, 'receipt_number' => null, 'reason' => 'Unable to record the receipt: ' . $e->getMessage()];
    }
    $ins->close();

    // Mark the invoice paid once a receipt is issued.
    if ($invoiceId) {
        $upd = $db->prepare("UPDATE transport_invoices SET status = 'paid' WHERE id = ?");
        $upd->bind_param('i', $invoiceId);
        $upd->execute();
        $upd->close();
    }

    return ['issued' => true, 'reused' => false, 'receipt_number' => $receiptNo, 'reason' => 'Receipt generated.'];
}
