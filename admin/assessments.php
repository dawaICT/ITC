<?php
$page_title = 'Assessments';

include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/assessment_weighting_helpers.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php'; // wuc_result_exam_written / NE

function admin_assessments_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_assessments_selected($value, $selected): string
{
    return (string)$value === (string)$selected ? ' selected' : '';
}

function admin_assessments_mark($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float)$value, 2);
}

function admin_assessments_grade(float $total): string
{
    require_once dirname(__DIR__) . '/includes/grading_helpers.php';
    return wuc_result_grade($total);
}

function admin_assessments_period_label($period): string
{
    $period = trim((string)$period);
    if ($period === '') {
        return '';
    }
    if ($period === '3') {
        return 'Term 3';
    }
    return 'Semester ' . $period . ' / Term ' . $period;
}

function admin_assessments_year_options(mysqli $db): array
{
    $years = [];
    foreach ([['semester_assessment', 'Year'], ['exams', 'Year']] as $source) {
        [$table, $column] = $source;
        if (!assessment_weighting_column_exists($db, $table, $column)) {
            continue;
        }
        if ($result = @$db->query("SELECT DISTINCT `{$column}` AS value FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' ORDER BY `{$column}` DESC")) {
            while ($row = $result->fetch_assoc()) {
                $value = trim((string)($row['value'] ?? ''));
                if ($value !== '') {
                    $years[$value] = $value;
                }
            }
            $result->free();
        }
    }

    $currentYear = date('Y');
    $years[$currentYear] = $currentYear;
    uksort($years, static fn($a, $b) => (int)$b <=> (int)$a);
    return array_values($years);
}

function admin_assessments_period_options(mysqli $db): array
{
    $periods = [];
    foreach ([['semester_assessment', 'semester'], ['exams', 'semester'], ['academic_periods', 'period_number']] as $source) {
        [$table, $column] = $source;
        if (!assessment_weighting_column_exists($db, $table, $column)) {
            continue;
        }
        if ($result = @$db->query("SELECT DISTINCT `{$column}` AS value FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' ORDER BY CAST(`{$column}` AS UNSIGNED), `{$column}`")) {
            while ($row = $result->fetch_assoc()) {
                $value = trim((string)($row['value'] ?? ''));
                if ($value !== '') {
                    $periods[$value] = admin_assessments_period_label($value);
                }
            }
            $result->free();
        }
    }

    foreach (['1', '2', '3'] as $fallback) {
        if (!isset($periods[$fallback])) {
            $periods[$fallback] = admin_assessments_period_label($fallback);
        }
    }
    uksort($periods, static fn($a, $b) => (int)$a <=> (int)$b);
    return $periods;
}

