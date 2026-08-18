<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/assessment_weighting_helpers.php';
require_once __DIR__ . '/includes/period_mode_helper.php';

function et_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$sid = (string)($_SESSION['Sid'] ?? '');
$periodLabel = 'Semester';
if ($sid !== '') {
    $periodLabel = getPeriodLabel($db, $sid);
}
$periods = [];
$selectedYear = trim((string)($_GET['year'] ?? ''));
$selectedSemester = trim((string)($_GET['semester'] ?? ''));
$records = [];
$caRecords = [];
$student = null;
$policy = ['label' => 'Legacy stored marks'];
$periodMeta = [];
$caOnlyReport = false;
$gpa = 0.0;
$totalCredits = 0.0;
$hasCredits = false;
$totalCaCredits = 0.0;
$hasCaCredits = false;

function student_exam_grade(float $total): string
{
    require_once dirname(__DIR__) . '/includes/grading_helpers.php';
    return wuc_result_grade($total);
}

function student_exam_points(float $total): float
{
    require_once dirname(__DIR__) . '/includes/grading_helpers.php';
    return wuc_result_points($total);
}

function student_transcript_add_period(array &$periodMeta, string $year, string $semester, string $source): void
{
    $year = trim($year);
    $semester = trim($semester);
    if ($year === '' || $semester === '') {
        return;
    }
    $key = $year . '|' . $semester;
    if (!isset($periodMeta[$key])) {
        $periodMeta[$key] = [
            'year' => $year,
            'semester' => $semester,
            'has_exam' => false,
            'has_ca' => false,
        ];
    }
    if ($source === 'exam') {
        $periodMeta[$key]['has_exam'] = true;
    }
    if ($source === 'ca') {
        $periodMeta[$key]['has_ca'] = true;
    }
}

function student_transcript_number($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float)$value, 2);
}

