<?php
session_start();
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/assessment_weighting_helpers.php';
require_once __DIR__ . '/includes/period_mode_helper.php';

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
require_once __DIR__ . '/../includes/page_meta.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(wuc_portal_title($caOnlyReport ? 'Continuous Assessment Report' : 'Exam Transcript'), ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php wuc_portal_favicon_links(); ?>
    <style>
        /* Modern design system tokens and variables */
        :root {
            --brand-primary: #6f42c1;
            --brand-primary-hover: #5a32a3;
            --brand-primary-light: #f5f2fd;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --border-color: #e5e7eb;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --premium-shadow: 0 10px 15px -3px rgba(111, 66, 193, 0.05), 0 4px 6px -2px rgba(111, 66, 193, 0.02);
        }

        .transcript-shell { 
            max-width: 1040px; 
            margin: 0 auto; 
        }

        /* Redesigned Selection Column */
        .report-selection-card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--premium-shadow);
            background: #fff;
            overflow: hidden;
            border: 1px solid rgba(111, 66, 193, 0.08);
        }

        .report-selection-title {
            color: #2d1e54;
            font-size: 1rem;
            font-weight: 700;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .semester-pill { 
            border: 1px solid rgba(111, 66, 193, 0.1); 
            border-radius: 12px; 
            padding: 14px 16px; 
            text-decoration: none; 
            color: var(--text-dark); 
            display: block; 
            transition: all 0.2s ease-in-out;
            background: #fff;
            position: relative;
        }

        .semester-pill:hover {
            border-color: var(--brand-primary);
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(111, 66, 193, 0.08);
            color: var(--brand-primary-hover);
        }

        .semester-pill.active { 
            background: linear-gradient(135deg, #6f42c1, #5a32a3); 
            color: #fff; 
            border-color: var(--brand-primary);
            box-shadow: 0 4px 15px rgba(111, 66, 193, 0.25);
        }
        
        .semester-pill.active .small {
            color: rgba(255, 255, 255, 0.85);
        }

        /* Redesigned Transcript Paper */
        .transcript-paper { 
            background: #fff; 
            border: 1px solid rgba(111, 66, 193, 0.1); 
            border-radius: 16px; 
            padding: 40px; 
            box-shadow: var(--premium-shadow);
            position: relative;
            overflow: hidden;
        }

        /* Subtle professional watermark */
        .transcript-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 5.5rem;
            font-weight: 900;
            color: rgba(111, 66, 193, 0.015);
            white-space: nowrap;
            pointer-events: none;
            user-select: none;
            z-index: 0;
            text-transform: uppercase;
            letter-spacing: 4px;
        }

        .transcript-header-container {
            z-index: 1;
            position: relative;
        }

        .transcript-title-main { 
            color: #2d1e54; 
            font-size: 1.6rem; 
            font-weight: 800; 
            margin: 0; 
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .transcript-subtitle {
            color: var(--brand-primary);
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 2px;
            margin-top: 4px;
        }

        /* Header dividing line */
        .header-divider {
            height: 3px;
            border-top: 2px solid var(--brand-primary);
            border-bottom: 1px solid var(--brand-primary);
            margin: 20px 0;
            opacity: 0.85;
        }

        /* Redesigned Structured Metadata Grid */
        .student-meta-container {
            background-color: var(--brand-primary-light);
            border-left: 4px solid var(--brand-primary);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            z-index: 1;
            position: relative;
        }

        .meta-grid { 
            display: grid; 
            grid-template-columns: repeat(3, minmax(0, 1fr)); 
            gap: 16px 24px; 
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label { 
            color: var(--brand-primary); 
            font-size: 0.7rem; 
            text-transform: uppercase; 
            font-weight: 700; 
            letter-spacing: 1px;
            margin-bottom: 2px;
        }

        .meta-value { 
            color: #1f2937; 
            font-weight: 600; 
            font-size: 0.95rem;
        }

        /* Table Styling */
        .table-responsive {
            z-index: 1;
            position: relative;
        }

        .transcript-table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 24px;
        }

        .transcript-table thead th {
            background: linear-gradient(135deg, #6f42c1, #5a32a3);
            color: #fff;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            border: 1px solid rgba(111, 66, 193, 0.2);
            text-align: center;
        }

        .transcript-table thead th.text-start {
            text-align: left;
        }

        .transcript-table tbody td {
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        .transcript-table tbody tr:nth-child(even) {
            background-color: #faf9fe;
        }

        .transcript-table tbody tr:hover {
            background-color: #f1ecf9;
        }

        .text-center { text-align: center; }
        .text-end { text-align: right; }
        .fw-bold { font-weight: bold; }

        /* Signature block */
        .signature-section {
            margin-top: 45px;
            z-index: 1;
            position: relative;
        }

        .signature-line {
            border-top: 1.5px solid #4b5563;
            width: 80%;
            margin: 40px auto 8px auto;
        }

        .transcript-official-notice {
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 40px;
            border-top: 1px dashed var(--border-color);
            padding-top: 15px;
            z-index: 1;
            position: relative;
        }

        @media (max-width: 991.98px) {
            .meta-grid { 
                grid-template-columns: repeat(2, minmax(0, 1fr)); 
            }
        }

        @media (max-width: 767.98px) {
            .transcript-paper { padding: 24px; }
            .meta-grid { grid-template-columns: 1fr; gap: 12px; }
            .transcript-title-main { font-size: 1.3rem; }
        }

        /* Targeted Print Style Sheet overrides */
        @media print {
            body { 
                background: #fff !important; 
                color: #000 !important;
            }
            .sidebar, .sidebar-toggle, .sidebar-backdrop, .no-print, .btn, .page-header { 
                display: none !important; 
            }
            .content-wrapper { 
                margin: 0 !important; 
                padding: 0 !important; 
            }
            .container-fluid {
                padding: 0 !important;
            }
            .transcript-shell { 
                max-width: 100% !important; 
                width: 100% !important;
            }
            .transcript-paper { 
                border: 0 !important; 
                box-shadow: none !important; 
                padding: 0 !important; 
                margin: 0 !important;
                border-radius: 0 !important;
            }
            .student-meta-container {
                background-color: #f8fafc !important;
                border-left: 4px solid #000 !important;
                border: 1px solid #cbd5e1 !important;
                border-left-width: 4px !important;
                padding: 15px !important;
                margin-bottom: 20px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .meta-value {
                color: #000 !important;
            }
            .meta-label {
                color: #475569 !important;
            }
            .transcript-table thead th {
                background: #f1f5f9 !important;
                color: #000 !important;
                border: 1px solid #475569 !important;
                font-size: 8pt !important;
                padding: 8px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .transcript-table tbody td {
                border: 1px solid #475569 !important;
                font-size: 8.5pt !important;
                padding: 8px !important;
                color: #000 !important;
            }
            .transcript-table tbody tr {
                background: none !important;
            }
            .signature-line {
                border-top: 1px solid #000 !important;
            }
            .transcript-official-notice {
                margin-top: 30px !important;
                font-size: 7pt !important;
            }
            .transcript-watermark {
                color: rgba(0, 0, 0, 0.01) !important;
            }
        }
    </style>
    <link rel="stylesheet" href="/wucportal/css/wuc-premium.css">
    <link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">
</head>
<body class="bg-light no-auto-print">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>
<div class="content-wrapper">
    <div class="container-fluid py-4">
        <div class="transcript-shell">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 no-print">
                <div>
                    <h2 class="page-title mb-1 fw-bold" style="color: #2d1e54;"><?php echo $caOnlyReport ? 'Continuous Assessment Report' : 'Exam Transcript'; ?></h2>
                    <div class="text-muted small">
                        <?php echo $caOnlyReport ? 'Print continuous assessment results for external-exam programmes.' : 'Print semester exam results for your academic record.'; ?>
                    </div>
                </div>
                <?php if (!empty($records) || !empty($caRecords)): ?>
                    <button type="button" class="btn btn-primary px-4 py-2 border-0 shadow-sm" style="background: linear-gradient(135deg, #6f42c1, #5a32a3); border-radius: 8px; font-weight: 500;" onclick="wucPrintSinglePage()">
                        <i class="fas fa-print me-2"></i> Print Selected Report
                    </button>
                <?php endif; ?>
            </div>

            <?php if (empty($periods)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No published exam transcript or CA report is available yet.
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <div class="col-lg-3 no-print">
                        <div class="card report-selection-card">
                            <div class="card-body">
                                <h6 class="report-selection-title mb-3 fw-bold"><i class="fas fa-file-invoice me-2 text-primary"></i>Available Reports</h6>
                                <div class="d-grid gap-2">
                                    <?php foreach ($periods as $period): ?>
                                        <?php $active = $period['year'] === $selectedYear && $period['semester'] === $selectedSemester; ?>
                                        <a class="semester-pill <?php echo $active ? 'active' : ''; ?>" href="examTranscript.php?year=<?php echo urlencode($period['year']); ?>&semester=<?php echo urlencode($period['semester']); ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong class="d-block"><?php echo htmlspecialchars($period['year'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <span class="small"><?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($period['semester'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>
                                                <i class="fas <?php echo $active ? 'fa-check-circle text-white' : 'fa-chevron-right text-muted'; ?> fs-5"></i>
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
                        <div class="transcript-paper wuc-a4-sheet">
                            <!-- Watermark -->
                            <div class="transcript-watermark"><?php echo $caOnlyReport ? 'CA REPORT' : 'OFFICIAL RECORD'; ?></div>

                            <!-- Header -->
                            <div class="transcript-header-container text-center pb-2">
                                <span class="wuc-logo-frame">
                                    <img src="images/itc_logo.png" alt="ITC Logo" class="wuc-logo-img report-logo">
                                </span>
                                <h1 class="transcript-title-main mt-2">Industrial Training Centre</h1>
                                <div class="transcript-subtitle text-uppercase"><?php echo $caOnlyReport ? 'Continuous Assessment Report' : 'Semester Exam Transcript'; ?></div>
                                <div class="header-divider"></div>
                            </div>

                            <!-- Student Info Card (3-Column Grid) -->
                            <div class="student-meta-container">
                                <div class="meta-grid">
                                    <div class="meta-item">
                                        <span class="meta-label">Student Name</span>
                                        <span class="meta-value"><?php echo htmlspecialchars(trim(($student['Fname'] ?? '') . ' ' . ($student['Lname'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Student ID</span>
                                        <span class="meta-value"><?php echo htmlspecialchars($sid, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Academic Period</span>
                                        <span class="meta-value"><?php echo htmlspecialchars($selectedYear, ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($selectedSemester, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Programme</span>
                                        <span class="meta-value"><?php echo htmlspecialchars((string)($student['program_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Assessment Rule</span>
                                        <span class="meta-value"><?php echo htmlspecialchars($caOnlyReport ? 'CA Marks Only (External Exam)' : (string)$policy['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Date Issued</span>
                                        <span class="meta-value"><?php echo date('d M Y'); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Report Content -->
                            <?php if ($caOnlyReport): ?>
                                <?php if (empty($caRecords)): ?>
                                    <div class="alert alert-info mb-0">No CA records were found for this semester.</div>
                                <?php else: ?>
                                    <div class="alert alert-info d-print-none mb-3">
                                        <i class="fas fa-info-circle me-2"></i>This programme uses external final examinations. The portal report below shows continuous assessment marks only.
                                    </div>
                                    <div class="table-responsive">
                                        <table class="transcript-table">
                                            <thead>
                                                <tr>
                                                    <th style="width: 5%;">#</th>
                                                    <th class="text-start" style="width: 15%;">Course Code</th>
                                                    <th class="text-start" style="width: 40%;">Course Name</th>
                                                    <th style="width: 10%;">Credits</th>
                                                    <th style="width: 7%;">Assignment 1</th>
                                                    <th style="width: 7%;">Assignment 2</th>
                                                    <th style="width: 7%;">Test</th>
                                                    <th style="width: 9%;">Total CA</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $i = 1; foreach ($caRecords as $row): ?>
                                                    <tr>
                                                        <td class="text-center"><?php echo $i++; ?></td>
                                                        <td class="fw-bold"><?php echo htmlspecialchars((string)$row['Course_Code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars((string)$row['course_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td class="text-center"><?php echo htmlspecialchars((string)($row['credit_units'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td class="text-center"><?php echo student_transcript_number($row['A1'] ?? null); ?></td>
                                                        <td class="text-center"><?php echo student_transcript_number($row['A2'] ?? null); ?></td>
                                                        <td class="text-center"><?php echo student_transcript_number($row['T1'] ?? null); ?></td>
                                                        <td class="text-center fw-bold"><?php echo student_transcript_number($row['Total_CA'] ?? null); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr class="fw-bold bg-light">
                                                    <td colspan="3" class="text-end">Total Registered Credits:</td>
                                                    <td class="text-center"><?php echo $hasCaCredits ? number_format($totalCaCredits, 1) : '-'; ?></td>
                                                    <td colspan="4"></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php elseif (empty($records)): ?>
                                <div class="alert alert-info mb-0">No exam records were found for this semester.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="transcript-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 5%;">#</th>
                                                <th class="text-start" style="width: 15%;">Course Code</th>
                                                <th class="text-start" style="width: 40%;">Course Name</th>
                                                <th style="width: 8%;">Credits</th>
                                                <th style="width: 8%;">CA</th>
                                                <th style="width: 8%;">Exam</th>
                                                <th style="width: 10%;">Final Mark</th>
                                                <th style="width: 8%;">Grade</th>
                                                <th style="width: 8%;">Points</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($records as $row): ?>
                                                <tr>
                                                    <td class="text-center"><?php echo $i++; ?></td>
                                                    <td class="fw-bold"><?php echo htmlspecialchars((string)$row['Course_Code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)$row['course_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-center"><?php echo htmlspecialchars((string)($row['credit_units'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-center"><?php echo number_format((float)$row['Total_CA'], 2); ?></td>
                                                    <td class="text-center"><?php echo number_format((float)$row['Exam_marks'], 2); ?></td>
                                                    <td class="text-center fw-bold"><?php echo number_format((float)$row['final_mark'], 2); ?></td>
                                                    <td class="text-center fw-bold text-primary"><?php echo htmlspecialchars((string)$row['grade'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-center"><?php echo number_format((float)$row['points'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="fw-bold bg-light" style="border-top: 2px solid #1f2937;">
                                                <td colspan="3" class="text-end">Summary:</td>
                                                <td class="text-center"><?php echo $hasCredits ? number_format($totalCredits, 1) : '-'; ?></td>
                                                <td colspan="4" class="text-end">GPA:</td>
                                                <td class="text-center"><?php echo number_format($gpa, 2); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <!-- Signature Section -->
                            <?php if (!empty($records) || !empty($caRecords)): ?>
                                <div class="signature-section container-fluid">
                                    <div class="row">
                                        <div class="col-6 text-center">
                                            <div class="signature-line"></div>
                                            <strong style="color: #2d1e54;">Registrar</strong>
                                            <div class="text-muted small mt-1">Industrial Training Centre</div>
                                        </div>
                                        <div class="col-6 text-center">
                                            <div class="signature-line"></div>
                                            <strong style="color: #2d1e54;">Academic Office</strong>
                                            <div class="text-muted small mt-1">Official Stamp & Date</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Official notice footer -->
                            <div class="transcript-official-notice">
                                <p class="mb-1">This transcript is valid only when it bears the official signature of the Registrar and the institutional stamp.</p>
                                <p class="mb-0 text-uppercase fw-bold text-muted" style="font-size: 0.65rem; letter-spacing: 1px;">Industrial Training Centre • Academic Record • End of Record</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
