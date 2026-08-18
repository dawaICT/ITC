<?php
require_once "../../includes/finance_helpers.php";
require_once dirname(__DIR__, 2) . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();
ensure_roles($db, ['Accountant','Systems Admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Method not allowed', 405);
}

// CSRF is enforced above by wuc_ajax_require_csrf().

$dryRun = isset($_POST['dry_run']) && (string)$_POST['dry_run'] === '1';

function finance_ai_columns(mysqli $db, string $table): array {
    $cols = [];
    $res = $db->query("SHOW COLUMNS FROM `{$table}`");
    if (!$res) {
        return $cols;
    }
    while ($row = $res->fetch_assoc()) {
        $cols[] = (string)$row['Field'];
    }
    $res->free();
    return $cols;
}

function finance_ai_has_col(array $cols, string $name): bool {
    return in_array($name, $cols, true);
}

function finance_ai_table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$res) {
        return false;
    }
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}

function finance_ai_semester_value(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '1';
    }
    if (preg_match('/(\d+)/', $raw, $m)) {
        return substr($m[1], 0, 1);
    }
    $v = strtolower($raw);
    if (strpos($v, 'first') !== false) {
        return '1';
    }
    if (strpos($v, 'second') !== false) {
        return '2';
    }
    return '1';
}

function finance_ai_make_invoice_number(): string {
    try {
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    } catch (Throwable $e) {
        $suffix = strtoupper(substr(md5((string)mt_rand()), 0, 6));
    }
    return 'INV-' . date('Ymd') . '-' . $suffix;
}

$invoiceCols = finance_ai_columns($db, 'invoices');
if (empty($invoiceCols)) {
    json_error('Invoices table not available', 500);
}

// Resolve current academic period.
$currentAcademicYear = date('Y');
$currentSemester = '1';
$dueDate = date('Y-m-d', strtotime('+30 days'));

$periodStmt = $db->prepare(
    "SELECT academic_year, semester_term, end_date
     FROM academic_periods
     WHERE is_current = 1
     ORDER BY id DESC
     LIMIT 1"
);

if ($periodStmt) {
    $periodStmt->execute();
    $period = $periodStmt->get_result()->fetch_assoc();
    $periodStmt->close();

    if (!$period) {
        $fallbackStmt = $db->prepare(
            "SELECT academic_year, semester_term, end_date
             FROM academic_periods
             WHERE LOWER(status) = 'active'
             ORDER BY id DESC
             LIMIT 1"
        );
        if ($fallbackStmt) {
            $fallbackStmt->execute();
            $period = $fallbackStmt->get_result()->fetch_assoc();
            $fallbackStmt->close();
        }
    }

    if ($period) {
        $currentAcademicYear = trim((string)($period['academic_year'] ?? $currentAcademicYear));
        $currentSemester = finance_ai_semester_value((string)($period['semester_term'] ?? '1'));
        $periodEnd = trim((string)($period['end_date'] ?? ''));
        if ($periodEnd !== '' && strtotime($periodEnd) !== false) {
            $dueDate = date('Y-m-d', strtotime($periodEnd . ' +14 days'));
        }
    }
}

if ($currentAcademicYear === '') {
    $currentAcademicYear = date('Y');
}

// Use latest active program row per student to avoid duplicate candidates per student.
$studentProgramCols = finance_ai_columns($db, 'student_program');
$spStudentColumn = finance_ai_has_col($studentProgramCols, 'Sid')
    ? 'Sid'
    : (finance_ai_has_col($studentProgramCols, 'student_id') ? 'student_id' : '');
if ($spStudentColumn === '') {
    json_error('Student program table has no student identifier column', 500);
}
$spStudentExpr = "sp.`{$spStudentColumn}`";
$spStudentGroupExpr = "`{$spStudentColumn}`";
$studentsSql = "
    SELECT sp.id,
           {$spStudentExpr} AS student_id,
           sp.program_code
    FROM student_program sp
    INNER JOIN (
        SELECT MAX(id) AS max_id
        FROM student_program
        WHERE status = 'active'
          AND program_code IS NOT NULL
          AND program_code <> ''
          AND `{$spStudentColumn}` IS NOT NULL
          AND `{$spStudentColumn}` <> ''
        GROUP BY {$spStudentGroupExpr}
    ) latest ON latest.max_id = sp.id
    ORDER BY sp.id DESC
";

$studentsRes = $db->query($studentsSql);
if (!$studentsRes) {
    error_log('finance_autoinvoice.php: student query failed: ' . $db->error);
    json_error('Unable to fetch active students', 500);
}

$created = 0;
$skippedExisting = 0;
$skippedNoFee = 0;
$skippedInvalid = 0;
$failed = 0;

