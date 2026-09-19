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

// Check short-course status, dual-enrolment status, and portal view context
$isShortCourseStudentOnly = isShortCourseStudent($db, $sid);
$scEnrolments = function_exists('sc_student_enrolments') ? sc_student_enrolments($db, $sid) : [];
$hasShortCourseEnrolments = ($scEnrolments !== []);
$hasLongProgram = function_exists('sc_student_has_long_program') ? sc_student_has_long_program($db, $sid) : false;
$isDualEnrolled = $hasLongProgram && $hasShortCourseEnrolments;
$portalViewShort = (($_SESSION['student_portal_view'] ?? '') === 'short_course') && $hasShortCourseEnrolments;

$requestedView = strtolower(trim((string)($_GET['view'] ?? ($_GET['type'] ?? ''))));
if ($requestedView === 'short_course' || $requestedView === 'short') {
    $isShortCourse = $hasShortCourseEnrolments || $isShortCourseStudentOnly;
} elseif ($requestedView === 'academic' || $requestedView === 'long' || $requestedView === 'certificate') {
    $isShortCourse = false;
} elseif ($portalViewShort) {
    $isShortCourse = true;
} else {
    $isShortCourse = $isShortCourseStudentOnly;
}

// Build short course program label if in short-course mode
$shortCourseProgramLabel = 'Short Course Programme';
if ($hasShortCourseEnrolments) {
    $scLabels = [];
    foreach ($scEnrolments as $scE) {
        $cCode = trim((string)($scE['course_code'] ?? ''));
        $cName = trim((string)($scE['course_name'] ?? ''));
        if ($cCode !== '') {
            $scLabels[] = $cName !== '' ? "{$cCode} - {$cName}" : $cCode;
        }
    }
    if ($scLabels !== []) {
        $shortCourseProgramLabel = implode(', ', $scLabels);
    }
}

$programStructure = $isShortCourse ? 'short_course' : getStudentProgramPeriodMode($db, $sid);
$periodLabel = $isShortCourse ? 'Duration' : wuc_period_label_from_structure($programStructure);

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

if (!$isShortCourse) {
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
}

$selectedYearOfStudy = student_ca_resolve_year_of_study($db, $sid, $selectedAcademicYear);
$programCodeForStructure = trim((string)($student['program_code'] ?? ''));
$periodConfig = student_ca_period_columns($db, $programCodeForStructure, $programStructure);
$periodNumbers = $periodConfig['periods'];
$periodHeaders = $periodConfig['headers'];
if (!$isShortCourse) {
    $periodLabel = $periodConfig['period_label'];
    $programStructure = $periodConfig['period_mode'];
}
$caPeriodComponents = student_ca_period_component_labels($periodNumbers, $programStructure);

$programCode = trim((string)($student['program_code'] ?? ''));
$hasNoProgram = !$isShortCourse && $programCode === '';

$periodLabelFull = $selectedAcademicYear;
$records = [];

