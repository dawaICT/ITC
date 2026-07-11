<?php
/**
 * Transport payment verification + booking guard (BR001).
 *
 * Business rule (spec §16 BR001 / §11): a trainee must NOT be booked into a
 * cohort until an accounts officer has VERIFIED a payment that covers the fee.
 *
 * The enrolment row carries the authoritative state:
 *   - payment_status: awaiting_payment | payment_submitted | partial | verified | rejected
 *   - booking_status: pending_payment  | booked | cancelled
 *   - amount_paid: SUM of VERIFIED payments only (never trusted from a form)
 *
 * Each submitted payment lives in transport_payments and is verified/rejected
 * by an accounts officer (canAccessFinance). Booking is a separate, deliberate
 * action (training officer) that this guard refuses unless payment is verified.
 */

require_once __DIR__ . '/../../includes/role_helpers.php';

const TPAY_PROOF_REL_DIR  = 'uploads/transport_payments';
const TPAY_MAX_BYTES      = 5242880; // 5 MB
const TPAY_ALLOWED_EXT    = ['pdf', 'jpg', 'jpeg', 'png'];

/** Who may verify/reject a payment (separation of duties, BR019). */
function tpay_user_can_verify(): bool
{
    return canAccessFinance() || (function_exists('isSystemsAdmin') && isSystemsAdmin());
}

/** Who may book a paid trainee into the cohort (training officer / admin). */
function tpay_user_can_book(): bool
{
    return canAccessTransport();
}

function tpay_actor(): string
{
    return (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'system');
}

function tpay_audit(mysqli $db, int $enrollmentId, string $action, ?string $notes = null): void
{
    $actor = tpay_actor();
    $stmt = $db->prepare(
        "INSERT INTO transport_audit_log (entity, entity_id, action, actor, notes) VALUES ('transport_enrollment', ?, ?, ?, ?)"
    );
    if (!$stmt) {
        error_log('tpay_audit: failed to record "' . $action . '" for enrollment ' . $enrollmentId . ': ' . $db->error);
        return;
    }
    $stmt->bind_param('isss', $enrollmentId, $action, $actor, $notes);
    if (!$stmt->execute()) {
        error_log('tpay_audit: failed to record "' . $action . '" for enrollment ' . $enrollmentId . ': ' . $stmt->error);
    }
    $stmt->close();
}

/** Load an enrolment with cohort + trainee context, or null. */
function tpay_enrollment(mysqli $db, int $enrollmentId): ?array
{
    $stmt = $db->prepare(
        "SELECT e.*, c.cohort_name, c.capacity, c.id AS cohort_id_chk,
                t.first_name, t.last_name, t.student_id
         FROM transport_enrollments e
         JOIN transport_cohorts c ON c.id = e.cohort_id
         JOIN transport_trainees t ON t.id = e.trainee_id
         WHERE e.id = ? LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** BR010: a payment reference number cannot be reused (across non-rejected rows). */
function tpay_reference_in_use(mysqli $db, string $reference, int $excludePaymentId = 0): bool
{
    if ($reference === '') {
        return false;
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM transport_payments
         WHERE reference_number = ? AND status <> 'rejected' AND id <> ? LIMIT 1"
    );
    $stmt->bind_param('si', $reference, $excludePaymentId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Recompute an enrolment's amount_paid + payment_status from the VERIFIED
 * payments ledger. amount_paid is never taken from a form.
 */
function tpay_recompute_enrollment(mysqli $db, int $enrollmentId): void
{
    $enr = tpay_enrollment($db, $enrollmentId);
    if (!$enr) {
        return;
    }
    $fee = (float)$enr['fee_amount'];

    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status='verified' THEN amount ELSE 0 END), 0) AS verified_sum,
            SUM(status='submitted') AS pending_count,
            SUM(status='rejected')  AS rejected_count,
            COUNT(*)                AS total_count
         FROM transport_payments WHERE enrollment_id = ?"
    );
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $agg = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $verified  = (float)$agg['verified_sum'];
    $pending   = (int)$agg['pending_count'];
    $rejected  = (int)$agg['rejected_count'];
    $total     = (int)$agg['total_count'];

    if ($fee > 0 && $verified >= $fee) {
        $status = 'verified';
    } elseif ($verified > 0) {
        $status = 'partial';
    } elseif ($pending > 0) {
        $status = 'payment_submitted';
    } elseif ($total > 0 && $rejected === $total) {
        $status = 'rejected';
    } else {
        $status = 'awaiting_payment';
    }

    if ($status === 'verified') {
        $verifier = tpay_actor();
        $stmt = $db->prepare(
            "UPDATE transport_enrollments
             SET amount_paid = ?, payment_status = ?,
                 payment_verified_by = COALESCE(payment_verified_by, ?),
                 payment_verified_at = COALESCE(payment_verified_at, NOW())
             WHERE id = ?"
        );
        $stmt->bind_param('dssi', $verified, $status, $verifier, $enrollmentId);
    } else {
        $stmt = $db->prepare(
            "UPDATE transport_enrollments SET amount_paid = ?, payment_status = ? WHERE id = ?"
        );
        $stmt->bind_param('dsi', $verified, $status, $enrollmentId);
    }
    $stmt->execute();
    $stmt->close();
}

/** Validate + store an uploaded proof file; returns relative path or null. */
function tpay_store_proof(?array $file, int $enrollmentId): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Proof of payment failed to upload. Please try again.');
    }
    if ((int)$file['size'] > TPAY_MAX_BYTES) {
        throw new RuntimeException('Proof of payment must be 5 MB or smaller.');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, TPAY_ALLOWED_EXT, true)) {
        throw new RuntimeException('Proof of payment must be a PDF, JPG, or PNG file.');
    }
    $absDir = dirname(__DIR__, 2) . '/' . TPAY_PROOF_REL_DIR;
    if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        throw new RuntimeException('Unable to prepare the upload folder for proof of payment.');
    }
    $name = sprintf('tp_%d_%d_%s.%s', $enrollmentId, time(), bin2hex(random_bytes(4)), $ext);
    $dest = $absDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Unable to save the uploaded proof of payment.');
    }
    return TPAY_PROOF_REL_DIR . '/' . $name;
}

