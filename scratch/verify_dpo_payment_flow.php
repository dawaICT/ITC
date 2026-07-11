<?php
/**
 * Verification harness for the DPO Pay integration (no gateway network calls).
 *
 * Simulates the full course-registration payment cycle with a synthetic
 * student, exactly as paygate_start.php / paygate_return.php drive it:
 *   pending course rows → invoice → gateway tx → (verified) apply → activate
 * then asserts idempotency (duplicate reference, re-activation) and the
 * server-side amount clamp. Cleans up everything it created.
 *
 * Run: php scratch/verify_dpo_payment_flow.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/payment_helpers.php';
mysqli_report(MYSQLI_REPORT_OFF);

$SID = 'TESTPAY900';
$AY = '2099';
$SEM = '1';
$YEAR = '1';
$COURSES = ['TESTPAY101', 'TESTPAY102'];

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  {$label}\n"; }
    else { $fail++; echo "  FAIL  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; }
}

function cleanup(mysqli $db, string $SID): void
{
    foreach ([
        "DELETE FROM payment_gateway_transactions WHERE student_id = '{$SID}'",
        "DELETE FROM student_payments WHERE Sid = '{$SID}'",
        "DELETE FROM payments WHERE student_id = '{$SID}'",
        "DELETE FROM invoices WHERE student_id = '{$SID}'",
        "DELETE FROM course_registration WHERE Sid = '{$SID}'",
        "DELETE FROM semester_registration WHERE SID = '{$SID}'",
        "DELETE FROM student_courses WHERE student_id = '{$SID}'",
        "DELETE FROM students WHERE SID = '{$SID}'",
    ] as $sql) {
        @$db->query($sql);
    }
}

echo "== setup ==\n";
cleanup($db, $SID);

// student_payments.Sid has an FK to students.SID, so the synthetic student
// must exist (as every real student does).
$stStmt = $db->prepare("INSERT INTO students (SID, Fname, Lname, sex, status) VALUES (?, 'Payment', 'Verification', 'M', 'Active')");
$stStmt->bind_param('s', $SID);
$okStudent = $stStmt->execute();
$stStmt->close();
check('synthetic students row created (FK target)', $okStudent, $db->error);

$programCode = '';
if ($r = $db->query("SELECT program_code FROM programs LIMIT 1")) {
    $programCode = (string)($r->fetch_assoc()['program_code'] ?? '');
    $r->free();
}
check('found a real program code to borrow', $programCode !== '');

$stmt = $db->prepare("INSERT INTO semester_registration (program_code, SID, student_id, semester, period_type, Year, year_of_study, academic_year, registration_date, created_at) VALUES (?, ?, ?, ?, 'semester', ?, ?, ?, NOW(), NOW())");
$stmt->bind_param('sssssss', $programCode, $SID, $SID, $SEM, $YEAR, $YEAR, $AY);
$ok = $stmt->execute();
$semRegId = (int)$stmt->insert_id;
$stmt->close();
check('semester_registration row created', $ok && $semRegId > 0, $db->error);

$crStmt = $db->prepare("INSERT INTO course_registration (Sid, course_code, semester, Year, semester_registration_id, status, is_active) VALUES (?, ?, ?, ?, ?, 'pending_payment', 0)");
foreach ($COURSES as $code) {
    $crStmt->bind_param('ssssi', $SID, $code, $SEM, $YEAR, $semRegId);
    $ok = $crStmt->execute();
    check("pending course row {$code} created", $ok, $crStmt->error);
}
$crStmt->close();

$inv = payment_create_student_invoice($db, $SID, 5000.00, $AY, $SEM, 'Verification tuition invoice');
check('tuition invoice created', !empty($inv['success']), (string)($inv['message'] ?? ''));
$invoiceNumber = (string)($inv['invoice_number'] ?? '');
$invoice = payment_fetch_invoice($db, $invoiceNumber, $SID);
check('invoice fetch scoped to student works', is_array($invoice));
check('invoice outstanding = 5000', $invoice && abs(payment_invoice_outstanding($invoice) - 5000.00) < 0.01, (string)payment_invoice_outstanding($invoice ?: []));

$foreign = payment_fetch_invoice($db, $invoiceNumber, 'SOMEONE_ELSE');
check('invoice NOT returned for another student', $foreign === null);

echo "== gateway transaction (as paygate_start would create it) ==\n";
$reference = payment_generate_reference('DPO');
$txRes = payment_create_gateway_transaction($db, [
    'invoice_number' => $invoiceNumber,
    'student_id' => $SID,
    'payment_type' => 'course_registration',
    'semester_registration_id' => $semRegId,
    'program_code' => $programCode,
    'course_codes' => $COURSES,
    'amount' => 2500.00,
    'currency' => 'ZMW',
    'narration' => 'Course registration payment — verification run',
    'provider' => 'DPO',
    'reference_number' => $reference,
    'status' => 'pending',
    'created_by' => $SID,
]);
check('gateway tx created with registration context', !empty($txRes['success']), (string)($txRes['message'] ?? ''));
$tx = payment_find_gateway_transaction($db, 'reference_number', $reference);
check('tx payment_type persisted', $tx && $tx['payment_type'] === 'course_registration');
check('tx semester_registration_id persisted', $tx && (int)$tx['semester_registration_id'] === $semRegId);
check('tx course_codes persisted as JSON', $tx && is_array(json_decode((string)$tx['course_codes'], true)));

echo "== tampered amount is rejected by the ledger ==\n";
$tamper = payment_apply_completed_payment($db, $invoice, 99999.00, 'DPO Pay', payment_generate_reference('DPO'), 'tamper test');
check('overpayment rejected', empty($tamper['success']), (string)($tamper['message'] ?? ''));

echo "== verified payment posts once and activates ==\n";
$apply = payment_apply_completed_payment($db, $invoice, 2500.00, 'DPO Pay', $reference, 'Course registration payment', ['posted_by' => 'dpo-gateway', 'student_id' => $SID]);
check('payment applied', !empty($apply['success']), (string)($apply['message'] ?? ''));
check('receipt number issued', !empty($apply['receipt_no']));

$invoice2 = payment_fetch_invoice($db, $invoiceNumber, $SID);
check('invoice amount_paid = 2500', $invoice2 && abs((float)$invoice2['amount_paid'] - 2500.00) < 0.01, (string)($invoice2['amount_paid'] ?? 'n/a'));
check('invoice balance = 2500', $invoice2 && abs((float)$invoice2['balance'] - 2500.00) < 0.01, (string)($invoice2['balance'] ?? 'n/a'));

$sp = null;
if ($r = $db->query("SELECT * FROM student_payments WHERE Sid = '{$SID}' AND reference_number = '{$reference}' LIMIT 1")) {
    $sp = $r->fetch_assoc();
    $r->free();
}
check('student_payments ledger row written', is_array($sp));
check('ledger payment_status = completed', $sp && strtolower((string)$sp['payment_status']) === 'completed', (string)($sp['payment_status'] ?? 'n/a'));
check('ledger status enum = approved (not empty)', $sp && (string)$sp['status'] === 'approved', var_export($sp['status'] ?? null, true));
check('ledger receipt_number stored', $sp && (string)$sp['receipt_number'] === (string)$apply['receipt_no']);

$pay = null;
if ($r = $db->query("SELECT * FROM payments WHERE student_id = '{$SID}' LIMIT 1")) {
    $pay = $r->fetch_assoc();
    $r->free();
}
check('normalized payments row written', is_array($pay));

$activation = payment_activate_pending_registration($db, $tx);
check('activation succeeded', !empty($activation['success']), (string)($activation['message'] ?? ''));
check('activation touched 2 rows', (int)($activation['activated'] ?? 0) === 2, (string)($activation['activated'] ?? 'n/a'));

$active = 0;
if ($r = $db->query("SELECT COUNT(*) c FROM course_registration WHERE Sid = '{$SID}' AND status = 'registered' AND is_active = 1")) {
    $active = (int)$r->fetch_assoc()['c'];
    $r->free();
}
check('course rows now registered + active', $active === 2, (string)$active);

echo "== idempotency (replayed return / duplicate IPN) ==\n";
$replay = payment_apply_completed_payment($db, payment_fetch_invoice($db, $invoiceNumber, $SID), 2500.00, 'DPO Pay', $reference, 'replay');
check('duplicate reference detected, no double post', !empty($replay['success']) && !empty($replay['duplicate']), json_encode($replay));

$invoice3 = payment_fetch_invoice($db, $invoiceNumber, $SID);
check('invoice unchanged after replay (paid still 2500)', $invoice3 && abs((float)$invoice3['amount_paid'] - 2500.00) < 0.01, (string)($invoice3['amount_paid'] ?? 'n/a'));

$reactivate = payment_activate_pending_registration($db, $tx);
check('re-activation is a no-op', !empty($reactivate['success']) && (int)$reactivate['activated'] === 0, json_encode($reactivate));

$ledgerCount = 0;
if ($r = $db->query("SELECT COUNT(*) c FROM student_payments WHERE Sid = '{$SID}'")) {
    $ledgerCount = (int)$r->fetch_assoc()['c'];
    $r->free();
}
check('exactly one ledger row (no duplicates)', $ledgerCount === 1, (string)$ledgerCount);

echo "== registration requirement helper ==\n";
$req = payment_required_for_registration($db, $SID, (int)$YEAR, (int)$SEM, $AY);
check('requirement helper returns structure', isset($req['required_now'], $req['tuition_total'], $req['threshold_pct']));
echo "     (tuition={$req['tuition_total']}, paid={$req['paid']}, required_now={$req['required_now']}, pct={$req['threshold_pct']})\n";

echo "== cleanup ==\n";
cleanup($db, $SID);
$left = 0;
foreach (['payment_gateway_transactions' => "student_id", 'student_payments' => 'Sid', 'invoices' => 'student_id', 'course_registration' => 'Sid', 'semester_registration' => 'SID', 'payments' => 'student_id'] as $t => $col) {
    if ($r = @$db->query("SELECT COUNT(*) c FROM {$t} WHERE {$col} = '{$SID}'")) {
        $left += (int)$r->fetch_assoc()['c'];
        $r->free();
    }
}
check('all test rows removed', $left === 0, (string)$left);

echo "\n== RESULT: {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
