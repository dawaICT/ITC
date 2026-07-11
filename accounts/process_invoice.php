<?php
require "../db/connect.php";
require_once __DIR__ . '/../includes/finance_helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Expected POST contract:
 * - csrf_token, student_id, invoice_amount, narration, other_narration, semester, year_of_study
 */

function redirect_with_alert(string $message, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo "<script>alert(" . json_encode($message) . ");window.open('invoice_student.php','_self');</script>";
    exit;
}

function accounts_table_columns(mysqli $db, string $table): array
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

function accounts_has_column(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function accounts_invoice_number_column(array $columns): ?string
{
    foreach (['invoice_number', 'invoice_no', 'invoice', 'reference'] as $column) {
        if (accounts_has_column($columns, $column)) {
            return $column;
        }
    }
    return null;
}

function accounts_bind_dynamic(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
}

function fetchStudentInvoiceContext(mysqli $db, string $studentId): ?array
{
    $invoiceColumns = accounts_table_columns($db, 'invoices');
    $paymentColumns = accounts_table_columns($db, 'student_payments');
    $normalizedPaymentColumns = accounts_table_columns($db, 'payments');

    $invoiceAmountExpression = accounts_has_column($invoiceColumns, 'total_amount')
        ? 'COALESCE(i.total_amount, i.amount, 0)'
        : (accounts_has_column($invoiceColumns, 'amount') ? 'COALESCE(i.amount, 0)' : '0');

    $invoicePredicates = [];
    if (accounts_has_column($invoiceColumns, 'student_id')) {
        $invoicePredicates[] = 'i.student_id COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci';
    }
    if (accounts_has_column($invoiceColumns, 'SID')) {
        $invoicePredicates[] = 'CAST(i.SID AS CHAR(50)) COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci';
    }
    $invoiceWhere = empty($invoicePredicates) ? '1 = 0' : implode(' OR ', $invoicePredicates);

    $paymentPredicates = [];
    if (accounts_has_column($paymentColumns, 'Sid')) {
        $paymentPredicates[] = 'p.Sid COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci';
    }
    if (accounts_has_column($paymentColumns, 'student_id')) {
        $paymentPredicates[] = 'p.student_id COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci';
    }
    $paymentWhere = empty($paymentPredicates) ? '1 = 0' : implode(' OR ', $paymentPredicates);
    $paymentAmountColumn = accounts_has_column($paymentColumns, 'amount_paid')
        ? 'amount_paid'
        : (accounts_has_column($paymentColumns, 'amount') ? 'amount' : null);
    $paymentSum = $paymentAmountColumn ? "COALESCE(SUM(p.`{$paymentAmountColumn}`), 0)" : '0';

    $normalizedPaymentWhere = accounts_has_column($normalizedPaymentColumns, 'student_id')
        ? 'np.student_id COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci'
        : '1 = 0';
    $normalizedPaymentSum = accounts_has_column($normalizedPaymentColumns, 'amount')
        ? 'COALESCE(SUM(np.amount), 0)'
        : '0';

    $sql = "SELECT
                s.SID,
                sp.program_code,
                (
                    SELECT COALESCE(SUM({$invoiceAmountExpression}), 0)
                    FROM invoices i
                    WHERE {$invoiceWhere}
                ) - (
                    SELECT {$paymentSum}
                    FROM student_payments p
                    WHERE {$paymentWhere}
                ) - (
                    SELECT {$normalizedPaymentSum}
                    FROM payments np
                    WHERE {$normalizedPaymentWhere}
                ) AS calculated_balance
            FROM students s
            LEFT JOIN student_program sp
                ON sp.Sid COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci
            WHERE s.SID COLLATE utf8mb4_unicode_ci = ?
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_alert('Invalid request method.', 405);
}

$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
$postedToken = (string)($_POST['csrf_token'] ?? '');

if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    redirect_with_alert('Invalid request token. Please refresh the page and try again.', 403);
}