/**
 * Record a submitted payment + proof against an enrolment.
 * Sets the enrolment to payment_submitted/partial; never to verified.
 */
function tpay_submit_payment(mysqli $db, int $enrollmentId, array $in, ?array $file): array
{
    $enr = tpay_enrollment($db, $enrollmentId);
    if (!$enr) {
        throw new RuntimeException('Select a valid trainee enrolment.');
    }
    if ($enr['booking_status'] === 'cancelled') {
        throw new RuntimeException('This enrolment has been cancelled.');
    }

    $amount    = round(max(0, (float)($in['amount'] ?? 0)), 2);
    $method    = trim((string)($in['payment_method'] ?? ''));
    $bank      = trim((string)($in['bank_name'] ?? ''));
    $reference = trim((string)($in['reference_number'] ?? ''));
    $notes     = trim((string)($in['notes'] ?? ''));

    if ($amount <= 0) {
        throw new RuntimeException('Enter the amount paid (greater than zero).');
    }
    if (strlen($reference) > 80) {
        throw new RuntimeException('Payment reference is too long.');
    }
    if (tpay_reference_in_use($db, $reference)) {
        throw new RuntimeException('That payment reference number has already been used (BR010).');
    }

    $proofPath = tpay_store_proof($file, $enrollmentId);
    if ($proofPath === null && $reference === '') {
        throw new RuntimeException('Attach proof of payment or enter a payment reference number.');
    }

    $submittedBy = tpay_actor();
    $methodN = $method !== '' ? $method : null;
    $bankN   = $bank !== '' ? $bank : null;
    $refN    = $reference !== '' ? $reference : null;
    $notesN  = $notes !== '' ? $notes : null;

    $stmt = $db->prepare(
        "INSERT INTO transport_payments
            (enrollment_id, amount, payment_method, bank_name, reference_number, proof_path, status, submitted_by, notes)
         VALUES (?,?,?,?,?,?, 'submitted', ?, ?)"
    );
    $stmt->bind_param('idssssss', $enrollmentId, $amount, $methodN, $bankN, $refN, $proofPath, $submittedBy, $notesN);
    $stmt->execute();
    $paymentId = (int)$db->insert_id;
    $stmt->close();

    tpay_recompute_enrollment($db, $enrollmentId);
    tpay_audit($db, $enrollmentId, 'payment_submitted', 'Payment #' . $paymentId . ' amount ' . number_format($amount, 2));

    return ['payment_id' => $paymentId, 'message' => 'Proof of payment submitted for verification.'];
}

/** Accounts officer verifies a submitted payment. */
function tpay_verify_payment(mysqli $db, int $paymentId): array
{
    if (!tpay_user_can_verify()) {
        throw new RuntimeException('Only an accounts officer can verify payments (BR019).');
    }
    $stmt = $db->prepare("SELECT enrollment_id, amount, status FROM transport_payments WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pay) {
        throw new RuntimeException('Payment record not found.');
    }
    if ($pay['status'] !== 'submitted') {
        throw new RuntimeException('Only a submitted payment can be verified.');
    }

    $actor = tpay_actor();
    $stmt = $db->prepare(
        "UPDATE transport_payments
         SET status='verified', verified_by=?, verified_at=NOW(), rejection_reason=NULL
         WHERE id=?"
    );
    $stmt->bind_param('si', $actor, $paymentId);
    $stmt->execute();
    $stmt->close();

    $enrollmentId = (int)$pay['enrollment_id'];
    tpay_recompute_enrollment($db, $enrollmentId);
    tpay_audit($db, $enrollmentId, 'payment_verified', 'Payment #' . $paymentId . ' verified (' . number_format((float)$pay['amount'], 2) . ')');

    return ['message' => 'Payment verified.'];
}

