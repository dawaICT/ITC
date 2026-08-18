<?php
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/finance_guard.php';
require_once __DIR__ . '/../includes/short_course_student.php';
require_once __DIR__ . '/includes/student_fee_records.php';
require_once __DIR__ . '/includes/StudentAcademicWorkflowService.php';

const STUDENT_FEES_SCHEMA_CACHE_VERSION = '1';

if (!isset($_SESSION['Sid'])) {
    header('Location: ../student_login.php');
    exit();
}

$studentId = (string)$_SESSION['Sid'];
$feesWorkflow = new StudentAcademicWorkflowService($db);
$feesPeriod = $feesWorkflow->getActiveAcademicPeriod($studentId);
$feesEligibility = $feesPeriod['ok']
    ? $feesWorkflow->checkFeeEligibility($studentId, $feesPeriod)
    : null;

// Students on the new Fees System have a unified printable statement. Only
// send them there when an active fee account can actually be resolved with the
// same join rules the statement page uses (LEFT JOIN courses — orphaned
// course_id still counts). Otherwise fall through to the legacy fees page so
// the student still sees balance/payment history instead of a dead-end.
$feesHasNewAccount = false;
if ($stmtFeeAcc = $db->prepare(
    "SELECT sfa.id
       FROM student_fee_accounts sfa
       INNER JOIN students s ON s.SID = sfa.student_id
      WHERE sfa.student_id = ? AND sfa.status = 'active'
      ORDER BY sfa.id DESC
      LIMIT 1"
)) {
    $stmtFeeAcc->bind_param('s', $studentId);
    $stmtFeeAcc->execute();
    $stmtFeeAcc->store_result();
    $feesHasNewAccount = $stmtFeeAcc->num_rows > 0;
    $stmtFeeAcc->close();
}
if ($feesHasNewAccount) {
    header('Location: /wucportal/accounts/fees_statement.php');
    exit();
}

$errors = [];
$warnings = [];
$records1 = [];
$pendingInvoices = [];
$paymentHistoryLimited = false;
$invoiceHasRealBalance = false;
$invoiceHasDueDate = false;
$invoiceDataUnavailable = false;
$totalPaid = 0.0;
$shownPaidTotal = 0.0;
$currentProgramFeeSummary = [
    'has_fees' => false,
    'total_due' => 0.0,
    'total_paid' => 0.0,
    'balance' => 0.0,
    'registration' => null,
    'program_code' => '',
];

if (!isset($db) || !($db instanceof mysqli) || !$db->ping()) {
    $errors[] = 'Database connection is not available.';
}

// Short-course students owe their course fee(s) rather than program/semester
// fees. Surface that obligation alongside the generic payment/invoice records.
$scIsShort = empty($errors) ? isShortCourseStudent($db, $studentId) : false;
$scFeeSummary = $scIsShort
    ? sc_student_fee_summary($db, $studentId)
    : ['total_due' => 0.0, 'items' => [], 'has_fees' => false];
// Ensure a payable invoice exists for any outstanding short-course fee so it
// flows through the standard invoice/payment path (and Outstanding Invoices).
$scInvoice = ($scIsShort && !empty($scFeeSummary['has_fees']))
    ? sc_ensure_fee_invoice($db, $studentId)
    : null;

// Period labels derived once — used for invoice descriptions and Sem columns
// throughout the page so term-based and short-course students see correct labels.
$feesPeriodMode       = empty($errors) ? getStudentProgramPeriodMode($db, $studentId) : 'semester';
$feesPeriodLabel      = wuc_period_label_from_structure($feesPeriodMode);
$feesPeriodShortLabel = wuc_period_short_label_from_structure($feesPeriodMode);

$studentTableExists = function (mysqli $db, string $table): bool {
    $safeTable = $db->real_escape_string($table);
    if ($result = $db->query("SHOW TABLES LIKE '{$safeTable}'")) {
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
    return false;
};

$showNewInvoice = isset($_SESSION['show_course_invoice']) && $_SESSION['show_course_invoice'] === true;
$lastCourseReg = [];
$highlightInvoice = trim((string)($_GET['invoice'] ?? ''));
$paymentStatus = strtolower(trim((string)($_GET['payment_status'] ?? '')));
$paymentMessage = trim((string)($_GET['payment_message'] ?? ''));
if ($showNewInvoice) {
    $lastCourseReg = is_array($_SESSION['last_course_reg'] ?? null) ? $_SESSION['last_course_reg'] : [];
    unset($_SESSION['show_course_invoice']);
    unset($_SESSION['last_course_reg']);
}

function invoice_fragment_id(string $invoiceNumber): string {
    $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $invoiceNumber);
    return trim((string)$safe, '-') ?: 'invoice';
}

