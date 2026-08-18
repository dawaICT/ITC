<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db/connect.php';
require_once $root . '/includes/payment_helpers.php';

$passed = 0;
$failed = 0;
$createdIds = [];
$createdInvoiceIds = [];
$ledgerReferences = [];
$receiptNumbers = [];
$proofFixturePath = null;
$httpSessionId = 'codexbank' . strtolower(bin2hex(random_bytes(8)));

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_id($httpSessionId);
session_start();
$_SESSION['user_id'] = 'ITC900';
$_SESSION['staff_id'] = 'ITC900';
$_SESSION['last_activity'] = time();
session_write_close();

function check(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo '[PASS] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
        return;
    }

    $failed++;
    echo '[FAIL] ' . $label . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL;
}

function source(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $contents;
}

function createTestInvoice(
    mysqli $db,
    string $invoiceNumber,
    string $studentId,
    string $status,
    float $amount,
    float $paid,
    float $balance,
    ?string $academicYear = null,
    string $semester = '1'
): int {
    $programCode = 'TEST';
    $academicYear = $academicYear ?? date('Y');
    $paymentStatus = $balance <= 0 ? 'completed' : 'pending';
    $stmt = $db->prepare(
        'INSERT INTO invoices
            (invoice_number, student_id, SID, program_code, semester, status, academic_year, amount, amount_paid, balance, payment_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'sssssssddds',
        $invoiceNumber,
        $studentId,
        $studentId,
        $programCode,
        $semester,
        $status,
        $academicYear,
        $amount,
        $paid,
        $balance,
        $paymentStatus
    );
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function httpGet(string $url, ?string $sessionId = null): array
{
    $headers = [];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if ($sessionId !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sessionId);
    }
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$headers): int {
        $length = strlen($line);
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $length;
    });
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => $error];
}