$dupStmt = $db->prepare("SELECT id FROM invoices WHERE student_id = ? AND academic_year = ? AND semester = ? LIMIT 1");
$semesterRegistrationCols = finance_ai_columns($db, 'semester_registration');
$registrationDateOrder = finance_ai_has_col($semesterRegistrationCols, 'registration_date')
    ? 'registration_date DESC,'
    : (finance_ai_has_col($semesterRegistrationCols, 'date_registered') ? 'date_registered DESC,' : '');

$srCurrentStmt = $db->prepare(
    "SELECT id, year_of_study
     FROM semester_registration
     WHERE student_id = ? AND program_code = ? AND academic_year = ?
     ORDER BY {$registrationDateOrder} id DESC
     LIMIT 1"
);
$srFallbackStmt = $db->prepare(
    "SELECT id, year_of_study
     FROM semester_registration
     WHERE student_id = ? AND program_code = ?
     ORDER BY {$registrationDateOrder} id DESC
     LIMIT 1"
);

$feeCols = finance_ai_columns($db, 'fee_structure');
$feeSql = "SELECT SUM(amount) AS total FROM fee_structure WHERE program_code = ? AND year_of_study = ? AND semester = ?";
if (finance_ai_has_col($feeCols, 'status')) {
    $feeSql .= " AND LOWER(status) = 'active'";
}
$feeStmt = $db->prepare($feeSql);

$pfStmt = finance_ai_table_exists($db, 'program_fees')
    ? $db->prepare("SELECT semester_fee FROM program_fees WHERE program_code = ? AND academic_year = ? LIMIT 1")
    : null;

while ($row = $studentsRes->fetch_assoc()) {
    $studentId = trim((string)($row['student_id'] ?? ''));
    $programCode = trim((string)($row['program_code'] ?? ''));

    if ($studentId === '' || $programCode === '') {
        $skippedInvalid++;
        continue;
    }

    if ($dupStmt) {
        $dupStmt->bind_param('sss', $studentId, $currentAcademicYear, $currentSemester);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            $skippedExisting++;
            continue;
        }
    }

    $yearOfStudy = '1';
    $registrationId = null;

    if ($srCurrentStmt) {
        $srCurrentStmt->bind_param('sss', $studentId, $programCode, $currentAcademicYear);
        $srCurrentStmt->execute();
        $srRow = $srCurrentStmt->get_result()->fetch_assoc();
        if ($srRow) {
            $registrationId = isset($srRow['id']) ? (int)$srRow['id'] : null;
            $yearOfStudy = trim((string)($srRow['year_of_study'] ?? '1')) ?: '1';
        }
    }

    if ($registrationId === null && $srFallbackStmt) {
        $srFallbackStmt->bind_param('ss', $studentId, $programCode);
        $srFallbackStmt->execute();
        $srRow = $srFallbackStmt->get_result()->fetch_assoc();
        if ($srRow) {
            $registrationId = isset($srRow['id']) ? (int)$srRow['id'] : null;
            $yearOfStudy = trim((string)($srRow['year_of_study'] ?? '1')) ?: '1';
        }
    }

    $total = 0.0;
    if ($feeStmt) {
        $feeStmt->bind_param('sss', $programCode, $yearOfStudy, $currentSemester);
        $feeStmt->execute();
        $feeRow = $feeStmt->get_result()->fetch_assoc();
        $total = (float)($feeRow['total'] ?? 0);
    }

    if ($total <= 0 && $pfStmt) {
        $pfStmt->bind_param('ss', $programCode, $yearOfStudy);
        $pfStmt->execute();
        $pfRow = $pfStmt->get_result()->fetch_assoc();
        $total = (float)($pfRow['semester_fee'] ?? 0);
    }

    if ($total <= 0) {
        $skippedNoFee++;
        continue;
    }

    if ($dryRun) {
        $created++;
        continue;
    }

    $fields = [];
    $values = [];
    $types = '';
    $add = static function (string $field, string $type, $value) use (&$fields, &$values, &$types): void {
        $fields[] = $field;
        $values[] = $value;
        $types .= $type;
    };

    $invoiceNumber = finance_ai_make_invoice_number();
    if (finance_ai_has_col($invoiceCols, 'invoice_number')) {
        $add('invoice_number', 's', $invoiceNumber);
    } elseif (finance_ai_has_col($invoiceCols, 'invoice_no')) {
        $add('invoice_no', 's', $invoiceNumber);
    }
    $add('student_id', 's', $studentId);
    if (finance_ai_has_col($invoiceCols, 'SID')) {
        $add('SID', 's', $studentId);
    }
    if (finance_ai_has_col($invoiceCols, 'registration_id') && $registrationId !== null) {
        $add('registration_id', 'i', $registrationId);
    }
    if (finance_ai_has_col($invoiceCols, 'program_code')) {
        $add('program_code', 's', $programCode);
    }
    if (finance_ai_has_col($invoiceCols, 'semester')) {
        $add('semester', 's', $currentSemester);
    }
    if (finance_ai_has_col($invoiceCols, 'Year')) {
        $add('Year', 's', $yearOfStudy);
    }
    if (finance_ai_has_col($invoiceCols, 'amount')) {
        $add('amount', 'd', round($total, 2));
    }
    if (finance_ai_has_col($invoiceCols, 'total_amount')) {
        $add('total_amount', 'd', round($total, 2));
    }
    if (finance_ai_has_col($invoiceCols, 'amount_paid')) {
        $add('amount_paid', 'd', 0.00);
    }
    if (finance_ai_has_col($invoiceCols, 'balance')) {
        $add('balance', 'd', round($total, 2));
    }
    if (finance_ai_has_col($invoiceCols, 'academic_year')) {
        $add('academic_year', 's', $currentAcademicYear);
    }
    if (finance_ai_has_col($invoiceCols, 'year_of_study')) {
        $add('year_of_study', 'i', (int)$yearOfStudy);
    }
    if (finance_ai_has_col($invoiceCols, 'invoice_date')) {
        $add('invoice_date', 's', date('Y-m-d'));
    }
    if (finance_ai_has_col($invoiceCols, 'due_date')) {
        $add('due_date', 's', $dueDate);
    }
    if (finance_ai_has_col($invoiceCols, 'status')) {
        $add('status', 's', 'Pending');
    }
    if (finance_ai_has_col($invoiceCols, 'payment_status')) {
        $add('payment_status', 's', 'PENDING');
    }

    $fieldSql = '`' . implode('`,`', $fields) . '`';
    $placeholders = rtrim(str_repeat('?,', count($values)), ',');
    $insSql = "INSERT INTO invoices ({$fieldSql}) VALUES ({$placeholders})";
    $insStmt = $db->prepare($insSql);

    if (!$insStmt) {
        $failed++;
        error_log('finance_autoinvoice.php: insert prepare failed: ' . $db->error);
        continue;
    }

    $bindParams = [$types];
    foreach ($values as $k => $v) {
        $bindParams[] = &$values[$k];
    }
    call_user_func_array([$insStmt, 'bind_param'], $bindParams);

    if ($insStmt->execute()) {
        $created++;
    } else {
        // Unique key collision on student/period means already invoiced.
        if ((int)$db->errno === 1062) {
            $skippedExisting++;
        } else {
            $failed++;
            error_log('finance_autoinvoice.php: insert failed for ' . $studentId . ': ' . $db->error);
        }
    }
    $insStmt->close();
}