if (empty($errors)) {
    // ----------------------------
    // Payment history (student_payments)
    // ----------------------------
    if (!$studentTableExists($db, 'student_payments')) {
        // Some local/live installs only have the normalized invoices table.
        // Use paid invoice rows as a read-only payment history fallback.
        if ($studentTableExists($db, 'invoices')) {
            $invoiceFallbackCols = [];
            if ($invoiceFallbackColumnsResult = $db->query("SHOW COLUMNS FROM `invoices`")) {
                while ($col = $invoiceFallbackColumnsResult->fetch_assoc()) {
                    $invoiceFallbackCols[] = (string)$col['Field'];
                }
                $invoiceFallbackColumnsResult->free();
            }
            $hasFallbackInvoiceCol = function (string $col) use ($invoiceFallbackCols): bool {
                return in_array($col, $invoiceFallbackCols, true);
            };

            $fallbackSidCol = $hasFallbackInvoiceCol('student_id') ? 'student_id' : ($hasFallbackInvoiceCol('SID') ? 'SID' : null);
            if ($fallbackSidCol !== null) {
                $fallbackRefExpr = $hasFallbackInvoiceCol('invoice_number')
                    ? '`invoice_number`'
                    : ($hasFallbackInvoiceCol('invoice_no') ? '`invoice_no`' : ($hasFallbackInvoiceCol('invoice') ? '`invoice`' : "CAST(`id` AS CHAR)"));
                $fallbackAmountExpr = $hasFallbackInvoiceCol('amount_paid')
                    ? '`amount_paid`'
                    : ($hasFallbackInvoiceCol('amount') ? '`amount`' : '0');
                $fallbackDateExpr = $hasFallbackInvoiceCol('paid_at')
                    ? '`paid_at`'
                    : ($hasFallbackInvoiceCol('last_payment_date') ? '`last_payment_date`' : ($hasFallbackInvoiceCol('updated_at') ? '`updated_at`' : ($hasFallbackInvoiceCol('invoice_date') ? '`invoice_date`' : 'NOW()')));
                $fallbackStatusExpr = $hasFallbackInvoiceCol('payment_status')
                    ? 'LOWER(`payment_status`)'
                    : ($hasFallbackInvoiceCol('status') ? 'LOWER(`status`)' : "'paid'");
                $fallbackDescriptionExpr = $hasFallbackInvoiceCol('description') ? '`description`' : "'Invoice payment'";

                $fallbackSql = "SELECT "
                    . $fallbackRefExpr . " AS reference_number, "
                    . "COALESCE(" . $fallbackDescriptionExpr . ", 'Invoice payment') AS narration, "
                    . "'' AS semester, "
                    . "'' AS Year, "
                    . $fallbackAmountExpr . " AS amount_paid, "
                    . "'invoice' AS channel, "
                    . $fallbackDateExpr . " AS payment_date, "
                    . "'completed' AS payment_status "
                    . "FROM `invoices` WHERE `" . $fallbackSidCol . "` = ? "
                    . "AND " . $fallbackStatusExpr . " IN ('paid','completed','cleared') "
                    . "ORDER BY " . $fallbackDateExpr . " DESC LIMIT 100";

                $fallbackTotalSql = "SELECT IFNULL(SUM(" . $fallbackAmountExpr . "), 0) AS lifetime_total "
                    . "FROM `invoices` WHERE `" . $fallbackSidCol . "` = ? "
                    . "AND " . $fallbackStatusExpr . " IN ('paid','completed','cleared')";

                $fallbackTotalStmt = $db->prepare($fallbackTotalSql);
                if ($fallbackTotalStmt) {
                    $fallbackTotalStmt->bind_param('s', $studentId);
                    $fallbackTotalStmt->execute();
                    if ($totalRow = $fallbackTotalStmt->get_result()->fetch_assoc()) {
                        $totalPaid = (float)($totalRow['lifetime_total'] ?? 0);
                    }
                    $fallbackTotalStmt->close();
                }

                $fallbackStmt = $db->prepare($fallbackSql);
                if ($fallbackStmt) {
                    $fallbackStmt->bind_param('s', $studentId);
                    $fallbackStmt->execute();
                    $fallbackResult = $fallbackStmt->get_result();
                    while ($row = $fallbackResult->fetch_assoc()) {
                        $records1[] = $row;
                    }
                    $paymentHistoryLimited = count($records1) === 100;
                    $fallbackStmt->close();
                } else {
                    error_log('students/fees.php: invoice payment fallback prepare failed: ' . $db->error);
                }
            }
        }
    } else {
    // Cache schema probes per session; bump STUDENT_FEES_SCHEMA_CACHE_VERSION after DB migrations.
    $paymentCacheKey = 'student_fees_schema_' . STUDENT_FEES_SCHEMA_CACHE_VERSION . '_student_payments';
    $paymentCols = is_array($_SESSION[$paymentCacheKey] ?? null) ? $_SESSION[$paymentCacheKey] : [];
    if (empty($paymentCols)) {
        $paymentsColumnsResult = $db->query("SHOW COLUMNS FROM `student_payments`");
        if ($paymentsColumnsResult) {
            while ($col = $paymentsColumnsResult->fetch_assoc()) {
                $paymentCols[] = (string)$col['Field'];
            }
            $paymentsColumnsResult->free();
            $_SESSION[$paymentCacheKey] = $paymentCols;
        } else {
            $warnings[] = 'Unable to read payment schema.';
        }
    }

    $hasPaymentCol = function (string $col) use ($paymentCols): bool {
        return in_array($col, $paymentCols, true);
    };

    $sidCol = $hasPaymentCol('student_id') ? 'student_id' : ($hasPaymentCol('Sid') ? 'Sid' : ($hasPaymentCol('SID') ? 'SID' : null));
    if ($sidCol === null) {
        $warnings[] = 'Payment table is missing a recognizable student ID column.';
    } else {
        $refParts = [];
        if ($hasPaymentCol('reference_number')) { $refParts[] = '`reference_number`'; }
        if ($hasPaymentCol('referenceID')) { $refParts[] = '`referenceID`'; }
        if ($hasPaymentCol('receiptNum')) { $refParts[] = '`receiptNum`'; }
        if ($hasPaymentCol('invoice')) { $refParts[] = '`invoice`'; }
        $refExpr = count($refParts) ? ('COALESCE(' . implode(', ', $refParts) . ')') : "''";

        $narParts = [];
        if ($hasPaymentCol('narration')) { $narParts[] = '`narration`'; }
        if ($hasPaymentCol('description')) { $narParts[] = '`description`'; }
        if ($hasPaymentCol('invoice')) { $narParts[] = '`invoice`'; }
        $narExpr = count($narParts) ? ('COALESCE(' . implode(', ', $narParts) . ", 'Payment')") : "'Payment'";

        $semExpr = $hasPaymentCol('semester_term') ? '`semester_term`' : ($hasPaymentCol('semester') ? '`semester`' : "''");
        $yearExpr = $hasPaymentCol('Year') ? '`Year`' : ($hasPaymentCol('year_of_study') ? '`year_of_study`' : "''");
        $amountExpr = $hasPaymentCol('amount_paid') ? '`amount_paid`' : ($hasPaymentCol('amount') ? '`amount`' : '0');
        $channelExpr = $hasPaymentCol('channel') ? '`channel`' : ($hasPaymentCol('payment_channel') ? '`payment_channel`' : "''");

        $dateCol = null;
        if ($hasPaymentCol('payment_date')) { $dateCol = 'payment_date'; }
        elseif ($hasPaymentCol('dte_time')) { $dateCol = 'dte_time'; }
        elseif ($hasPaymentCol('created_at')) { $dateCol = 'created_at'; }
        $dateExpr = $dateCol ? ('`' . $dateCol . '`') : 'NOW()';

        $orderCol = $dateCol ?: ($hasPaymentCol('payment_id') ? 'payment_id' : ($hasPaymentCol('id') ? 'id' : null));
        $hasStatus = $hasPaymentCol('payment_status');

        $sql = "SELECT "
            . $refExpr . " AS reference_number, "
            . $narExpr . " AS narration, "
            . $semExpr . " AS semester, "
            . $yearExpr . " AS Year, "
            . $amountExpr . " AS amount_paid, "
            . $channelExpr . " AS channel, "
            . $dateExpr . " AS payment_date, "
            . ($hasStatus ? '`payment_status`' : "'completed'") . " AS payment_status "
            . "FROM `student_payments` WHERE `" . $sidCol . "` = ? ";

        if ($hasStatus) {
            $sql .= "AND LOWER(`payment_status`) = 'completed' ";
        }

        $totalSql = "SELECT IFNULL(SUM(" . $amountExpr . "), 0) AS lifetime_total "
            . "FROM `student_payments` WHERE `" . $sidCol . "` = ? ";
        if ($hasStatus) {
            $totalSql .= "AND LOWER(`payment_status`) = 'completed' ";
        }
        $totalStmt = $db->prepare($totalSql);
        if ($totalStmt) {
            $totalStmt->bind_param('s', $studentId);
            $totalStmt->execute();
            if ($totalRow = $totalStmt->get_result()->fetch_assoc()) {
                $totalPaid = (float)($totalRow['lifetime_total'] ?? 0);
            }
            $totalStmt->close();
        } else {
            $warnings[] = 'Unable to calculate lifetime payment total.';
            error_log('students/fees.php: payment total prepare failed: ' . $db->error);
        }

        if ($orderCol) {
            $sql .= "ORDER BY `" . $orderCol . "` DESC";
        }
        $sql .= " LIMIT 100";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $records1[] = $row;
            }
            $paymentHistoryLimited = count($records1) === 100;
            $stmt->close();
        } else {
            $warnings[] = 'Unable to load payment records.';
            error_log('students/fees.php: payment prepare failed: ' . $db->error);
        }
    }
    }

    // ----------------------------
    // Outstanding invoices (invoices)
    // ----------------------------
    if (!$studentTableExists($db, 'invoices')) {
        $warnings[] = 'Invoice records are not configured yet.';
        $invoiceDataUnavailable = true;
    } else {
    // Cache schema probes per session; bump STUDENT_FEES_SCHEMA_CACHE_VERSION after DB migrations.
    $invoiceCacheKey = 'student_fees_schema_' . STUDENT_FEES_SCHEMA_CACHE_VERSION . '_invoices';
    $invoiceCols = is_array($_SESSION[$invoiceCacheKey] ?? null) ? $_SESSION[$invoiceCacheKey] : [];
    if (empty($invoiceCols)) {
        $invoiceColumnsResult = $db->query("SHOW COLUMNS FROM `invoices`");
        if ($invoiceColumnsResult) {
            while ($col = $invoiceColumnsResult->fetch_assoc()) {
                $invoiceCols[] = (string)$col['Field'];
            }
            $invoiceColumnsResult->free();
            $_SESSION[$invoiceCacheKey] = $invoiceCols;
        } else {
            $warnings[] = 'Unable to read invoice schema.';
            $invoiceDataUnavailable = true;
        }
    }

    $hasInvoiceCol = function (string $col) use ($invoiceCols): bool {
        return in_array($col, $invoiceCols, true);
    };

    $invSidCol = $hasInvoiceCol('student_id') ? 'student_id' : ($hasInvoiceCol('SID') ? 'SID' : null);
    if ($invSidCol !== null) {
        $invNoExpr = $hasInvoiceCol('invoice_number') ? '`invoice_number`' : ($hasInvoiceCol('invoice_no') ? '`invoice_no`' : ($hasInvoiceCol('invoice') ? '`invoice`' : "''"));
        $invAmountExpr = $hasInvoiceCol('amount') ? '`amount`' : ($hasInvoiceCol('total_amount') ? '`total_amount`' : '0');
        $invBalanceExpr = $hasInvoiceCol('balance') ? '`balance`' : ($hasInvoiceCol('amount_paid') ? ("(" . $invAmountExpr . " - COALESCE(`amount_paid`,0))") : $invAmountExpr);
        $invoiceHasRealBalance = $hasInvoiceCol('balance') || $hasInvoiceCol('amount_paid');
        $invoiceHasDueDate = $hasInvoiceCol('due_date');
        $invYearExpr = $hasInvoiceCol('academic_year') ? '`academic_year`' : ($hasInvoiceCol('Year') ? '`Year`' : "''");
        $invSemExpr = $hasInvoiceCol('semester') ? '`semester`' : "''";
        $invDateExpr = $hasInvoiceCol('invoice_date') ? '`invoice_date`' : ($hasInvoiceCol('date_generated') ? '`date_generated`' : ($hasInvoiceCol('created_at') ? '`created_at`' : 'NOW()'));
        $invDueExpr = $invoiceHasDueDate ? '`due_date`' : $invDateExpr;
        $invStatusExpr = $hasInvoiceCol('status') ? 'LOWER(`status`)' : ($hasInvoiceCol('payment_status') ? 'LOWER(`payment_status`)' : "'pending'");

        $invoiceSql = "SELECT "
            . $invNoExpr . " AS invoice_number, "
            . $invAmountExpr . " AS amount, "
            . $invBalanceExpr . " AS balance, "
            . $invYearExpr . " AS academic_year, "
            . $invSemExpr . " AS semester, "
            . $invDateExpr . " AS invoice_date, "
            . $invDueExpr . " AS due_date, "
            . $invStatusExpr . " AS invoice_status "
            . "FROM `invoices` WHERE `" . $invSidCol . "` = ? ";

        if ($hasInvoiceCol('status') || $hasInvoiceCol('payment_status')) {
            // Outstanding = anything not settled. The table default status is
            // 'unpaid', so match by excluding settled states rather than listing
            // every possible open state (which previously dropped 'unpaid' rows).
            $invoiceSql .= "AND " . $invStatusExpr . " NOT IN ('paid','completed','cleared','cancelled','void') ";
        }
        $invoiceSql .= "ORDER BY " . $invDateExpr . " DESC";

        $invStmt = $db->prepare($invoiceSql);
        if ($invStmt) {
            $invStmt->bind_param('s', $studentId);
            $invStmt->execute();
            $invResult = $invStmt->get_result();
            while ($row = $invResult->fetch_assoc()) {
                $pendingInvoices[] = $row;
            }
            $invStmt->close();
        } else {
            $warnings[] = 'Unable to load outstanding invoices.';
            $invoiceDataUnavailable = true;
            error_log('students/fees.php: invoice prepare failed: ' . $db->error);
        }
    }
    }
}