try {
    $studentPage = source($root . '/students/payment.php');
    $reviewPage = source($root . '/accounts/pendingPayments.php');
    $helpersSource = source($root . '/includes/payment_helpers.php');
    $proofEndpoint = source($root . '/accounts/paymentProof.php');
    $proofHtaccess = source($root . '/uploads/payment_proofs/.htaccess');

    check(payment_normalize_bank_reference("  zn-123   45  ") === 'ZN-123 45', 'bank reference is normalized consistently');
    check(strpos($studentPage, 'strlen($bankReference) > 100') !== false, 'oversized bank references are rejected rather than truncated');
    check(strpos($studentPage, "'provider_transaction_id' => \$bankReference") !== false, 'external bank reference is stored structurally');
    check(strpos($studentPage, 'payment_find_bank_transaction_by_reference') !== false, 'student submission checks existing bank references');
    check(strpos($reviewPage, '/uploads/payment_proofs/') === false, 'finance queue does not expose public proof paths');
    check(strpos($reviewPage, 'paymentProof.php?transaction_id=') !== false, 'finance queue uses authenticated proof downloads');
    check(strpos($reviewPage, 'payment_review_bank_transfer') !== false, 'finance controller delegates to the atomic review service');
    check(strpos($reviewPage, 'name="target_invoice"') !== false, 'finance queue exposes a guarded reallocation selector');
    check(strpos($reviewPage, 'value="reallocate"') !== false, 'finance queue posts an explicit reallocation action');
    check(strpos($helpersSource, "'status' => 'needs_review'") !== false, 'settled-invoice transfers move to reconciliation');
    check(strpos($helpersSource, "!empty(\$posted['already_paid'])") !== false, 'already-settled invoices cannot be marked completed');
    check(strpos($helpersSource, 'payment_bank_reallocation_candidates') !== false, 'reallocation candidates are derived server-side');
    check(strpos($proofEndpoint, 'canAccessFinance') !== false, 'proof endpoint enforces finance authorization');
    check(strpos($proofEndpoint, 'basename($storedName)') !== false, 'proof endpoint rejects path traversal');
    check(strpos($proofEndpoint, 'Content-Disposition: attachment') !== false, 'proofs are downloaded rather than rendered inline');
    check(strpos($proofHtaccess, 'Require all denied') !== false, 'Apache denies direct proof-file access');

    $index = $db->query("SHOW INDEX FROM payment_gateway_transactions WHERE Key_name = 'uniq_pgt_provider_external_ref'");
    check($index && $index->num_rows === 2, 'provider/external-reference unique index is installed');
    if ($index) {
        $index->free();
    }

    $suffix = strtoupper(bin2hex(random_bytes(6)));
    $bankReference = 'TEST-BANK-' . $suffix;
    $first = payment_create_gateway_transaction($db, [
        'invoice_number' => 'TEST-INVOICE',
        'student_id' => 'TEST-STUDENT',
        'amount' => 10,
        'provider' => 'BANK_TRANSFER',
        'reference_number' => 'BANK-TEST-A-' . $suffix,
        'provider_transaction_id' => $bankReference,
        'status' => 'pending_verification',
        'created_by' => 'TEST-STUDENT',
    ]);
    if (!empty($first['id'])) {
        $createdIds[] = (int)$first['id'];
    }
    check(!empty($first['success']), 'first bank-reference submission is accepted');

    $found = payment_find_bank_transaction_by_reference($db, strtolower($bankReference));
    check((int)($found['id'] ?? 0) === (int)($first['id'] ?? 0), 'normalized duplicate lookup finds the existing submission');

    $duplicate = payment_create_gateway_transaction($db, [
        'invoice_number' => 'TEST-INVOICE',
        'student_id' => 'TEST-STUDENT',
        'amount' => 10,
        'provider' => 'BANK_TRANSFER',
        'reference_number' => 'BANK-TEST-B-' . $suffix,
        'provider_transaction_id' => $bankReference,
        'status' => 'pending_verification',
        'created_by' => 'TEST-STUDENT',
    ]);
    check(empty($duplicate['success']) && !empty($duplicate['duplicate']), 'database constraint blocks a racing duplicate bank reference');

    $stmt = $db->prepare("SELECT COUNT(*) AS total FROM payment_gateway_transactions WHERE provider = 'BANK_TRANSFER' AND provider_transaction_id = ?");
    $stmt->bind_param('s', $bankReference);
    $stmt->execute();
    $rowCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    check($rowCount === 1, 'only one bank transaction survives duplicate submission', 'rows=' . $rowCount);

    $studentResult = $db->query("SELECT SID FROM students WHERE COALESCE(status, 'active') NOT IN ('inactive', 'suspended', 'blocked', 'disabled', 'withdrawn', 'deleted') ORDER BY SID LIMIT 1");
    $studentId = $studentResult ? (string)($studentResult->fetch_assoc()['SID'] ?? '') : '';
    if ($studentResult) {
        $studentResult->free();
    }
    if ($studentId === '') {
        throw new RuntimeException('No active student is available for the bank-transfer fixture.');
    }
    $fixtureAcademicYear = (string)((int)date('Y') + 1);
    $invoiceNumber = 'BTI-A-' . substr($suffix, 0, 12);
    $invoiceId = createTestInvoice($db, $invoiceNumber, $studentId, 'Pending', 25, 0, 25, $fixtureAcademicYear, '1');
    $createdInvoiceIds[] = $invoiceId;
    $ledgerReference = 'BANK-POST-' . $suffix;
    $ledgerReferences[] = $ledgerReference;
    $submission = payment_create_gateway_transaction($db, [
        'invoice_number' => $invoiceNumber,
        'student_id' => $studentId,
        'amount' => 25,
        'narration' => 'Bank-transfer integrity fixture',
        'provider' => 'BANK_TRANSFER',
        'reference_number' => $ledgerReference,
        'provider_transaction_id' => 'EXT-POST-' . $suffix,
        'status' => 'pending_verification',
        'created_by' => $studentId,
    ]);
    $createdIds[] = (int)($submission['id'] ?? 0);

    $review = payment_review_bank_transfer($db, (int)$submission['id'], 'approve', 'ITC900', 'Automated integrity fixture');
    check(!empty($review['success']) && ($review['review_status'] ?? '') === 'completed', 'approval atomically posts and completes the gateway transaction', json_encode($review));
    $receiptNo = (string)($review['payment']['receipt_no'] ?? '');
    if ($receiptNo !== '') {
        $receiptNumbers[] = $receiptNo;
    }
    $afterReview = payment_find_gateway_transaction_by_id($db, (int)$submission['id']);
    check(($afterReview['status'] ?? '') === 'completed' && ($afterReview['receipt_no'] ?? '') === $receiptNo, 'gateway review stores the issued receipt', json_encode($afterReview));

    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM student_payments WHERE reference_number = ?');
    $stmt->bind_param('s', $ledgerReference);
    $stmt->execute();
    $legacyRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    check($legacyRows === 1, 'approval creates exactly one reconciliation-ledger row', 'rows=' . $legacyRows);

    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM payments WHERE receipt_no = ?');
    $stmt->bind_param('s', $receiptNo);
    $stmt->execute();
    $normalizedRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    check($receiptNo !== '' && $normalizedRows === 1, 'approval creates exactly one normalized-ledger row', 'receipt=' . $receiptNo . ' rows=' . $normalizedRows);

    $secondReview = payment_review_bank_transfer($db, (int)$submission['id'], 'approve', 'ITC900');
    check(empty($secondReview['success']) && str_contains((string)($secondReview['message'] ?? ''), 'already been reviewed'), 'a reviewed transfer cannot be posted again');

    $rollbackStudentId = 'NO-STUDENT-' . substr($suffix, 0, 8);
    $rollbackInvoiceNumber = 'BTI-R-' . substr($suffix, 0, 12);
    $rollbackInvoiceId = createTestInvoice($db, $rollbackInvoiceNumber, $rollbackStudentId, 'Pending', 15, 0, 15);
    $createdInvoiceIds[] = $rollbackInvoiceId;
    $rollbackReference = 'BANK-ROLLBACK-' . $suffix;
    $ledgerReferences[] = $rollbackReference;
    $rollbackSubmission = payment_create_gateway_transaction($db, [
        'invoice_number' => $rollbackInvoiceNumber,
        'student_id' => $rollbackStudentId,
        'amount' => 15,
        'provider' => 'BANK_TRANSFER',
        'reference_number' => $rollbackReference,
        'provider_transaction_id' => 'EXT-ROLLBACK-' . $suffix,
        'status' => 'pending_verification',
        'created_by' => $rollbackStudentId,
    ]);
    $createdIds[] = (int)($rollbackSubmission['id'] ?? 0);
    $rollbackReview = payment_review_bank_transfer($db, (int)$rollbackSubmission['id'], 'approve', 'ITC900');
    $rollbackAfter = payment_find_gateway_transaction_by_id($db, (int)$rollbackSubmission['id']);
    $rollbackInvoiceAfter = payment_fetch_invoice($db, $rollbackInvoiceNumber, $rollbackStudentId);
    check(
        empty($rollbackReview['success'])
            && ($rollbackAfter['status'] ?? '') === 'needs_review'
            && ($rollbackAfter['result_code'] ?? '') === 'POSTING_REVIEW',
        'posting failure commits reconciliation status while retaining the review lock'
    );
    check(
        payment_decimal($rollbackInvoiceAfter['amount_paid'] ?? 0) === 0.0
            && payment_decimal($rollbackInvoiceAfter['balance'] ?? 0) === 15.0,
        'savepoint restores the invoice after a mid-posting failure'
    );
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM payments WHERE student_id = ?');
    $stmt->bind_param('s', $rollbackStudentId);
    $stmt->execute();
    $rollbackNormalizedRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    check($rollbackNormalizedRows === 0, 'savepoint removes partial normalized-ledger writes');

    $settledInvoiceNumber = 'BTI-S-' . substr($suffix, 0, 12);
    $settledInvoiceId = createTestInvoice($db, $settledInvoiceNumber, $studentId, 'Paid', 30, 30, 0, $fixtureAcademicYear, '2');
    $createdInvoiceIds[] = $settledInvoiceId;
    $settledReference = 'BANK-SETTLED-' . $suffix;
    $ledgerReferences[] = $settledReference;
    $settledSubmission = payment_create_gateway_transaction($db, [
        'invoice_number' => $settledInvoiceNumber,
        'student_id' => $studentId,
        'amount' => 30,
        'provider' => 'BANK_TRANSFER',
        'reference_number' => $settledReference,
        'provider_transaction_id' => 'EXT-SETTLED-' . $suffix,
        'status' => 'pending_verification',
        'created_by' => $studentId,
    ]);
    $createdIds[] = (int)($settledSubmission['id'] ?? 0);
    $settledReview = payment_review_bank_transfer($db, (int)$settledSubmission['id'], 'approve', 'ITC900');
    $settledAfter = payment_find_gateway_transaction_by_id($db, (int)$settledSubmission['id']);
    check(empty($settledReview['success']) && ($settledReview['review_status'] ?? '') === 'needs_review', 'settled invoice does not consume an unallocated transfer');
    check(($settledAfter['status'] ?? '') === 'needs_review' && ($settledAfter['result_code'] ?? '') === 'INVOICE_SETTLED', 'unallocated transfer is visible to finance reconciliation');

    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM student_payments WHERE reference_number = ?');
    $stmt->bind_param('s', $settledReference);
    $stmt->execute();
    $settledLedgerRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    check($settledLedgerRows === 0, 'settled-invoice review creates no false payment ledger entry');

    $reconciliationIds = array_map(
        static fn(array $row): int => (int)($row['id'] ?? 0),
        payment_list_pending_bank_transactions($db)
    );
    check(in_array((int)$settledSubmission['id'], $reconciliationIds, true), 'reconciliation transfer remains visible in the finance queue');

    $targetInvoiceNumber = 'BTI-T-' . substr($suffix, 0, 12);
    $targetInvoiceId = createTestInvoice($db, $targetInvoiceNumber, $studentId, 'Pending', 40, 0, 40, $fixtureAcademicYear, '3');
    $createdInvoiceIds[] = $targetInvoiceId;
    $otherInvoiceNumber = 'BTI-X-' . substr($suffix, 0, 12);
    $otherInvoiceId = createTestInvoice($db, $otherInvoiceNumber, 'OTHER-STUDENT-' . substr($suffix, 0, 6), 'Pending', 40, 0, 40);
    $createdInvoiceIds[] = $otherInvoiceId;
    $smallInvoiceNumber = 'BTI-L-' . substr($suffix, 0, 12);
    $smallInvoiceId = createTestInvoice($db, $smallInvoiceNumber, $studentId, 'Pending', 20, 0, 20, $fixtureAcademicYear, '4');
    $createdInvoiceIds[] = $smallInvoiceId;

    $candidateReferences = array_map(
        static fn(array $invoice): string => (string)($invoice['invoice_number'] ?? ''),
        payment_bank_reallocation_candidates($db, $settledAfter)
    );
    check(in_array($targetInvoiceNumber, $candidateReferences, true), 'same-student invoice is offered for reallocation');
    check(!in_array($otherInvoiceNumber, $candidateReferences, true), 'another student invoice is excluded from reallocation');
    check(!in_array($smallInvoiceNumber, $candidateReferences, true), 'invoice with insufficient balance is excluded from reallocation');

    $queuePage = httpGet('http://localhost/wucportal/accounts/pendingPayments.php', $httpSessionId);
    check($queuePage['status'] === 200 && str_contains((string)$queuePage['body'], 'name="target_invoice"'), 'authenticated queue renders the reallocation control', 'status=' . $queuePage['status']);
    check(str_contains((string)$queuePage['body'], $targetInvoiceNumber), 'authenticated queue renders the eligible invoice option');

    $crossStudent = payment_review_bank_transfer(
        $db,
        (int)$settledSubmission['id'],
        'reallocate',
        'ITC900',
        'Cross-student rejection fixture',
        $otherInvoiceNumber
    );
    $afterCrossStudent = payment_find_gateway_transaction_by_id($db, (int)$settledSubmission['id']);
    check(empty($crossStudent['success']) && ($afterCrossStudent['status'] ?? '') === 'needs_review', 'cross-student reallocation is rejected without changing review state');

    $insufficientBalance = payment_review_bank_transfer(
        $db,
        (int)$settledSubmission['id'],
        'reallocate',
        'ITC900',
        'Insufficient balance fixture',
        $smallInvoiceNumber
    );
    $afterInsufficientBalance = payment_find_gateway_transaction_by_id($db, (int)$settledSubmission['id']);
    check(empty($insufficientBalance['success']) && ($afterInsufficientBalance['status'] ?? '') === 'needs_review', 'invoice that cannot absorb the transfer is rejected without posting');

    $reallocated = payment_review_bank_transfer(
        $db,
        (int)$settledSubmission['id'],
        'reallocate',
        'ITC900',
        'Allocated by automated integrity fixture',
        $targetInvoiceNumber
    );
    $reallocatedAfter = payment_find_gateway_transaction_by_id($db, (int)$settledSubmission['id']);
    check(
        !empty($reallocated['success'])
            && ($reallocated['review_status'] ?? '') === 'completed'
            && ($reallocatedAfter['invoice_number'] ?? '') === $targetInvoiceNumber
            && ($reallocatedAfter['result_code'] ?? '') === 'REALLOCATED',
        'same-student reallocation atomically updates the review transaction',
        json_encode($reallocated)
    );
    $reallocatedReceipt = (string)($reallocated['payment']['receipt_no'] ?? '');
    if ($reallocatedReceipt !== '') {
        $receiptNumbers[] = $reallocatedReceipt;
    }

    $targetAfter = payment_fetch_invoice($db, $targetInvoiceNumber, $studentId);
    $originalAfter = payment_fetch_invoice($db, $settledInvoiceNumber, $studentId);
    check(
        payment_decimal($targetAfter['amount_paid'] ?? 0) === 30.0
            && payment_decimal($targetAfter['balance'] ?? 0) === 10.0,
        'reallocated amount is posted to the target invoice only',
        json_encode(['target' => $targetAfter, 'original' => $originalAfter])
    );
    check(
        payment_decimal($originalAfter['amount_paid'] ?? 0) === 30.0
            && payment_decimal($originalAfter['balance'] ?? 0) === 0.0,
        'original settled invoice remains unchanged'
    );

    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM student_payments WHERE reference_number = ?');
    $stmt->bind_param('s', $settledReference);
    $stmt->execute();
    $reallocatedLedgerRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    check($reallocatedLedgerRows === 1, 'reallocation creates one ledger entry for the transfer reference');

    $reallocateAgain = payment_review_bank_transfer($db, (int)$settledSubmission['id'], 'reallocate', 'ITC900', '', $targetInvoiceNumber);
    check(empty($reallocateAgain['success']), 'completed reallocation cannot be posted again');

    $closeReference = 'BANK-CLOSE-' . $suffix;
    $ledgerReferences[] = $closeReference;
    $closeSubmission = payment_create_gateway_transaction($db, [
        'invoice_number' => 'UNALLOCATED',
        'student_id' => $studentId,
        'amount' => 5,
        'provider' => 'BANK_TRANSFER',
        'reference_number' => $closeReference,
        'provider_transaction_id' => 'EXT-CLOSE-' . $suffix,
        'status' => 'needs_review',
        'created_by' => $studentId,
    ]);
    $createdIds[] = (int)($closeSubmission['id'] ?? 0);
    $closed = payment_review_bank_transfer($db, (int)$closeSubmission['id'], 'reject', 'ITC900', 'Fixture reconciliation closed');
    $closedAfter = payment_find_gateway_transaction_by_id($db, (int)$closeSubmission['id']);
    check(!empty($closed['success']) && ($closedAfter['status'] ?? '') === 'rejected', 'finance can close a reconciliation transfer without posting it');

    $proofBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    if ($proofBytes === false) {
        throw new RuntimeException('Unable to decode proof fixture.');
    }
    $proofName = 'bank-proof-test-' . strtolower($suffix) . '.png';
    $proofFixturePath = $root . '/uploads/payment_proofs/' . $proofName;
    if (file_put_contents($proofFixturePath, $proofBytes) === false) {
        throw new RuntimeException('Unable to write proof fixture.');
    }
    $proofSubmission = payment_create_gateway_transaction($db, [
        'invoice_number' => $invoiceNumber,
        'student_id' => $studentId,
        'amount' => 25,
        'provider' => 'BANK_TRANSFER',
        'reference_number' => 'BANK-PROOF-' . $suffix,
        'provider_transaction_id' => 'EXT-PROOF-' . $suffix,
        'status' => 'pending_verification',
        'proof_file' => $proofName,
        'proof_mime' => 'image/png',
        'created_by' => $studentId,
    ]);
    $createdIds[] = (int)($proofSubmission['id'] ?? 0);

    $download = httpGet('http://localhost/wucportal/accounts/paymentProof.php?transaction_id=' . (int)$proofSubmission['id'], $httpSessionId);
    check($download['status'] === 200 && $download['body'] === $proofBytes, 'authorized finance user downloads the exact proof bytes', 'status=' . $download['status']);
    check(str_starts_with(strtolower((string)($download['headers']['content-type'] ?? '')), 'image/png'), 'proof download preserves its verified MIME type');
    check(str_contains(strtolower((string)($download['headers']['content-disposition'] ?? '')), 'attachment'), 'proof download is forced as an attachment');

    $direct = httpGet('http://localhost/wucportal/uploads/payment_proofs/' . rawurlencode($proofName));
    check($direct['status'] === 403, 'the same proof cannot be downloaded directly', 'status=' . $direct['status']);
} catch (Throwable $e) {
    check(false, 'bank-transfer fixture completed', $e->getMessage());
} finally {
    if (!empty($createdIds)) {
        $createdIds = array_values(array_filter(array_unique($createdIds), static fn(int $id): bool => $id > 0));
        $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
        $types = str_repeat('i', count($createdIds));
        $stmt = $db->prepare("DELETE FROM payment_gateway_transactions WHERE id IN ({$placeholders})");
        $stmt->bind_param($types, ...$createdIds);
        $stmt->execute();
        $stmt->close();
    }
    foreach (array_unique($ledgerReferences) as $reference) {
        $stmt = $db->prepare('DELETE FROM student_payments WHERE reference_number = ?');
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $stmt->close();
    }
    foreach (array_unique($receiptNumbers) as $receiptNo) {
        $stmt = $db->prepare('DELETE FROM payments WHERE receipt_no = ?');
        $stmt->bind_param('s', $receiptNo);
        $stmt->execute();
        $stmt->close();
        if (payment_table_columns($db, 'portal_alerts')) {
            $stmt = $db->prepare("DELETE FROM portal_alerts WHERE entity_type = 'payment' AND entity_id = ?");
            $stmt->bind_param('s', $receiptNo);
            $stmt->execute();
            $stmt->close();
        }
    }
    if (!empty($createdInvoiceIds)) {
        $createdInvoiceIds = array_values(array_filter(array_unique($createdInvoiceIds), static fn(int $id): bool => $id > 0));
        $placeholders = implode(',', array_fill(0, count($createdInvoiceIds), '?'));
        $types = str_repeat('i', count($createdInvoiceIds));
        $stmt = $db->prepare("DELETE FROM invoices WHERE id IN ({$placeholders})");
        $stmt->bind_param($types, ...$createdInvoiceIds);
        $stmt->execute();
        $stmt->close();
    }
    if ($proofFixturePath !== null && is_file($proofFixturePath)) {
        unlink($proofFixturePath);
    }
    session_id($httpSessionId);
    session_start();
    session_destroy();
}

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
