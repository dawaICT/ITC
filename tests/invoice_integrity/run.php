<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db/connect.php';
require_once $root . '/includes/invoice_helpers.php';
require_once $root . '/includes/payment_helpers.php';

$passed = 0;
$failed = 0;
$createdInvoiceIds = [];
$sessionId = 'codexinvoice' . strtolower(bin2hex(random_bytes(8)));
$studentSessionId = 'codexreg' . strtolower(bin2hex(random_bytes(8)));
$studentCsrfToken = bin2hex(random_bytes(32));
$studentSessionRow = $db->query(
    "SELECT s.SID, i.invoice_number
       FROM students s
       JOIN invoices i ON i.student_id = s.SID
      WHERE LOWER(COALESCE(s.status, 'active')) = 'active'
      ORDER BY s.SID, i.id DESC
      LIMIT 1"
)->fetch_assoc();
$studentSessionSid = (string)($studentSessionRow['SID'] ?? '');
$studentInvoiceNumber = (string)($studentSessionRow['invoice_number'] ?? '');

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_id($sessionId);
session_start();
$_SESSION['user_id'] = 'ITC900';
$_SESSION['staff_id'] = 'ITC900';
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
session_write_close();

session_id($studentSessionId);
session_start();
$_SESSION['Sid'] = $studentSessionSid;
$_SESSION['user_role'] = 'student';
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = $studentCsrfToken;
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

function httpRequest(string $url, string $method = 'GET', ?string $sessionId = null, array $fields = []): array
{
    $headers = [];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($sessionId !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sessionId);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
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

$page = source($root . '/accounts/invoice_student.php');
$controller = source($root . '/accounts/process_invoice.php');
$service = source($root . '/includes/invoice_helpers.php');
$paymentHelpers = source($root . '/includes/payment_helpers.php');
$autoInvoice = source($root . '/admin/ajax/finance_autoinvoice.php');
$registrationController = source($root . '/students/process_registration.php');
$studentInvoiceService = source($root . '/students/includes/InvoiceService.php');
$studentInvoicePage = source($root . '/students/invoice.php');
$migration = source($root . '/migrations/20260717_invoice_integrity.php');

check(strpos($page, 'i.total_amount') === false, 'invoice search does not reference the nonexistent total_amount column');
check(strpos($page, 'name="academic_year"') !== false, 'finance invoice form submits an explicit academic year');
check(strpos($page, 'current_year_of_study') !== false, 'invoice form derives year of study separately from academic year');
check(strpos($controller, "require __DIR__ . '/includes/nav.php'") !== false, 'invoice POST controller enforces finance authorization');
check(strpos($controller, 'hash_equals($sessionToken, $postedToken)') !== false, 'invoice POST controller validates CSRF tokens');
check(strpos($controller, 'invoice_create_for_student') !== false, 'invoice controller delegates to the shared atomic service');
check(stripos($controller, 'INSERT INTO invoices') === false, 'invoice controller has no direct SQL write');
check(stripos($controller, 'INSERT INTO student_payments') === false, 'invoice creation no longer fabricates a payment-ledger row');
check(strpos($service, 'SELECT @@in_transaction') !== false, 'invoice service detects outer transactions');
check(strpos($service, "SAVEPOINT ' . \$savepoint") !== false, 'invoice service preserves caller transaction ownership');
check(strpos($paymentHelpers, "return invoice_create_for_student(") !== false, 'legacy invoice callers delegate to the atomic service');
check(strpos($autoInvoice, "\$add('SID', 's', \$studentId)") !== false, 'bulk auto-invoice preserves alphanumeric SID exactly');
check(strpos($registrationController, "require_once __DIR__ . '/includes/guard.php'") !== false, 'retired legacy registration route still enforces the student guard');
check(strpos($registrationController, 'http_response_code(410)') !== false, 'legacy registration mutation route is explicitly retired');
check(strpos($registrationController, "'next_url' => '/wucportal/students/registration.php'") !== false, 'retired route identifies the canonical registration page');
check(stripos($registrationController, 'INSERT INTO') === false && stripos($registrationController, 'UPDATE ') === false, 'retired registration route cannot mutate invoice or registration data');
check(strpos($registrationController, 'invoice_create_for_student(') === false, 'retired registration route has no parallel invoice workflow');
check(strpos($studentInvoiceService, 'invoice_create_for_student(') !== false, 'student invoice service delegates creation to the atomic service');
check(strpos($studentInvoiceService, '{$dateExpr} AS invoice_date') !== false, 'student invoice reads adapt to the live date column');
check(strpos($studentInvoiceService, "\$newStatus = \$newBalance <= 0 ? 'Paid' : 'Pending';") !== false, 'student invoice payment status respects the live enum');
check(strpos($studentInvoicePage, "'amount' => (float)\$invoice['total_amount']") !== false, 'student invoice fallback line uses the authoritative stored total');
check(strpos($studentInvoicePage, "'Not specified'") !== false, 'student invoice does not invent a missing due date');
check(strpos($migration, 'uniq_invoice_student_period') !== false, 'invoice migration enforces one invoice per academic period');

$sidColumn = $db->query("SHOW COLUMNS FROM invoices LIKE 'SID'")->fetch_assoc();
check(str_starts_with(strtolower((string)($sidColumn['Type'] ?? '')), 'varchar(50)'), 'live invoice SID supports alphanumeric identities');
$yearColumn = $db->query("SHOW COLUMNS FROM invoices LIKE 'year_of_study'");
check($yearColumn && $yearColumn->num_rows === 1, 'live invoices store year of study separately');
if ($yearColumn) {
    $yearColumn->free();
}
$periodIndex = $db->query("SHOW INDEX FROM invoices WHERE Key_name = 'uniq_invoice_student_period'");
check($periodIndex && $periodIndex->num_rows === 3, 'live database has the three-column period uniqueness index');
if ($periodIndex) {
    $periodIndex->free();
}

foreach ([
    'chk_invoice_identity_match',
    'chk_invoice_period_valid',
    'chk_invoice_year_study_valid',
    'chk_invoice_amounts_valid',
    'chk_invoice_status_valid',
] as $constraintName) {
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
            AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'"
    );
    $stmt->bind_param('s', $constraintName);
    $stmt->execute();
    check($stmt->get_result()->num_rows === 1, 'live constraint exists: ' . $constraintName);
    $stmt->close();
}