/** Accounts officer rejects a submitted payment with a reason. */
function tpay_reject_payment(mysqli $db, int $paymentId, string $reason): array
{
    if (!tpay_user_can_verify()) {
        throw new RuntimeException('Only an accounts officer can reject payments (BR019).');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Give a reason for rejecting the payment.');
    }
    $stmt = $db->prepare("SELECT enrollment_id, status FROM transport_payments WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pay) {
        throw new RuntimeException('Payment record not found.');
    }
    if ($pay['status'] !== 'submitted') {
        throw new RuntimeException('Only a submitted payment can be rejected.');
    }

    $actor = tpay_actor();
    $reasonTrim = mb_substr($reason, 0, 255);
    $stmt = $db->prepare(
        "UPDATE transport_payments SET status='rejected', rejection_reason=?, verified_by=?, verified_at=NOW() WHERE id=?"
    );
    $stmt->bind_param('ssi', $reasonTrim, $actor, $paymentId);
    $stmt->execute();
    $stmt->close();

    $enrollmentId = (int)$pay['enrollment_id'];
    tpay_recompute_enrollment($db, $enrollmentId);
    tpay_audit($db, $enrollmentId, 'payment_rejected', 'Payment #' . $paymentId . ' rejected: ' . $reasonTrim);

    return ['message' => 'Payment rejected.'];
}

/** BR001 gate: is this enrolment allowed to be booked? */
function tpay_can_book(array $enr): array
{
    if (($enr['booking_status'] ?? '') === 'booked') {
        return ['allowed' => false, 'reason' => 'Already booked into the cohort.'];
    }
    if (($enr['booking_status'] ?? '') === 'cancelled') {
        return ['allowed' => false, 'reason' => 'This enrolment has been cancelled.'];
    }
    if (($enr['payment_status'] ?? '') !== 'verified') {
        return ['allowed' => false, 'reason' => 'Payment is not verified yet — booking is blocked (BR001).'];
    }
    return ['allowed' => true, 'reason' => 'Payment verified.'];
}

/** Count confirmed (booked) seats already taken in a cohort. */
function tpay_cohort_booked_count(mysqli $db, int $cohortId, int $excludeEnrollmentId = 0): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM transport_enrollments
         WHERE cohort_id = ? AND booking_status = 'booked' AND id <> ?"
    );
    $stmt->bind_param('ii', $cohortId, $excludeEnrollmentId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int)$count;
}

/** Training officer books a verified-payment trainee into the cohort (BR001 + BR012). */
function tpay_book_trainee(mysqli $db, int $enrollmentId): array
{
    if (!tpay_user_can_book()) {
        throw new RuntimeException('You are not authorised to book trainees.');
    }
    $enr = tpay_enrollment($db, $enrollmentId);
    if (!$enr) {
        throw new RuntimeException('Select a valid trainee enrolment.');
    }
    $gate = tpay_can_book($enr);
    if (!$gate['allowed']) {
        throw new RuntimeException($gate['reason']);
    }
    // BR012: a cohort cannot exceed capacity.
    $capacity = (int)$enr['capacity'];
    if ($capacity > 0 && tpay_cohort_booked_count($db, (int)$enr['cohort_id'], $enrollmentId) >= $capacity) {
        throw new RuntimeException('The cohort is full (' . $capacity . ' seats). Cannot book this trainee.');
    }

    $stmt = $db->prepare(
        "UPDATE transport_enrollments SET booking_status='booked', status='active' WHERE id = ?"
    );
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $stmt->close();

    tpay_audit($db, $enrollmentId, 'booked_for_training', 'Booked into cohort ' . $enr['cohort_name']);
    return ['message' => 'Trainee booked into the cohort.'];
}

/** Human labels + badge classes for the UI. */
function tpay_payment_badge(string $status): array
{
    switch ($status) {
        case 'verified':          return ['Verified', 'bg-success'];
        case 'partial':           return ['Partial', 'bg-warning text-dark'];
        case 'payment_submitted': return ['Awaiting Verification', 'bg-info text-dark'];
        case 'rejected':          return ['Rejected', 'bg-danger'];
        default:                  return ['Awaiting Payment', 'bg-secondary'];
    }
}

function tpay_booking_badge(string $status): array
{
    switch ($status) {
        case 'booked':    return ['Booked for Training', 'bg-success'];
        case 'cancelled': return ['Cancelled', 'bg-dark'];
        default:          return ['Pending Payment', 'bg-secondary'];
    }
}

/** Short, plain-language hint shown under a locked "Book" control. */
function tpay_booking_block_hint(string $paymentStatus): string
{
    switch ($paymentStatus) {
        case 'awaiting_payment':  return 'Record a payment to begin.';
        case 'payment_submitted': return 'Waiting for accounts to verify the payment.';
        case 'partial':           return 'The full fee must be verified before booking.';
        case 'rejected':          return 'Payment was rejected. Submit a new payment.';
        default:                  return 'Verify payment to unlock booking.';
    }
}
