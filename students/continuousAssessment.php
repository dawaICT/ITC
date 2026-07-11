<?php
declare(strict_types=1);

$page_title = 'Continuous Assessment';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/period_mode_helper.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';
require_once __DIR__ . '/includes/AcademicSessionService.php';
require_once __DIR__ . '/includes/continuous_assessment_helpers.php';
require_once dirname(__DIR__) . '/includes/short_course_student.php';

$sid = (string)($_SESSION['Sid'] ?? '');
if ($sid === '') {
    $error_title = 'Session Expired';
    $error_message = 'Your session has expired. Please log in again to view your continuous assessment results.';
    include __DIR__ . '/includes/error_template.php';
    exit;
}

$student = student_ca_fetch_student_profile($db, $sid);
if (!is_array($student)) {
    $student = [];
}
$isShortCourse = isShortCourseStudent($db, $sid);
$programStructure = getStudentProgramPeriodMode($db, $sid);
$periodLabel = wuc_period_label_from_structure($programStructure);

$currentAcademicYearSetting = '';
$currentSemesterSetting = '1';
if (student_ca_table_exists($db, 'portal_settings')) {
    if ($settingsResult = $db->query("SELECT setting_key, setting_value FROM portal_settings WHERE setting_key IN ('current_academic_year', 'current_semester')")) {
        while ($row = $settingsResult->fetch_assoc()) {
            if ($row['setting_key'] === 'current_academic_year') {
                $currentAcademicYearSetting = trim((string)$row['setting_value']);
            } elseif ($row['setting_key'] === 'current_semester') {
                $currentSemesterSetting = (string)$row['setting_value'];
            }
        }
        $settingsResult->free();
    }
}

$regDataService = new RegistrationDataService($db);
$sessionService = new AcademicSessionService($db);
$currentSession = $sessionService->getCurrentSession($programStructure);
$sessionAcademicYear = trim((string)($currentSession['academic_year'] ?? ''));
$sessionSemester = (int)($currentSession['semester_term'] ?? 0);

$academicYearOptions = student_ca_academic_year_options($db, $sid, $currentAcademicYearSetting);
if ($sessionAcademicYear !== '') {
    student_ca_add_year_option($academicYearOptions, $sessionAcademicYear);
    rsort($academicYearOptions, SORT_NATURAL);
}

$requestedAcademicYear = trim((string)($_GET['academic_year'] ?? ''));
if ($requestedAcademicYear !== '' && in_array($requestedAcademicYear, $academicYearOptions, true)) {
    $selectedAcademicYear = $requestedAcademicYear;
} elseif ($sessionAcademicYear !== '' && in_array($sessionAcademicYear, $academicYearOptions, true)) {
    $selectedAcademicYear = $sessionAcademicYear;
} elseif ($academicYearOptions !== []) {
    $selectedAcademicYear = (string)$academicYearOptions[0];
} else {
    $selectedAcademicYear = $currentAcademicYearSetting !== '' ? $currentAcademicYearSetting : date('Y');
    $academicYearOptions = [$selectedAcademicYear];
}

$selectedYearOfStudy = '1';
$currentSemester = $currentSemesterSetting;
$latestRegistration = student_ca_latest_registration($db, $sid, $selectedAcademicYear);
$termContext = null;

if ($latestRegistration) {
    $currentSemester = (string)($latestRegistration['period'] ?? $currentSemesterSetting);
    $selectedYearOfStudy = trim((string)($latestRegistration['year_of_study'] ?? '1')) ?: '1';
    $termContext = [
        'semester' => (int)$currentSemester,
        'year_of_study' => (int)$selectedYearOfStudy,
        'academic_year' => trim((string)($latestRegistration['academic_year'] ?? $selectedAcademicYear)),
        'program_code' => trim((string)($latestRegistration['program_code'] ?? '')),
        'period_type' => trim((string)($latestRegistration['period_type'] ?? '')),
    ];
} else {
    $termContext = $regDataService->resolveRegistrationTermContext(
        $sid,
        null,
        $selectedAcademicYear,
        null,
        null,
        true
    );
    if ($termContext) {
        $currentSemester = (string)($termContext['semester'] ?? $currentSemesterSetting);
        $selectedYearOfStudy = trim((string)($termContext['year_of_study'] ?? '1')) ?: '1';
    }
}