$invalidRows = $db->query(
    "SELECT COUNT(*) AS total FROM invoices
      WHERE SID <> student_id OR academic_year NOT REGEXP '^[0-9]{4}$'
         OR semester NOT IN ('1','2','3','4') OR year_of_study NOT BETWEEN 1 AND 10
         OR amount < 0 OR amount_paid < 0 OR amount_paid > amount OR balance < 0
         OR ABS((amount_paid + balance) - amount) > 0.01"
)->fetch_assoc();
check((int)($invalidRows['total'] ?? 0) === 0, 'live invoice rows satisfy all identity, period, and amount invariants');

$paidInvoice = $db->query(
    "SELECT amount, amount_paid, balance, status FROM invoices
      WHERE student_id = 'CSE26456789' AND academic_year = '2026' AND semester = '2' LIMIT 1"
)->fetch_assoc();
check(
    (float)($paidInvoice['amount'] ?? 0) === 15000.0
    && (float)($paidInvoice['amount_paid'] ?? 0) === 15000.0
    && (float)($paidInvoice['balance'] ?? -1) === 0.0
    && ($paidInvoice['status'] ?? '') === 'Paid',
    'historical normalized payment is reconciled to its unique term invoice'
);

$studentResult = $db->query(
    "SELECT s.SID
       FROM students s
      WHERE s.status = 'active'
        AND EXISTS (SELECT 1 FROM student_program sp WHERE sp.Sid = s.SID AND COALESCE(sp.status,'active') <> 'inactive')
      ORDER BY s.SID LIMIT 1"
);
$studentId = $studentResult ? (string)($studentResult->fetch_assoc()['SID'] ?? '') : '';
if ($studentResult) {
    $studentResult->free();
}
if ($studentId === '') {
    throw new RuntimeException('No active student is available for invoice testing.');
}