function admin_assessments_course_options(mysqli $db): array
{
    $courses = [];
    foreach (['courses', 'program_courses'] as $table) {
        if (!assessment_weighting_column_exists($db, $table, 'course_code')) {
            continue;
        }
        $nameExpr = assessment_weighting_column_exists($db, $table, 'course_name') ? 'course_name' : 'course_code';
        if ($result = @$db->query("SELECT DISTINCT course_code, {$nameExpr} AS course_name FROM `{$table}` WHERE course_code IS NOT NULL AND course_code <> '' ORDER BY course_name")) {
            while ($row = $result->fetch_assoc()) {
                $code = trim((string)($row['course_code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $name = trim((string)($row['course_name'] ?? ''));
                $courses[$code] = $name !== '' ? $name : $code;
            }
            $result->free();
        }
    }

    asort($courses, SORT_NATURAL | SORT_FLAG_CASE);
    return $courses;
}

function admin_assessments_bind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
}

function admin_assessments_fetch_ca(mysqli $db, string $year, string $period, string $course): array
{
    if (!assessment_weighting_table_exists($db, 'semester_assessment')) {
        return [];
    }

    $conditions = [];
    $params = [];
    $types = '';

    if ($year !== '') {
        $conditions[] = 'sa.`Year` = ?';
        $params[] = $year;
        $types .= 's';
    }
    if ($period !== '') {
        $conditions[] = 'sa.semester = ?';
        $params[] = $period;
        $types .= 's';
    }
    if ($course !== '') {
        $conditions[] = 'sa.Course_Code COLLATE utf8mb4_general_ci = ?';
        $params[] = $course;
        $types .= 's';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $sql = "SELECT sa.Sid,
                   COALESCE(s.Fname, '') AS Fname,
                   COALESCE(s.Lname, '') AS Lname,
                   sa.Course_Code,
                   COALESCE(c.course_name, sa.Course_Code) AS course_name,
                   sa.A1, sa.A2, sa.A3, sa.T1, sa.T2, sa.Total_CA
            FROM semester_assessment sa
            LEFT JOIN students s
              ON s.SID COLLATE utf8mb4_general_ci = sa.Sid COLLATE utf8mb4_general_ci
            LEFT JOIN courses c
              ON c.course_code COLLATE utf8mb4_general_ci = sa.Course_Code COLLATE utf8mb4_general_ci
            {$where}
            ORDER BY s.Lname, s.Fname, sa.Sid, sa.Course_Code";

    if (!$stmt = @$db->prepare($sql)) {
        error_log('admin/assessments.php CA query prepare failed: ' . $db->error);
        return [];
    }
    admin_assessments_bind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function admin_assessments_fetch_exams(mysqli $db, string $year, string $period, string $course): array
{
    if (!assessment_weighting_table_exists($db, 'exams')) {
        return [];
    }

    $conditions = [];
    $params = [];
    $types = '';

    if ($year !== '') {
        $conditions[] = 'e.`Year` = ?';
        $params[] = $year;
        $types .= 's';
    }
    if ($period !== '') {
        $conditions[] = 'e.semester = ?';
        $params[] = $period;
        $types .= 's';
    }
    if ($course !== '') {
        $conditions[] = 'e.Course_Code COLLATE utf8mb4_general_ci = ?';
        $params[] = $course;
        $types .= 's';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $sql = "SELECT e.Sid,
                   COALESCE(s.Fname, '') AS Fname,
                   COALESCE(s.Lname, '') AS Lname,
                   e.Course_Code,
                   COALESCE(c.course_name, e.Course_Code) AS course_name,
                   COALESCE(sa.Total_CA, 0) AS Total_CA,
                   COALESCE(e.Exam_marks, e.Total_marks) AS Exam_marks
            FROM exams e
            LEFT JOIN students s
              ON s.SID COLLATE utf8mb4_general_ci = e.Sid COLLATE utf8mb4_general_ci
            LEFT JOIN courses c
              ON c.course_code COLLATE utf8mb4_general_ci = e.Course_Code COLLATE utf8mb4_general_ci
            LEFT JOIN semester_assessment sa
              ON sa.Sid COLLATE utf8mb4_general_ci = e.Sid COLLATE utf8mb4_general_ci
             AND sa.Course_Code COLLATE utf8mb4_general_ci = e.Course_Code COLLATE utf8mb4_general_ci
             AND sa.`Year` = e.`Year`
             AND sa.semester = e.semester
            {$where}
            ORDER BY s.Lname, s.Fname, e.Sid, e.Course_Code";

    if (!$stmt = @$db->prepare($sql)) {
        error_log('admin/assessments.php exam query prepare failed: ' . $db->error);
        return [];
    }
    admin_assessments_bind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

$yearOptions = admin_assessments_year_options($db);
$periodOptions = admin_assessments_period_options($db);
$courseOptions = admin_assessments_course_options($db);

$selectedYear = trim((string)($_GET['academic_year'] ?? date('Y')));
$selectedPeriod = trim((string)($_GET['semester'] ?? ''));
$selectedCourse = trim((string)($_GET['course'] ?? ''));
$caRows = isset($_GET['filter_ca']) ? admin_assessments_fetch_ca($db, $selectedYear, $selectedPeriod, $selectedCourse) : [];
$examRows = isset($_GET['filter_exam']) ? admin_assessments_fetch_exams($db, $selectedYear, $selectedPeriod, $selectedCourse) : [];

require "includes/header.php";
?>

<style>
.assessment-page {
    color: #172033;
}
.assessment-page .dashboard-header,
.assessment-page .data-table-card {
    background: #fff;
    border: 1px solid #e6eaf2;
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
}
.assessment-page .card-header {
    background: #f8fafc;
    border-bottom: 1px solid #e6eaf2;
    color: #172033;
}
.assessment-page .form-label {
    color: #344054;
    font-weight: 600;
}
.assessment-page .table thead th {
    white-space: nowrap;
}
.assessment-page .table td {
    vertical-align: middle;
}
.assessment-page .actions-row {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
}
</style>

<div class="container-fluid px-4 portal-dashboard assessment-page">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">View CA and Exams</h1>
                <p class="text-muted mb-0">View and analyze student continuous assessment and exam results.</p>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['successMsg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo admin_assessments_h($_SESSION['successMsg']); unset($_SESSION['successMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['errorMsg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo admin_assessments_h($_SESSION['errorMsg']); unset($_SESSION['errorMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-tasks me-2"></i>Continuous Assessment Results</h5>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3 mb-4" id="caForm">
                <input type="hidden" name="type" value="ca">
                <div class="col-lg-4 col-md-6">
                    <label for="ca_academic_year" class="form-label">Academic Year</label>
                    <select class="form-select" id="ca_academic_year" name="academic_year" required>
                        <?php foreach ($yearOptions as $year): ?>
                            <option value="<?php echo admin_assessments_h($year); ?>"<?php echo admin_assessments_selected($year, $selectedYear); ?>>
                                <?php echo admin_assessments_h($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-4 col-md-6">
                    <label for="ca_semester" class="form-label">Semester / Term</label>
                    <select class="form-select" id="ca_semester" name="semester" required>
                        <option value="" disabled<?php echo $selectedPeriod === '' ? ' selected' : ''; ?>>Select semester or term</option>
                        <?php foreach ($periodOptions as $value => $label): ?>
                            <option value="<?php echo admin_assessments_h($value); ?>"<?php echo admin_assessments_selected($value, $selectedPeriod); ?>>
                                <?php echo admin_assessments_h($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-4 col-md-12">
                    <label for="ca_course" class="form-label">Course</label>
                    <select class="form-select" id="ca_course" name="course" required>
                        <option value="" disabled<?php echo $selectedCourse === '' ? ' selected' : ''; ?>>Select course</option>
                        <?php foreach ($courseOptions as $code => $name): ?>
                            <option value="<?php echo admin_assessments_h($code); ?>"<?php echo admin_assessments_selected($code, $selectedCourse); ?>>
                                <?php echo admin_assessments_h($code . ' - ' . $name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 actions-row">
                    <button type="submit" name="filter_ca" class="btn btn-primary">
                        <i class="fas fa-filter me-2"></i>View CA Results
                    </button>
                    <button type="button" class="btn btn-outline-primary" onclick="exportToExcel('caTable')">
                        <i class="fas fa-file-excel me-2"></i>Export CA
                    </button>
                    <button type="button" class="btn btn-outline-primary" onclick="printResults('caTable', 'CA Results')">
                        <i class="fas fa-print me-2"></i>Print CA
                    </button>
                </div>
            </form>

            <?php if (isset($_GET['filter_ca'])): ?>
                <div class="table-responsive mt-4">
                    <table class="table table-hover align-middle" id="caTable">
                        <thead class="table-light">
                            <tr>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Assignment 1</th>
                                <th>Assignment 2</th>
                                <th>Assignment 3</th>
                                <th>Test 1</th>
                                <th>Test 2</th>
                                <th>Total CA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($caRows)): ?>
                                <?php foreach ($caRows as $row): ?>
                                    <tr>
                                        <td><?php echo admin_assessments_h($row['Sid']); ?></td>
                                        <td><?php echo admin_assessments_h(trim(($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? '')) ?: '-'); ?></td>
                                        <td><?php echo admin_assessments_h($row['Course_Code']); ?></td>
                                        <td><?php echo admin_assessments_h($row['course_name']); ?></td>
                                        <td><?php echo admin_assessments_mark($row['A1']); ?></td>
                                        <td><?php echo admin_assessments_mark($row['A2']); ?></td>
                                        <td><?php echo admin_assessments_mark($row['A3']); ?></td>
                                        <td><?php echo admin_assessments_mark($row['T1']); ?></td>
                                        <td><?php echo admin_assessments_mark($row['T2']); ?></td>
                                        <td><?php echo admin_assessments_mark($row['Total_CA']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="10" class="text-center text-muted py-4">No CA results found for the selected filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Examination Results</h5>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3 mb-4" id="examForm">
                <input type="hidden" name="type" value="exam">
                <div class="col-lg-4 col-md-6">
                    <label for="exam_academic_year" class="form-label">Academic Year</label>
                    <select class="form-select" id="exam_academic_year" name="academic_year" required>
                        <?php foreach ($yearOptions as $year): ?>
                            <option value="<?php echo admin_assessments_h($year); ?>"<?php echo admin_assessments_selected($year, $selectedYear); ?>>
                                <?php echo admin_assessments_h($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-4 col-md-6">
                    <label for="exam_semester" class="form-label">Semester / Term</label>
                    <select class="form-select" id="exam_semester" name="semester" required>
                        <option value="" disabled<?php echo $selectedPeriod === '' ? ' selected' : ''; ?>>Select semester or term</option>
                        <?php foreach ($periodOptions as $value => $label): ?>
                            <option value="<?php echo admin_assessments_h($value); ?>"<?php echo admin_assessments_selected($value, $selectedPeriod); ?>>
                                <?php echo admin_assessments_h($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-4 col-md-12">
                    <label for="exam_course" class="form-label">Course</label>
                    <select class="form-select" id="exam_course" name="course" required>
                        <option value="" disabled<?php echo $selectedCourse === '' ? ' selected' : ''; ?>>Select course</option>
                        <?php foreach ($courseOptions as $code => $name): ?>
                            <option value="<?php echo admin_assessments_h($code); ?>"<?php echo admin_assessments_selected($code, $selectedCourse); ?>>
                                <?php echo admin_assessments_h($code . ' - ' . $name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 actions-row">
                    <button type="submit" name="filter_exam" class="btn btn-primary">
                        <i class="fas fa-filter me-2"></i>View Exam Results
                    </button>
                    <button type="button" class="btn btn-outline-primary" onclick="exportToExcel('examTable')">
                        <i class="fas fa-file-excel me-2"></i>Export Exam
                    </button>
                    <button type="button" class="btn btn-outline-primary" onclick="printResults('examTable', 'Exam Results')">
                        <i class="fas fa-print me-2"></i>Print Exam
                    </button>
                </div>
            </form>

            <?php if (isset($_GET['filter_exam'])): ?>
                <div class="table-responsive mt-4">
                    <table class="table table-hover align-middle" id="examTable">
                        <thead class="table-light">
                            <tr>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>CA Score</th>
                                <th>Exam Score</th>
                                <th>Final Total</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($examRows)): ?>
                                <?php foreach ($examRows as $row): ?>
                                    <?php
                                    // A final mark/grade is only meaningful once the exam was sat.
                                    // Without an exam mark, show NE rather than a bogus CA-only total/F.
                                    $wroteExam = wuc_result_exam_written($row['Exam_marks']);
                                    $finalTotal = $wroteExam
                                        ? assessment_weighting_total($db, (string)$row['Sid'], $row['Total_CA'], $row['Exam_marks'])
                                        : null;
                                    ?>
                                    <tr>
                                        <td><?php echo admin_assessments_h($row['Sid']); ?></td>
                                        <td><?php echo admin_assessments_h(trim(($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? '')) ?: '-'); ?></td>
                                        <td><?php echo admin_assessments_h($row['Course_Code']); ?></td>
                                        <td><?php echo admin_assessments_h($row['course_name']); ?></td>
                                        <td><?php echo admin_assessments_mark($row['Total_CA']); ?></td>
                                        <td><?php echo $wroteExam ? admin_assessments_mark($row['Exam_marks']) : WUC_RESULT_NOT_EXAMINED; ?></td>
                                        <td><?php echo $wroteExam ? admin_assessments_mark($finalTotal) : WUC_RESULT_NOT_EXAMINED; ?></td>
                                        <td><span class="badge text-bg-primary"><?php echo $wroteExam ? admin_assessments_h(admin_assessments_grade((float)$finalTotal)) : WUC_RESULT_NOT_EXAMINED; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">No exam results found for the selected filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Individual Student Transcript</h5>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="student_transcript.php" class="row g-3">
                <div class="col-md-6">
                    <label for="student_id" class="form-label">Student ID</label>
                    <input type="text" class="form-control" id="student_id" name="student_id" placeholder="Enter student ID" required>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>View Complete Transcript
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function exportToExcel(tableId) {
    const table = document.getElementById(tableId);
    if (!table) {
        alert('Filter results before exporting.');
        return;
    }
    if (!window.XLSX || !window.saveAs) {
        alert('Export libraries are still loading. Please try again.');
        return;
    }

    const wb = XLSX.utils.table_to_book(table, {sheet: "Results"});
    const wbout = XLSX.write(wb, {bookType: 'xlsx', type: 'binary'});

    function s2ab(s) {
        const buf = new ArrayBuffer(s.length);
        const view = new Uint8Array(buf);
        for (let i = 0; i < s.length; i++) view[i] = s.charCodeAt(i) & 0xFF;
        return buf;
    }

    const fileName = tableId === 'caTable' ? 'ca_results.xlsx' : 'exam_results.xlsx';
    saveAs(new Blob([s2ab(wbout)], {type: "application/octet-stream"}), fileName);
}

function printResults(tableId, title) {
    const table = document.getElementById(tableId);
    if (!table) {
        alert('Filter results before printing.');
        return;
    }

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>${title}</title>
            <style>
                body { font-family: Arial, sans-serif; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f8f9fa; }
                .header { text-align: center; margin-bottom: 20px; }
                .logo-watermark {
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    opacity: 0.1;
                    z-index: -1;
                    width: 300px;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>${title}</h2>
                <p>Generated on ${new Date().toLocaleDateString()}</p>
            </div>
            <img src="/wucportal/assets/images/logo.png" class="logo-watermark">
            ${table.outerHTML}
        </body>
        </html>
    `);

    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
    printWindow.close();
}
</script>

<script src="https://unpkg.com/xlsx/dist/xlsx.full.min.js"></script>
<script src="https://unpkg.com/file-saver/dist/FileSaver.min.js"></script>

<?php require_once "includes/footer.php"; ?>