if ($termContext) {
    $registrationPeriodType = normalizeProgramPeriodMode((string)($termContext['period_type'] ?? ''));
    if (in_array($registrationPeriodType, ['semester', 'term'], true)) {
        $programStructure = $registrationPeriodType;
        $periodLabel = wuc_period_label_from_structure($programStructure);
    }
    $resolvedProgramCode = trim((string)($termContext['program_code'] ?? ''));
    if ($resolvedProgramCode !== '' && is_array($student)) {
        $student['program_code'] = $resolvedProgramCode;
        if ($stmt = $db->prepare('SELECT program_name FROM programs WHERE program_code = ? LIMIT 1')) {
            $stmt->bind_param('s', $resolvedProgramCode);
            $stmt->execute();
            $programRow = $stmt->get_result()->fetch_assoc();
            if ($programRow && trim((string)($programRow['program_name'] ?? '')) !== '') {
                $student['program_name'] = (string)$programRow['program_name'];
            }
            $stmt->close();
        }
    }
}

$selectedYearOfStudy = student_ca_resolve_year_of_study($db, $sid, $selectedAcademicYear);
$programCodeForStructure = trim((string)($student['program_code'] ?? ''));
$periodConfig = student_ca_period_columns($db, $programCodeForStructure, $programStructure);
$periodNumbers = $periodConfig['periods'];
$periodHeaders = $periodConfig['headers'];
$periodLabel = $periodConfig['period_label'];
$programStructure = $periodConfig['period_mode'];
$caPeriodComponents = student_ca_period_component_labels($periodNumbers, $programStructure);

$programCode = trim((string)($student['program_code'] ?? ''));
$hasNoProgram = !$isShortCourse && $programCode === '';

$periodLabelFull = $selectedAcademicYear;
$records = [];

if (!$isShortCourse && !$hasNoProgram) {
    $courses = $regDataService->getRegisteredCourses(
        $sid,
        (int)$selectedYearOfStudy,
        0,
        null,
        $selectedAcademicYear,
        'year'
    );
    $componentsMap = student_ca_fetch_period_components_map(
        $db,
        $sid,
        $selectedAcademicYear,
        $selectedYearOfStudy,
        $periodNumbers
    );
    $records = student_ca_build_annual_records($courses, $componentsMap, $periodNumbers, $selectedYearOfStudy);
    $pendingPublicationCount = student_ca_count_pending_publication(
        $db,
        $sid,
        $selectedAcademicYear,
        $selectedYearOfStudy,
        $periodNumbers
    );
} else {
    $pendingPublicationCount = 0;
}

$shortCourseRecords = student_ca_load_short_course_records($db, $sid);
$summary = student_ca_compute_annual_summary($records, $periodNumbers);
$hasAnyResults = $records !== [] || $shortCourseRecords !== [];
$studentFullName = trim(
    (string)($student['Fname'] ?? '') . ' ' . (string)($student['Lname'] ?? '')
);
$studentName = $studentFullName;
$institutionName = 'Industrial Training Centre';
$reportTitle = $isShortCourse ? 'Short Course Continuous Assessment Report' : 'Continuous Assessment Report';
$programName = $isShortCourse
    ? 'Short Course Programme'
    : trim((string)($student['program_name'] ?? ''));
if ($hasNoProgram) {
    $programName = 'Not assigned';
} elseif ($programName === '') {
    $programName = 'N/A';
}
$studentPeriodLabel = $isShortCourse ? $selectedAcademicYear : $periodLabelFull;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Continuous Assessment - ITC</title>
<?php require_once dirname(__DIR__) . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/students/css/dashboard.css">
    <link rel="stylesheet" href="/wucportal/students/css/continuous-assessment.css">
    <link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">
