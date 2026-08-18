<?php
declare(strict_types=1);

require_once __DIR__ . '/fees_helpers.php';

if (!function_exists('manual_payment_allowed_methods')) {
    /** @return array<string,string> */
    function manual_payment_allowed_methods(): array
    {
        return [
            'Cash' => 'Cash',
            'Cheque' => 'Cheque',
            'Sponsor / Scholarship' => 'Sponsor / Scholarship',
        ];
    }
}

if (!function_exists('manual_payment_normalize_reference')) {
    function manual_payment_normalize_reference(string $reference): string
    {
        $reference = preg_replace('/\s+/', ' ', trim($reference)) ?? '';
        return strtoupper($reference);
    }
}

if (!function_exists('manual_payment_valid_date')) {
    function manual_payment_valid_date(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

        return $parsed instanceof DateTimeImmutable
            && !$hasErrors
            && $parsed->format('Y-m-d') === $date
            && $parsed <= new DateTimeImmutable('today');
    }
}

if (!function_exists('manual_payment_reference_exists')) {
    function manual_payment_reference_exists(mysqli $db, string $reference): bool
    {
        $stmt = $db->prepare(
            "SELECT 1 FROM payments WHERE receipt_no = ?
             UNION ALL
             SELECT 1 FROM student_payments WHERE reference_number = ? OR receipt_number = ?
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to validate the receipt number.');
        }
        $stmt->bind_param('sss', $reference, $reference, $reference);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('manual_payment_record')) {
    /**
     * Record one accountant-entered payment and recalculate its fee account in
     * a single transaction. Bank/mobile/card payments deliberately stay out of
     * this service because those channels require provider or proof validation.
     *
     * @param array<string,mixed> $input
     * @return array{success:bool,message:string,payment_id?:int,reference?:string}
     */
    function manual_payment_record(mysqli $db, array $input, string $staffId): array
    {
        $accountId = (int)($input['student_fee_account_id'] ?? 0);
        $amount = round((float)($input['amount'] ?? 0), 2);
        $method = trim((string)($input['payment_method'] ?? ''));
        $reference = manual_payment_normalize_reference((string)($input['receipt_number'] ?? ''));
        $paymentDate = trim((string)($input['payment_date'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));
        $staffId = trim($staffId) !== '' ? trim($staffId) : 'accounts';

        if ($accountId <= 0) {
            return ['success' => false, 'message' => 'Select a valid active student fee account.'];
        }
        if (!is_finite($amount) || $amount <= 0 || $amount > 99999999.99) {
            return ['success' => false, 'message' => 'Payment amount must be greater than zero and within the supported limit.'];
        }
        if (!array_key_exists($method, manual_payment_allowed_methods())) {
            return ['success' => false, 'message' => 'Select an approved manual payment method. Bank transfers must use proof verification.'];
        }
        if (strlen($reference) < 4 || strlen($reference) > 50) {
            return ['success' => false, 'message' => 'Receipt number must contain between 4 and 50 characters.'];
        }
        if (!manual_payment_valid_date($paymentDate)) {
            return ['success' => false, 'message' => 'Payment date must be a valid date that is not in the future.'];
        }
        if (strlen($notes) > 1000) {
            return ['success' => false, 'message' => 'Notes cannot exceed 1,000 characters.'];
        }

        $db->begin_transaction();
        try {
            $accountStmt = $db->prepare(
                "SELECT student_id, academic_year
                   FROM student_fee_accounts
                  WHERE id = ? AND status = 'active'
                  LIMIT 1 FOR UPDATE"
            );
            if (!$accountStmt) {
                throw new RuntimeException('Unable to lock the selected fee account.');
            }
            $accountStmt->bind_param('i', $accountId);
            $accountStmt->execute();
            $account = $accountStmt->get_result()->fetch_assoc() ?: null;
            $accountStmt->close();

            if (!$account) {
                $db->rollback();
                return ['success' => false, 'message' => 'Select a valid active student fee account.'];
            }
            if (manual_payment_reference_exists($db, $reference)) {
                $db->rollback();
                return ['success' => false, 'message' => 'This receipt/reference number has already been used.'];
            }

            $studentId = (string)$account['student_id'];
            $academicYear = (string)$account['academic_year'];
            $context = fees_statement_resolve_registration_context($db, $studentId, $academicYear);
            $semester = (string)($context['period_number'] ?? '1');
            $yearOfStudy = (string)($context['year_of_study'] ?? '1');

            $insert = $db->prepare(
                "INSERT INTO student_payments
                    (student_fee_account_id, Sid, amount_paid, channel, payment_date,
                     academic_year, semester_term, payment_status, reference_number,
                     description, status, recorded_by, receipt_number, year_of_study, `Year`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, 'approved', ?, ?, ?, ?)"
            );
            if (!$insert) {
                throw new RuntimeException('Unable to prepare the payment record.');
            }
            $insert->bind_param(
                'isdssssssssss',
                $accountId,
                $studentId,
                $amount,
                $method,
                $paymentDate,
                $academicYear,
                $semester,
                $reference,
                $notes,
                $staffId,
                $reference,
                $yearOfStudy,
                $yearOfStudy
            );
            $insert->execute();
            $paymentId = (int)$insert->insert_id;
            $insert->close();

            if (!fees_recalculate_student_balance($db, $accountId)) {
                throw new RuntimeException('Unable to recalculate the student fee account.');
            }

            $db->commit();
            if (function_exists('log_audit')) {
                log_audit($db, $staffId, 'manual_payment.record', json_encode([
                    'payment_id' => $paymentId,
                    'student_id' => $studentId,
                    'fee_account_id' => $accountId,
                    'amount' => $amount,
                    'method' => $method,
                    'reference' => $reference,
                ]));
            }

            return [
                'success' => true,
                'message' => 'Payment recorded successfully.',
                'payment_id' => $paymentId,
                'reference' => $reference,
            ];
        } catch (Throwable $e) {
            $db->rollback();
            error_log('Manual payment record failed: ' . $e->getMessage());
            if ((int)$e->getCode() === 1062) {
                return ['success' => false, 'message' => 'This receipt/reference number has already been used.'];
            }
            return ['success' => false, 'message' => 'The payment could not be recorded safely. Please refresh and try again.'];
        }
    }
}

if (!function_exists('manual_payment_reverse')) {
    /** @return array{success:bool,message:string} */
    function manual_payment_reverse(mysqli $db, int $paymentId, string $targetStatus, string $reason, string $staffId): array
    {
        $targetStatus = strtolower(trim($targetStatus));
        $reason = trim($reason);
        $staffId = trim($staffId) !== '' ? trim($staffId) : 'accounts';

        if ($paymentId <= 0 || !in_array($targetStatus, ['reversed', 'cancelled'], true)) {
            return ['success' => false, 'message' => 'Invalid payment reversal request.'];
        }
        if (strlen($reason) < 5 || strlen($reason) > 1000) {
            return ['success' => false, 'message' => 'Provide a reversal reason between 5 and 1,000 characters.'];
        }

        $db->begin_transaction();
        try {
            $select = $db->prepare(
                "SELECT payment_id, student_fee_account_id, Sid, amount_paid, reference_number, receipt_number,
                        status, payment_status
                   FROM student_payments
                  WHERE payment_id = ?
                  LIMIT 1 FOR UPDATE"
            );
            if (!$select) {
                throw new RuntimeException('Unable to lock the payment record.');
            }
            $select->bind_param('i', $paymentId);
            $select->execute();
            $payment = $select->get_result()->fetch_assoc() ?: null;
            $select->close();

            if (!$payment) {
                $db->rollback();
                return ['success' => false, 'message' => 'The selected payment could not be found.'];
            }
            if ((string)$payment['status'] !== 'approved' || (string)$payment['payment_status'] !== 'completed') {
                $db->rollback();
                return ['success' => false, 'message' => 'This payment is not in a reversible state.'];
            }

            $accountId = (int)($payment['student_fee_account_id'] ?? 0);
            if ($accountId <= 0) {
                $db->rollback();
                return ['success' => false, 'message' => 'This legacy payment is not linked to a fee account and requires manual reconciliation.'];
            }

            $update = $db->prepare(
                "UPDATE student_payments
                    SET status = ?, reversal_reason = ?, payment_status = 'failed', updated_at = NOW()
                  WHERE payment_id = ? AND status = 'approved' AND payment_status = 'completed'
                  LIMIT 1"
            );
            if (!$update) {
                throw new RuntimeException('Unable to prepare the payment reversal.');
            }
            $update->bind_param('ssi', $targetStatus, $reason, $paymentId);
            $update->execute();
            $affected = $update->affected_rows;
            $update->close();
            if ($affected !== 1) {
                throw new RuntimeException('Payment state changed during reversal.');
            }

            if (!fees_recalculate_student_balance($db, $accountId)) {
                throw new RuntimeException('Unable to recalculate the student fee account after reversal.');
            }

            $db->commit();
            if (function_exists('log_audit')) {
                log_audit($db, $staffId, 'manual_payment.' . $targetStatus, json_encode([
                    'payment_id' => $paymentId,
                    'student_id' => (string)($payment['Sid'] ?? ''),
                    'fee_account_id' => $accountId,
                    'amount' => (float)($payment['amount_paid'] ?? 0),
                    'reference' => (string)($payment['receipt_number'] ?: $payment['reference_number']),
                    'reason' => $reason,
                ]));
            }

            return [
                'success' => true,
                'message' => $targetStatus === 'reversed'
                    ? 'Payment reversed successfully.'
                    : 'Payment cancelled successfully.',
            ];
        } catch (Throwable $e) {
            $db->rollback();
            error_log('Manual payment reversal failed for payment ' . $paymentId . ': ' . $e->getMessage());
            return ['success' => false, 'message' => 'The payment reversal could not be completed safely. Please refresh and try again.'];
        }
    }
}