$currentFeeLineItems = [];
if (empty($errors)) {
    $currentProgramFeeSummary = student_fee_current_program_summary($db, $studentId);
    $combinedPayments = student_fee_completed_payments($db, $studentId, null, 100);
    // Use the same combined ledger as the dashboard: normalized payments plus
    // legacy student_payments, de-duplicated by reference number.
    $records1 = $combinedPayments['records'];
    $totalPaid = (float)$combinedPayments['total_paid'];
    $paymentHistoryLimited = !empty($combinedPayments['limited']);

    // Load fee line items for the breakdown table (guard optional columns —
    // fee_structure.entity_type is present on current local DB but is a known
    // phantom on some installs; never hard-require it).
    $reg = is_array($currentProgramFeeSummary['registration'] ?? null) ? $currentProgramFeeSummary['registration'] : [];
    if (!empty($currentProgramFeeSummary['has_fees']) && $reg && $studentTableExists($db, 'fee_structure')) {
        $feeWhere = ['program_code = ?', 'year_of_study = ?', 'semester = ?'];
        $feeCols = [];
        if ($feeColRes = $db->query('SHOW COLUMNS FROM `fee_structure`')) {
            while ($feeCol = $feeColRes->fetch_assoc()) {
                $feeCols[strtolower((string)$feeCol['Field'])] = true;
            }
            $feeColRes->free();
        }
        if (!empty($feeCols['entity_type'])) {
            $feeWhere[] = "entity_type = 'program'";
        }
        if (!empty($feeCols['status'])) {
            $feeWhere[] = "status = 'active'";
        }
        $feeItemsSql = 'SELECT fee_type, fee_description, amount FROM fee_structure WHERE '
            . implode(' AND ', $feeWhere) . ' ORDER BY id';
        if ($feeItemsStmt = $db->prepare($feeItemsSql)) {
            $feeProgramCode = (string)($currentProgramFeeSummary['program_code'] ?? '');
            $feeYear = (string)($reg['year_of_study'] ?? '');
            $feeSem = (string)($reg['semester'] ?? '');
            $feeItemsStmt->bind_param('sss', $feeProgramCode, $feeYear, $feeSem);
            $feeItemsStmt->execute();
            $feeItemsResult = $feeItemsStmt->get_result();
            while ($feeItemRow = $feeItemsResult->fetch_assoc()) {
                $currentFeeLineItems[] = $feeItemRow;
            }
            $feeItemsStmt->close();
        }
    }

    // Auto-generate an invoice when the student has fees for the current period
    // but no invoice exists yet, so they can make a payment.
    if (!empty($currentProgramFeeSummary['has_fees']) && empty($pendingInvoices) && $reg) {
        require_once __DIR__ . '/../includes/payment_helpers.php';
        $autoInvAcademicYear = (string)($reg['academic_year'] ?? '');
        $autoInvSemester = (string)($reg['semester'] ?? '');
        $autoInvAmount = (float)$currentProgramFeeSummary['total_due'];
        $autoInvProgram = (string)($currentProgramFeeSummary['program_code'] ?? '');
        if ($autoInvAcademicYear !== '' && $autoInvSemester !== '' && $autoInvAmount > 0.0) {
            $existingInv = payment_find_invoice_for_student_term($db, $studentId, $autoInvAcademicYear, $autoInvSemester);
            if ($existingInv === null) {
                $autoInvDesc = 'Tuition fee — ' . $autoInvProgram . ' Year ' . ($reg['year_of_study'] ?? '') . ', ' . $feesPeriodLabel . ' ' . $autoInvSemester . ' / ' . $autoInvAcademicYear;
                $autoInvResult = payment_create_student_invoice($db, $studentId, $autoInvAmount, $autoInvAcademicYear, $autoInvSemester, $autoInvDesc);
                if (!empty($autoInvResult['success'])) {
                    // Reload pending invoices to include the new one
                    $newInvFetched = payment_fetch_invoice($db, $autoInvResult['invoice_number'], $studentId);
                    if ($newInvFetched) {
                        $pendingInvoices[] = array_merge($newInvFetched, [
                            'invoice_number' => $newInvFetched['invoice_number'] ?? $autoInvResult['invoice_number'],
                            'amount' => $autoInvAmount,
                            'balance' => $autoInvAmount,
                            'invoice_status' => 'pending',
                        ]);
                        $pendingCount = count($pendingInvoices);
                        $invoiceHasRealBalance = true;
                    }
                } else {
                    error_log('students/fees.php: auto-invoice failed: ' . ($autoInvResult['message'] ?? ''));
                }
            } elseif (payment_invoice_outstanding($existingInv) > 0.0) {
                $pendingInvoices[] = array_merge($existingInv, [
                    'invoice_number' => $existingInv['invoice_number'] ?? '',
                    'amount' => (float)($existingInv['amount'] ?? $autoInvAmount),
                    'balance' => payment_invoice_outstanding($existingInv),
                    'invoice_status' => strtolower((string)($existingInv['status'] ?? 'pending')),
                ]);
                $pendingCount = count($pendingInvoices);
                $invoiceHasRealBalance = true;
            }
        }
    }
}