</head>
<body class="bg-light student-dashboard-page no-auto-print ca-page">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="dash-content content-wrapper portal-dashboard ca-main">
<div class="container-fluid ca-viewport">

    <div class="ca-header no-print">
        <div class="ca-toolbar">
            <div class="ca-toolbar-title-wrap">
                <h1 class="ca-toolbar-title"><i class="fas fa-chart-line"></i> CA Results</h1>
                <?php if (!$isShortCourse): ?>
                <div class="ca-toolbar-stats">
                    <span class="ca-stat-pill" title="Enrolled courses"><i class="fas fa-book-open"></i><?= (int)$summary['course_count'] ?></span>
                    <span class="ca-stat-pill green" title="Published"><i class="fas fa-check"></i><?= (int)$summary['published_count'] ?></span>
                    <span class="ca-stat-pill amber" title="Awaiting"><i class="fas fa-clock"></i><?= (int)$summary['pending_count'] ?></span>
                    <?php if ($summary['average_total'] !== null): ?>
                    <span class="ca-stat-pill" title="Average CA"><i class="fas fa-chart-bar"></i><?= student_ca_h(number_format($summary['average_total'], 1)) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <form method="get" class="ca-toolbar-filters" id="caFilterForm" aria-label="Filter CA results">
                <select class="form-select form-select-sm" id="academicYearFilter" name="academic_year" aria-label="Academic year">
                    <?php foreach ($academicYearOptions as $yearOption): ?>
                    <option value="<?= student_ca_h($yearOption) ?>" <?= (string)$yearOption === (string)$selectedAcademicYear ? 'selected' : '' ?>><?= student_ca_h($yearOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($hasAnyResults): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm ca-btn-icon" onclick="wucPrintSinglePage()" title="Print report"><i class="fas fa-print"></i></button>
                <?php endif; ?>
            </form>
        </div>
        <div class="ca-identity-bar" aria-label="Report identity">
            <div class="ca-letterhead">
                <span class="ca-logo-frame">
                    <img src="/wucportal/images/itc_logo.png" alt="<?= student_ca_h($institutionName) ?> logo" class="ca-logo-img">
                </span>
                <div class="ca-institution-name"><?= student_ca_h($institutionName) ?></div>
                <div class="ca-report-subtitle"><?= student_ca_h($reportTitle) ?></div>
                <div class="ca-letterhead-rule" aria-hidden="true"></div>
            </div>
            <div class="ca-student-fullname"><?= student_ca_h($studentFullName !== '' ? $studentFullName : 'N/A') ?></div>
            <div class="ca-student-meta-grid">
                <div class="ca-meta-item">
                    <div class="ca-meta-key">Student ID</div>
                    <div class="ca-meta-val"><?= student_ca_h($sid) ?></div>
                </div>
                <div class="ca-meta-item">
                    <div class="ca-meta-key">Programme</div>
                    <div class="ca-meta-val"><?= student_ca_h($programName) ?><?php if ($programCode !== '' && !$isShortCourse): ?> <span class="ca-student-code">(<?= student_ca_h($programCode) ?>)</span><?php endif; ?></div>
                </div>
                <?php if (!$isShortCourse): ?>
                <div class="ca-meta-item">
                    <div class="ca-meta-key">Year of Study</div>
                    <div class="ca-meta-val">Year <?= student_ca_h($selectedYearOfStudy) ?></div>
                </div>
                <?php endif; ?>
                <div class="ca-meta-item">
                    <div class="ca-meta-key">Academic Year</div>
                    <div class="ca-meta-val ca-student-period"><?= student_ca_h($studentPeriodLabel) ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($_SESSION['student_notice'])): ?>
    <div class="alert alert-info alert-dismissible fade show no-print ca-alert-compact py-2" role="alert">
        <i class="fas fa-info-circle me-1"></i><?= student_ca_h($_SESSION['student_notice']); unset($_SESSION['student_notice']); ?>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if ($hasNoProgram): ?>
    <div class="alert alert-danger no-print ca-alert-compact py-2 mb-2" role="alert">
        <i class="fas fa-triangle-exclamation me-1"></i>No programme is assigned to your account. Please contact the <strong>Admissions Office</strong> or <strong>Administration</strong> to have your programme assigned before you can view CA results.
    </div>
    <?php elseif (!$isShortCourse && $records === []): ?>
    <div class="alert alert-warning no-print ca-alert-compact py-2 mb-2" role="status">
        <i class="fas fa-info-circle me-1"></i>No courses registered for <?= student_ca_h($selectedAcademicYear) ?> yet.
        <a href="registration.php" class="alert-link">Register</a>
    </div>
    <?php elseif (!$isShortCourse && !empty($pendingPublicationCount)): ?>
    <div class="alert alert-info no-print ca-alert-compact py-2 mb-2" role="status">
        <i class="fas fa-hourglass-half me-1"></i><?= (int)$pendingPublicationCount ?> CA record(s) for this year are awaiting publication. Only published marks appear on this report.
    </div>
    <?php endif; ?>

    <div class="ca-results-panel">
    <div class="ca-paper wuc-a4-sheet">
        <div class="ca-watermark"><?= $isShortCourse ? 'SHORT COURSE' : 'CA REPORT' ?></div>
        <div class="ca-paper-inner">

            <div class="text-center mb-4 pb-3 border-bottom ca-print-only">
                <span class="wuc-logo-frame d-inline-block mb-2">
                    <img src="/wucportal/images/itc_logo.png" alt="ITC Logo" class="wuc-logo-img report-logo">
                </span>
                <h2 class="h4 fw-bold mb-0 text-uppercase" style="color:#2d1e54;"><?= student_ca_h($institutionName) ?></h2>
                <div class="text-purple fw-semibold small text-uppercase mt-1 letter-spacing-1"><?= student_ca_h($reportTitle) ?></div>
            </div>

            <div class="ca-meta-grid ca-print-only">
                <div>
                    <div class="ca-meta-label">Student</div>
                    <div class="ca-meta-value"><?= student_ca_h($studentFullName !== '' ? $studentFullName : 'N/A') ?></div>
                </div>
                <div>
                    <div class="ca-meta-label">Student ID</div>
                    <div class="ca-meta-value"><?= student_ca_h($sid) ?></div>
                </div>
                <div>
                    <div class="ca-meta-label">Academic Year</div>
                    <div class="ca-meta-value"><?= student_ca_h($studentPeriodLabel) ?></div>
                </div>
                <div>
                    <div class="ca-meta-label">Programme</div>
                    <div class="ca-meta-value"><?= student_ca_h($programName) ?><?php if ($programCode !== '' && !$isShortCourse): ?> (<?= student_ca_h($programCode) ?>)<?php endif; ?></div>
                </div>
                <?php if (!$isShortCourse): ?>
                <div>
                    <div class="ca-meta-label">Year of Study</div>
                    <div class="ca-meta-value">Year <?= student_ca_h($selectedYearOfStudy) ?></div>
                </div>
                <?php endif; ?>
                <div>
                    <div class="ca-meta-label">Date Issued</div>
                    <div class="ca-meta-value"><?= date('d M Y') ?></div>
                </div>
            </div>

            <?php if (!$isShortCourse): ?>
            <?php if ($hasNoProgram): ?>
            <div class="ca-empty">
                <i class="fas fa-user-graduate fa-2x mb-2 d-block"></i>
                <p class="mb-0">Your programme has not been assigned yet. Contact the Admissions Office or Administration for assistance.</p>
            </div>
            <?php else: ?>
            <h3 class="ca-section-title ca-print-only"><i class="fas fa-list-check me-2 text-purple"></i><?= student_ca_h($selectedAcademicYear) ?> Results</h3>
            <?php
            $caTableRecords = $records;
            $caPeriodHeaders = $periodHeaders;
            $caPeriodNumbers = $periodNumbers;
            $caPeriodComponents = $caPeriodComponents;
            $caTableEmptyMessage = 'No CA records for this academic year yet.';
            include __DIR__ . '/includes/ca_annual_results_table.php';
            ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($isShortCourse && $shortCourseRecords === []): ?>
            <div class="ca-empty">
                <i class="fas fa-certificate fa-2x mb-2 d-block"></i>
                <p class="mb-0">No CA results have been posted for your short course yet.</p>
            </div>
            <?php endif; ?>

            <?php if ($shortCourseRecords !== []): ?>
            <h3 class="ca-section-title mt-2 ca-print-only"><i class="fas fa-certificate me-2 text-purple"></i>Short Course Results</h3>
            <div class="ca-table-wrap">
                <table class="table ca-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col" class="ca-row-number">#</th>
                            <th scope="col" class="text-start ca-code-column">Code</th>
                            <th scope="col" class="text-start ca-course-column">Course</th>
                            <th scope="col" class="text-start ca-duration-column">Duration</th>
                            <th scope="col" class="ca-score-heading">A1</th>
                            <th scope="col" class="ca-score-heading">A2</th>
                            <th scope="col" class="ca-score-heading">T1</th>
                            <th scope="col" class="ca-score-heading">T2</th>
                            <th scope="col" class="ca-total-heading">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $sn = 1; foreach ($shortCourseRecords as $r): ?>
                        <tr>
                            <td class="ca-score text-muted"><?= $sn++ ?></td>
                            <td class="ca-code-cell"><?= student_ca_h($r->course_code) ?></td>
                            <td class="ca-course-cell"><?= student_ca_h($r->course_name) ?></td>
                            <td class="ca-duration-cell"><?= !empty($r->start_date) ? student_ca_h(date('M j, Y', strtotime((string)$r->start_date))) : 'N/A' ?> - <?= !empty($r->end_date) ? student_ca_h(date('M j, Y', strtotime((string)$r->end_date))) : 'N/A' ?></td>
                            <?php foreach (['A1', 'A2', 'T1', 'T2'] as $comp): ?>
                            <td class="ca-score <?= ($r->$comp === null || $r->$comp === '') ? 'missing' : '' ?>"><?= student_ca_h(student_ca_format_score($r->$comp)) ?></td>
                            <?php endforeach; ?>
                            <td class="ca-score text-center">
                                <?php if ($r->Total_CA !== null && $r->Total_CA !== ''): ?>
                                <span class="ca-total-score"><?= student_ca_h(student_ca_format_score($r->Total_CA)) ?></span>
                                <?php else: ?>
                                <span class="ca-score missing">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($hasAnyResults): ?>
            <div class="ca-signature-row row ca-print-only">
                <div class="col-md-6 text-center">
                    <div class="ca-signature-line"></div>
                    <strong style="color:#2d1e54;">Registrar</strong>
                    <div class="text-muted small">Industrial Training Centre</div>
                </div>
                <div class="col-md-6 text-center">
                    <div class="ca-signature-line"></div>
                    <strong style="color:#2d1e54;">Academic Office</strong>
                    <div class="text-muted small">Official stamp &amp; date</div>
                </div>
            </div>
            <?php endif; ?>

            <div class="ca-footer-note ca-print-only">
                Published CA marks only. Contact your lecturer or the academic office if a score is missing or incorrect.
            </div>
        </div>
    </div>

    </div><!-- .ca-results-panel -->

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('#caFilterForm select').forEach(function(el) {
    el.addEventListener('change', function() {
        document.getElementById('caFilterForm').submit();
    });
});
</script>
</body>
</html>