if ($sid !== '') {
    $studentSidCol = assessment_weighting_column_exists($db, 'students', 'SID') ? 'SID' : 'Sid';
    $studentProgramSidCol = assessment_weighting_column_exists($db, 'student_program', 'Sid') ? 'Sid'
        : (assessment_weighting_column_exists($db, 'student_program', 'SID') ? 'SID'
        : (assessment_weighting_column_exists($db, 'student_program', 'student_id') ? 'student_id' : ''));
    $programJoin = '';
    $programSelect = "'' AS program_name, '' AS program_code";
    if ($studentProgramSidCol !== '' && assessment_weighting_column_exists($db, 'student_program', 'program_code')) {
        $programJoin .= " LEFT JOIN student_program sp ON sp.`{$studentProgramSidCol}` COLLATE utf8mb4_general_ci = s.`{$studentSidCol}` COLLATE utf8mb4_general_ci";
        if (assessment_weighting_table_exists($db, 'programs')) {
            $programJoin .= " LEFT JOIN programs p ON p.program_code COLLATE utf8mb4_general_ci = sp.program_code COLLATE utf8mb4_general_ci";
            $programSelect = "COALESCE(p.program_name, '') AS program_name, COALESCE(p.program_code, sp.program_code) AS program_code";
        } else {
            $programSelect = "'' AS program_name, sp.program_code AS program_code";
        }
    }

    $studentSql = "SELECT s.`{$studentSidCol}` AS SID, s.Fname, s.Lname, {$programSelect}
                   FROM students s
                   {$programJoin}
                   WHERE s.`{$studentSidCol}` COLLATE utf8mb4_general_ci = ?
                   LIMIT 1";
    if ($stmt = $db->prepare($studentSql)) {
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $policy = assessment_weighting_policy_for_student($db, $sid);
    $isTransport = assessment_weighting_is_transport_logistics($policy['program_code'] ?? '', $policy['program_name'] ?? '');

    // The 'exams' table is absent in some installs (mysqli throws on a missing
    // table, which fatals the page). Probe once and guard every exams query.
    // A period only counts as having an EXAM when the exam mark is actually on
    // record (Exam_marks IS NOT NULL) — a published CA-only row otherwise looks
    // like a sat exam and would render a bogus final grade. See wuc_result_exam_written.
    $hasExamsTable = assessment_weighting_table_exists($db, 'exams');
    if ($isTransport && $hasExamsTable && ($stmt = $db->prepare("SELECT DISTINCT `Year`, semester FROM exams WHERE Sid COLLATE utf8mb4_general_ci = ? AND status = 'Published' AND Exam_marks IS NOT NULL ORDER BY `Year` DESC, CAST(semester AS UNSIGNED) DESC"))) {
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            student_transcript_add_period($periodMeta, (string)($row['Year'] ?? ''), (string)($row['semester'] ?? ''), 'exam');
        }
        $stmt->close();
    }
    if (assessment_weighting_table_exists($db, 'semester_assessment') &&
        ($stmt = $db->prepare("SELECT DISTINCT `Year`, semester FROM semester_assessment WHERE Sid COLLATE utf8mb4_general_ci = ? AND status = 'Published' ORDER BY `Year` DESC, CAST(semester AS UNSIGNED) DESC"))) {
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            student_transcript_add_period($periodMeta, (string)($row['Year'] ?? ''), (string)($row['semester'] ?? ''), 'ca');
        }
        $stmt->close();
    }

    $periods = array_values($periodMeta);
    usort($periods, static function (array $a, array $b): int {
        $yearCompare = (int)$b['year'] <=> (int)$a['year'];
        if ($yearCompare !== 0) {
            return $yearCompare;
        }
        return (int)$b['semester'] <=> (int)$a['semester'];
    });

    $validSelection = false;
    foreach ($periods as $period) {
        if ($period['year'] === $selectedYear && $period['semester'] === $selectedSemester) {
            $validSelection = true;
            break;
        }
    }
    if (!$validSelection && !empty($periods)) {
        $selectedYear = $periods[0]['year'];
        $selectedSemester = $periods[0]['semester'];
    }

    if ($selectedYear !== '' && $selectedSemester !== '') {
        $selectedKey = $selectedYear . '|' . $selectedSemester;
        $selectedMeta = $periodMeta[$selectedKey] ?? ['has_exam' => false, 'has_ca' => false];
        $caOnlyReport = !$isTransport || (!empty($selectedMeta['has_ca']) && empty($selectedMeta['has_exam']));

        $creditExpr = "''";
        if (assessment_weighting_column_exists($db, 'courses', 'credit_units')) {
            $creditExpr = 'c.credit_units';
        } elseif (assessment_weighting_column_exists($db, 'courses', 'credits')) {
            $creditExpr = 'c.credits';
        }
        if (!$caOnlyReport && $hasExamsTable) {
            $caSelect = '0';
            $caJoin = '';
            if (assessment_weighting_table_exists($db, 'semester_assessment')) {
                $caSelect = 'COALESCE(sa.Total_CA, 0)';
                $caJoin = "LEFT JOIN semester_assessment sa
                             ON sa.Sid COLLATE utf8mb4_general_ci = e.Sid COLLATE utf8mb4_general_ci
                            AND sa.Course_Code COLLATE utf8mb4_general_ci = e.Course_Code COLLATE utf8mb4_general_ci
                            AND sa.Year = e.Year
                            AND sa.semester = e.semester";
            }

            $resultsSql = "SELECT e.Course_Code,
                                  COALESCE(c.course_name, e.Course_Code) AS course_name,
                                  COALESCE({$creditExpr}, '') AS credit_units,
                                  {$caSelect} AS Total_CA,
                                  COALESCE(e.Exam_marks, e.Total_marks, 0) AS Exam_marks
                           FROM exams e
                           LEFT JOIN courses c ON c.course_code COLLATE utf8mb4_general_ci = e.Course_Code COLLATE utf8mb4_general_ci
                           {$caJoin}
                           WHERE e.Sid COLLATE utf8mb4_general_ci = ?
                             AND e.Year = ?
                             AND e.semester = ?
                             AND e.status = 'Published'
                             AND e.Exam_marks IS NOT NULL
                           ORDER BY e.Course_Code";
            if ($stmt = $db->prepare($resultsSql)) {
                $stmt->bind_param('sss', $sid, $selectedYear, $selectedSemester);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $row['final_mark'] = assessment_weighting_total($db, $sid, $row['Total_CA'], $row['Exam_marks']);
                    $row['grade'] = student_exam_grade((float)$row['final_mark']);
                    $row['points'] = student_exam_points((float)$row['final_mark']);
                    $records[] = $row;
                }
                $stmt->close();
            }
        }

        if (($caOnlyReport || empty($records)) && assessment_weighting_table_exists($db, 'semester_assessment')) {
            $caColumns = [];
            foreach (['A1', 'A2', 'A3', 'T1', 'T2', 'Exam', 'Total_CA'] as $column) {
                $caColumns[$column] = assessment_weighting_column_exists($db, 'semester_assessment', $column);
            }
            $caSelectParts = [
                'sa.Course_Code',
                'COALESCE(c.course_name, sa.Course_Code) AS course_name',
                "COALESCE({$creditExpr}, '') AS credit_units",
            ];
            foreach ($caColumns as $column => $exists) {
                $caSelectParts[] = $exists ? "sa.`{$column}` AS `{$column}`" : "NULL AS `{$column}`";
            }
            $caSql = "SELECT " . implode(",\n                              ", $caSelectParts) . "
                      FROM semester_assessment sa
                      LEFT JOIN courses c ON c.course_code COLLATE utf8mb4_general_ci = sa.Course_Code COLLATE utf8mb4_general_ci
                      WHERE sa.Sid COLLATE utf8mb4_general_ci = ?
                        AND sa.Year = ?
                        AND sa.semester = ?
                        AND sa.status = 'Published'
                      ORDER BY sa.Course_Code";
            if ($stmt = $db->prepare($caSql)) {
                $stmt->bind_param('sss', $sid, $selectedYear, $selectedSemester);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $caRecords[] = $row;
                }
                $stmt->close();
            }
            if (empty($records) && !empty($caRecords)) {
                $caOnlyReport = true;
            }
        }
    // Calculate GPA and credit totals for the selected period
    $totalCredits = 0.0;
    $totalQualityPoints = 0.0;
    $hasCredits = false;
    $gpa = 0.0;
    if (!$caOnlyReport && !empty($records)) {
        foreach ($records as $row) {
            $cVal = is_numeric($row['credit_units'] ?? '') ? (float)$row['credit_units'] : 0.0;
            if ($cVal > 0) {
                $hasCredits = true;
                $totalCredits += $cVal;
                $totalQualityPoints += $cVal * (float)($row['points'] ?? 0.0);
            }
        }
        if ($hasCredits && $totalCredits > 0) {
            $gpa = $totalQualityPoints / $totalCredits;
        } else {
            // Simple average fallback
            $sumPoints = 0.0;
            foreach ($records as $row) {
                $sumPoints += (float)($row['points'] ?? 0.0);
            }
            $gpa = !empty($records) ? ($sumPoints / count($records)) : 0.0;
        }
    }

    $totalCaCredits = 0.0;
    $hasCaCredits = false;
    if ($caOnlyReport && !empty($caRecords)) {
        foreach ($caRecords as $row) {
            $cVal = is_numeric($row['credit_units'] ?? '') ? (float)$row['credit_units'] : 0.0;
            if ($cVal > 0) {
                $hasCaCredits = true;
                $totalCaCredits += $cVal;
            }
        }
    }
}
}