$studentId = trim($_POST['student_id'] ?? '');
$invoiceAmountRaw = trim($_POST['invoice_amount'] ?? '');
$narrationChoice = trim($_POST['narration'] ?? '');
$otherNarration = trim($_POST['other_narration'] ?? '');
$semester = trim($_POST['semester'] ?? '');
$yearOfStudy = trim($_POST['year_of_study'] ?? '');

$allowedNarrations = [
    'Tuition Fee',
    'Registration Fee',
    'Library Fee',
    'Accommodation Fee',
    'Examination Fee',
    'Other',
];

if ($studentId === '' || $invoiceAmountRaw === '' || $narrationChoice === '' || $semester === '' || $yearOfStudy === '') {
    redirect_with_alert('Please complete all required invoice fields.');
}

if (!is_numeric($invoiceAmountRaw) || (float)$invoiceAmountRaw <= 0) {
    redirect_with_alert('Please enter a valid invoice amount.');
}

if (!in_array($narrationChoice, $allowedNarrations, true)) {
    redirect_with_alert('Please select a valid narration.');
}

$narration = $narrationChoice === 'Other' ? $otherNarration : $narrationChoice;
if ($narration === '') {
    redirect_with_alert('Please provide a narration for this invoice.');
}

$student = fetchStudentInvoiceContext($db, $studentId);
if (!$student) {
    redirect_with_alert('This student ID is not registered in the system.');
}

$currentBalance = (float)($student['calculated_balance'] ?? 0);
$invoiceAmount = round((float)$invoiceAmountRaw, 2);
$newBalance = round($currentBalance + $invoiceAmount, 2);
$programCode = (string)($student['program_code'] ?? '');
$invoiceNumber = 'INV-' . date('YmdHis') . '-' . substr(preg_replace('/\D+/', '', $studentId) ?: '0000', -4);
$description = 'Invoice: ' . $narration;
$dueDate = date('Y-m-d', strtotime('+30 days'));
$invoiceColumns = accounts_table_columns($db, 'invoices');
$paymentColumns = accounts_table_columns($db, 'student_payments');

if (empty($invoiceColumns)) {
    redirect_with_alert('Invoice table is not configured. Please contact systems support.');
}

if (!empty($paymentColumns) && accounts_has_column($paymentColumns, 'Sid')) {
    $paymentIdColumn = accounts_has_column($paymentColumns, 'payment_id') ? 'payment_id' : (accounts_has_column($paymentColumns, 'id') ? 'id' : 'Sid');
    $dupSql = "SELECT `{$paymentIdColumn}`
               FROM student_payments
               WHERE Sid = ?
                 AND channel = 'invoice'
                 AND semester_term = ?";
    $dupTypes = 'ss';
    $dupParams = [$studentId, $semester];

    $yearChecks = [];
    foreach (['academic_year', 'year_of_study', 'Year'] as $column) {
        if (accounts_has_column($paymentColumns, $column)) {
            $yearChecks[] = "`{$column}` = ?";
            $dupTypes .= 's';
            $dupParams[] = $yearOfStudy;
        }
    }
    if (!empty($yearChecks)) {
        $dupSql .= " AND (" . implode(' OR ', $yearChecks) . ")";
    }
    $dupSql .= " LIMIT 1";

    $dupPayments = $db->prepare($dupSql);
}

if (!empty($dupPayments)) {
    accounts_bind_dynamic($dupPayments, $dupTypes, $dupParams);
    $dupPayments->execute();
    $dupPayments->store_result();
    if ($dupPayments->num_rows > 0) {
        $dupPayments->close();
        redirect_with_alert('Invoice already exists for this student/term.');
    }
    $dupPayments->close();
}

$invoiceStudentPredicates = [];
$dupTypes = '';
$dupParams = [];
if (accounts_has_column($invoiceColumns, 'student_id')) {
    $invoiceStudentPredicates[] = 'student_id COLLATE utf8mb4_general_ci = ?';
    $dupTypes .= 's';
    $dupParams[] = $studentId;
}
if (accounts_has_column($invoiceColumns, 'SID')) {
    $invoiceStudentPredicates[] = 'CAST(SID AS CHAR(50)) COLLATE utf8mb4_general_ci = ?';
    $dupTypes .= 's';
    $dupParams[] = $studentId;
}

