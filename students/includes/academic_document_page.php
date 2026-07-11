<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/StudentAcademicWorkflowService.php';
require_once __DIR__ . '/StudentDataService.php';
require_once dirname(__DIR__, 2) . '/includes/page_meta.php';

$studentId = (string)($_SESSION['Sid'] ?? '');
$workflow = new StudentAcademicWorkflowService($db);
$studentSvc = new StudentDataService($db);
$student = $studentSvc->getStudentWithProgram($studentId);

$docKind = ($docKind ?? 'docket') === 'exam_slip' ? 'exam_slip' : 'docket';
$docTitle = $docKind === 'docket' ? 'Test Docket' : 'Exam Slip';
$eligibility = $docKind === 'docket'
    ? $workflow->getDocketEligibility($studentId)
    : $workflow->getExamSlipEligibility($studentId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars(wuc_portal_title($docTitle), ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
</head>
<body class="bg-light">
<?php require_once __DIR__ . '/navbar.php'; ?>
<main class="content-wrapper portal-dashboard pt-3">
<div class="container-fluid px-4">
    <div class="page-header mb-4 mt-2">
        <h5 class="page-title mb-0"><i class="fas fa-file-alt me-2 text-primary"></i><?= htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') ?></h5>
        <p class="page-subtitle mb-0">Academic document for your active registration period.</p>
    </div>

    <?php if (!$eligibility['available']): ?>
    <div class="alert alert-warning">
        <strong><?= htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') ?>: Not Available</strong>
        <div class="mt-1"><?= htmlspecialchars((string)$eligibility['reason'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="mt-3 d-flex flex-wrap gap-2">
            <a href="registration.php" class="btn btn-outline-primary btn-sm">Registration</a>
            <a href="fees.php" class="btn btn-outline-primary btn-sm">Fees</a>
        </div>
    </div>
    <?php else:
        $period = $eligibility['period'] ?? [];
        $registration = $eligibility['registration'] ?? [];
        $fee = $eligibility['fee'] ?? [];
        $courses = $registration['registered_courses'] ?? [];
        $fullName = trim((string)($student['full_name'] ?? ($student['Fname'] ?? '') . ' ' . ($student['Lname'] ?? '')));
        $programName = (string)($student['program_name'] ?? $period['program_code'] ?? '');
        $periodLabel = (string)($registration['period_label'] ?? '');
    ?>
    <div class="card shadow-sm mb-4 printable-document" id="academicDocument">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h4 class="mb-1"><?= htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') ?></h4>
                    <p class="text-muted mb-0">Generated <?= htmlspecialchars(date('d M Y H:i'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm d-print-none" onclick="window.print()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
            </div>
            <dl class="row mb-4">
                <dt class="col-sm-3">Student ID</dt>
                <dd class="col-sm-9"><?= htmlspecialchars($studentId, ENT_QUOTES, 'UTF-8') ?></dd>
                <dt class="col-sm-3">Name</dt>
                <dd class="col-sm-9"><?= htmlspecialchars($fullName !== '' ? $fullName : $studentId, ENT_QUOTES, 'UTF-8') ?></dd>
                <dt class="col-sm-3">Programme</dt>
                <dd class="col-sm-9"><?= htmlspecialchars($programName, ENT_QUOTES, 'UTF-8') ?></dd>
                <dt class="col-sm-3">Period</dt>
                <dd class="col-sm-9"><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
            <h6 class="fw-bold">Registered Courses</h6>
            <div class="table-responsive mb-4">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr><th>Code</th><th>Course</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $course): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($course['course_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($course['course_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($fee): ?>
            <h6 class="fw-bold">Fee Summary</h6>
            <ul class="list-unstyled mb-0">
                <li>Total Fee: ZMW <?= htmlspecialchars(number_format((float)($fee['total_fee'] ?? 0), 2), ENT_QUOTES, 'UTF-8') ?></li>
                <li>Amount Paid: ZMW <?= htmlspecialchars(number_format((float)($fee['amount_paid'] ?? 0), 2), ENT_QUOTES, 'UTF-8') ?></li>
                <li>Payment: <?= htmlspecialchars(number_format((float)($fee['payment_percentage'] ?? 0), 1), ENT_QUOTES, 'UTF-8') ?>% (required <?= htmlspecialchars(number_format((float)($fee['required_percentage'] ?? 0), 0), ENT_QUOTES, 'UTF-8') ?>%)</li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
</main>
<style>@media print { .sidebar, .sidebar-toggle, .sidebar-backdrop, .d-print-none { display: none !important; } }</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
