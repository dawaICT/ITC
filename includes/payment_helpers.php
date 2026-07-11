<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/finance_guard.php';

if (!function_exists('payment_setting')) {
    function payment_setting(mysqli $db, string $key, string $default = ''): string
    {
        return wuc_get_setting($db, $key, $default);
    }
}

if (!function_exists('payment_app_base_url')) {
    function payment_app_base_url(): string
    {
        return wuc_public_base_url();
    }
}

if (!function_exists('payment_decimal')) {
    function payment_decimal($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('payment_invoice_total')) {
    function payment_invoice_total(array $invoice): float
    {
        $totalAmount = payment_decimal($invoice['total_amount'] ?? 0);
        $amount = payment_decimal($invoice['amount'] ?? 0);
        $paid = payment_decimal($invoice['amount_paid'] ?? 0);
        $balance = payment_decimal($invoice['balance'] ?? 0);
        return max($totalAmount, $amount, payment_decimal($paid + $balance));
    }
}

if (!function_exists('payment_invoice_outstanding')) {
    function payment_invoice_outstanding(array $invoice): float
    {
        $balance = payment_decimal($invoice['balance'] ?? 0);
        if ($balance > 0) {
            return $balance;
        }

        $total = payment_invoice_total($invoice);
        $paid = payment_decimal($invoice['amount_paid'] ?? 0);
        return max(0.0, payment_decimal($total - $paid));
    }
}

if (!function_exists('payment_invoice_status')) {
    function payment_invoice_status(array $invoice): string
    {
        $status = strtolower(trim((string)($invoice['status'] ?? $invoice['payment_status'] ?? 'pending')));
        if ($status !== '') {
            return $status;
        }
        return payment_invoice_outstanding($invoice) <= 0.0 ? 'paid' : 'pending';
    }
}

if (!function_exists('payment_table_columns')) {
    function payment_table_columns(mysqli $db, string $table): array
    {
        $columns = [];
        $safeTable = $db->real_escape_string($table);
        if ($result = $db->query("SHOW COLUMNS FROM `{$safeTable}`")) {
            while ($row = $result->fetch_assoc()) {
                $columns[] = (string)$row['Field'];
            }
            $result->free();
        }
        return $columns;
    }
}

if (!function_exists('payment_first_column')) {
    function payment_first_column(mysqli $db, string $table, array $candidates): ?string
    {
        $columns = payment_table_columns($db, $table);
        $lookup = [];
        foreach ($columns as $column) {
            $lookup[strtolower((string)$column)] = (string)$column;
        }
        foreach ($candidates as $candidate) {
            $key = strtolower((string)$candidate);
            if (isset($lookup[$key])) {
                return $lookup[$key];
            }
        }
        return null;
    }
}

if (!function_exists('payment_invoice_number_column')) {
    function payment_invoice_number_column(mysqli $db): ?string
    {
        $columns = payment_table_columns($db, 'invoices');
        foreach (['invoice_number', 'invoice_no', 'invoice', 'reference'] as $column) {
            if (in_array($column, $columns, true)) {
                return $column;
            }
        }
        return null;
    }
}

if (!function_exists('payment_invoice_select_list')) {
    function payment_invoice_select_list(mysqli $db): string
    {
        $columns = payment_table_columns($db, 'invoices');
        $select = ['*'];
        foreach (['invoice_number', 'invoice_no', 'invoice', 'reference'] as $column) {
            if (in_array($column, $columns, true)) {
                $select[] = "`{$column}` AS invoice_number";
                break;
            }
        }
        if (!in_array('amount_paid', $columns, true)) {
            $select[] = '0.00 AS amount_paid';
        }
        if (!in_array('balance', $columns, true) && in_array('amount', $columns, true)) {
            $select[] = '`amount` AS balance';
        }
        if (!in_array('due_date', $columns, true) && in_array('invoice_date', $columns, true)) {
            $select[] = '`invoice_date` AS due_date';
        }
        return implode(', ', $select);
    }
}

if (!function_exists('payment_generate_reference')) {
    function payment_generate_reference(string $prefix = 'PAY'): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefix));
        if ($prefix === '') {
            $prefix = 'PAY';
        }

        try {
            $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } catch (Throwable $e) {
            $random = strtoupper(substr(md5((string)mt_rand()), 0, 8));
        }

        return $prefix . '-' . date('YmdHis') . '-' . $random;
    }
}