$studentsRes->free();
if ($dupStmt) { $dupStmt->close(); }
if ($srCurrentStmt) { $srCurrentStmt->close(); }
if ($srFallbackStmt) { $srFallbackStmt->close(); }
if ($feeStmt) { $feeStmt->close(); }
if ($pfStmt) { $pfStmt->close(); }

$summary = [
    'created' => $created,
    'skipped_existing' => $skippedExisting,
    'skipped_no_fee' => $skippedNoFee,
    'skipped_invalid' => $skippedInvalid,
    'failed' => $failed,
    'academic_year' => $currentAcademicYear,
    'semester' => $currentSemester,
];

if ($dryRun) {
    json_success([
        'created' => $created,
        'skipped_existing' => $skippedExisting,
        'skipped_no_fee' => $skippedNoFee,
        'skipped_invalid' => $skippedInvalid,
        'failed' => $failed,
        'academic_year' => $currentAcademicYear,
        'semester' => $currentSemester,
        'message' => "Preview complete: {$created} invoices would be created, {$skippedExisting} existing, {$skippedNoFee} missing fee setup, {$skippedInvalid} invalid records, {$failed} failed."
    ]);
}

log_audit($db, $_SESSION['staff_id'] ?? 'system', 'fees.autoinvoice', json_encode($summary, JSON_UNESCAPED_SLASHES));

json_success([
    'created' => $created,
    'skipped_existing' => $skippedExisting,
    'skipped_no_fee' => $skippedNoFee,
    'skipped_invalid' => $skippedInvalid,
    'failed' => $failed,
    'message' => "Auto-invoice complete: {$created} created, {$skippedExisting} existing, {$skippedNoFee} missing fee setup, {$skippedInvalid} invalid records, {$failed} failed."
]);