$pageTitle = $caOnlyReport ? 'Continuous Assessment Report' : 'Exam Transcript';
$pageDescription = $caOnlyReport
    ? 'Published continuous assessment marks for programmes with external final examinations.'
    : 'Published semester exam results and grades for your academic record.';
$institutionName = 'Industrial Training Centre';
$reportPaperTitle = $caOnlyReport ? 'Continuous Assessment Report' : 'Semester Exam Transcript';
$studentFullName = trim((string)($student['Fname'] ?? '') . ' ' . (string)($student['Lname'] ?? ''));
$programName = trim((string)($student['program_name'] ?? ''));
if ($programName === '') {
    $programName = 'N/A';
}
$activeRecordCount = $caOnlyReport ? count($caRecords) : count($records);
$hasReportData = $activeRecordCount > 0;
$periodDisplay = $selectedYear !== '' && $selectedSemester !== ''
    ? $selectedYear . ', ' . $periodLabel . ' ' . $selectedSemester
    : '—';
$assessmentRuleLabel = $caOnlyReport
    ? 'CA marks only (external exam)'
    : (string)($policy['label'] ?? 'Legacy stored marks');

require_once __DIR__ . '/../includes/page_meta.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= et_h(wuc_portal_title($pageTitle)) ?></title>
<?php wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/students/css/dashboard.css">
    <link rel="stylesheet" href="/wucportal/students/css/exam-transcript.css">
    <link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">
