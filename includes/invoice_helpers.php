<?php
declare(strict_types=1);

if (!function_exists('invoice_transaction_active')) {
    function invoice_transaction_active(mysqli $db): bool
    {
        $result = $db->query('SELECT @@in_transaction AS active');
        $row = $result->fetch_assoc();
        $result->free();
        return !empty($row['active']);
    }
}

if (!function_exists('invoice_generate_number')) {
    function invoice_generate_number(): string
    {
        return 'INV-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }
}

if (!function_exists('invoice_create_for_student')) {
    /**
     * Create exactly one invoice for a student/academic-year/period.
     *
     * The function joins an existing transaction through a savepoint when one
     * is active, which keeps registration workflows atomic. Older callers that
     * supply a one-digit year in $academicYear are interpreted as passing the
     * year of study; the four-digit academic year is resolved from registration.
     *
     * @return array<string,mixed>
     */
    function invoice_create_for_student(
        mysqli $db,
        string $studentId,
        float $amount,
        string $academicYear,
        string $semester,
        string $description = '',
        ?string $invoiceNumber = null,
        ?int $yearOfStudy = null,
        string $createdBy = 'system'
    ): array {
        $studentId = trim($studentId);
        $academicYear = trim($academicYear);
        $semester = trim($semester);
        $description = trim($description) !== '' ? trim($description) : 'Student invoice';
        $amount = round($amount, 2);
        $createdBy = trim($createdBy) !== '' ? trim($createdBy) : 'system';

        if (
            $studentId === ''
            || strlen($studentId) > 50
            || !is_finite($amount)
            || $amount <= 0
            || $amount > 99999999.99
            || !in_array($semester, ['1', '2', '3', '4'], true)
            || strlen($description) > 255
        ) {
            return ['success' => false, 'message' => 'Invalid invoice details.'];
        }

        if (!preg_match('/^\d{4}$/', $academicYear)) {
            if ($yearOfStudy === null && ctype_digit($academicYear)) {
                $yearOfStudy = (int)$academicYear;
            }
            $academicYear = '';
        }

        $contextStmt = $db->prepare(
            "SELECT s.SID,
                    COALESCE(NULLIF(sp.program_code, ''), NULLIF(s.program, '')) AS program_code,
                    COALESCE(NULLIF(sr.academic_year, ''), NULLIF(s.academic_year, '')) AS resolved_academic_year,
                    COALESCE(sr.year_of_study, sp.year_of_study, s.year, 1) AS resolved_year_of_study
               FROM students s
          LEFT JOIN student_program sp
                 ON sp.Sid = s.SID
                AND COALESCE(sp.status, 'active') <> 'inactive'
          LEFT JOIN semester_registration sr
                 ON sr.id = (
                    SELECT sr2.id
                      FROM semester_registration sr2
                     WHERE sr2.student_id = s.SID
                       AND sr2.semester = ?
                     ORDER BY (sr2.academic_year = ?) DESC, sr2.id DESC
                     LIMIT 1
                 )
              WHERE s.SID = ?
              ORDER BY sp.id DESC
              LIMIT 1"
        );
        if (!$contextStmt) {
            return ['success' => false, 'message' => 'Unable to validate the invoice student.'];
        }
        $contextStmt->bind_param('sss', $semester, $academicYear, $studentId);
        $contextStmt->execute();
        $context = $contextStmt->get_result()->fetch_assoc() ?: null;
        $contextStmt->close();
        if (!$context) {
            return ['success' => false, 'message' => 'This student ID is not registered in the system.'];
        }

        if ($academicYear === '') {
            $academicYear = trim((string)($context['resolved_academic_year'] ?? ''));
        }
        $yearOfStudy = $yearOfStudy ?? (int)($context['resolved_year_of_study'] ?? 1);
        $programCode = trim((string)($context['program_code'] ?? ''));
        if (
            !preg_match('/^\d{4}$/', $academicYear)
            || (int)$academicYear < 2000
            || (int)$academicYear > ((int)date('Y') + 1)
            || $yearOfStudy < 1
            || $yearOfStudy > 10
            || $programCode === ''
            || strlen($programCode) > 20
        ) {
            return ['success' => false, 'message' => 'The student registration context is incomplete for invoicing.'];
        }

        $invoiceNumber = $invoiceNumber === null || trim($invoiceNumber) === ''
            ? invoice_generate_number()
            : strtoupper(trim($invoiceNumber));
        if (strlen($invoiceNumber) < 4 || strlen($invoiceNumber) > 32 || !preg_match('/^[A-Z0-9-]+$/', $invoiceNumber)) {
            return ['success' => false, 'message' => 'Invalid invoice reference.'];
        }

        $manageTransaction = !invoice_transaction_active($db);
        $savepoint = 'invoice_create';
        if ($manageTransaction) {
            $db->begin_transaction();
        } else {
            $db->query('SAVEPOINT ' . $savepoint);
        }

        try {
            $duplicateStmt = $db->prepare(
                'SELECT id, invoice_number
                   FROM invoices
                  WHERE student_id = ? AND academic_year = ? AND semester = ?
                  LIMIT 1 FOR UPDATE'
            );
            if (!$duplicateStmt) {
                throw new RuntimeException('Unable to check the invoice period.');
            }
            $duplicateStmt->bind_param('sss', $studentId, $academicYear, $semester);
            $duplicateStmt->execute();
            $existing = $duplicateStmt->get_result()->fetch_assoc() ?: null;
            $duplicateStmt->close();
            if ($existing) {
                if ($manageTransaction) {
                    $db->rollback();
                } else {
                    $db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $db->query('RELEASE SAVEPOINT ' . $savepoint);
                }
                return [
                    'success' => false,
                    'message' => 'Student is already invoiced for this period.',
                    'duplicate' => true,
                    'invoice_id' => (int)$existing['id'],
                    'invoice_number' => (string)$existing['invoice_number'],
                ];
            }

            $insert = $db->prepare(
                "INSERT INTO invoices
                    (invoice_number, student_id, SID, program_code, semester,
                     status, academic_year, year_of_study, amount, amount_paid,
                     balance, date_generated, payment_status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?, ?, 0.00, ?, NOW(), 'pending', NOW(), NOW())"
            );
            if (!$insert) {
                throw new RuntimeException('Unable to prepare invoice insert.');
            }
            $insert->bind_param(
                'ssssssidd',
                $invoiceNumber,
                $studentId,
                $studentId,
                $programCode,
                $semester,
                $academicYear,
                $yearOfStudy,
                $amount,
                $amount
            );
            $insert->execute();
            $invoiceId = (int)$insert->insert_id;
            $insert->close();

            if ($manageTransaction) {
                $db->commit();
            } else {
                $db->query('RELEASE SAVEPOINT ' . $savepoint);
            }

            if (function_exists('log_audit')) {
                log_audit($db, $createdBy, 'invoice.create', json_encode([
                    'invoice_id' => $invoiceId,
                    'invoice_number' => $invoiceNumber,
                    'student_id' => $studentId,
                    'academic_year' => $academicYear,
                    'semester' => $semester,
                    'year_of_study' => $yearOfStudy,
                    'amount' => $amount,
                    'description' => $description,
                ]));
            }

            return [
                'success' => true,
                'message' => 'Invoice created successfully.',
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'student_id' => $studentId,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'year_of_study' => $yearOfStudy,
                'amount' => $amount,
            ];
        } catch (Throwable $e) {
            if ($manageTransaction) {
                $db->rollback();
            } else {
                $db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $db->query('RELEASE SAVEPOINT ' . $savepoint);
            }
            error_log('Invoice creation failed for ' . $studentId . ': ' . $e->getMessage());
            if ((int)$e->getCode() === 1062) {
                return ['success' => false, 'message' => 'Student is already invoiced for this period.', 'duplicate' => true];
            }
            return ['success' => false, 'message' => 'The invoice could not be created safely. Please refresh and try again.'];
        }
    }
}