if (!function_exists('payment_find_invoice_for_student_term')) {
    function payment_find_invoice_for_student_term(mysqli $db, string $studentId, string $academicYear, string $semester): ?array
    {
        $columns = payment_table_columns($db, 'invoices');
        $studentPredicates = [];
        $findTypes = '';
        $findParams = [];
        if (in_array('student_id', $columns, true)) {
            $studentPredicates[] = 'student_id = ?';
            $findTypes .= 's';
            $findParams[] = $studentId;
        }
        // Only match bigint SID column when the student ID is numeric
        if (in_array('SID', $columns, true) && ctype_digit($studentId)) {
            $studentPredicates[] = 'SID = ?';
            $findTypes .= 's';
            $findParams[] = $studentId;
        }
        if (empty($studentPredicates)) {
            return null;
        }
        $findTypes .= 'ss';
        $findParams[] = $academicYear;
        $findParams[] = $semester;
        $stmt = $db->prepare("SELECT " . payment_invoice_select_list($db) . "
            FROM invoices
            WHERE (" . implode(' OR ', $studentPredicates) . ")
              AND academic_year = ?
              AND semester = ?
            ORDER BY id DESC
            LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param($findTypes, ...$findParams);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('payment_create_student_invoice')) {
    function payment_create_student_invoice(mysqli $db, string $studentId, float $amount, string $academicYear, string $semester, string $description = '', ?string $invoiceNumber = null): array
    {
        if ($studentId === '' || $amount <= 0 || $academicYear === '' || $semester === '') {
            return ['success' => false, 'message' => 'Missing invoice details.'];
        }

        if (payment_find_invoice_for_student_term($db, $studentId, $academicYear, $semester)) {
            return ['success' => false, 'message' => 'Student is already invoiced for this period.', 'duplicate' => true];
        }

        $columns = payment_table_columns($db, 'invoices');
        if (empty($columns)) {
            return ['success' => false, 'message' => 'Invoice table is not available.'];
        }

        $invoiceNumber = $invoiceNumber ?: payment_generate_reference('INV');
        $description = trim($description) !== '' ? trim($description) : 'Student invoice';
        $statusValue = 'Pending';
        $paymentStatusValue = 'pending';

        $fields = [];
        $placeholders = [];
        $types = '';
        $params = [];
        $add = static function(string $column, string $type, $value, bool $raw = false) use (&$fields, &$placeholders, &$types, &$params, $columns): void {
            if (!in_array($column, $columns, true)) {
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

        $add('invoice_number', 's', $invoiceNumber);
        $add('invoice_no', 's', $invoiceNumber);
        $add('invoice', 's', $invoiceNumber);
        $add('student_id', 's', $studentId);
        // SID may be a bigint column — only insert if the value is numeric
        if (ctype_digit($studentId)) {
            $add('SID', 's', $studentId);
            $add('Sid', 's', $studentId);
        }
        $add('academic_year', 's', $academicYear);
        $add('Year', 's', $academicYear);
        $add('semester', 's', $semester);
        $add('semester_term', 's', $semester);
        $add('amount', 'd', $amount);
        $add('total_amount', 'd', $amount);
        $add('balance', 'd', $amount);
        $add('amount_paid', 'd', 0.0);
        $add('description', 's', $description);
        $add('narration', 's', $description);
        $add('status', 's', $statusValue);
        $add('payment_status', 's', $paymentStatusValue);
        $add('date_generated', '', 'NOW()', true);
        $add('invoice_date', '', 'NOW()', true);
        $add('created_at', '', 'NOW()', true);
        $add('updated_at', '', 'NOW()', true);

        if (empty($fields)) {
            return ['success' => false, 'message' => 'No writable invoice columns found.'];
        }

        $stmt = $db->prepare("INSERT INTO invoices (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to prepare invoice insert.'];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            return ['success' => false, 'message' => $error];
        }
        $invoiceId = (int)$stmt->insert_id;
        $stmt->close();

        return [
            'success' => true,
            'message' => 'Invoice created successfully.',
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
        ];
    }
}

if (!function_exists('payment_encode_payload')) {
    function payment_encode_payload($payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        if (is_string($payload)) {
            return $payload;
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? null : $encoded;
    }
}

if (!function_exists('payment_ensure_gateway_transactions_table')) {
    /**
     * No runtime DDL: the app user is DML-only, so the table must come from
     * migrations/20260703_dpo_paygate_payments.php. Returns whether it exists
     * so callers can degrade gracefully instead of crashing on INSERT.
     */
    function payment_ensure_gateway_transactions_table(mysqli $db): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = wuc_table_exists($db, 'payment_gateway_transactions');
            if (!$ready) {
                error_log('payment_gateway_transactions table is missing; run migrations/20260703_dpo_paygate_payments.php.');
            }
        }
        return $ready;
    }
}

if (!function_exists('payment_get_dpo_config')) {
    function payment_get_dpo_config(mysqli $db): array
    {
        $baseUrl = payment_app_base_url();
        $defaultCallback = $baseUrl . '/wucportal/students/dpo_callback.php';

        return [
            'enabled' => payment_setting($db, 'dpo_enabled', '0') === '1',
            'company_token' => trim(payment_setting($db, 'dpo_company_token', '')),
            'service_type' => trim(payment_setting($db, 'dpo_service_type', '')),
            'api_url' => trim(payment_setting($db, 'dpo_api_url', 'https://secure.3gdirectpay.com/API/v6/')),
            'payment_url' => trim(payment_setting($db, 'dpo_payment_url', 'https://secure.3gdirectpay.com/payv2.php')),
            'currency' => strtoupper(trim(payment_setting($db, 'dpo_currency', 'ZMW'))),
            'redirect_url' => trim(payment_setting($db, 'dpo_redirect_url', $defaultCallback)),
            'back_url' => trim(payment_setting($db, 'dpo_back_url', $baseUrl . '/wucportal/students/fees.php?payment_status=cancelled')),
            'callback_url' => trim(payment_setting($db, 'dpo_callback_url', $defaultCallback)),
            'default_payment' => strtoupper(trim(payment_setting($db, 'dpo_default_payment', ''))),
            'default_payment_country' => trim(payment_setting($db, 'dpo_default_payment_country', '')),
            'default_payment_mno' => trim(payment_setting($db, 'dpo_default_payment_mno', '')),
            'ptl_hours' => max(1, (int)payment_setting($db, 'dpo_ptl_hours', '24')),
            'debug' => payment_setting($db, 'dpo_debug_mode', '0') === '1',
        ];
    }
}

if (!function_exists('payment_dpo_missing_fields')) {
    function payment_dpo_missing_fields(array $config): array
    {
        $missing = [];
        foreach (['company_token', 'service_type', 'api_url', 'payment_url'] as $field) {
            if (trim((string)($config[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }
}

if (!function_exists('payment_dpo_is_ready')) {
    function payment_dpo_is_ready(array $config): bool
    {
        return !empty($config['enabled']) && empty(payment_dpo_missing_fields($config));
    }
}

if (!function_exists('payment_get_bank_details')) {
    function payment_get_bank_details(mysqli $db): array
    {
        return [
            'bank_name' => payment_setting($db, 'bank_name', 'Zambia National Commercial Bank (Zanaco)'),
            'branch' => payment_setting($db, 'bank_branch', 'Main Branch'),
            'branch_code' => payment_setting($db, 'bank_branch_code', '060003'),
            'account_name' => payment_setting($db, 'bank_account_name', 'ITC Student Fees Account'),
            'account_number' => payment_setting($db, 'bank_account_number', '1234567890'),
            'swift_code' => payment_setting($db, 'bank_swift_code', ''),
        ];
    }
}

if (!function_exists('payment_fetch_student_summary')) {
    function payment_fetch_student_summary(mysqli $db, string $studentId): ?array
    {
        $studentProgramColumns = payment_table_columns($db, 'student_program');
        $spStudentCol = payment_first_column($db, 'student_program', ['Sid', 'SID', 'student_id', 'studentId']);
        $spProgramCol = payment_first_column($db, 'student_program', ['program_code', 'programme_code', 'program']);
        $spOrderCol = payment_first_column($db, 'student_program', ['id', 'created_at', 'updated_at']);

        $studentProgramJoin = '';
        if ($spStudentCol) {
            $studentProgramJoin = "LEFT JOIN student_program sp ON sp.`{$spStudentCol}` COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci";
        } else {
            $studentProgramJoin = 'LEFT JOIN (SELECT NULL AS Sid, NULL AS program_code, NULL AS id) sp ON 1 = 0';
        }
        $programExpr = $spProgramCol ? "sp.`{$spProgramCol}`" : "NULL";
        $orderExpr = $spOrderCol ? "sp.`{$spOrderCol}` DESC" : "s.id DESC";

        $sql = "SELECT
                    s.SID,
                    s.title,
                    s.Fname,
                    s.Lname,
                    s.nrc_pass,
                    COALESCE(NULLIF(p.program_name, ''), NULLIF({$programExpr}, ''), 'Program not assigned') AS program_name,
                    COALESCE(NULLIF({$programExpr}, ''), '') AS program_code,
                    s.email
                FROM students s
                {$studentProgramJoin}
                LEFT JOIN programs p
                    ON p.program_code = {$programExpr}
                WHERE s.SID COLLATE utf8mb4_general_ci = ?
                ORDER BY {$orderExpr}
                LIMIT 1";

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $student = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $student ?: null;
    }
}

if (!function_exists('payment_fetch_student_outstanding_invoices')) {
    function payment_fetch_student_outstanding_invoices(mysqli $db, string $studentId): array
    {
        $rows = [];
        $columns = payment_table_columns($db, 'invoices');
        if (empty($columns)) {
            return $rows;
        }

        $studentPredicates = [];
        $types = '';
        $params = [];
        if (in_array('student_id', $columns, true)) {
            $studentPredicates[] = 'student_id COLLATE utf8mb4_general_ci = ?';
            $types .= 's';
            $params[] = $studentId;
        }
        if (in_array('SID', $columns, true)) {
            $studentPredicates[] = 'CAST(SID AS CHAR(50)) COLLATE utf8mb4_general_ci = ?';
            $types .= 's';
            $params[] = $studentId;
        }
        if (empty($studentPredicates)) {
            return $rows;
        }

        $dateOrder = in_array('invoice_date', $columns, true) ? 'invoice_date DESC,' : '';
        $sql = "SELECT " . payment_invoice_select_list($db) . "
                FROM invoices
                WHERE " . implode(' OR ', $studentPredicates) . "
                ORDER BY {$dateOrder} id DESC";

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return $rows;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            if (payment_invoice_outstanding($row) <= 0.0) {
                continue;
            }
            $status = payment_invoice_status($row);
            if (in_array($status, ['paid', 'completed', 'cleared', 'cancelled', 'refunded'], true)) {
                continue;
            }
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('payment_fetch_invoice')) {
    function payment_fetch_invoice(mysqli $db, string $invoiceReference, ?string $studentId = null): ?array
    {
        $invoiceReference = trim($invoiceReference);
        if ($invoiceReference === '') {
            return null;
        }

        $columns = payment_table_columns($db, 'invoices');
        if (empty($columns)) {
            return null;
        }
        $numberColumn = payment_invoice_number_column($db);
        $where = [];
        $types = '';
        $params = [];
        if ($numberColumn !== null) {
            $where[] = "`{$numberColumn}` = ?";
            $types .= 's';
            $params[] = $invoiceReference;
        }
        if (in_array('id', $columns, true)) {
            $where[] = "CAST(id AS CHAR(20)) = ?";
            $types .= 's';
            $params[] = $invoiceReference;
        }
        if (empty($where)) {
            return null;
        }

        $sql = "SELECT " . payment_invoice_select_list($db) . "
                FROM invoices
                WHERE (" . implode(' OR ', $where) . ")";

        if ($studentId !== null && trim($studentId) !== '') {
            $studentPredicates = [];
            if (in_array('student_id', $columns, true)) {
                $studentPredicates[] = 'student_id COLLATE utf8mb4_general_ci = ?';
                $types .= 's';
                $params[] = $studentId;
            }
            if (in_array('SID', $columns, true)) {
                $studentPredicates[] = 'CAST(SID AS CHAR(50)) COLLATE utf8mb4_general_ci = ?';
                $types .= 's';
                $params[] = $studentId;
            }
            if (!empty($studentPredicates)) {
                $sql .= " AND (" . implode(' OR ', $studentPredicates) . ")";
            }
        }

        $sql .= " ORDER BY id DESC LIMIT 1";

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('payment_find_gateway_transaction_by_id')) {
    function payment_find_gateway_transaction_by_id(mysqli $db, int $id): ?array
    {
        payment_ensure_gateway_transactions_table($db);
        $stmt = $db->prepare("SELECT * FROM payment_gateway_transactions WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('payment_find_gateway_transaction')) {
    function payment_find_gateway_transaction(mysqli $db, string $column, string $value): ?array
    {
        payment_ensure_gateway_transactions_table($db);
        $allowed = ['reference_number', 'provider_token', 'invoice_number', 'provider_transaction_id'];
        if (!in_array($column, $allowed, true) || trim($value) === '') {
            return null;
        }

        $stmt = $db->prepare("SELECT * FROM payment_gateway_transactions WHERE `$column` = ? ORDER BY id DESC LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $value);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('payment_create_gateway_transaction')) {
    function payment_create_gateway_transaction(mysqli $db, array $tx): array
    {
        if (!payment_ensure_gateway_transactions_table($db)) {
            return ['success' => false, 'message' => 'Online payments are not available yet. Please contact finance.'];
        }

        $sql = "INSERT INTO payment_gateway_transactions (
                    invoice_number,
                    student_id,
                    payment_type,
                    semester_registration_id,
                    program_code,
                    course_codes,
                    amount,
                    currency,
                    narration,
                    provider,
                    reference_number,
                    provider_transaction_id,
                    provider_token,
                    status,
                    proof_file,
                    proof_mime,
                    notes,
                    request_payload,
                    response_payload,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to prepare payment transaction record.'];
        }

        $invoiceNumber = trim((string)($tx['invoice_number'] ?? ''));
        $studentId = trim((string)($tx['student_id'] ?? ''));
        $paymentType = trim((string)($tx['payment_type'] ?? 'fee_payment'));
        if (!in_array($paymentType, ['course_registration', 'fee_payment', 'exam_fee', 'other'], true)) {
            $paymentType = 'other';
        }
        $semRegId = isset($tx['semester_registration_id']) && (int)$tx['semester_registration_id'] > 0
            ? (int)$tx['semester_registration_id']
            : null;
        $programCode = trim((string)($tx['program_code'] ?? ''));
        if ($programCode === '') {
            $programCode = null;
        }
        $courseCodes = $tx['course_codes'] ?? null;
        if (is_array($courseCodes)) {
            $courseCodes = payment_encode_payload($courseCodes);
        } elseif (trim((string)$courseCodes) === '') {
            $courseCodes = null;
        }
        $amount = payment_decimal($tx['amount'] ?? 0);
        $currency = strtoupper(trim((string)($tx['currency'] ?? 'ZMW')));
        $narration = trim((string)($tx['narration'] ?? ''));
        $provider = strtoupper(trim((string)($tx['provider'] ?? 'BANK_TRANSFER')));
        $referenceNumber = trim((string)($tx['reference_number'] ?? payment_generate_reference($provider)));
        $providerTransactionId = trim((string)($tx['provider_transaction_id'] ?? ''));
        if ($providerTransactionId === '') {
            $providerTransactionId = null;
        }

        $providerToken = trim((string)($tx['provider_token'] ?? ''));
        if ($providerToken === '') {
            $providerToken = null;
        }
        $status = trim((string)($tx['status'] ?? 'pending'));
        $proofFile = trim((string)($tx['proof_file'] ?? ''));
        $proofMime = trim((string)($tx['proof_mime'] ?? ''));
        $notes = trim((string)($tx['notes'] ?? ''));
        $requestPayload = payment_encode_payload($tx['request_payload'] ?? null);
        $responsePayload = payment_encode_payload($tx['response_payload'] ?? null);
        $createdBy = trim((string)($tx['created_by'] ?? ''));

        $stmt->bind_param(
            'sssissdsssssssssssss',
            $invoiceNumber,
            $studentId,
            $paymentType,
            $semRegId,
            $programCode,
            $courseCodes,
            $amount,
            $currency,
            $narration,
            $provider,
            $referenceNumber,
            $providerTransactionId,
            $providerToken,
            $status,
            $proofFile,
            $proofMime,
            $notes,
            $requestPayload,
            $responsePayload,
            $createdBy
        );

        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            return ['success' => false, 'message' => $message];
        }

        $insertId = (int)$stmt->insert_id;
        $stmt->close();

        return [
            'success' => true,
            'id' => $insertId,
            'reference_number' => $referenceNumber,
            'transaction' => payment_find_gateway_transaction_by_id($db, $insertId),
        ];
    }
}

if (!function_exists('payment_update_gateway_transaction')) {
    function payment_update_gateway_transaction(mysqli $db, int $id, array $fields): bool
    {
        payment_ensure_gateway_transactions_table($db);
        $allowed = [
            'provider_transaction_id',
            'provider_token',
            'status',
            'result_code',
            'result_desc',
            'receipt_no',
            'proof_file',
            'proof_mime',
            'notes',
            'request_payload',
            'response_payload',
            'verified_by',
            'verified_at',
            'completed_at',
        ];

        $sets = [];
        $values = [];
        $types = '';

        foreach ($fields as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                continue;
            }
            $sets[] = "`$field` = ?";
            if (in_array($field, ['request_payload', 'response_payload'], true)) {
                $values[] = payment_encode_payload($value);
            } elseif (in_array($field, ['provider_transaction_id', 'provider_token', 'result_code', 'result_desc', 'receipt_no', 'proof_file', 'proof_mime', 'notes', 'verified_by', 'verified_at', 'completed_at'], true) && trim((string)$value) === '') {
                $values[] = null;
            } else {
                $values[] = $value;
            }
            $types .= 's';
        }

        if (empty($sets)) {
            return false;
        }

        $values[] = $id;
        $types .= 'i';

        $sql = "UPDATE payment_gateway_transactions SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param($types, ...$values);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('payment_count_pending_bank_transactions')) {
    function payment_count_pending_bank_transactions(mysqli $db): int
    {
        payment_ensure_gateway_transactions_table($db);
        $result = @$db->query("SELECT COUNT(*) AS total
                               FROM payment_gateway_transactions
                               WHERE provider = 'BANK_TRANSFER'
                                 AND status = 'pending_verification'");
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    }
}

if (!function_exists('payment_list_pending_bank_transactions')) {
    function payment_list_pending_bank_transactions(mysqli $db): array
    {
        payment_ensure_gateway_transactions_table($db);
        $rows = [];
        $sql = "SELECT *
                FROM payment_gateway_transactions
                WHERE provider = 'BANK_TRANSFER'
                  AND status = 'pending_verification'
                ORDER BY created_at DESC, id DESC";

        if ($result = @$db->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }

        return $rows;
    }
}

if (!function_exists('payment_apply_completed_payment')) {
    function payment_apply_completed_payment(
        mysqli $db,
        array $invoice,
        float $amount,
        string $channel,
        string $referenceNumber,
        string $description,
        array $meta = []
    ): array {
        $invoiceId = (int)($invoice['id'] ?? 0);
        if ($invoiceId <= 0) {
            return ['success' => false, 'message' => 'Invalid invoice selected.'];
        }

        $amount = payment_decimal($amount);
        if ($amount <= 0.0) {
            return ['success' => false, 'message' => 'Payment amount must be greater than zero.'];
        }

        $channel = trim($channel) !== '' ? trim($channel) : 'Payment';
        $description = trim($description) !== '' ? trim($description) : 'Student fee payment';
        $referenceNumber = trim($referenceNumber) !== '' ? trim($referenceNumber) : payment_generate_reference('PAY');

        $db->begin_transaction();

        try {
            $lockStmt = $db->prepare("SELECT " . payment_invoice_select_list($db) . " FROM invoices WHERE id = ? LIMIT 1 FOR UPDATE");
            if (!$lockStmt) {
                throw new RuntimeException('Unable to lock invoice.');
            }
            $lockStmt->bind_param('i', $invoiceId);
            $lockStmt->execute();
            $lockResult = $lockStmt->get_result();
            $liveInvoice = $lockResult ? $lockResult->fetch_assoc() : null;
            $lockStmt->close();

            if (!$liveInvoice) {
                throw new RuntimeException('Invoice no longer exists.');
            }

            $outstanding = payment_invoice_outstanding($liveInvoice);
            if ($outstanding <= 0.0) {
                $db->commit();
                return ['success' => true, 'message' => 'Invoice is already fully paid.', 'already_paid' => true];
            }

            if ($amount > ($outstanding + 0.01)) {
                throw new RuntimeException('Payment amount cannot exceed the outstanding balance.');
            }

            $invoiceColumnsForTracking = payment_table_columns($db, 'invoices');
            $canTrackPartialInvoicePayment = in_array('amount_paid', $invoiceColumnsForTracking, true)
                || in_array('balance', $invoiceColumnsForTracking, true);
            if ($amount + 0.01 < $outstanding && !$canTrackPartialInvoicePayment) {
                throw new RuntimeException('This invoice table cannot track partial payments. Please post the full outstanding amount or add invoice balance columns.');
            }

            $paymentColumns = payment_table_columns($db, 'student_payments');
            $paymentIdColumn = in_array('payment_id', $paymentColumns, true) ? 'payment_id' : (in_array('id', $paymentColumns, true) ? 'id' : null);
            $paymentReferenceColumn = in_array('reference_number', $paymentColumns, true)
                ? 'reference_number'
                : (in_array('reference', $paymentColumns, true) ? 'reference' : null);
            $dupStmt = (!empty($paymentColumns) && $paymentIdColumn && $paymentReferenceColumn !== null)
                ? $db->prepare("SELECT `{$paymentIdColumn}` AS payment_id FROM student_payments WHERE `{$paymentReferenceColumn}` = ? LIMIT 1")
                : null;
            if ($dupStmt) {
                $dupStmt->bind_param('s', $referenceNumber);
                $dupStmt->execute();
                $dupResult = $dupStmt->get_result();
                if ($dupResult && $dupResult->num_rows > 0) {
                    $dupRow = $dupResult->fetch_assoc();
                    $dupStmt->close();
                    $db->commit();
                    return [
                        'success' => true,
                        'message' => 'This payment reference was already posted.',
                        'duplicate' => true,
                        'payment_id' => (int)($dupRow['payment_id'] ?? 0),
                    ];
                }
                $dupStmt->close();
            }

            $studentId = trim((string)($liveInvoice['student_id'] ?? ''));
            if ($studentId === '') {
                $studentId = trim((string)($liveInvoice['SID'] ?? ''));
            }
            if ($studentId === '') {
                $studentId = trim((string)($meta['student_id'] ?? ''));
            }
            if ($studentId === '') {
                throw new RuntimeException('Invoice is missing a student ID.');
            }

            $invoiceNumber = trim((string)($liveInvoice['invoice_number'] ?? $liveInvoice['invoice_no'] ?? $liveInvoice['invoice'] ?? ''));
            $academicYear = trim((string)($liveInvoice['academic_year'] ?? ''));
            $yearOfStudy = trim((string)($liveInvoice['Year'] ?? ''));
            if ($academicYear === '') {
                $academicYear = trim((string)($meta['academic_year'] ?? $yearOfStudy));
            }
            if ($yearOfStudy === '') {
                $yearOfStudy = trim((string)($meta['year_of_study'] ?? $academicYear));
            }
            $semester = trim((string)($liveInvoice['semester'] ?? ''));
            if ($semester === '') {
                $semester = trim((string)($meta['semester'] ?? ''));
            }

            $invoiceTotal = payment_invoice_total($liveInvoice);
            $currentPaid = payment_decimal($liveInvoice['amount_paid'] ?? 0);
            $newPaid = payment_decimal($currentPaid + $amount);
            if ($newPaid > $invoiceTotal) {
                $newPaid = $invoiceTotal;
            }

            $newBalance = max(0.0, payment_decimal($invoiceTotal - $newPaid));
            $invoiceStatus = $newBalance <= 0.0 ? 'Paid' : 'Pending';
            $paymentStatus = $newBalance <= 0.0 ? 'completed' : 'pending';

            $narration = trim((string)($meta['narration'] ?? $description));
            $paymentId = 0;
            $receiptNo = payment_issue_receipt_number($db);
            $postedBy = (string)($meta['posted_by'] ?? $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'online-gateway');

            if (payment_table_columns($db, 'payments')) {
                $paymentLedgerStmt = $db->prepare("INSERT INTO payments
                    (student_id, receipt_no, amount, method, payment_date, status, description, posted_by, academic_year, semester)
                    VALUES (?, ?, ?, ?, NOW(), 'completed', ?, ?, ?, ?)");
                if (!$paymentLedgerStmt) {
                    throw new RuntimeException('Unable to prepare normalized payment ledger insert.');
                }
                $paymentLedgerStmt->bind_param('ssdsssss', $studentId, $receiptNo, $amount, $channel, $description, $postedBy, $academicYear, $semester);
                if (!$paymentLedgerStmt->execute()) {
                    $error = $paymentLedgerStmt->error;
                    $paymentLedgerStmt->close();
                    throw new RuntimeException($error);
                }
                $paymentId = (int)$paymentLedgerStmt->insert_id;
                $paymentLedgerStmt->close();
            }

            if (!empty($paymentColumns)) {
                $fields = [];
                $placeholders = [];
                $types = '';
                $params = [];
                $addPayment = function(string $column, string $type, $value, bool $raw = false) use (&$fields, &$placeholders, &$types, &$params, $paymentColumns): void {
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

                $addPayment('Sid', 's', $studentId);
                $addPayment('SID', 's', $studentId);
                $addPayment('student_id', 's', $studentId);
                $addPayment('amount_paid', 'd', $amount);
                $addPayment('amount', 'd', $amount);
                $addPayment('balance', 'd', $newBalance);
                $addPayment('channel', 's', $channel);
                $addPayment('payment_method', 's', $channel);
                $addPayment('payment_date', '', 'NOW()', true);
                $addPayment('academic_year', 's', $academicYear);
                $addPayment('year_of_study', 's', $yearOfStudy);
                $addPayment('semester_term', 's', $semester);
                $addPayment('semester', 's', $semester);
                $addPayment('payment_status', 's', 'completed');
                // student_payments.status is enum('approved','cancelled','reversed');
                // 'completed' would be coerced to an invalid empty value.
                $addPayment('status', 's', 'approved');
                $addPayment('reference_number', 's', $referenceNumber);
                $addPayment('reference', 's', $referenceNumber);
                $addPayment('receipt_number', 's', $receiptNo);
                $addPayment('recorded_by', 's', $postedBy);
                $addPayment('description', 's', $description);
                $addPayment('invoice', 's', $invoiceNumber);
                $addPayment('narration', 's', $narration);
                $addPayment('Year', 's', $yearOfStudy);
                $addPayment('dte_time', '', 'NOW()', true);
                $addPayment('created_at', '', 'NOW()', true);
                $addPayment('updated_at', '', 'NOW()', true);

                if (!empty($fields)) {
                    $insertStmt = $db->prepare("INSERT INTO student_payments (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
                    if (!$insertStmt) {
                        throw new RuntimeException('Unable to prepare payment ledger insert.');
                    }
                    if ($types !== '') {
                        $insertStmt->bind_param($types, ...$params);
                    }
                    if (!$insertStmt->execute()) {
                        $error = $insertStmt->error;
                        $insertStmt->close();
                        throw new RuntimeException($error);
                    }
                    $paymentId = (int)$insertStmt->insert_id;
                    $insertStmt->close();
                }
            }

            $invoiceColumns = $invoiceColumnsForTracking;
            $sets = [];
            $types = '';
            $params = [];
            $addInvoiceUpdate = function(string $column, string $type, $value, bool $raw = false) use (&$sets, &$types, &$params, $invoiceColumns): void {
                if (!in_array($column, $invoiceColumns, true)) {
                    return;
                }
                if ($raw) {
                    $sets[] = "`{$column}` = {$value}";
                    return;
                }
                $sets[] = "`{$column}` = ?";
                $types .= $type;
                $params[] = $value;
            };
            $addInvoiceUpdate('total_amount', 'd', $invoiceTotal);
            $addInvoiceUpdate('amount', 'd', $invoiceTotal);
            $addInvoiceUpdate('amount_paid', 'd', $newPaid);
            $addInvoiceUpdate('balance', 'd', $newBalance);
            $addInvoiceUpdate('status', 's', $invoiceStatus);
            $addInvoiceUpdate('payment_status', 's', $paymentStatus);
            $addInvoiceUpdate('last_payment_date', '', 'NOW()', true);
            $addInvoiceUpdate('updated_at', '', 'NOW()', true);
            if (empty($sets)) {
                throw new RuntimeException('No writable invoice columns found.');
            }
            $types .= 'i';
            $params[] = $invoiceId;
            $updateStmt = $db->prepare("UPDATE invoices SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1");
            if (!$updateStmt) {
                throw new RuntimeException('Unable to prepare invoice update.');
            }
            $updateStmt->bind_param($types, ...$params);

            if (!$updateStmt->execute()) {
                $error = $updateStmt->error;
                $updateStmt->close();
                throw new RuntimeException($error);
            }
            $updateStmt->close();

            $db->commit();

            if (!function_exists('wuc_notify_payment_confirmed')) {
                require_once __DIR__ . '/notification_integrations.php';
            }
            if (function_exists('wuc_notify_payment_confirmed') && $studentId !== '') {
                wuc_notify_payment_confirmed($db, $studentId, $amount, $receiptNo, $newBalance);
            }

            return [
                'success' => true,
                'message' => 'Payment recorded successfully.',
                'payment_id' => $paymentId,
                'invoice_number' => $invoiceNumber,
                'new_balance' => $newBalance,
                'status' => $invoiceStatus,
                'receipt_no' => $receiptNo,
            ];
        } catch (Throwable $e) {
            $db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('payment_activate_pending_registration')) {
    /**
     * Activate the pending course registration tied to a VERIFIED gateway
     * transaction: course_registration rows go pending_payment → registered
     * (is_active=1) and the canonical/legacy mirrors are synced. Idempotent —
     * only rows still in pending_payment are touched. Mirror failures are
     * logged, never fatal: the payment is already posted at this point.
     */
    function payment_activate_pending_registration(mysqli $db, array $transaction): array
    {
        $studentId = trim((string)($transaction['student_id'] ?? ''));
        $semRegId = (int)($transaction['semester_registration_id'] ?? 0);
        $programCode = trim((string)($transaction['program_code'] ?? ''));
        $courseCodes = [];
        $decoded = json_decode((string)($transaction['course_codes'] ?? ''), true);
        if (is_array($decoded)) {
            foreach ($decoded as $code) {
                $code = strtoupper(trim((string)$code));
                if ($code !== '') {
                    $courseCodes[] = $code;
                }
            }
        }
        if ($studentId === '') {
            return ['success' => false, 'message' => 'Transaction is missing a student ID.'];
        }

        // Period context from the semester_registration row when linked.
        $semester = null;
        $yearOfStudy = null;
        $academicYear = null;
        if ($semRegId > 0) {
            if ($stmt = $db->prepare("SELECT semester, year_of_study, academic_year FROM semester_registration WHERE id = ? LIMIT 1")) {
                $stmt->bind_param('i', $semRegId);
                $stmt->execute();
                if ($row = $stmt->get_result()->fetch_assoc()) {
                    $semester = (string)$row['semester'];
                    $yearOfStudy = (string)$row['year_of_study'];
                    $academicYear = trim((string)($row['academic_year'] ?? ''));
                }
                $stmt->close();
            }
        }

        $crCols = [];
        if ($m = $db->query('SHOW COLUMNS FROM course_registration')) {
            while ($c = $m->fetch_assoc()) { $crCols[strtolower((string)$c['Field'])] = (string)$c['Field']; }
            $m->free();
        }
        if (!isset($crCols['status'])) {
            return ['success' => false, 'message' => 'course_registration has no status column.'];
        }
        $crSidCol = $crCols['sid'] ?? ($crCols['student_id'] ?? 'Sid');
        $crSemCol = $crCols['semester'] ?? 'semester';
        $crYearCol = $crCols['year'] ?? 'Year';

        $sets = ["status = 'registered'"];
        if (isset($crCols['is_active'])) {
            $sets[] = "`{$crCols['is_active']}` = 1";
        }
        if (isset($crCols['updated_at'])) {
            $sets[] = "`{$crCols['updated_at']}` = NOW()";
        }

        $where = ["`{$crSidCol}` = ?", "status = 'pending_payment'"];
        $types = 's';
        $params = [$studentId];
        if ($semRegId > 0 && isset($crCols['semester_registration_id'])) {
            $where[] = 'semester_registration_id = ?';
            $types .= 'i';
            $params[] = $semRegId;
        } elseif ($semester !== null && $yearOfStudy !== null) {
            $where[] = "`{$crSemCol}` = ?";
            $where[] = "`{$crYearCol}` = ?";
            $types .= 'ss';
            $params[] = $semester;
            $params[] = $yearOfStudy;
        } elseif (!empty($courseCodes)) {
            $where[] = 'course_code IN (' . implode(',', array_fill(0, count($courseCodes), '?')) . ')';
            $types .= str_repeat('s', count($courseCodes));
            foreach ($courseCodes as $code) {
                $params[] = $code;
            }
        } else {
            return ['success' => false, 'message' => 'No period or course context to activate.'];
        }

        $stmt = $db->prepare("UPDATE course_registration SET " . implode(', ', $sets) . " WHERE " . implode(' AND ', $where));
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to prepare registration activation: ' . $db->error];
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['success' => false, 'message' => $err];
        }
        $activated = $stmt->affected_rows;
        $stmt->close();

        // Canonical + legacy mirrors, matching what processCourseReg.php does
        // for directly-registered courses.
        if ($programCode !== '' && $semester !== null && $yearOfStudy !== null && !empty($courseCodes)) {
            if (!function_exists('wuc_sync_legacy_course_registration_to_canonical')) {
                @include_once __DIR__ . '/helpers/academic_structure_helpers.php';
            }
            foreach ($courseCodes as $code) {
                if (function_exists('wuc_sync_legacy_course_registration_to_canonical')) {
                    try {
                        $sync = wuc_sync_legacy_course_registration_to_canonical($db, $studentId, $programCode, $code, (int)$yearOfStudy, (int)$semester);
                        if (empty($sync['ok'])) {
                            error_log('payment_activate_pending_registration: canonical sync failed for ' . $code . ': ' . (string)($sync['reason'] ?? 'unknown'));
                        }
                    } catch (Throwable $e) {
                        error_log('payment_activate_pending_registration: canonical sync threw for ' . $code . ': ' . $e->getMessage());
                    }
                }
            }
            if (wuc_table_exists($db, 'student_courses') && $academicYear !== null && $academicYear !== '') {
                if ($legacyStmt = @$db->prepare("INSERT IGNORE INTO student_courses (student_id, course_code, academic_year, semester) VALUES (?, ?, ?, ?)")) {
                    foreach ($courseCodes as $code) {
                        $legacyStmt->bind_param('ssss', $studentId, $code, $academicYear, $semester);
                        @$legacyStmt->execute();
                    }
                    $legacyStmt->close();
                }
            }
        }

        return ['success' => true, 'activated' => $activated];
    }
}

if (!function_exists('payment_issue_receipt_number')) {
    /**
     * Unique student-facing receipt number, checked against the
     * student_payments.receipt_number unique key.
     */
    function payment_issue_receipt_number(mysqli $db): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            } catch (Throwable $e) {
                $random = strtoupper(substr(md5((string)mt_rand()), 0, 6));
            }
            $candidate = 'RCPT-' . date('Ymd') . '-' . $random;

            $exists = false;
            if ($stmt = @$db->prepare("SELECT 1 FROM student_payments WHERE receipt_number = ? LIMIT 1")) {
                $stmt->bind_param('s', $candidate);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = $res && $res->num_rows > 0;
                $stmt->close();
            }
            if (!$exists) {
                return $candidate;
            }
        }
        return 'RCPT-' . date('YmdHis') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
    }
}

if (!function_exists('payment_registration_gate_settings')) {
    function payment_registration_gate_settings(mysqli $db): array
    {
        $threshold = (float)payment_setting($db, 'reg_payment_threshold_pct', '50');
        if ($threshold < 0) {
            $threshold = 0.0;
        }
        if ($threshold > 100) {
            $threshold = 100.0;
        }
        return [
            'enabled' => payment_setting($db, 'reg_payment_gate_enabled', '1') === '1',
            'threshold_pct' => $threshold,
            'pending_expiry_days' => max(1, (int)payment_setting($db, 'reg_pending_payment_expiry_days', '7')),
        ];
    }
}

if (!function_exists('payment_required_for_registration')) {
    /**
     * Server-side "how much must this student pay now to register" figure.
     * Uses the SAME evidence sources as FeeGuard::fg_check_fee_threshold so the
     * registration gate and the checkout amount can never disagree:
     * tuition from fee_structure/program_fees, paid evidence from the per-term
     * student_payments balance or the posted payments ledger.
     */
    function payment_required_for_registration(mysqli $db, string $sid, int $year, int $semester, ?string $academicYear = null): array
    {
        if (!function_exists('fg_check_fee_threshold')) {
            require_once dirname(__DIR__) . '/students/includes/FeeGuard.php';
        }

        $gate = payment_registration_gate_settings($db);
        $result = [
            'required_now' => 0.0,
            'tuition_total' => 0.0,
            'paid' => 0.0,
            'threshold_pct' => $gate['threshold_pct'],
            'gate_enabled' => $gate['enabled'],
            'sponsored' => false,
            'program_code' => '',
        ];

        if (fg_is_fully_sponsored($db, $sid)) {
            $result['sponsored'] = true;
            return $result;
        }

        $program = fg_student_program($db, $sid);
        if ($program === null || $program === '') {
            return $result;
        }
        $result['program_code'] = $program;

        $tuition = fg_required_fee($db, $program, $year, $semester);
        $result['tuition_total'] = payment_decimal($tuition);
        if ($tuition <= 0) {
            // No fee structure configured: FeeGuard fails open, so nothing is due.
            return $result;
        }

        // Paid evidence, mirroring fg_check_fee_threshold: primary is the
        // per-term running balance in student_payments.
        $paid = 0.0;
        $latestBalance = fg_latest_term_balance($db, $sid, $year, $semester);
        if ($latestBalance !== null) {
            $paid = max(0.0, $tuition - (float)$latestBalance);
        } elseif (wuc_table_exists($db, 'payments')) {
            $where = ["student_id = ?", "status IN ('posted','completed','confirmed')"];
            $types = 's';
            $params = [$sid];
            if ($academicYear !== null && $academicYear !== '' && fg_column_exists($db, 'payments', 'academic_year')) {
                $where[] = "academic_year = ?";
                $types .= 's';
                $params[] = $academicYear;
            }
            if (fg_column_exists($db, 'payments', 'semester')) {
                $where[] = "semester = ?";
                $types .= 'i';
                $params[] = $semester;
            }
            if ($stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) AS p FROM payments WHERE " . implode(' AND ', $where))) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $paid = (float)($stmt->get_result()->fetch_assoc()['p'] ?? 0.0);
                $stmt->close();
            }
        }

        $result['paid'] = payment_decimal($paid);
        $requiredNow = ($gate['threshold_pct'] / 100.0) * $tuition - $paid;
        $result['required_now'] = max(0.0, payment_decimal($requiredNow));
        return $result;
    }
}

if (!function_exists('payment_table_exists')) {
    function payment_table_exists(mysqli $db, string $table): bool
    {
        $safeTable = $db->real_escape_string($table);
        try {
            $result = $db->query("SHOW TABLES LIKE '{$safeTable}'");
            $exists = $result && $result->num_rows > 0;
            if ($result) {
                $result->free();
            }
            return $exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('payment_column_exists')) {
    function payment_column_exists(mysqli $db, string $table, string $column): bool
    {
        if (!payment_table_exists($db, $table)) {
            return false;
        }
        $safeTable = $db->real_escape_string($table);
        $safeColumn = $db->real_escape_string($column);
        try {
            $result = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
            $exists = $result && $result->num_rows > 0;
            if ($result) {
                $result->free();
            }
            return $exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('payment_reference_posted')) {
    /**
     * Returns true when the receipt/reference was already posted for this student.
     */
    function payment_reference_posted(mysqli $db, string $studentId, string $reference): bool
    {
        $reference = trim($reference);
        $studentId = trim($studentId);
        if ($reference === '' || $studentId === '') {
            return false;
        }

        if (payment_table_exists($db, 'payments') && payment_column_exists($db, 'payments', 'receipt_no')) {
            $stmt = $db->prepare('SELECT 1 FROM payments WHERE student_id = ? AND receipt_no = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('ss', $studentId, $reference);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($exists) {
                    return true;
                }
            }
        }

        if (payment_table_exists($db, 'student_payments')) {
            foreach (['reference_number', 'reference', 'referenceID', 'receiptNum'] as $column) {
                if (!payment_column_exists($db, 'student_payments', $column)) {
                    continue;
                }
                $sidColumn = payment_first_column($db, 'student_payments', ['Sid', 'SID', 'student_id']);
                if ($sidColumn === null) {
                    continue;
                }
                $stmt = $db->prepare("SELECT 1 FROM student_payments WHERE `{$sidColumn}` = ? AND `{$column}` = ? LIMIT 1");
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param('ss', $studentId, $reference);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($exists) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('payment_guard_student_exists')) {
    /**
     * @return array{SID:string,Fname:string,Lname:string,title?:string,nrc_pass?:string}|null
     */
    function payment_guard_student_exists(mysqli $db, string $studentId): ?array
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return null;
        }
        $stmt = $db->prepare('SELECT SID, Fname, Lname, title, nrc_pass FROM students WHERE SID = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('payment_recent_for_student')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function payment_recent_for_student(mysqli $db, string $studentId, int $limit = 10): array
    {
        $studentId = trim($studentId);
        if ($studentId === '' || !payment_table_exists($db, 'payments')) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $stmt = $db->prepare("SELECT receipt_no, amount, method, payment_date, status, description, academic_year, semester
            FROM payments WHERE student_id = ? ORDER BY payment_date DESC, id DESC LIMIT {$limit}");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows ?: [];
    }
}