</head>
<body class="bg-light student-dashboard-page no-auto-print et-page">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="content-wrapper pt-3 pb-5 et-main">
<div class="container-fluid px-3 px-lg-4 portal-dashboard et-viewport">

    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title"><?= et_h($pageTitle) ?></h1>
                <p class="text-muted mb-0"><?= et_h($pageDescription) ?></p>
            </div>
            <div class="col-auto no-print">
                <?php if ($hasReportData): ?>
                <button type="button" class="btn btn-primary" onclick="wucPrintSinglePage()" title="Print report" aria-label="Print report">
                    <i class="fas fa-print me-2"></i>Print Report
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($periods === []): ?>
    <div class="alert alert-info et-alert-compact">
        <i class="fas fa-info-circle me-2"></i>No published exam transcript or CA report is available yet.
    </div>
    <?php else: ?>
    <div class="row g-4">
        <div class="col-lg-3 no-print">
            <div class="card et-selection-card">
                <div class="card-body">
                    <h2 class="et-selection-title mb-0">
                        <i class="fas fa-file-invoice me-2 text-primary" aria-hidden="true"></i>Available Reports
                    </h2>
                    <div class="d-grid gap-2 mt-3">
                        <?php foreach ($periods as $period): ?>
                            <?php $active = $period['year'] === $selectedYear && $period['semester'] === $selectedSemester; ?>
                            <a class="et-period-pill <?= $active ? 'active' : '' ?>" href="examTranscript.php?year=<?= urlencode($period['year']) ?>&amp;semester=<?= urlencode($period['semester']) ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong class="d-block"><?= et_h($period['year']) ?></strong>
                                        <span class="small"><?= et_h($periodLabel) ?> <?= et_h($period['semester']) ?></span>
                                    </div>
                                    <i class="fas <?= $active ? 'fa-check-circle text-white' : 'fa-chevron-right text-muted' ?>" aria-hidden="true"></i>
                                </div>
                                <?php if (!empty($period['has_ca']) && empty($period['has_exam'])): ?>
                                <span class="badge bg-info text-dark mt-2">CA Report</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-9">
            <section class="et-header no-print mb-3" aria-label="Report summary">
                <div class="et-toolbar">
                    <h2 class="et-toolbar-title">
                        <i class="fas fa-graduation-cap" aria-hidden="true"></i>
                        <?= et_h($periodDisplay) ?>
                    </h2>
                    <div class="et-toolbar-stats">
                        <span class="et-stat-pill neutral">
                            <i class="fas fa-book" aria-hidden="true"></i>
                            <span class="et-stat-label">Courses</span>
                            <span class="et-stat-value"><?= (int)$activeRecordCount ?></span>
                        </span>
                        <?php if (!$caOnlyReport && $hasReportData): ?>
                        <span class="et-stat-pill green">
                            <i class="fas fa-star" aria-hidden="true"></i>
                            <span class="et-stat-label">GPA</span>
                            <span class="et-stat-value"><?= et_h(number_format($gpa, 2)) ?></span>
                        </span>
                        <span class="et-stat-pill neutral">
                            <i class="fas fa-layer-group" aria-hidden="true"></i>
                            <span class="et-stat-label">Credits</span>
                            <span class="et-stat-value"><?= $hasCredits ? et_h(number_format($totalCredits, 1)) : '—' ?></span>
                        </span>
                        <?php elseif ($caOnlyReport && $hasReportData): ?>
                        <span class="et-stat-pill neutral">
                            <i class="fas fa-layer-group" aria-hidden="true"></i>
                            <span class="et-stat-label">Credits</span>
                            <span class="et-stat-value"><?= $hasCaCredits ? et_h(number_format($totalCaCredits, 1)) : '—' ?></span>
                        </span>
                        <?php endif; ?>
                        <span class="et-stat-pill amber">
                            <i class="fas fa-scale-balanced" aria-hidden="true"></i>
                            <span class="et-stat-label">Rule</span>
                            <span class="et-stat-value"><?= et_h($caOnlyReport ? 'CA only' : 'CA + Exam') ?></span>
                        </span>
                    </div>
                </div>
                <div class="et-identity-bar">
                    <div class="et-letterhead">
                        <img src="images/itc_logo.png" alt="" class="et-logo-img" width="48" height="48" onerror="this.style.display='none'">
                        <div class="et-institution-name"><?= et_h($institutionName) ?></div>
                        <div class="et-report-subtitle"><?= et_h($reportPaperTitle) ?></div>
                    </div>
                    <div class="et-student-identity">
                        <div class="et-student-fullname"><?= et_h($studentFullName !== '' ? $studentFullName : 'Student') ?></div>
                        <div class="et-student-meta-grid">
                            <div>
                                <div class="et-meta-key">Student ID</div>
                                <div class="et-meta-val"><?= et_h($sid) ?></div>
                            </div>
                            <div>
                                <div class="et-meta-key">Programme</div>
                                <div class="et-meta-val"><?= et_h($programName) ?></div>
                            </div>
                            <div>
                                <div class="et-meta-key">Assessment rule</div>
                                <div class="et-meta-val"><?= et_h($assessmentRuleLabel) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="et-legend">
                    <span class="et-legend-title">How to read</span>
                    <?php if ($caOnlyReport): ?>
                    <span class="et-legend-item">
                        <span class="et-legend-swatch is-empty">—</span>
                        Component not published
                    </span>
                    <?php else: ?>
                    <span class="et-legend-item">
                        <span class="et-legend-swatch is-grade">B+</span>
                        Final grade
                    </span>
                    <span class="et-legend-item">
                        <span class="et-legend-swatch is-empty">—</span>
                        Missing mark
                    </span>
                    <?php endif; ?>
                    <span class="et-legend-item">Published marks only appear on this report.</span>
                </div>
            </section>

            <div class="et-results-panel">
            <div class="transcript-paper wuc-a4-sheet">
                <div class="transcript-watermark"><?= et_h($caOnlyReport ? 'CA REPORT' : 'OFFICIAL RECORD') ?></div>

                <div class="transcript-header-container text-center pb-2">
                    <span class="wuc-logo-frame">
                        <img src="images/itc_logo.png" alt="ITC Logo" class="wuc-logo-img report-logo" onerror="this.style.display='none'">
                    </span>
                    <h1 class="transcript-title-main mt-2"><?= et_h($institutionName) ?></h1>
                    <div class="transcript-subtitle"><?= et_h($reportPaperTitle) ?></div>
                    <div class="header-divider"></div>
                </div>

                <div class="student-meta-container">
                    <div class="meta-grid">
                        <div class="meta-item">
                            <span class="meta-label">Student Name</span>
                            <span class="meta-value"><?= et_h($studentFullName) ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Student ID</span>
                            <span class="meta-value"><?= et_h($sid) ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Academic Period</span>
                            <span class="meta-value"><?= et_h($periodDisplay) ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Programme</span>
                            <span class="meta-value"><?= et_h($programName) ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Assessment Rule</span>
                            <span class="meta-value"><?= et_h($assessmentRuleLabel) ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Date Issued</span>
                            <span class="meta-value"><?= et_h(date('d M Y')) ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($caOnlyReport): ?>
                    <?php if ($caRecords === []): ?>
                    <div class="alert alert-info mb-0">No CA records were found for this period.</div>
                    <?php else: ?>
                    <div class="alert alert-info no-print mb-3">
                        <i class="fas fa-info-circle me-2"></i>This programme uses external final examinations. The report below shows continuous assessment marks only.
                    </div>
                    <div class="et-table-wrap">
                        <table class="transcript-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="text-start">Course Code</th>
                                    <th class="text-start">Course Name</th>
                                    <th>Credits</th>
                                    <th>Ass1</th>
                                    <th>Ass2</th>
                                    <th>Test</th>
                                    <th>Total CA</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($caRecords as $row): ?>
                                <tr>
                                    <td class="text-center"><?= $i++ ?></td>
                                    <td class="fw-bold"><?= et_h((string)$row['Course_Code']) ?></td>
                                    <td><?= et_h((string)$row['course_name']) ?></td>
                                    <td class="text-center"><?= et_h((string)($row['credit_units'] ?: '—')) ?></td>
                                    <td class="text-center"><?= et_h(student_transcript_number($row['A1'] ?? null)) ?></td>
                                    <td class="text-center"><?= et_h(student_transcript_number($row['A2'] ?? null)) ?></td>
                                    <td class="text-center"><?= et_h(student_transcript_number($row['T1'] ?? null)) ?></td>
                                    <td class="text-center fw-bold"><?= et_h(student_transcript_number($row['Total_CA'] ?? null)) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="et-summary-row">
                                    <td colspan="3" class="text-end">Total registered credits</td>
                                    <td class="text-center"><?= $hasCaCredits ? et_h(number_format($totalCaCredits, 1)) : '—' ?></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                <?php elseif ($records === []): ?>
                <div class="alert alert-info mb-0">No exam records were found for this period.</div>
                <?php else: ?>
                <div class="et-table-wrap">
                    <table class="transcript-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th class="text-start">Course Code</th>
                                <th class="text-start">Course Name</th>
                                <th>Credits</th>
                                <th>CA</th>
                                <th>Exam</th>
                                <th>Final</th>
                                <th>Grade</th>
                                <th>Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($records as $row): ?>
                            <tr>
                                <td class="text-center"><?= $i++ ?></td>
                                <td class="fw-bold"><?= et_h((string)$row['Course_Code']) ?></td>
                                <td><?= et_h((string)$row['course_name']) ?></td>
                                <td class="text-center"><?= et_h((string)($row['credit_units'] ?: '—')) ?></td>
                                <td class="text-center"><?= et_h(number_format((float)$row['Total_CA'], 2)) ?></td>
                                <td class="text-center"><?= et_h(number_format((float)$row['Exam_marks'], 2)) ?></td>
                                <td class="text-center fw-bold"><?= et_h(number_format((float)$row['final_mark'], 2)) ?></td>
                                <td class="text-center"><span class="et-grade-badge"><?= et_h((string)$row['grade']) ?></span></td>
                                <td class="text-center"><?= et_h(number_format((float)$row['points'], 2)) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="et-summary-row">
                                <td colspan="3" class="text-end">Summary</td>
                                <td class="text-center"><?= $hasCredits ? et_h(number_format($totalCredits, 1)) : '—' ?></td>
                                <td colspan="4" class="text-end">GPA</td>
                                <td class="text-center"><?= et_h(number_format($gpa, 2)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if ($hasReportData): ?>
                <div class="signature-section">
                    <div class="row">
                        <div class="col-md-6 text-center">
                            <div class="signature-line"></div>
                            <strong style="color:#2d1e54;">Registrar</strong>
                            <div class="text-muted small mt-1"><?= et_h($institutionName) ?></div>
                        </div>
                        <div class="col-md-6 text-center">
                            <div class="signature-line"></div>
                            <strong style="color:#2d1e54;">Academic Office</strong>
                            <div class="text-muted small mt-1">Official stamp &amp; date</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="transcript-official-notice">
                    <p class="mb-1">This transcript is valid only when it bears the official signature of the Registrar and the institutional stamp.</p>
                    <p class="mb-0 text-uppercase fw-bold text-muted" style="font-size:0.65rem;letter-spacing:1px;"><?= et_h($institutionName) ?> · Academic Record · End of Record</p>
                </div>
            </div>
            </div><!-- .et-results-panel -->
        </div>
    </div>
    <?php endif; ?>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