if (!$isShortCourse) {
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

    // Merge any courses found in componentsMap or semester_assessment so uploaded marks are never hidden by registration mismatches
    $existingCodes = [];
    foreach ($courses as $c) {
        $cCode = strtoupper(trim((string)($c['course_code'] ?? '')));
        if ($cCode !== '') {
            $existingCodes[$cCode] = true;
        }
    }

    // An explicit drop is not a registration mismatch: courses the student
    // dropped/withdrawn from must not reappear via the marks safety-net below.
    $droppedCodes = [];
    $crCols = student_ca_table_columns($db, 'course_registration');
    $crCodeCol = student_ca_pick_column($crCols, ['course_code', 'Course_Code'], 'course_code');
    $crStatusCol = student_ca_pick_column($crCols, ['status'], 'status');
    $crYearCol = student_ca_pick_column($crCols, ['Year', 'year_of_study'], 'Year');
    $crAcYearCol = student_ca_pick_column($crCols, ['academic_year'], 'academic_year');
    if ($crCols !== [] && $crStatusCol !== '') {
        $acYearInt = (int)preg_replace('/\D.*/', '', $selectedAcademicYear);
        $dropSql = "SELECT UPPER(TRIM(`{$crCodeCol}`)) AS code
                    FROM course_registration
                    WHERE Sid COLLATE utf8mb4_general_ci = ?
                      AND (`{$crYearCol}` = ? OR CAST(`{$crAcYearCol}` AS CHAR) = ? OR `{$crAcYearCol}` = ?)
                      AND LOWER(COALESCE(`{$crStatusCol}`, '')) IN ('dropped','withdrawn','cancelled','inactive')";
        if ($dropStmt = $db->prepare($dropSql)) {
            $acYearStr = (string)$acYearInt;
            $dropStmt->bind_param('sssi', $sid, $selectedYearOfStudy, $acYearStr, $acYearInt);
            $dropStmt->execute();
            $dropRes = $dropStmt->get_result();
            while ($dropRow = $dropRes->fetch_assoc()) {
                $code = strtoupper(trim((string)($dropRow['code'] ?? '')));
                if ($code !== '') {
                    $droppedCodes[$code] = true;
                }
            }
            $dropStmt->close();
        }
    }

    foreach (array_keys($componentsMap) as $compCode) {
        $normCode = strtoupper(trim((string)$compCode));
        if ($normCode !== '' && !isset($existingCodes[$normCode]) && !isset($droppedCodes[$normCode])) {
            $cName = $compCode;
            if ($cStmt = $db->prepare('SELECT course_name FROM courses WHERE UPPER(course_code) = ? LIMIT 1')) {
                $cStmt->bind_param('s', $normCode);
                $cStmt->execute();
                if ($cRow = $cStmt->get_result()->fetch_assoc()) {
                    $cName = (string)($cRow['course_name'] ?? $compCode);
                }
                $cStmt->close();
            }
            $courses[] = [
                'course_code' => $compCode,
                'course_name' => $cName,
                'credit_hours' => 3,
            ];
            $existingCodes[$normCode] = true;
        }
    }

    $records = student_ca_build_annual_records($courses, $componentsMap, $periodNumbers, $selectedYearOfStudy, $db, $sid);
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

// Keep short-course CA out of the long-programme report (and vice versa).
$shortCourseRecords = $isShortCourse
    ? student_ca_load_short_course_records($db, $sid)
    : [];

if ($isShortCourse) {
    // Annual term/semester CA is for long programmes only.
    $records = [];
    $pendingPublicationCount = 0;
}

$summary = student_ca_compute_annual_summary($records, $periodNumbers);
if ($isShortCourse && $shortCourseRecords !== []) {
    $scPublished = 0;
    $scTotals = [];
    foreach ($shortCourseRecords as $scRow) {
        if ($scRow->Total_CA !== null && $scRow->Total_CA !== '') {
            $scPublished++;
            $scTotals[] = (float)$scRow->Total_CA;
        }
    }
    $scCount = count($shortCourseRecords);
    $summary = [
        'course_count' => $scCount,
        'published_count' => $scPublished,
        'pending_count' => max(0, $scCount - $scPublished),
        'average_total' => $scTotals !== [] ? round(array_sum($scTotals) / count($scTotals), 1) : null,
    ];
}

$hasAnyResults = $records !== [] || $shortCourseRecords !== [];
$studentFullName = trim(
    (string)($student['Fname'] ?? '') . ' ' . (string)($student['Lname'] ?? '')
);
$studentName = $studentFullName;
$institutionName = 'Industrial Training Centre';
$reportTitle = $isShortCourse ? 'Short Course Continuous Assessment Report' : 'Continuous Assessment Report';
$programName = $isShortCourse
    ? $shortCourseProgramLabel
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

<main class="content-wrapper pt-3 pb-5 ca-main">
<div class="container-fluid px-3 px-lg-4 portal-dashboard ca-viewport">

    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title"><?= student_ca_h($isShortCourse ? 'Short Course Continuous Assessment' : 'Continuous Assessment') ?></h1>
                <p class="text-muted mb-0">
                    Published CA marks for <?= student_ca_h($selectedAcademicYear) ?>.
                    Only marks released by your lecturers appear here.
                </p>
            </div>
            <div class="col-auto no-print">
                <?php if ($hasAnyResults): ?>
                <button type="button" class="btn btn-primary" onclick="wucPrintSinglePage()" title="Print report" aria-label="Print report">
                    <i class="fas fa-print me-2"></i>Print Report
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($isDualEnrolled): ?>
    <!-- Dual-Enrolled Programme Switcher -->
    <div class="card border-0 shadow-sm mb-4 no-print" style="border-radius: 12px; background: #fff;">
        <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-layer-group text-primary fs-5"></i>
                <div>
                    <strong class="d-block text-dark small">Multiple Enrolments Detected</strong>
                    <span class="text-muted small">Switch between your long-term academic programme and short course results.</span>
                </div>
            </div>
            <div class="btn-group btn-group-sm" role="group" aria-label="CA view switcher">
                <a href="continuousAssessment.php?view=academic" class="btn <?= !$isShortCourse ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <i class="fas fa-graduation-cap me-1"></i> Academic / Certificate CA
                </a>
                <a href="continuousAssessment.php?view=short_course" class="btn <?= $isShortCourse ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <i class="fas fa-certificate me-1"></i> Short Course CA
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['student_notice'])): ?>
    <div class="alert alert-info alert-dismissible fade show no-print ca-alert-compact py-2 mb-3" role="alert">
        <i class="fas fa-info-circle me-1"></i><?= student_ca_h($_SESSION['student_notice']); unset($_SESSION['student_notice']); ?>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if ($hasNoProgram): ?>
    <div class="alert alert-danger no-print ca-alert-compact py-2 mb-3" role="alert">
        <i class="fas fa-triangle-exclamation me-1"></i>No programme is assigned to your account. Please contact the <strong>Admissions Office</strong> or <strong>Administration</strong> to have your programme assigned before you can view CA results.
    </div>
    <?php elseif (!$isShortCourse && $records === []): ?>
    <div class="alert alert-warning no-print ca-alert-compact py-2 mb-3" role="status">
        <i class="fas fa-info-circle me-1"></i>No courses registered for <?= student_ca_h($selectedAcademicYear) ?> yet.
        <a href="registration.php" class="alert-link">Register</a>
    </div>
    <?php elseif (!$isShortCourse && !empty($pendingPublicationCount)): ?>
    <div class="alert alert-info no-print ca-alert-compact py-2 mb-3" role="status">
        <i class="fas fa-hourglass-half me-1"></i><?= (int)$pendingPublicationCount ?> CA record(s) for this year are awaiting publication. Only published marks appear on this report.
    </div>
    <?php endif; ?>

    <section class="ca-header no-print mb-3" aria-label="Report filters and summary">
        <div class="ca-toolbar">
            <div class="ca-toolbar-title-wrap">
                <h2 class="ca-toolbar-title">
                    <i class="fas fa-chart-line" aria-hidden="true"></i>
                    <?= student_ca_h($selectedAcademicYear) ?> CA Results
                </h2>
                <div class="ca-toolbar-stats">
                    <span class="ca-stat-pill neutral">
                        <i class="fas fa-book" aria-hidden="true"></i>
                        <span class="ca-stat-label">Courses</span>
                        <span class="ca-stat-value"><?= (int)($summary['course_count'] ?? count($records)) ?></span>
                    </span>
                    <span class="ca-stat-pill green">
                        <i class="fas fa-check-circle" aria-hidden="true"></i>
                        <span class="ca-stat-label">Published</span>
                        <span class="ca-stat-value"><?= (int)($summary['published_count'] ?? 0) ?></span>
                    </span>
                    <span class="ca-stat-pill amber">
                        <i class="fas fa-hourglass-half" aria-hidden="true"></i>
                        <span class="ca-stat-label">Awaiting</span>
                        <span class="ca-stat-value"><?= (int)($summary['pending_count'] ?? 0) ?></span>
                    </span>
                    <?php if (($summary['average_total'] ?? null) !== null): ?>
                    <span class="ca-stat-pill neutral">
                        <i class="fas fa-chart-simple" aria-hidden="true"></i>
                        <span class="ca-stat-label">Avg CA</span>
                        <span class="ca-stat-value"><?= student_ca_h((string)$summary['average_total']) ?>%</span>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <form method="get" class="ca-toolbar-filters" id="caFilterForm">
                <?php if ($isDualEnrolled || $requestedView !== ''): ?>
                <input type="hidden" name="view" value="<?= $isShortCourse ? 'short_course' : 'academic' ?>">
                <?php endif; ?>
                <label class="form-label-inline" for="academicYearFilter">Academic year</label>
                <select class="form-select form-select-sm" id="academicYearFilter" name="academic_year" aria-label="Academic year">
                    <?php foreach ($academicYearOptions as $yearOption): ?>
                    <option value="<?= student_ca_h($yearOption) ?>" <?= (string)$yearOption === (string)$selectedAcademicYear ? 'selected' : '' ?>><?= student_ca_h($yearOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="ca-identity-bar">
            <div class="ca-letterhead">
                <div class="ca-logo-frame">
                    <img src="images/itc_logo.png" alt="" class="ca-logo-img" width="48" height="48" onerror="this.style.display='none'">
                </div>
                <div class="ca-institution-name"><?= student_ca_h($institutionName) ?></div>
                <div class="ca-report-subtitle"><?= student_ca_h($reportTitle) ?></div>
            </div>
            <div class="ca-student-identity">
                <div class="ca-student-fullname"><?= student_ca_h($studentFullName !== '' ? $studentFullName : 'Student') ?></div>
                <div class="ca-student-meta-grid">
                    <div class="ca-meta-item">
                        <span class="ca-meta-key">Student ID</span>
                        <span class="ca-meta-val"><?= student_ca_h($sid) ?></span>
                    </div>
                    <div class="ca-meta-item">
                        <span class="ca-meta-key">Programme</span>
                        <span class="ca-meta-val"><?= student_ca_h($programName) ?></span>
                    </div>
                    <div class="ca-meta-item">
                        <span class="ca-meta-key">Academic year</span>
                        <span class="ca-meta-val ca-student-period"><?= student_ca_h($selectedAcademicYear) ?></span>
                    </div>
                    <?php if (!$isShortCourse): ?>
                    <div class="ca-meta-item">
                        <span class="ca-meta-key">Year of study</span>
                        <span class="ca-meta-val">Year <?= student_ca_h($selectedYearOfStudy) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="ca-legend">
            <span class="ca-legend-title">How to read</span>
            <span class="ca-legend-item">
                <span class="ca-legend-swatch is-score">72</span>
                Published mark
            </span>
            <span class="ca-legend-item">
                <span class="ca-legend-swatch is-empty">—</span>
                Not published yet
            </span>
            <span class="ca-legend-item">
                <span class="ca-legend-swatch is-final">68</span>
                Final CA (year average)
            </span>
        </div>
    </section>

    <div class="ca-results-panel">
    <div class="ca-paper wuc-a4-sheet">
        <div class="ca-watermark">CA RESULTS</div>
        <div class="ca-paper-inner">
            <div class="ca-print-only text-center mb-3">
                <div class="fw-bold" style="color:#2d1e54;"><?= student_ca_h($institutionName) ?></div>
                <div class="text-uppercase small fw-semibold" style="color:#6f42c1;"><?= student_ca_h($reportTitle) ?></div>
                <div class="small text-muted mt-1"><?= student_ca_h($studentFullName) ?> · <?= student_ca_h($sid) ?> · <?= student_ca_h($selectedAcademicYear) ?></div>
            </div>

            <?php if (!$isShortCourse && !$hasNoProgram): ?>
                <?php
                $caTableRecords = $records;
                $caPeriodHeaders = $periodHeaders;
                $caPeriodNumbers = $periodNumbers;
                $caPeriodComponentLabels = $caPeriodComponents;
                $caTableEmptyMessage = 'No CA records for this academic year yet.';
                include __DIR__ . '/includes/ca_annual_results_table.php';
                ?>
            <?php endif; ?>

            <?php if ($isShortCourse && $shortCourseRecords === []): ?>
            <div class="ca-empty">
                <i class="fas fa-certificate fa-2x mb-2 d-block text-purple" aria-hidden="true"></i>
                <p class="mb-0">No CA results have been posted for your short course yet.</p>
            </div>
            <?php endif; ?>

            <?php if ($shortCourseRecords !== []): ?>
            <div class="ca-results-heading no-print">
                <h2><i class="fas fa-certificate me-2 text-purple" aria-hidden="true"></i>Short Course Results</h2>
                <p class="ca-results-hint mb-0">Assessment components for your enrolled short courses.</p>
            </div>
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
document.addEventListener('DOMContentLoaded', function() {
    const filterSelect = document.getElementById('academicYearFilter');
    if (filterSelect) {
        filterSelect.addEventListener('change', function() {
            document.getElementById('caFilterForm').submit();
        });
    }
});

function wucPrintSinglePage() {
    window.print();
}
</script>
</body>
</html>