$periods = ['1', '2', '3', '4'];
$used = [];
$usedResult = $db->prepare("SELECT semester FROM invoices WHERE student_id = ? AND academic_year = '2026'");
$usedResult->bind_param('s', $studentId);
$usedResult->execute();
$usedRows = $usedResult->get_result();
while ($row = $usedRows->fetch_assoc()) {
    $used[] = (string)$row['semester'];
}
$usedResult->close();
$available = array_values(array_diff($periods, $used));
if (count($available) < 2) {
    throw new RuntimeException('Two free invoice periods are required for the fixture student.');
}
$periodA = $available[0];
$periodB = $available[1];
$referenceA = 'INV-TST-' . strtoupper(bin2hex(random_bytes(5)));
$referenceB = 'INV-NST-' . strtoupper(bin2hex(random_bytes(5)));

try {
    $created = invoice_create_for_student(
        $db,
        $studentId,
        123.45,
        '2026',
        $periodA,
        'Invoice integrity fixture',
        $referenceA,
        1,
        'ITC900'
    );
    if (!empty($created['invoice_id'])) {
        $createdInvoiceIds[] = (int)$created['invoice_id'];
    }
    check(!empty($created['success']), 'atomic service creates a valid invoice', json_encode($created));

    $stmt = $db->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
    $createdId = (int)($created['invoice_id'] ?? 0);
    $stmt->bind_param('i', $createdId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    check(($row['SID'] ?? '') === $studentId && ($row['student_id'] ?? '') === $studentId, 'invoice stores one exact alphanumeric student identity');
    check(($row['academic_year'] ?? '') === '2026' && (int)($row['year_of_study'] ?? 0) === 1, 'invoice keeps academic year and year of study separate');
    check((float)($row['amount'] ?? 0) === 123.45 && (float)($row['balance'] ?? 0) === 123.45, 'new invoice starts with a consistent amount/balance pair');

    $mirrorStmt = $db->prepare('SELECT COUNT(*) AS total FROM student_payments WHERE reference_number = ? OR receipt_number = ?');
    $mirrorStmt->bind_param('ss', $referenceA, $referenceA);
    $mirrorStmt->execute();
    $mirrorRows = (int)($mirrorStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $mirrorStmt->close();
    check($mirrorRows === 0, 'invoice creation does not create a fake payment row');

    $duplicate = invoice_create_for_student($db, $studentId, 50, '2026', $periodA, 'Duplicate fixture');
    check(empty($duplicate['success']) && !empty($duplicate['duplicate']), 'same-student period duplicate is rejected');

    $constraintRejected = false;
    $db->begin_transaction();
    try {
        $db->query("UPDATE invoices SET SID = 'WRONG-STUDENT' WHERE id = {$createdId}");
    } catch (Throwable $e) {
        $constraintRejected = true;
    } finally {
        $db->rollback();
    }
    check($constraintRejected, 'database rejects a mismatched duplicate SID identity');

    $db->begin_transaction();
    $nested = invoice_create_for_student(
        $db,
        $studentId,
        77.00,
        '1',
        $periodB,
        'Nested transaction fixture',
        $referenceB
    );
    check(!empty($nested['success']) && ($nested['academic_year'] ?? '') === '2026', 'legacy one-digit year resolves through registration context');
    $nestedId = (int)($nested['invoice_id'] ?? 0);
    $visibleInside = (int)($db->query('SELECT COUNT(*) AS total FROM invoices WHERE id = ' . $nestedId)->fetch_assoc()['total'] ?? 0);
    check($visibleInside === 1, 'nested invoice is visible inside the caller transaction');
    $db->rollback();
    $visibleAfter = (int)($db->query('SELECT COUNT(*) AS total FROM invoices WHERE id = ' . $nestedId)->fetch_assoc()['total'] ?? 0);
    check($visibleAfter === 0, 'caller rollback removes the nested invoice');
} finally {
    if ($db->query('SELECT @@in_transaction AS active')->fetch_assoc()['active'] ?? false) {
        $db->rollback();
    }
    foreach ($createdInvoiceIds as $invoiceId) {
        $stmt = $db->prepare('DELETE FROM invoices WHERE id = ?');
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $stmt->close();
    }
}

$baseUrl = rtrim((string)(getenv('WUC_TEST_BASE_URL') ?: 'http://localhost/wucportal'), '/');
$search = httpRequest($baseUrl . '/accounts/invoice_student.php', 'POST', $sessionId, [
    'search' => '1',
    'student_id' => $studentId,
]);
check($search['status'] === 200, 'authenticated invoice search renders successfully', 'status=' . $search['status']);
check(strpos((string)$search['body'], 'Fatal error') === false && strpos((string)$search['body'], 'Warning:') === false, 'invoice search has no schema fatal or warning');
check(strpos((string)$search['body'], 'name="academic_year"') !== false, 'rendered invoice form carries the resolved academic year');

$methodRedirect = httpRequest($baseUrl . '/accounts/process_invoice.php', 'GET', $sessionId);
check($methodRedirect['status'] === 303, 'invoice controller rejects GET with a 303 redirect', 'status=' . $methodRedirect['status']);
check(($methodRedirect['headers']['location'] ?? '') === '/wucportal/accounts/invoice_student.php', 'invalid method returns to the invoice screen');

$unauthenticated = httpRequest($baseUrl . '/accounts/process_invoice.php');
check($unauthenticated['status'] === 302, 'invoice controller requires staff authentication');
check(($unauthenticated['headers']['location'] ?? '') === '/wucportal/staff_login.php', 'unauthenticated invoice request returns to staff login');

$registrationRowsBefore = (int)($db->query('SELECT COUNT(*) AS total FROM semester_registration')->fetch_assoc()['total'] ?? 0);
$invoiceRowsBeforeRetiredRoute = (int)($db->query('SELECT COUNT(*) AS total FROM invoices')->fetch_assoc()['total'] ?? 0);
$retiredRegistration = httpRequest(
    $baseUrl . '/students/process_registration.php',
    'POST',
    $studentSessionId,
    ['student_id' => $studentSessionSid, 'academic_year' => date('Y'), 'year_of_study' => '1', 'semester' => '1']
);
check($retiredRegistration['status'] === 410, 'legacy registration POST is gone instead of running a second workflow');
check(stripos((string)$retiredRegistration['body'], 'retired') !== false, 'legacy registration response safely explains the retirement');
$registrationRowsAfter = (int)($db->query('SELECT COUNT(*) AS total FROM semester_registration')->fetch_assoc()['total'] ?? 0);
$invoiceRowsAfterRetiredRoute = (int)($db->query('SELECT COUNT(*) AS total FROM invoices')->fetch_assoc()['total'] ?? 0);
check(
    $registrationRowsAfter === $registrationRowsBefore
        && $invoiceRowsAfterRetiredRoute === $invoiceRowsBeforeRetiredRoute,
    'retired registration POST leaves registration and invoice tables unchanged'
);

$studentInvoiceResponse = httpRequest(
    $baseUrl . '/students/invoice.php?invoice=' . rawurlencode($studentInvoiceNumber),
    'GET',
    $studentSessionId
);
check($studentInvoiceResponse['status'] === 200, 'student invoice page renders against the live schema');
check(
    stripos((string)$studentInvoiceResponse['body'], 'Unknown column') === false
        && stripos((string)$studentInvoiceResponse['body'], 'Fatal error') === false,
    'student invoice page has no schema fatal'
);
check(stripos((string)$studentInvoiceResponse['body'], 'Not specified') !== false, 'student invoice clearly labels an unstored due date');

session_id($sessionId);
session_start();
session_destroy();

session_id($studentSessionId);
session_start();
session_destroy();

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