$paymentCount = count($records1);
$shownPaidTotal = array_reduce($records1, function (float $carry, array $row): float {
    return $carry + (float)($row['amount_paid'] ?? 0);
}, 0.0);

$pendingCount = count($pendingInvoices);
$pendingTotal = array_reduce($pendingInvoices, function (float $carry, array $row) use ($invoiceHasRealBalance): float {
    $bal = (float)($row['balance'] ?? 0);
    $amt = (float)($row['amount'] ?? 0);
    $status = strtolower(trim((string)($row['invoice_status'] ?? 'pending')));
    if (in_array($status, ['paid', 'completed', 'cleared'], true)) {
        return $carry;
    }
    if ($invoiceHasRealBalance) {
        return $carry + max(0.0, $bal);
    }
    return $carry + max(0.0, $bal > 0 ? $bal : $amt);
}, 0.0);
$currentProgramBalance = !empty($currentProgramFeeSummary['has_fees'])
    ? (float)$currentProgramFeeSummary['balance']
    : 0.0;
if ($currentProgramBalance > $pendingTotal) {
    $pendingTotal = $currentProgramBalance;
}
$pendingClass = $pendingTotal > 0 ? 'warning' : 'success';

function format_date_safe(?string $rawDate, string $format = 'M d, Y'): string {
    $rawDate = trim((string)$rawDate);
    if ($rawDate === '') {
        return '-';
    }
    $ts = strtotime($rawDate);
    if ($ts === false) {
        return '-';
    }
    return date($format, $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Records - ITC</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <main class="content-wrapper portal-dashboard pt-3">
        <div class="container-fluid">
        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
        <?php foreach ($warnings as $warn): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($warn) ?></div>
        <?php endforeach; ?>
        <?php if ($feesEligibility): ?>
            <div class="alert alert-<?= $feesEligibility['is_eligible'] ? 'success' : 'warning' ?> mb-4">
                <div class="fw-bold mb-1">Fee eligibility — <?= htmlspecialchars((string)($feesPeriod['period_label'] ?? 'current period'), ENT_QUOTES, 'UTF-8') ?></div>
                <div>Total Fee: ZMW <?= number_format((float)$feesEligibility['total_fee'], 2) ?>
                    · Paid: ZMW <?= number_format((float)$feesEligibility['amount_paid'], 2) ?>
                    · Balance: ZMW <?= number_format((float)$feesEligibility['balance'], 2) ?></div>
                <div>Required: <?= number_format((float)$feesEligibility['required_percentage'], 0) ?>%
                    · Current: <?= number_format((float)$feesEligibility['payment_percentage'], 1) ?>%
                    · <?= $feesEligibility['is_eligible'] ? 'Eligible' : 'Not eligible' ?></div>
                <?php if (!$feesEligibility['is_eligible']): ?>
                    <div class="mt-1"><?= htmlspecialchars((string)$feesEligibility['reason'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($paymentMessage !== ''):
            $paymentAlertClass = 'danger';
            if ($paymentStatus === 'success') {
                $paymentAlertClass = 'success';
            } elseif ($paymentStatus === 'pending') {
                $paymentAlertClass = 'warning';
            } elseif ($paymentStatus === 'cancelled') {
                $paymentAlertClass = 'secondary';
            }
        ?>
            <div class="alert alert-<?= htmlspecialchars($paymentAlertClass) ?>">
                <i class="fas <?= $paymentAlertClass === 'success' ? 'fa-check-circle' : ($paymentAlertClass === 'warning' ? 'fa-clock' : ($paymentAlertClass === 'secondary' ? 'fa-ban' : 'fa-exclamation-triangle')) ?> me-2"></i>
                <?= htmlspecialchars($paymentMessage) ?>
            </div>
        <?php endif; ?>

        <div class="page-header mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="page-title mb-0"><i class="fas fa-wallet me-2 text-primary"></i>Financial Records</h5>
                    <p class="page-subtitle mb-0">Manage your tuition payments and invoices</p>
                </div>
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm btn-print">
                    <i class="fas fa-print me-2"></i>Print Page
                </button>
            </div>
        </div>

        <?php if ($showNewInvoice && !empty($lastCourseReg)):
            $reg = $lastCourseReg;
        ?>
            <div class="card bg-purple text-white shadow-sm mb-4">
                <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="text-white fw-bold mb-1"><i class="fas fa-file-invoice me-2"></i>Registration Invoice Generated</h4>
                        <p class="mb-0 text-white-50">Invoice #: <?= htmlspecialchars((string)($reg['invoice'] ?? '')) ?> | Total: ZMW <?= number_format((float)($reg['fees'] ?? 0), 2) ?></p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="payment.php?invoice=<?= urlencode((string)($reg['invoice'] ?? '')) ?>" class="btn btn-light fw-bold px-4">
                            <i class="fas fa-credit-card me-1"></i> Pay Now
                        </a>
                        <a href="printReceipt.php?invoice=<?= urlencode((string)($reg['invoice'] ?? '')) ?>" class="btn btn-outline-light" target="_blank">
                            <i class="fas fa-print"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($highlightInvoice !== ''): ?>
            <div class="alert alert-info mb-4">
                <i class="fas fa-info-circle me-2"></i>Looking for invoice <strong><?= htmlspecialchars($highlightInvoice) ?></strong>?
                <a href="<?= htmlspecialchars('#invoice-' . invoice_fragment_id($highlightInvoice), ENT_QUOTES, 'UTF-8') ?>" class="alert-link">Jump to invoice</a>.
            </div>
        <?php endif; ?>

        <ul class="nav nav-pills mb-4 gap-2">
            <li class="nav-item"><a class="nav-link active" href="" aria-current="page">Payment Records</a></li>
            <li class="nav-item"><a class="nav-link bg-white border" href="balanceStatement.php">Balance Statement</a></li>
        </ul>

        <div class="row g-3 mb-4">
            <div class="col-md-4 col-12">
                <div class="stat-card h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-primary text-white"><i class="fas fa-exchange-alt"></i></div>
                        <div>
                            <h3 class="mb-0"><?= (int)$paymentCount ?></h3>
                            <p class="text-muted mb-0">Total Transactions</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="stat-card h-100 border-start border-success border-4">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success text-white"><i class="fas fa-money-bill-wave"></i></div>
                        <div>
                            <h3 class="mb-0 text-success"><?= number_format($totalPaid, 2) ?></h3>
                            <p class="text-muted mb-0">Lifetime Paid (ZMW)</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="stat-card h-100 border-start border-<?= htmlspecialchars($pendingClass) ?> border-4">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-<?= htmlspecialchars($pendingClass) ?> text-white">
                            <i class="fas <?= $pendingClass === 'success' ? 'fa-check-circle' : 'fa-file-invoice-dollar' ?>"></i>
                        </div>
                        <div>
                            <h3 class="mb-0 text-<?= htmlspecialchars($pendingClass) ?>"><?= number_format($pendingTotal, 2) ?></h3>
                            <p class="text-muted mb-0">Outstanding (ZMW)</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$scIsShort && !empty($currentProgramFeeSummary['has_fees'])):
            $currentReg = is_array($currentProgramFeeSummary['registration'] ?? null) ? $currentProgramFeeSummary['registration'] : [];
            $periodMode = getStudentProgramPeriodMode($db, $studentId);
            $periodLabel = wuc_period_label_from_structure($periodMode);
            $periodParts = [];
            if (!empty($currentReg['academic_year'])) { $periodParts[] = 'Academic Year ' . (string)$currentReg['academic_year']; }
            if (!empty($currentReg['year_of_study'])) { $periodParts[] = 'Year ' . (string)$currentReg['year_of_study']; }
            if (!empty($currentReg['semester'])) { $periodParts[] = $periodLabel . ' ' . (string)$currentReg['semester']; }
            $periodText = $periodParts ? implode(' / ', $periodParts) : 'Current registration';
        ?>
            <div class="data-table-card mb-4">
                <div class="card-header border-bottom-0 pb-0">
                    <h5 class="mb-0"><i class="fas fa-scale-balanced me-2 text-primary"></i>Current Period Fees</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-center mb-3">
                        <div class="col-lg-5">
                            <div class="text-muted small text-uppercase fw-bold mb-1">Period</div>
                            <div class="fw-semibold"><?= htmlspecialchars($periodText, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-muted small mt-1">Programme: <?= htmlspecialchars((string)($currentProgramFeeSummary['program_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="col-6 col-lg-2">
                            <div class="text-muted small text-uppercase fw-bold mb-1">Billed</div>
                            <div class="fw-bold">ZMW <?= number_format((float)$currentProgramFeeSummary['total_due'], 2) ?></div>
                        </div>
                        <div class="col-6 col-lg-2">
                            <div class="text-muted small text-uppercase fw-bold mb-1">Paid</div>
                            <div class="fw-bold text-success">ZMW <?= number_format((float)$currentProgramFeeSummary['total_paid'], 2) ?></div>
                        </div>
                        <div class="col-lg-3">
                            <div class="text-muted small text-uppercase fw-bold mb-1">Balance</div>
                            <div class="h5 mb-0 text-<?= (float)$currentProgramFeeSummary['balance'] > 0 ? 'warning' : 'success' ?>">
                                ZMW <?= number_format((float)$currentProgramFeeSummary['balance'], 2) ?>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($currentFeeLineItems)): ?>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Fee Item</th>
                                    <th class="text-end">Amount (ZMW)</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($currentFeeLineItems as $lineItem):
                                $lineLabel = trim((string)($lineItem['fee_type'] ?? ''));
                                $lineDesc = trim((string)($lineItem['fee_description'] ?? ''));
                                if ($lineLabel === '' && ($lineDesc === '' || $lineDesc === '0')) {
                                    $lineLabel = 'Tuition Fee';
                                } elseif ($lineLabel === '') {
                                    $lineLabel = $lineDesc;
                                } elseif ($lineDesc !== '' && $lineDesc !== '0') {
                                    $lineLabel .= ' — ' . $lineDesc;
                                }
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($lineLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end fw-semibold"><?= number_format((float)$lineItem['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">ZMW <?= number_format((float)$currentProgramFeeSummary['total_due'], 2) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($scIsShort && $scFeeSummary['has_fees']):
            $scDue = (float)$scFeeSummary['total_due'];
            $scBalance = max(0.0, $scDue - $totalPaid);
        ?>
            <div class="data-table-card mb-4">
                <div class="card-header border-bottom-0 pb-0">
                    <h5 class="mb-0"><i class="fas fa-certificate me-2 text-warning"></i>Short Course Fees</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Course</th><th>Status</th><th class="text-end">Fee (ZMW)</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($scFeeSummary['items'] as $it): ?>
                                <tr>
                                    <td><?= htmlspecialchars(trim((string)$it['course_code'] . ' — ' . (string)$it['course_name'], ' —')) ?></td>
                                    <td><span class="badge bg-secondary text-capitalize"><?= htmlspecialchars((string)$it['status']) ?></span></td>
                                    <td class="text-end fw-bold"><?= number_format((float)$it['fee'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr><th colspan="2" class="text-end">Total Due</th><th class="text-end">ZMW <?= number_format($scDue, 2) ?></th></tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php if ($scInvoice && !empty($scInvoice['invoice_no'])): ?>
                        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                            <a href="payment.php?invoice=<?= urlencode((string)$scInvoice['invoice_no']) ?>" class="btn btn-primary">
                                <i class="fas fa-credit-card me-1"></i> Pay Now
                            </a>
                            <span class="text-muted small">Invoice <strong><?= htmlspecialchars((string)$scInvoice['invoice_no']) ?></strong> &middot; also listed under Outstanding Invoices below.</span>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-0 mt-3"><i class="fas fa-info-circle me-1"></i>Short course fees are settled with the accounts office. Contact them to make a payment or for a receipt.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($pendingCount > 0): ?>
            <div class="data-table-card mb-4">
                <div class="card-header border-bottom-0 pb-0">
                    <h5 class="mb-0"><i class="fas fa-file-invoice-dollar me-2 text-warning"></i>Outstanding Invoices (<?= (int)$pendingCount ?>)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Invoice</th>
                                <th>Issued</th>
                                <th>Due Date</th>
                                <th>Period</th>
                                <th>Amount</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th class="text-end no-print">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pendingInvoices as $inv):
                                $status = strtolower(trim((string)($inv['invoice_status'] ?? 'pending')));
                                $statusClass = $status === 'overdue' ? 'danger' : 'warning';
                                $invNumber = (string)($inv['invoice_number'] ?? '');
                                $invAmount = (float)($inv['amount'] ?? 0);
                                $invBalance = (float)($inv['balance'] ?? 0);
                                $invDate = (string)($inv['invoice_date'] ?? '');
                                $invDue = (string)($inv['due_date'] ?? '');
                                $invoiceRowId = $invNumber !== '' ? ' id="invoice-' . invoice_fragment_id($invNumber) . '"' : '';
                            ?>
                                <tr<?= $invoiceRowId ?>>
                                    <td><span class="badge bg-light border text-primary font-monospace text-wrap" style="max-width: 180px;"><?= htmlspecialchars($invNumber !== '' ? $invNumber : '-') ?></span></td>
                                    <td class="text-muted"><small><?= htmlspecialchars(format_date_safe($invDate, 'M d, Y')) ?></small></td>
                                    <td class="text-muted"><small><?= $invoiceHasDueDate ? htmlspecialchars(format_date_safe($invDue, 'M d, Y')) : '&mdash;' ?></small></td>
                                    <td class="text-secondary fw-medium small">
                                        <?php
                                        $period = [];
                                        if (!empty($inv['academic_year'])) { $period[] = 'Yr ' . $inv['academic_year']; }
                                        if (!empty($inv['semester'])) { $period[] = $feesPeriodShortLabel . ' ' . $inv['semester']; }
                                        echo !empty($period) ? htmlspecialchars(implode(' ', $period)) : '-';
                                        ?>
                                    </td>
                                    <td class="fw-bold">ZMW <?= number_format($invAmount, 2) ?></td>
                                    <td class="fw-bold text-<?= htmlspecialchars($statusClass) ?>">ZMW <?= number_format($invBalance > 0 ? $invBalance : 0.0, 2) ?></td>
                                    <td><span class="badge bg-<?= $statusClass === 'warning' ? 'warning text-dark' : ($statusClass === 'danger' ? 'danger' : 'success') ?> rounded-pill"><i class="fas fa-clock me-1"></i> <?= htmlspecialchars(ucfirst($status)) ?></span></td>
                                    <td class="text-end no-print">
                                        <div class="d-flex justify-content-end gap-2">
                                            <?php if ($invNumber !== ''): ?>
                                                <a class="btn btn-primary btn-sm" href="payment.php?invoice=<?= urlencode($invNumber) ?>" title="Pay this invoice online or by bank transfer">
                                                    <i class="fas fa-credit-card me-1"></i>Pay Fees Online
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php if (!$invoiceDataUnavailable && $currentProgramBalance <= 0): ?>
                <div class="alert alert-success mb-4">
                    <i class="fas fa-check-circle me-2"></i>No outstanding invoices - your account is clear.
                </div>
            <?php elseif (!$invoiceDataUnavailable): ?>
                <div class="alert alert-warning mb-4">
                    <i class="fas fa-circle-info me-2"></i>No invoice row is pending, but your current registered period still has a balance of ZMW <?= number_format($currentProgramBalance, 2) ?>.
                </div>
            <?php else: ?>
                <div class="alert alert-secondary mb-4">
                    <i class="fas fa-info-circle me-2"></i>Invoice data unavailable. Please try again later or contact accounts.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($records1)): ?>
            <div class="data-table-card">
                <div class="card-body">
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3 d-block opacity-25"></i>
                        <h5 class="text-muted">No completed payment records found</h5>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="data-table-card">
                <div class="card-header border-bottom-0 pb-0">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>Payment History</h5>
                </div>
                <div class="card-body">
                    <div class="d-none d-print-block text-center mb-3">
                        <h4 class="mb-1">Industrial Training Centre</h4>
                        <div class="fw-semibold">Financial Records</div>
                        <small>Printed: <?= htmlspecialchars(date('Y-m-d H:i')) ?></small>
                    </div>
                    <?php if ($paymentHistoryLimited): ?>
                        <div class="alert alert-secondary py-2 mb-3">
                            <i class="fas fa-info-circle me-2"></i>Showing most recent 100 payments.
                            Shown total: ZMW <?= number_format($shownPaidTotal, 2) ?>.
                            Lifetime total: ZMW <?= number_format($totalPaid, 2) ?>.
                        </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Description</th>
                                    <th>Period</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Channel</th>
                                    <th class="text-end no-print">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($records1 as $r): ?>
                                <tr>
                                    <td class="text-muted"><small><?= htmlspecialchars(format_date_safe((string)($r['payment_date'] ?? ''), 'M d, Y')) ?></small></td>
                                    <td>
                                        <?php if (!empty($r['reference_number'])): ?>
                                            <span class="badge bg-light border text-primary font-monospace text-wrap" style="max-width: 180px;"><?= htmlspecialchars((string)$r['reference_number']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)(($r['narration'] ?? '') ?: 'Payment')) ?></td>
                                    <td class="text-secondary fw-medium small">
                                        <?php
                                        $period = [];
                                        if (!empty($r['Year'])) { $period[] = 'Yr ' . $r['Year']; }
                                        if (!empty($r['semester'])) { $period[] = $feesPeriodShortLabel . ' ' . $r['semester']; }
                                        echo !empty($period) ? htmlspecialchars(implode(' ', $period)) : '-';
                                        ?>
                                    </td>
                                    <td class="amount fw-bold">ZMW <?= number_format((float)($r['amount_paid'] ?? 0), 2) ?></td>
                                    <td><span class="badge bg-success rounded-pill"><i class="fas fa-check-circle me-1"></i> Completed</span></td>
                                    <td><span class="badge bg-secondary text-capitalize"><?= htmlspecialchars((string)(($r['channel'] ?? '') ?: '-')) ?></span></td>
                                    <td class="text-end pe-4 no-print">
                                        <?php if (!empty($r['reference_number'])): ?>
                                            <a href="printReceipt.php?view=<?= urlencode((string)$r['reference_number']) ?>" class="btn btn-outline-secondary btn-sm rounded-circle" target="_blank" title="Download Receipt">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