$invoicePeriodPredicates = [];
if (accounts_has_column($invoiceColumns, 'semester')) {
    $invoicePeriodPredicates[] = 'semester = ?';
    $dupTypes .= 's';
    $dupParams[] = $semester;
}
$invoiceYearPredicates = [];
foreach (['Year', 'academic_year', 'year_of_study'] as $column) {
    if (accounts_has_column($invoiceColumns, $column)) {
        $invoiceYearPredicates[] = "`{$column}` = ?";
        $dupTypes .= 's';
        $dupParams[] = $yearOfStudy;
    }
}
if (!empty($invoiceStudentPredicates) && !empty($invoicePeriodPredicates) && !empty($invoiceYearPredicates)) {
    $dupSql = "SELECT id
               FROM invoices
               WHERE (" . implode(' OR ', $invoiceStudentPredicates) . ")
                 AND " . implode(' AND ', $invoicePeriodPredicates) . "
                 AND (" . implode(' OR ', $invoiceYearPredicates) . ")
               LIMIT 1";
    $dupInvoices = $db->prepare($dupSql);
}

if (!empty($dupInvoices)) {
    accounts_bind_dynamic($dupInvoices, $dupTypes, $dupParams);
    $dupInvoices->execute();
    $dupInvoices->store_result();
    if ($dupInvoices->num_rows > 0) {
        $dupInvoices->close();
        redirect_with_alert('Invoice already exists for this student/term.');
    }
    $dupInvoices->close();
}

$db->begin_transaction();

