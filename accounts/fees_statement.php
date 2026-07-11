<?php
require_once __DIR__ . '/../includes/portal_config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/session_guard.php';

wuc_apply_security_headers(true);

if (session_status() === PHP_SESSION_NONE) {
    wuc_configure_session_cookie();
    session_start();
}

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/fees_helpers.php';
require_once __DIR__ . '/../students/includes/student_fee_records.php';

/**
 * Render a lightweight error page for statement access problems.
 */
function fees_statement_render_error(string $title, string $message, bool $isStudent): void
{
    $base = WUC_APP_BASE_PATH;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Fee Statement - <?= htmlspecialchars($title) ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>body { font-family: 'Inter', sans-serif; }</style>
    </head>
    <body class="bg-light py-5">
        <div class="container text-center" style="max-width: 640px;">
            <div class="card p-5 border-0 shadow rounded-4">
                <h3 class="text-danger fw-bold mb-3"><?= htmlspecialchars($title) ?></h3>
                <p class="text-muted mb-0"><?= $message ?></p>
                <?php if ($isStudent): ?>
                    <a href="<?= htmlspecialchars($base) ?>/students/index.php" class="btn btn-primary rounded-pill px-4 mt-4">Back to Student Panel</a>
                <?php else: ?>
                    <a href="fees_student_accounts.php" class="btn btn-secondary rounded-pill px-4 mt-4">Back to Fee Accounts</a>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Load a student fee account with joined course/student details.
 */
function fees_statement_fetch_account(mysqli $db, string $studentId, int $courseFilter = 0, ?int $accountId = null): ?array
{
    $select = "SELECT sfa.*, c.course_name, c.course_code, tm.mode_name, d.department_name, s.Fname, s.Lname
               FROM student_fee_accounts sfa
               INNER JOIN courses c ON sfa.course_id = c.id
               INNER JOIN training_modes tm ON sfa.training_mode_id = tm.id
               INNER JOIN students s ON sfa.student_id = s.SID
               LEFT JOIN departments d ON c.department_id = d.id";

    if ($accountId !== null && $accountId > 0) {
        $stmt = $db->prepare($select . " WHERE sfa.id = ? AND sfa.status = 'active' LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $accountId);
    } elseif ($courseFilter > 0) {
        $stmt = $db->prepare($select . " WHERE sfa.student_id = ? AND sfa.course_id = ? AND sfa.status = 'active' LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('si', $studentId, $courseFilter);
    } else {
        $stmt = $db->prepare($select . " WHERE sfa.student_id = ? AND sfa.status = 'active' ORDER BY sfa.id DESC LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $studentId);
    }

    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $account;
}

// Auth: student (own statement) or finance staff (any student via student_id param).
$studentId = '';
$isStudent = false;
$basePath = WUC_APP_BASE_PATH;

if (!empty($_SESSION['Sid'])) {
    $sessionSid = trim((string)$_SESSION['Sid']);
    if (!preg_match('/^[A-Za-z0-9\/\-_]+$/', $sessionSid)) {
        wuc_guard_clear_session();
        wuc_safe_redirect($basePath . '/student_login.php');
    }
    $studentId = $sessionSid;
    $isStudent = true;
} elseif (!empty($_SESSION['user_id']) || !empty($_SESSION['staff_id'])) {
    if (!isset($_SESSION['user_id']) && isset($_SESSION['staff_id'])) {
        $_SESSION['user_id'] = $_SESSION['staff_id'];
    }

    require_once __DIR__ . '/../includes/staff_role_helpers.php';
    require_once __DIR__ . '/../includes/role_helpers.php';

    $staffId = trim((string)($_SESSION['user_id'] ?? ''));
    if ($staffId !== '') {
        wuc_hydrate_staff_roles($db, $staffId);
    }

    $staffAllowed = (function_exists('canAccessFinance') && canAccessFinance())
        || (function_exists('isSystemsAdmin') && isSystemsAdmin())
        || hasAnyRole([ROLE_ACCOUNTANT, ROLE_SYSTEMS_ADMIN]);

    if (!$staffAllowed) {
        $_SESSION['errorMessage'] = 'Access denied. Your role does not include access to fee statements.';
        wuc_safe_redirect($basePath . '/portal_selection.php');
    }

    $isStudent = false;
    $studentId = trim((string)($_GET['student_id'] ?? ''));
} else {
    wuc_safe_redirect($basePath . '/index.php');
}

if ($studentId === '') {
    fees_statement_render_error(
        'Student ID Required',
        'A student ID is required to generate a fee statement. Return to Fee Accounts and open a statement from a student account row.',
        $isStudent
    );
}

if (!preg_match('/^[A-Za-z0-9_.@\/\-]{3,50}$/', $studentId)) {
    fees_statement_render_error(
        'Invalid Student ID',
        'The supplied student ID format is not valid.',
        $isStudent
    );
}

$courseFilter = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$account = fees_statement_fetch_account($db, $studentId, $courseFilter);

if (!$account) {
    $stuStmt = $db->prepare("SELECT program, mode, academic_year, intake FROM students WHERE SID = ? LIMIT 1");
    if ($stuStmt) {
        $stuStmt->bind_param('s', $studentId);
        $stuStmt->execute();
        $stuRes = $stuStmt->get_result();
        if ($stuRow = $stuRes->fetch_assoc()) {
            $progCode = trim($stuRow['program'] ?? '');
            $modeName = trim($stuRow['mode'] ?? '');
            $academicYear = trim($stuRow['academic_year'] ?? '2026');
            $intakeName = trim($stuRow['intake'] ?? 'January');

            if ($progCode !== '' && $modeName !== '') {
                $courseId = 0;
                if ($cStmt = $db->prepare("SELECT id FROM courses WHERE course_code = ? LIMIT 1")) {
                    $cStmt->bind_param('s', $progCode);
                    $cStmt->execute();
                    $cRes = $cStmt->get_result();
                    if ($cRow = $cRes->fetch_assoc()) {
                        $courseId = (int)$cRow['id'];
                    }
                    $cStmt->close();
                }

                $modeId = 0;
                if ($mStmt = $db->prepare("SELECT id FROM training_modes WHERE LOWER(mode_name) = LOWER(?) LIMIT 1")) {
                    $mStmt->bind_param('s', $modeName);
                    $mStmt->execute();
                    $mRes = $mStmt->get_result();
                    if ($mRow = $mRes->fetch_assoc()) {
                        $modeId = (int)$mRow['id'];
                    }
                    $mStmt->close();
                }

                if ($modeId === 0) {
                    $modeFallback = $db->query("SELECT id FROM training_modes LIMIT 1");
                    if ($modeFallback && $modeFallback->num_rows > 0) {
                        $modeId = (int)$modeFallback->fetch_assoc()['id'];
                    }
                }

                if ($courseId > 0 && $modeId > 0) {
                    $newAccId = fees_generate_student_account($db, $studentId, $courseId, $modeId, $academicYear, $intakeName);
                    if ($newAccId) {
                        $account = fees_statement_fetch_account($db, $studentId, 0, $newAccId);
                    }
                }
            }
        }
        $stuStmt->close();
    }
}

if (!$account) {
    fees_statement_render_error(
        'Fee Account Not Found',
        'We could not locate an active fee account for Student ID: <strong>' . htmlspecialchars($studentId) . '</strong>.',
        $isStudent
    );
}

// Keep balances in sync with the payments ledger before rendering.
fees_recalculate_student_balance($db, (int)$account['id']);
$account = fees_statement_fetch_account($db, $studentId, $courseFilter, (int)$account['id']) ?? $account;

$breakdown = fees_statement_build_breakdown($db, $account);

$displayBursary = (float)$breakdown['bursary'];
$displayTotalPayable = (float)$breakdown['total_payable'];
$institutionalLines = $breakdown['institutional_lines'];
$statementPeriodLabel = (string)($breakdown['period_label_full'] ?? '');
$statementProgramCode = (string)($breakdown['program_code'] ?? '');
$displayAmountPaid = (float)$account['amount_paid'];
$displayBalance = (float)$account['balance'];
$displayOutstanding = max(0.0, $displayBalance);
$paymentPct = $displayTotalPayable > 0
    ? min(100.0, round(($displayAmountPaid / $displayTotalPayable) * 100, 1))
    : ($displayAmountPaid > 0 ? 100.0 : 0.0);
$isFullyPaid = $displayTotalPayable > 0 && $displayOutstanding <= 0.01;
$isPartiallyPaid = !$isFullyPaid && $displayAmountPaid > 0.01;

$paymentSummary = fees_sum_completed_payments_for_account($db, (int)$account['id']);
$payments = $paymentSummary['records'];

// Online (DPO Pay) gateway payment history for this student — merged from the
// former students/payments/index.php page so fees + online payments live on
// one statement. students/payments/index.php now redirects here.
require_once __DIR__ . '/../includes/payment_helpers.php';
$gatewayTransactions = [];
if (payment_ensure_gateway_transactions_table($db)) {
    if ($gtStmt = $db->prepare("SELECT * FROM payment_gateway_transactions WHERE student_id = ? ORDER BY id DESC LIMIT 50")) {
        $gtSid = (string)$account['student_id'];
        $gtStmt->bind_param('s', $gtSid);
        $gtStmt->execute();
        $gatewayTransactions = $gtStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $gtStmt->close();
    }
}
// Flash from the hosted-checkout return redirect (?payment_status=...).
$paymentFlashStatus = strtolower(trim((string)($_GET['payment_status'] ?? '')));
$paymentFlashMessage = trim((string)($_GET['payment_message'] ?? ''));

function fees_statement_gateway_badge(string $status): array
{
    switch (strtolower($status)) {
        case 'completed':
            return ['success', 'fa-check-circle', 'Completed'];
        case 'pending':
        case 'token_created':
            return ['warning text-dark', 'fa-clock', 'Awaiting confirmation'];
        case 'cancelled':
            return ['secondary', 'fa-ban', 'Cancelled'];
        case 'pending_verification':
            return ['info text-dark', 'fa-hourglass-half', 'Pending verification'];
        default:
            return ['danger', 'fa-times-circle', 'Failed'];
    }
}

function fees_statement_gateway_type(string $type): string
{
    switch ($type) {
        case 'course_registration':
            return 'Course Registration';
        case 'fee_payment':
            return 'Fees Payment';
        case 'exam_fee':
            return 'Exam Fee';
        default:
            return 'Other';
    }
}

// All of this student's active fee accounts (one per course). With more than
// one, the page renders course tabs that switch statements via ?course_id=.
$accountOptions = [];
$optStmt = $db->prepare("SELECT sfa.course_id, c.course_code, c.course_name
                           FROM student_fee_accounts sfa
                          INNER JOIN courses c ON c.id = sfa.course_id
                          WHERE sfa.student_id = ? AND sfa.status = 'active'
                          ORDER BY sfa.id DESC");
if ($optStmt) {
    $optAccountStudent = (string)$account['student_id'];
    $optStmt->bind_param('s', $optAccountStudent);
    $optStmt->execute();
    $accountOptions = $optStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $optStmt->close();
}
$statementTabBase = $basePath . '/accounts/fees_statement.php?'
    . ($isStudent ? '' : 'student_id=' . urlencode((string)$account['student_id']) . '&')
    . 'course_id=';
?>
<?php if ($isStudent): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Statement - ITC</title>
    <link rel="icon" href="<?= htmlspecialchars($basePath) ?>/images/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($basePath) ?>/images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= htmlspecialchars($basePath) ?>/images/favicon-16.png">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($basePath) ?>/images/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>/css/portal-dashboard.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>/students/css/dashboard.css">
</head>
<body class="bg-light student-dashboard-page no-auto-print single-page-document">

<?php require dirname(__DIR__) . '/students/includes/navbar.php'; ?>

<main class="dash-content content-wrapper portal-dashboard pt-3">
<?php else:
    $page_title = 'Fee Statement - ' . (string)$account['student_id'];
    require __DIR__ . '/includes/nav.php';
?>
<div class="container-fluid py-4 px-4">
<script>document.body.classList.add('no-auto-print', 'single-page-document');</script>
<?php endif; ?>

    <style>
        .statement-card { border: none; box-shadow: 0 8px 40px rgba(80,60,180,0.10); border-radius: 16px; }
        .invoice-title { font-size: 2rem; font-weight: 700; color: #6f42c1; text-align: center; }
        .text-purple { color: #6f42c1; }
        .table-totals th { font-weight: 700; }
        .statement-course-tabs .nav-link { color: #5a32a3; border: 1px solid #e4defc; border-radius: 999px; padding: .35rem 1rem; font-weight: 600; }
        .statement-course-tabs .nav-link.active { background: linear-gradient(135deg, #6f42c1, #5a32a3); border-color: #6f42c1; color: #fff; }
        .fee-progress-wrap { background: #f3effc; border-radius: 12px; padding: 1rem 1.25rem; }
        .fee-progress-bar { height: 10px; border-radius: 999px; background: #e4defc; overflow: hidden; }
        .fee-progress-bar .fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #6f42c1, #5a32a3); transition: width .3s ease; }
        .fee-status-pill { border-radius: 999px; padding: .35rem .85rem; font-size: .8rem; font-weight: 600; }
        @media print {
            .no-print { display: none !important; }
            .statement-card { box-shadow: none !important; border: none !important; }
        }
    </style>

    <?php if ($paymentFlashMessage !== ''):
        $flashClass = $paymentFlashStatus === 'success' ? 'success' : ($paymentFlashStatus === 'cancelled' ? 'secondary' : ($paymentFlashStatus === 'pending' ? 'warning' : 'danger'));
    ?>
        <div class="alert alert-<?= htmlspecialchars($flashClass) ?> no-print">
            <i class="fas <?= $flashClass === 'success' ? 'fa-check-circle' : ($flashClass === 'warning' ? 'fa-clock' : 'fa-exclamation-triangle') ?> me-2"></i>
            <?= htmlspecialchars($paymentFlashMessage) ?>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <div>
            <h1 class="h4 fw-bold mb-1"><i class="fas fa-file-invoice-dollar text-purple me-2"></i>Fee Statement</h1>
            <div class="text-muted small"><?= htmlspecialchars(trim($account['Fname'] . ' ' . $account['Lname'])) ?> &middot; <?= htmlspecialchars($account['student_id']) ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($isStudent): ?>
                <a href="<?= htmlspecialchars($basePath) ?>/students/index.php" class="btn btn-outline-secondary rounded-pill px-4"><i class="fas fa-arrow-left me-2"></i>Dashboard</a>
                <?php if ($displayOutstanding > 0.01): ?>
                    <a href="#online-payments" class="btn text-white rounded-pill px-4" style="background: linear-gradient(135deg, #6f42c1, #5a32a3);"><i class="fas fa-credit-card me-2"></i>Pay Online</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="fees_student_accounts.php" class="btn btn-outline-secondary rounded-pill px-4"><i class="fas fa-arrow-left me-2"></i>Back to Accounts</a>
            <?php endif; ?>
            <button type="button" onclick="wucPrintSinglePage()" class="btn btn-outline-primary rounded-pill px-4"><i class="fas fa-print me-2"></i>Print</button>
        </div>
    </div>

    <div class="fee-progress-wrap no-print mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div>
                <strong class="text-purple">Payment progress</strong>
                <span class="text-muted ms-2"><?= htmlspecialchars($account['course_code']) ?> · <?= htmlspecialchars($account['academic_year']) ?></span>
            </div>
            <span class="fee-status-pill bg-<?= $isFullyPaid ? 'success' : ($isPartiallyPaid ? 'warning text-dark' : 'secondary') ?>">
                <?= htmlspecialchars((string)$account['payment_status']) ?>
            </span>
        </div>
        <div class="fee-progress-bar mb-2" role="progressbar" aria-valuenow="<?= htmlspecialchars((string)$paymentPct) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Fee payment progress">
            <div class="fill" style="width: <?= htmlspecialchars((string)$paymentPct) ?>%;"></div>
        </div>
        <div class="d-flex flex-wrap justify-content-between small text-muted">
            <span><i class="fas fa-money-bill-wave me-1"></i>Paid ZMW <?= number_format($displayAmountPaid, 2) ?></span>
            <span><?= number_format($paymentPct, 1) ?>% of ZMW <?= number_format($displayTotalPayable, 2) ?></span>
            <span><i class="fas fa-scale-balanced me-1"></i><?= $isFullyPaid ? 'No outstanding balance' : 'Outstanding ZMW ' . number_format($displayOutstanding, 2) ?></span>
        </div>
    </div>

    <section class="stats-grid no-print mb-3" aria-label="Fee summary">
        <article class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-file-invoice-dollar"></i></div>
            <div>
                <div class="stat-label">Total Payable</div>
                <div class="stat-value">ZMW <?= number_format($displayTotalPayable, 2) ?></div>
                <div class="stat-sub"><?= htmlspecialchars($account['mode_name']) ?> · <?= htmlspecialchars($account['intake']) ?></div>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-icon green"><i class="fas fa-money-bill-wave"></i></div>
            <div>
                <div class="stat-label">Total Paid</div>
                <div class="stat-value green">ZMW <?= number_format($displayAmountPaid, 2) ?></div>
                <div class="stat-sub"><?= count($payments) ?> recorded payment<?= count($payments) === 1 ? '' : 's' ?></div>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-icon <?= $isFullyPaid ? 'green' : 'red' ?>"><i class="fas fa-scale-balanced"></i></div>
            <div>
                <div class="stat-label">Outstanding Balance</div>
                <div class="stat-value <?= $isFullyPaid ? 'green' : 'red' ?>">ZMW <?= number_format($displayOutstanding, 2) ?></div>
                <div class="stat-sub"><?= $isFullyPaid ? 'Fully settled' : ($isPartiallyPaid ? 'Partial payment received' : 'Payment due') ?></div>
            </div>
        </article>
    </section>

    <?php if (count($accountOptions) > 1): ?>
    <nav aria-label="Statement by course" class="no-print mb-3">
        <ul class="nav statement-course-tabs gap-2">
            <?php foreach ($accountOptions as $opt): ?>
                <li class="nav-item">
                    <a class="nav-link <?= (int)$opt['course_id'] === (int)$account['course_id'] ? 'active' : '' ?>"
                       href="<?= htmlspecialchars($statementTabBase . (int)$opt['course_id']) ?>">
                        <i class="fas fa-book me-1"></i><?= htmlspecialchars($opt['course_code']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <?php endif; ?>

    <div class="card statement-card p-5 wuc-a4-sheet">
        <div class="text-center mb-5 pb-4 border-bottom">
            <span class="wuc-logo-frame d-block mb-3">
                <img src="<?= htmlspecialchars($basePath) ?>/images/itc_logo.png" alt="ITC Logo" class="wuc-logo-img report-logo">
            </span>
            <h4 class="fw-bold mb-0">Industrial Training Centre</h4>
            <small class="text-muted d-block mb-3">Student Fees Statement Office</small>
            <div class="invoice-title text-center">FEE STATEMENT</div>
            <div>Date Generated: <?= date('d M Y') ?></div>
            <div class="font-monospace text-purple fw-bold">Ref: FST-<?= (int)$account['id'] ?>-<?= date('Ymd') ?></div>
        </div>

        <hr class="mb-5">

        <div class="row mb-5">
            <div class="col-md-6">
                <h6 class="text-muted text-uppercase fw-bold mb-3">Student Details</h6>
                <table class="table table-sm table-borderless">
                    <tr><th class="ps-0" style="width: 150px;">Student ID</th><td>: <strong><?= htmlspecialchars($account['student_id']) ?></strong></td></tr>
                    <tr><th class="ps-0">Full Name</th><td>: <?= htmlspecialchars(trim($account['Fname'] . ' ' . $account['Lname'])) ?></td></tr>
                    <tr><th class="ps-0">Academic Year</th><td>: <?= htmlspecialchars($account['academic_year']) ?></td></tr>
                    <tr><th class="ps-0">Billing Period</th><td>: <?= $statementPeriodLabel !== '' ? htmlspecialchars($statementPeriodLabel) : htmlspecialchars($account['intake']) ?></td></tr>
                    <?php if ($statementProgramCode !== ''): ?>
                    <tr><th class="ps-0">Programme</th><td>: <?= htmlspecialchars($statementProgramCode) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted text-uppercase fw-bold mb-3">Enrolment Course Details</h6>
                <table class="table table-sm table-borderless">
                    <tr><th class="ps-0" style="width: 150px;">Course Name</th><td>: <strong><?= htmlspecialchars($account['course_name']) ?></strong> (<?= htmlspecialchars($account['course_code']) ?>)</td></tr>
                    <tr><th class="ps-0">Department</th><td>: <?= htmlspecialchars($account['department_name'] ?: 'Unassigned') ?></td></tr>
                    <tr><th class="ps-0">Training Mode</th><td>: <?= htmlspecialchars($account['mode_name']) ?></td></tr>
                    <tr><th class="ps-0">Payment Status</th><td>: <span class="badge bg-purple px-3 py-1" style="background-color:#6f42c1;"><?= htmlspecialchars($account['payment_status']) ?></span></td></tr>
                </table>
            </div>
        </div>

        <div class="mb-5">
            <h6 class="text-muted text-uppercase fw-bold mb-3">Fees Structure Breakdown</h6>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Fee Description</th>
                            <th>Category</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($institutionalLines === []): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted py-3">No fee lines configured for this account.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($institutionalLines as $line): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars((string)$line['name']) ?></td>
                                <td><?= htmlspecialchars((string)($line['category'] ?? 'Institutional Fee')) ?></td>
                                <td class="text-end font-monospace fw-semibold">ZMW <?= number_format((float)$line['amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if ($displayBursary > 0): ?>
                        <tr>
                            <td class="fw-bold text-success">Less Bursary / Sponsorship (<?= htmlspecialchars((string)($account['sponsor'] ?? 'Sponsor')) ?>)</td>
                            <td class="text-success fw-semibold">Sponsorship Discount</td>
                            <td class="text-end font-monospace text-success fw-semibold">-ZMW <?= number_format($displayBursary, 2) ?></td>
                        </tr>
                        <?php endif; ?>

                        <tr class="table-light">
                            <td colspan="2" class="fw-bold text-purple text-uppercase">Total Institutional Fees Payable</td>
                            <td class="text-end font-monospace fw-bold text-purple">ZMW <?= number_format($displayTotalPayable, 2) ?></td>
                        </tr>

                        <?php if (!empty($breakdown['external_fees'])): ?>
                            <tr><td colspan="3" class="table-light py-2 text-muted fw-bold">External Third-Party Payments (Collected Externally or for Guidance)</td></tr>
                            <?php foreach ($breakdown['external_fees'] as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td>External Payment (e.g. RTSA/Agency)</td>
                                    <td class="text-end font-monospace text-warning">ZMW <?= number_format((float)$item['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($breakdown['informational_fees'])): ?>
                            <tr><td colspan="3" class="table-light py-2 text-muted fw-bold">Informational Fees Only (Not added to Payable)</td></tr>
                            <?php foreach ($breakdown['informational_fees'] as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td>Informational Only</td>
                                    <td class="text-end font-monospace text-info">ZMW <?= number_format((float)$item['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mb-5">
            <h6 class="text-muted text-uppercase fw-bold mb-3">Recent Payments Ledger</h6>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Payment Date</th>
                            <th>Reference</th>
                            <th>Method</th>
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4">
                                    <div class="empty-state py-2">
                                        <i class="fas fa-receipt fa-2x mb-2 d-block text-muted"></i>
                                        <p class="mb-0 text-muted">No payments recorded yet for this account.</p>
                                        <?php if ($isStudent && $displayOutstanding > 0.01): ?>
                                            <a href="#online-payments" class="btn btn-sm btn-primary rounded-pill mt-3"><i class="fas fa-credit-card me-1"></i>Pay online below</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payments as $p):
                                $pDate = (string)($p['payment_date'] ?? '');
                                $pRef = trim((string)($p['reference_number'] ?? ''));
                                $pChannel = trim((string)($p['channel'] ?? ''));
                                $pNote = trim((string)($p['narration'] ?? ($p['description'] ?? '')));
                                $pAmount = (float)($p['amount_paid'] ?? 0);
                                $pSource = (string)($p['_source'] ?? 'ledger');
                            ?>
                                <tr>
                                    <td><?= $pDate !== '' ? htmlspecialchars(date('d M Y', strtotime($pDate))) : '&mdash;' ?></td>
                                    <td>
                                        <?php if ($pRef !== ''): ?>
                                            <span class="badge bg-light text-dark font-monospace"><?= htmlspecialchars($pRef) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $pChannel !== '' ? htmlspecialchars(ucfirst($pChannel)) : '—' ?></td>
                                    <td><small class="text-muted"><?= $pNote !== '' ? htmlspecialchars($pNote) : 'Fee payment' ?></small></td>
                                    <td class="text-end font-monospace fw-semibold text-success">ZMW <?= number_format($pAmount, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row justify-content-end mb-4">
            <div class="col-md-5">
                <div class="card border-0 bg-light rounded-4">
                    <div class="card-body p-4">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <th class="text-muted">Total Payable:</th>
                                <td class="text-end font-monospace fw-semibold">ZMW <?= number_format($displayTotalPayable, 2) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Total Paid:</th>
                                <td class="text-end font-monospace text-success fw-semibold">ZMW <?= number_format($displayAmountPaid, 2) ?></td>
                            </tr>
                            <tr class="border-top">
                                <th class="text-purple fs-5">Outstanding Balance:</th>
                                <td class="text-end font-monospace fw-bold fs-5 <?= $isFullyPaid ? 'text-success' : 'text-danger' ?>">ZMW <?= number_format($displayOutstanding, 2) ?></td>
                            </tr>
                            <?php if ($displayBalance < -0.01): ?>
                            <tr>
                                <th class="text-muted">Credit balance:</th>
                                <td class="text-end font-monospace text-success fw-semibold">ZMW <?= number_format(abs($displayBalance), 2) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center text-muted mt-5 pt-5 border-top" style="font-size: 0.85rem;">
            <p class="mb-0">This is an official computer-generated fee statement from the ITC Student Finance Office.</p>
            <p>For any queries, please visit the accountant desk or contact support@itc.edu.zm.</p>
        </div>
    </div>

    <div class="card statement-card mt-4 no-print" id="online-payments">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h6 class="text-muted text-uppercase fw-bold mb-1"><i class="fas fa-credit-card text-purple me-2"></i>Online Payments (DPO Pay)</h6>
                    <small class="text-muted">Card and mobile-money payments made through the secure hosted checkout. Payments are verified with the gateway before they are posted to the ledger above.</small>
                </div>
            </div>
            <?php if (empty($gatewayTransactions)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-credit-card fa-2x mb-2 d-block"></i>
                    No online payments yet<?= $isStudent ? ' — use the Fees page to pay an invoice online, or pay during course registration.' : ' for this student.' ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Reference</th>
                                <th>Type</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                                <th>Receipt</th>
                                <?php if ($isStudent): ?><th class="text-end">Action</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gatewayTransactions as $tx):
                                [$gtBadge, $gtIcon, $gtLabel] = fees_statement_gateway_badge((string)$tx['status']);
                                $gtType = (string)($tx['payment_type'] ?? 'fee_payment');
                                $gtInvoice = (string)($tx['invoice_number'] ?? '');
                                $gtRetryable = in_array(strtolower((string)$tx['status']), ['failed', 'cancelled', 'pending', 'token_created'], true);
                            ?>
                                <tr>
                                    <td class="text-muted"><small><?= htmlspecialchars((string)$tx['created_at']) ?></small></td>
                                    <td><span class="badge bg-light border text-primary font-monospace text-wrap" style="max-width:170px;"><?= htmlspecialchars((string)$tx['reference_number']) ?></span></td>
                                    <td><small class="fw-medium"><?= htmlspecialchars(fees_statement_gateway_type($gtType)) ?></small></td>
                                    <td class="text-end fw-bold"><?= htmlspecialchars((string)$tx['currency']) ?> <?= number_format((float)$tx['amount'], 2) ?></td>
                                    <td><span class="badge bg-<?= $gtBadge ?> rounded-pill"><i class="fas <?= $gtIcon ?> me-1"></i><?= $gtLabel ?></span></td>
                                    <td>
                                        <?php if (!empty($tx['receipt_no'])): ?>
                                            <small class="font-monospace"><?= htmlspecialchars((string)$tx['receipt_no']) ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">&mdash;</small>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($isStudent): ?>
                                        <td class="text-end">
                                            <?php if ($gtRetryable && $gtType === 'fee_payment' && $gtInvoice !== ''): ?>
                                                <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($basePath) ?>/students/payment.php?invoice=<?= urlencode($gtInvoice) ?>">
                                                    <i class="fas fa-redo me-1"></i>Retry
                                                </a>
                                            <?php elseif ($gtRetryable && $gtType === 'course_registration'): ?>
                                                <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($basePath) ?>/students/courseReg.php">
                                                    <i class="fas fa-redo me-1"></i>Resume
                                                </a>
                                            <?php elseif (strtolower((string)$tx['status']) === 'completed' && $gtInvoice !== ''): ?>
                                                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($basePath) ?>/students/printReceipt.php?invoice=<?= urlencode($gtInvoice) ?>" target="_blank">
                                                    <i class="fas fa-print me-1"></i>Receipt
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php if ($isStudent): ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php endif; ?>
