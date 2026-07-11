<?php
/**
 * Seed period fee payment so a test student can pass the registration fee gate.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/seed_test_student_period_payment.php
 *   C:\xampp\php\php.exe scripts/seed_test_student_period_payment.php --apply
 *   C:\xampp\php\php.exe scripts/seed_test_student_period_payment.php --apply --reset-period-registration
 *   C:\xampp\php\php.exe scripts/seed_test_student_period_payment.php --apply --student=CSE26456789 --period=2
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/schema_guard.php';
require_once __DIR__ . '/../students/includes/FeeGuard.php';
require_once __DIR__ . '/../students/includes/period_mode_helper.php';
require_once __DIR__ . '/../students/includes/StudentAcademicWorkflowService.php';

$apply = in_array('--apply', $argv ?? [], true);
$resetPeriodReg = in_array('--reset-period-registration', $argv ?? [], true);
$studentId = 'CSE26456789';
$periodOverride = null;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--student=')) {
        $studentId = trim(substr($arg, 10));
    }
    if (str_starts_with($arg, '--period=')) {
        $periodOverride = max(1, (int)substr($arg, 9));
    }
}

if (!wuc_table_exists($db, 'payments')) {
    fwrite(STDERR, "payments table not found.\n");
    exit(1);
}

$workflow = new StudentAcademicWorkflowService($db);
$period = $workflow->getActiveAcademicPeriod($studentId);
if (empty($period['ok'])) {
    fwrite(STDERR, 'Could not resolve active period: ' . ($period['error'] ?? 'unknown') . "\n");
    exit(1);
}

$programCode = (string)$period['program_code'];
$yearOfStudy = (int)$period['year_of_study'];
$periodNumber = $periodOverride ?? (int)$period['period_number'];
$academicYear = (string)$period['academic_year'];
$calendarType = (string)$period['calendar_type'];
$requiredPct = StudentAcademicWorkflowService::requiredFeePercentage($calendarType, $periodNumber);
$totalFee = fg_required_fee($db, $programCode, $yearOfStudy, $periodNumber);

if ($totalFee <= 0) {
    fwrite(STDERR, "No fee_structure row for {$programCode} year {$yearOfStudy} period {$periodNumber}.\n");
    exit(1);
}

$amountDue = round($totalFee * ($requiredPct / 100), 2);
$receiptNo = 'TEST-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

echo ($apply ? 'APPLY' : 'DRY-RUN') . ": seed payment for {$studentId}\n";
echo "Program: {$programCode}, year of study: {$yearOfStudy}, period: {$periodNumber}, academic year: {$academicYear}\n";
echo "Required fee: {$totalFee}, threshold: {$requiredPct}%, payment to seed: {$amountDue}\n";

$existingPaid = 0.0;
if ($stmt = $db->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS p FROM payments
      WHERE student_id = ? AND academic_year = ? AND semester = ?
        AND LOWER(status) IN ('posted', 'completed', 'confirmed', 'paid', 'success')"
)) {
    $stmt->bind_param('ssi', $studentId, $academicYear, $periodNumber);
    $stmt->execute();
    $existingPaid = (float)($stmt->get_result()->fetch_assoc()['p'] ?? 0);
    $stmt->close();
}
echo 'Existing payments for this period: ' . number_format($existingPaid, 2) . "\n";

$toInsert = max(0.0, $amountDue - $existingPaid);
if ($toInsert <= 0) {
    echo "Payment threshold already met for this period.\n";
} else {
    echo "Will insert payment: {$toInsert} (receipt {$receiptNo})\n";
    if ($apply) {
        $desc = "Test seed payment — Term {$periodNumber} registration ({$requiredPct}% threshold)";
        $method = 'cash';
        $postedBy = 'seed_script';
        $stmt = $db->prepare(
            'INSERT INTO payments (student_id, receipt_no, amount, method, payment_date, status, description, posted_by, academic_year, semester)
             VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            fwrite(STDERR, "Prepare failed: {$db->error}\n");
            exit(1);
        }
        $status = 'completed';
        $stmt->bind_param(
            'ssdsssssi',
            $studentId,
            $receiptNo,
            $toInsert,
            $method,
            $status,
            $desc,
            $postedBy,
            $academicYear,
            $periodNumber
        );
        if (!$stmt->execute()) {
            fwrite(STDERR, "Insert failed: {$stmt->error}\n");
            $stmt->close();
            exit(1);
        }
        $stmt->close();
        echo "Payment recorded.\n";
    }
}

if (wuc_table_exists($db, 'student_fee_accounts')) {
    echo "\nstudent_fee_accounts: sync active fee account.\n";
    $accountRow = null;
    if ($stmt = $db->prepare(
        "SELECT id, total_payable, amount_paid FROM student_fee_accounts
          WHERE student_id = ? AND (status = 'active' OR status = '' OR status IS NULL)
          ORDER BY id DESC LIMIT 1"
    )) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $accountRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $accountTotal = max($amountDue, (float)($accountRow['total_payable'] ?? 0));
    if ($accountTotal <= 0) {
        $accountTotal = $amountDue;
    }

    if ($apply) {
        if ($accountRow) {
            $upd = $db->prepare(
                'UPDATE student_fee_accounts
                    SET total_payable = ?, amount_paid = ?, balance = 0,
                        payment_status = ?, status = ?, updated_at = NOW()
                  WHERE id = ?'
            );
            if ($upd) {
                $paymentStatus = 'paid';
                $status = 'active';
                $upd->bind_param('ddssi', $accountTotal, $accountTotal, $paymentStatus, $status, $accountRow['id']);
                $upd->execute();
                echo 'Updated student_fee_accounts id=' . $accountRow['id'] . "\n";
                $upd->close();
            }
        } else {
            $ins = $db->prepare(
                'INSERT INTO student_fee_accounts
                    (student_id, academic_year, total_payable, amount_paid, balance, payment_status, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, NOW(), NOW())'
            );
            if ($ins) {
                $paymentStatus = 'paid';
                $status = 'active';
                $ins->bind_param('ssddss', $studentId, $academicYear, $accountTotal, $accountTotal, $paymentStatus, $status);
                $ins->execute();
                echo "Created student_fee_accounts row.\n";
                $ins->close();
            }
        }
    } else {
        echo "Would set student_fee_accounts paid total to {$accountTotal}.\n";
    }
}

if ($resetPeriodReg) {
    echo "\nReset period registration for active period {$periodNumber}...\n";
    if ($apply && wuc_table_exists($db, 'semester_registration')) {
        $periodType = wuc_legacy_period_type($calendarType);
        $yearText = (string)$yearOfStudy;
        $periodText = (string)$periodNumber;
        $stmt = $db->prepare(
            'DELETE FROM semester_registration
              WHERE student_id = ?
                AND program_code = ?
                AND semester = ?
                AND period_type = ?
                AND year_of_study = ?
                AND academic_year = ?'
        );
        if ($stmt) {
            $stmt->bind_param('ssssss', $studentId, $programCode, $periodText, $periodType, $yearText, $academicYear);
            $stmt->execute();
            echo 'Removed semester_registration rows: ' . $stmt->affected_rows . "\n";
            $stmt->close();
        }
    } elseif (!$apply) {
        echo "Would remove semester_registration for this period (keeps course_registration year enrolments).\n";
    }
}

if ($apply) {
    $fee = $workflow->checkFeeEligibility($studentId, $period);
    echo "\nFee check: " . ($fee['is_eligible'] ? 'ELIGIBLE' : 'NOT ELIGIBLE') . "\n";
    echo 'Paid: ' . number_format((float)$fee['amount_paid'], 2)
        . ' / ' . number_format((float)$fee['total_fee'], 2)
        . ' (' . $fee['payment_percentage'] . "%, required {$fee['required_percentage']}%)\n";
    echo ($fee['reason'] ?? '') . "\n";

    $reg = $workflow->checkStudentRegistration($studentId, $period);
    echo 'Period registration: ' . ($reg['is_registered'] ? 'yes (id ' . $reg['registration_id'] . ')' : 'no — ready for UI registration') . "\n";
}

if (!$apply) {
    echo "\nRe-run with --apply to record payment.\n";
    echo "Add --reset-period-registration to clear dev-backfill so registration.php can run fresh.\n";
}