try {
    $invoiceFields = [];
    $invoicePlaceholders = [];
    $invoiceTypes = '';
    $invoiceParams = [];
    $addInvoice = function(string $column, string $type, $value, bool $raw = false) use (&$invoiceFields, &$invoicePlaceholders, &$invoiceTypes, &$invoiceParams, $invoiceColumns): void {
        if (!accounts_has_column($invoiceColumns, $column)) {
            return;
        }
        $invoiceFields[] = "`{$column}`";
        if ($raw) {
            $invoicePlaceholders[] = (string)$value;
            return;
        }
        $invoicePlaceholders[] = '?';
        $invoiceTypes .= $type;
        $invoiceParams[] = $value;
    };

    $invoiceNumberColumn = accounts_invoice_number_column($invoiceColumns);
    if ($invoiceNumberColumn !== null) {
        $addInvoice($invoiceNumberColumn, 's', $invoiceNumber);
    }
    $addInvoice('invoice_date', '', 'CURDATE()', true);
    $addInvoice('date_generated', '', 'NOW()', true);
    $addInvoice('student_id', 's', $studentId);
    $addInvoice('SID', 's', $studentId);
    $addInvoice('program_code', 's', $programCode);
    $addInvoice('semester', 's', $semester);
    $addInvoice('description', 's', $description);
    $addInvoice('total_amount', 'd', $invoiceAmount);
    $addInvoice('amount', 'd', $invoiceAmount);
    $addInvoice('amount_paid', 'd', 0.0);
    $addInvoice('balance', 'd', $invoiceAmount);
    $addInvoice('due_date', 's', $dueDate);
    $addInvoice('status', 's', 'Pending');
    $addInvoice('Year', 's', $yearOfStudy);
    $addInvoice('academic_year', 's', $yearOfStudy);
    $addInvoice('year_of_study', 's', $yearOfStudy);
    $addInvoice('payment_status', 's', 'pending');
    $addInvoice('created_at', '', 'NOW()', true);
    $addInvoice('updated_at', '', 'NOW()', true);

    if (empty($invoiceFields)) {
        throw new RuntimeException('No writable invoice columns found.');
    }

    $invoiceInsert = $db->prepare("INSERT INTO invoices (" . implode(', ', $invoiceFields) . ") VALUES (" . implode(', ', $invoicePlaceholders) . ")");

    if (!$invoiceInsert) {
        throw new RuntimeException('Unable to prepare invoice insert.');
    }

    accounts_bind_dynamic($invoiceInsert, $invoiceTypes, $invoiceParams);

    if (!$invoiceInsert->execute()) {
        throw new RuntimeException($invoiceInsert->error);
    }
    $invoiceInsert->close();

    if (!empty($paymentColumns)) {
        $paymentFields = [];
        $paymentPlaceholders = [];
        $paymentTypes = '';
        $paymentParams = [];
        $addPayment = function(string $column, string $type, $value, bool $raw = false) use (&$paymentFields, &$paymentPlaceholders, &$paymentTypes, &$paymentParams, $paymentColumns): void {
            if (!accounts_has_column($paymentColumns, $column)) {
                return;
            }
            $paymentFields[] = "`{$column}`";
            if ($raw) {
                $paymentPlaceholders[] = (string)$value;
                return;
            }
            $paymentPlaceholders[] = '?';
            $paymentTypes .= $type;
            $paymentParams[] = $value;
        };

        $addPayment('Sid', 's', $studentId);
        $addPayment('SID', 's', $studentId);
        $addPayment('student_id', 's', $studentId);
        $addPayment('amount_paid', 'd', 0.0);
        $addPayment('amount', 'd', $invoiceAmount);
        $addPayment('balance', 'd', $newBalance);
        $addPayment('channel', 's', 'invoice');
        $addPayment('payment_method', 's', 'invoice');
        $addPayment('payment_channel', 's', 'invoice');
        $addPayment('payment_date', '', 'NOW()', true);
        $addPayment('academic_year', 's', $yearOfStudy);
        $addPayment('year_of_study', 's', $yearOfStudy);
        $addPayment('semester_term', 's', $semester);
        $addPayment('semester', 's', $semester);
        $addPayment('payment_status', 's', 'pending');
        $addPayment('status', 's', 'pending');
        $addPayment('reference_number', 's', $invoiceNumber);
        $addPayment('reference', 's', $invoiceNumber);
        $addPayment('referenceID', 's', $invoiceNumber);
        $addPayment('description', 's', $description);
        $addPayment('invoice', 's', $invoiceNumber);
        $addPayment('narration', 's', $narration);
        $addPayment('Year', 's', $yearOfStudy);
        $addPayment('dte_time', '', 'NOW()', true);
        $addPayment('created_at', '', 'NOW()', true);
        $addPayment('updated_at', '', 'NOW()', true);

        if (!empty($paymentFields)) {
            $paymentInsert = $db->prepare("INSERT INTO student_payments (" . implode(', ', $paymentFields) . ") VALUES (" . implode(', ', $paymentPlaceholders) . ")");
            if (!$paymentInsert) {
                throw new RuntimeException('Unable to prepare ledger insert.');
            }
            accounts_bind_dynamic($paymentInsert, $paymentTypes, $paymentParams);
            if (!$paymentInsert->execute()) {
                throw new RuntimeException($paymentInsert->error);
            }
            $paymentInsert->close();
        }
    }

    try {
        $arTableResult = $db->query("SHOW TABLES LIKE 'finance_student_installments'");
        if ($arTableResult && $arTableResult->num_rows > 0) {
            $arTableResult->free();
            $installmentInsert = $db->prepare("INSERT INTO finance_student_installments (
                    student_id,
                    plan_id,
                    installment_no,
                    due_date,
                    amount,
                    status
                ) VALUES (?, 0, 1, DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, 'pending')");

            if ($installmentInsert) {
                $installmentInsert->bind_param('sd', $studentId, $invoiceAmount);
                $installmentInsert->execute();
                $installmentInsert->close();
            }
        }
    } catch (Throwable $e) {
        // Keep invoice creation resilient if the AR bridge is unavailable.
    }

    $audUser = isset($_SESSION['staff_id'])
        ? (string)$_SESSION['staff_id']
        : (isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : 'system');
    if (function_exists('log_audit')) {
        log_audit($db, $audUser, 'invoice.create', json_encode([
            'student_id' => $studentId,
            'amount' => $invoiceAmount,
            'semester' => $semester,
            'year_of_study' => $yearOfStudy,
            'invoice_number' => $invoiceNumber,
        ]));
    }

    $db->commit();
    redirect_with_alert('Student invoice updated successfully!');
} catch (Throwable $e) {
    $db->rollback();
    redirect_with_alert('Invoice failed. There was an error processing the invoice.');
}
