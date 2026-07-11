<?php
$page_title = 'View CA Results';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/ca_helpers.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';

ca_ensure_schema($db);

function ca_results_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ca_results_grade($mark): string
{
    if ($mark === null || $mark === '' || (float)$mark == 0.0) {
        return 'NE';
    }
    require_once dirname(__DIR__) . '/includes/grading_helpers.php';
    return wuc_result_grade((float)$mark);
}

function ca_results_display($value): string
{
    return ($value === null || $value === '') ? '-' : number_format((float)$value, 2) . '%';
}

function ca_results_total(object $row): ?float
{
    if (isset($row->Total_CA) && $row->Total_CA !== null && $row->Total_CA !== '') {
        return (float)$row->Total_CA;
    }

    return ca_calculate_total_ca([
        'A1' => $row->A1 ?? null,
        'A2' => $row->A2 ?? null,
        'A3' => $row->A3 ?? null,
        'T1' => $row->T1 ?? null,
        'T2' => $row->T2 ?? null,
    ]);
}

function ca_results_load(mysqli $db, string $courseCode, string $year, string $term, string $program = ''): array
{
    $records = [];
    $sql = "SELECT sa.*, COALESCE(st.Fname, '') AS Fname, COALESCE(st.Lname, '') AS Lname,
                   COALESCE(st.program, '') AS Program, COALESCE(p.program_name, '') AS ProgramName
            FROM semester_assessment sa
            LEFT JOIN students st ON st.SID = sa.Sid
            LEFT JOIN programs p ON p.program_code = st.program
            WHERE sa.Course_Code = ?
              AND sa.Year = ?
              AND sa.semester = ?";
    $types = 'sss';
    $params = [$courseCode, $year, $term];
    if ($program !== '') {
        $sql .= " AND st.program = ?";
        $types .= 's';
        $params[] = $program;
    }
    $sql .= " ORDER BY COALESCE(st.program, ''), sa.Sid";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        if ($res = $stmt->get_result()) {
            while ($row = $res->fetch_object()) {
                $records[] = $row;
            }
        }
        $stmt->close();
    } else {
        error_log('lecturers/viewCaRes.php: result query prepare failed: ' . $db->error);
    }

    return $records;
}

/**
 * Normalise a stored period_type / program_type value to one of:
 *   'term'     -> term-based program (periods 1-3)
 *   'semester' -> semester-based program (periods 1-2)
 *   'period'   -> unknown, keep the widest set (1-3) and a neutral label
 */
function ca_results_period_type(?string $raw): string
{
    $value = strtolower(trim((string)$raw));
    if ($value === '') {
        return 'period';
    }
    if (str_contains($value, 'term')) {
        return 'term';
    }
    if (str_contains($value, 'semester') || str_contains($value, 'sem')) {
        return 'semester';
    }
    return 'period';
}

/** Human label for the period field given a detected type. */
function ca_results_period_label(string $type): string
{
    return ['term' => 'Term', 'semester' => 'Semester'][$type] ?? 'Term / Semester';
}

/**
 * Selectable period values + labels for a detected type.
 * term     -> 1,2,3   semester -> 1,2   unknown -> 1,2,3 (don't hide options).
 * @return array<string,string> value => label
 */
function ca_results_period_options(string $type): array
{
    $word = $type === 'semester' ? 'Semester' : ($type === 'term' ? 'Term' : 'Period');
    $count = $type === 'semester' ? 2 : 3;
    $options = [];
    for ($i = 1; $i <= $count; $i++) {
        $options[(string)$i] = $word . ' ' . $i;
    }
    return $options;
}

/**
 * Build the cascading Academic Year -> Program -> Course catalogue for the
 * filter, scoped to what the lecturer is actually ASSIGNED to teach. Picking a
 * program then narrows the Course dropdown to that program's assigned courses
 * (handled client-side from the same data), and the period field adapts to each
 * program's detected period type (term vs semester).
 *
 * Assignment scoping (so only assigned programs/courses appear):
 *   1. course_lecturer.program_code  — explicit per-program assignment.
 *   2. program_courses               — the curriculum: which programs include
 *                                      the lecturer's assigned courses.
 *   3. Fallback ONLY for assigned courses left unmapped by 1-2: the programs
 *      whose students are registered / assessed for that course, so an assigned
 *      course is never orphaned out of the filter.
 *
 * @param array  $courseMap [course_code => course_name] of the lecturer's assigned courses.
 * @param string $staffId   the lecturer.
 * @return array{
 *   programs: array<string,string>,
 *   coursesByProgram: array<string,array<string,string>>,
 *   periodTypeByProgram: array<string,string>
 * }
 */
function ca_results_course_catalog(mysqli $db, array $courseMap, string $staffId): array
{
    $programs = [];          // program_code => program_name
    $coursesByProgram = [];  // program_code => [course_code => course_label]

    $codes = array_values(array_filter(array_map('strval', array_keys($courseMap)), static fn($c) => $c !== ''));
    if (!$codes) {
        return ['programs' => [], 'coursesByProgram' => [], 'periodTypeByProgram' => []];
    }

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));

    $register = static function (string $programCode, string $courseCode, string $programName) use (&$programs, &$coursesByProgram, $courseMap): void {
        $programCode = trim($programCode);
        $courseCode = trim($courseCode);
        if ($programCode === '' || $courseCode === '' || !array_key_exists($courseCode, $courseMap)) {
            return;
        }
        $courseName = (string)($courseMap[$courseCode] ?? '');
        $coursesByProgram[$programCode][$courseCode] = $courseName !== '' && $courseName !== $courseCode
            ? $courseCode . ' - ' . $courseName
            : $courseCode;
        if (!isset($programs[$programCode]) || ($programs[$programCode] === '' && $programName !== '')) {
            $programs[$programCode] = $programName;
        }
    };

    // 1. Explicit per-program assignment from course_lecturer — only when that
    //    optional column exists. The canonical schema has no course_lecturer.program_code,
    //    and referencing a missing column makes prepare() throw under mysqli exception
    //    mode (the @ suppresses warnings, NOT exceptions), which fatals the page.
    $clHasProgramCode = false;
    if ($probe = @$db->query("SHOW COLUMNS FROM course_lecturer LIKE 'program_code'")) {
        $clHasProgramCode = $probe->num_rows > 0;
        $probe->free();
    }
    if ($clHasProgramCode) {
        $sql = "SELECT cl.program_code AS code, cl.course_code AS course, COALESCE(p.program_name, '') AS pname
                FROM course_lecturer cl
                LEFT JOIN programs p ON p.program_code = cl.program_code
                WHERE cl.staff_id = ? AND cl.program_code IS NOT NULL AND cl.program_code <> ''
                  AND cl.course_code IN ($placeholders)";
        if ($stmt = @$db->prepare($sql)) {
            $stmt->bind_param('s' . $types, ...array_merge([$staffId], $codes));
            if (@$stmt->execute() && ($res = $stmt->get_result())) {
                while ($row = $res->fetch_assoc()) {
                    $register((string)$row['code'], (string)$row['course'], (string)$row['pname']);
                }
            }
            $stmt->close();
        }
    }

    // 2. Curriculum: programs whose syllabus includes the assigned courses.
    $sql = "SELECT pc.program_code AS code, pc.course_code AS course, COALESCE(p.program_name, '') AS pname
            FROM program_courses pc
            LEFT JOIN programs p ON p.program_code = pc.program_code
            WHERE pc.course_code IN ($placeholders)";
    if ($stmt = @$db->prepare($sql)) {
        $stmt->bind_param($types, ...$codes);
        if (@$stmt->execute() && ($res = $stmt->get_result())) {
            while ($row = $res->fetch_assoc()) {
                $register((string)$row['code'], (string)$row['course'], (string)$row['pname']);
            }
        }
        $stmt->close();
    }

    // 3. Fallback only for assigned courses still not mapped to any program.
    $covered = [];
    foreach ($coursesByProgram as $list) {
        foreach (array_keys($list) as $c) {
            $covered[$c] = true;
        }
    }
    $unmapped = array_values(array_diff($codes, array_keys($covered)));
    if ($unmapped) {
        $ph2 = implode(',', array_fill(0, count($unmapped), '?'));
        $t2 = str_repeat('s', count($unmapped));

        if (!function_exists('wuc_table_exists') || wuc_table_exists($db, 'course_registration')) {
            $sql = "SELECT DISTINCT st.program AS code, cr.course_code AS course, COALESCE(p.program_name, '') AS pname
                    FROM course_registration cr
                    JOIN students st ON st.SID = cr.Sid
                    LEFT JOIN programs p ON p.program_code = st.program
                    WHERE cr.course_code IN ($ph2) AND st.program IS NOT NULL AND st.program <> ''";
            if ($stmt = @$db->prepare($sql)) {
                $stmt->bind_param($t2, ...$unmapped);
                if (@$stmt->execute() && ($res = $stmt->get_result())) {
                    while ($row = $res->fetch_assoc()) {
                        $register((string)$row['code'], (string)$row['course'], (string)$row['pname']);
                    }
                }
                $stmt->close();
            }
        }

        // Recompute and try CA records for any still-unmapped assigned course.
        $covered = [];
        foreach ($coursesByProgram as $list) {
            foreach (array_keys($list) as $c) {
                $covered[$c] = true;
            }
        }
        $unmapped = array_values(array_diff($codes, array_keys($covered)));
        if ($unmapped) {
            $ph3 = implode(',', array_fill(0, count($unmapped), '?'));
            $t3 = str_repeat('s', count($unmapped));
            $sql = "SELECT DISTINCT st.program AS code, sa.Course_Code AS course, COALESCE(p.program_name, '') AS pname
                    FROM semester_assessment sa
                    JOIN students st ON st.SID = sa.Sid
                    LEFT JOIN programs p ON p.program_code = st.program
                    WHERE sa.Course_Code IN ($ph3) AND st.program IS NOT NULL AND st.program <> ''";
            if ($stmt = @$db->prepare($sql)) {
                $stmt->bind_param($t3, ...$unmapped);
                if (@$stmt->execute() && ($res = $stmt->get_result())) {
                    while ($row = $res->fetch_assoc()) {
                        $register((string)$row['code'], (string)$row['course'], (string)$row['pname']);
                    }
                }
                $stmt->close();
            }
        }
    }

    // Detect each program's period type (term vs semester).
    $periodTypeByProgram = [];
    if ($programs) {
        $pcodes = array_keys($programs);
        $pph = implode(',', array_fill(0, count($pcodes), '?'));
        $pt = str_repeat('s', count($pcodes));
        // semester_registration is the authoritative source for period_type.
        if (!function_exists('wuc_table_exists') || wuc_table_exists($db, 'semester_registration')) {
            $sql = "SELECT program_code, period_type, COUNT(*) AS c
                    FROM semester_registration
                    WHERE program_code IN ($pph)
                    GROUP BY program_code, period_type
                    ORDER BY c DESC";
            if ($stmt = @$db->prepare($sql)) {
                $stmt->bind_param($pt, ...$pcodes);
                if (@$stmt->execute() && ($res = $stmt->get_result())) {
                    while ($row = $res->fetch_assoc()) {
                        $code = (string)$row['program_code'];
                        if (!isset($periodTypeByProgram[$code])) {
                            $periodTypeByProgram[$code] = ca_results_period_type((string)$row['period_type']);
                        }
                    }
                }
                $stmt->close();
            }
        }
        foreach ($pcodes as $code) {
            if (!isset($periodTypeByProgram[$code])) {
                $periodTypeByProgram[$code] = 'period';
            }
        }
    }

    // Build program labels (code - name) and sort programs + their courses.
    $programLabels = [];
    foreach ($programs as $code => $name) {
        $programLabels[$code] = ($name !== '' && $name !== $code) ? $code . ' - ' . $name : $code;
        if (isset($coursesByProgram[$code])) {
            asort($coursesByProgram[$code], SORT_NATURAL | SORT_FLAG_CASE);
        }
    }
    asort($programLabels, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'programs' => $programLabels,
        'coursesByProgram' => $coursesByProgram,
        'periodTypeByProgram' => $periodTypeByProgram,
    ];
}

function ca_results_csv(string $courseCode, string $term, string $year, array $records): void
{
    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="CA_Results_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $courseCode) . '_Term' . preg_replace('/[^A-Za-z0-9_-]/', '_', $term) . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $year) . '.csv"');
    }

    $output = fopen('php://output', 'w');
    fputcsv($output, ['No.', 'Student ID', 'Student Name', 'Program', 'A1 (%)', 'A2 (%)', 'A3 (%)', 'T1 (%)', 'T2 (%)', 'CA Total (%)', 'Grade']);
    $num = 1;
    foreach ($records as $row) {
        $total = ca_results_total($row);
        $studentName = trim((string)($row->Fname ?? '') . ' ' . (string)($row->Lname ?? ''));
        $programLabel = trim((string)($row->Program ?? ''));
        if ($programLabel !== '' && ($row->ProgramName ?? '') !== '' && $row->ProgramName !== $row->Program) {
            $programLabel .= ' - ' . (string)$row->ProgramName;
        }
        fputcsv($output, [
            $num++,
            $row->Sid ?? '',
            $studentName,
            $programLabel,
            $row->A1 === null ? '' : number_format((float)$row->A1, 2),
            $row->A2 === null ? '' : number_format((float)$row->A2, 2),
            $row->A3 === null ? '' : number_format((float)$row->A3, 2),
            $row->T1 === null ? '' : number_format((float)$row->T1, 2),
            $row->T2 === null ? '' : number_format((float)$row->T2, 2),
            $total === null ? '' : number_format($total, 2),
            ca_results_grade($total),
        ]);
    }
    fclose($output);
    exit;
}

$staffId = (string)($_SESSION['staff_id'] ?? '');
$courseDetails = getLecturerCourseDetails($db, $staffId);
$courseMap = [];
foreach ($courseDetails as $course) {
    $courseMap[(string)$course['course_code']] = (string)$course['course_name'];
}

$academicYears = [];
if ($res = $db->query("SELECT DISTINCT Year FROM semester_assessment WHERE Year IS NOT NULL AND Year <> '' ORDER BY Year DESC LIMIT 8")) {
    while ($row = $res->fetch_assoc()) {
        $academicYears[] = (string)$row['Year'];
    }
    $res->free();
}
$currentYear = date('Y');
foreach ([$currentYear, (string)((int)$currentYear - 1), (string)((int)$currentYear - 2)] as $yearOption) {
    if (!in_array($yearOption, $academicYears, true)) {
        $academicYears[] = $yearOption;
    }
}

$catalog = ca_results_course_catalog($db, $courseMap, $staffId);
$programOptions = $catalog['programs'];                 // program_code => label
$coursesByProgram = $catalog['coursesByProgram'];       // program_code => [course_code => label]
$periodTypeByProgram = $catalog['periodTypeByProgram']; // program_code => 'term'|'semester'|'period'

$source = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$isRequested = isset($source['submit']) || (($_GET['download'] ?? '') === 'csv');
$selectedCourse = trim((string)($source['Course_Code'] ?? ''));
$selectedTerm = trim((string)($source['term'] ?? ''));
$selectedYear = trim((string)($source['Year'] ?? ''));
$selectedProgram = trim((string)($source['program'] ?? ''));
// Courses available for the chosen program (drives the Course dropdown).
$programCourses = ($selectedProgram !== '' && isset($coursesByProgram[$selectedProgram]))
    ? $coursesByProgram[$selectedProgram]
    : [];
// Detected period type + selectable periods for the chosen program.
$selectedPeriodType = ($selectedProgram !== '' && isset($periodTypeByProgram[$selectedProgram]))
    ? $periodTypeByProgram[$selectedProgram]
    : 'period';
$periodLabel = ca_results_period_label($selectedPeriodType);
$periodOptions = ca_results_period_options($selectedPeriodType);
$records = [];
$errors = [];
$notice = '';

if ($isRequested) {
    if ($selectedYear === '' || $selectedProgram === '' || $selectedCourse === '' || $selectedTerm === '') {
        $errors[] = 'Select an academic year, program, course, and ' . strtolower($periodLabel) . '.';
    } elseif (!array_key_exists($selectedProgram, $programOptions)) {
        $errors[] = 'Program is invalid or not assigned to you.';
    } elseif (!isLecturerAssignedToCourse($db, $staffId, $selectedCourse)) {
        http_response_code(403);
        $errors[] = 'You are not assigned to this course.';
    } elseif (!array_key_exists($selectedCourse, $programCourses)) {
        $errors[] = 'The selected course is not offered in the chosen program.';
    } elseif (!preg_match('/^\d{4}$/', $selectedYear)) {
        // Academic year is the calendar year (4 digits); CA is stored under the
        // calendar academic_year, so this matches the data.
        $errors[] = 'Academic year is invalid.';
    } elseif (!array_key_exists($selectedTerm, $periodOptions)) {
        $errors[] = $periodLabel . ' is invalid for this program.';
    } else {
        $records = ca_results_load($db, $selectedCourse, $selectedYear, $selectedTerm, $selectedProgram);
        if (!$records) {
            $notice = 'No records found for the selected criteria.';
        }
    }
}

if (($_GET['download'] ?? '') === 'csv') {
    if ($errors) {
        if (!headers_sent()) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo implode(PHP_EOL, $errors);
        exit;
    }
    ca_results_csv($selectedCourse, $selectedTerm, $selectedYear, $records);
}

$validationWarnings = [];
foreach ($records as $row) {
    foreach (['A1', 'A2', 'A3', 'T1', 'T2'] as $component) {
        $value = $row->{$component} ?? null;
        if ($value !== null && $value !== '' && (float)$value > 100) {
            $validationWarnings[] = 'Student ' . (string)$row->Sid . ': ' . $component . ' score (' . (string)$value . ') exceeds 100';
        }
    }
}

$average = null;
$passed = 0;
if ($records) {
    $sum = 0.0;
    $count = 0;
    foreach ($records as $row) {
        $total = ca_results_total($row);
        if ($total !== null) {
            $sum += $total;
            $count++;
            if ($total >= 50) {
                $passed++;
            }
        }
    }
    $average = $count > 0 ? round($sum / $count, 2) : null;
}

require_once __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard ca-results-page lecturer-workflow-page">
    <div class="dashboard-header lecturer-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title">CA Results</h1>
                <p class="text-muted mb-0">Generate, print, and export continuous assessment grade sheets.</p>
            </div>
            <div class="col-auto header-actions">
                <a class="btn btn-outline-primary" href="upload_ca.php">
                    <i class="fas fa-upload me-2"></i>Enter CA
                </a>
                <a class="btn btn-primary" href="<?php echo $selectedCourse !== '' ? 'materials.php?code=' . urlencode($selectedCourse) : 'materials.php'; ?>">
                    <i class="fas fa-file-alt me-2"></i>Materials
                </a>
            </div>
        </div>
    </div>

    <div class="assignment-command-bar mb-4" role="navigation" aria-label="Assessment navigation">
        <a class="command-link" href="assessments.php"><i class="fas fa-file-alt"></i><span>Student Submissions</span></a>
        <a class="command-link" href="upload_ca.php"><i class="fas fa-upload"></i><span>Upload CA</span></a>
        <a class="command-link" href="post_assign.php"><i class="fas fa-tasks"></i><span>Post Assignments</span></a>
        <a class="command-link active" href="viewCaRes.php" aria-current="page"><i class="fas fa-eye"></i><span>View CA Results</span></a>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?>
                <div><i class="fas fa-exclamation-circle me-2"></i><?php echo ca_results_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($notice !== ''): ?>
        <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i><?php echo ca_results_h($notice); ?></div>
    <?php elseif ($isRequested): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Results retrieved successfully.</div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="data-table-card workflow-filter-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Grade Sheet Filter</h5>
                </div>
                <div class="card-body">
                    <form method="get" action="viewCaRes.php" class="needs-validation" novalidate>
                        <input type="hidden" name="submit" value="1">
                        <div class="mb-3">
                            <label class="form-label" for="Year">Academic Year</label>
                            <select class="form-select" id="Year" name="Year" required>
                                <option value="" disabled <?php echo $selectedYear === '' ? 'selected' : ''; ?>>Select academic year</option>
                                <?php foreach ($academicYears as $year): ?>
                                    <option value="<?php echo ca_results_h($year); ?>" <?php echo $selectedYear === $year ? 'selected' : ''; ?>>
                                        <?php echo ca_results_h($year); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Select an academic year.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="program">Program</label>
                            <select class="form-select" id="program" name="program" required>
                                <option value="" disabled <?php echo $selectedProgram === '' ? 'selected' : ''; ?>>Select program</option>
                                <?php foreach ($programOptions as $code => $label): ?>
                                    <option value="<?php echo ca_results_h($code); ?>" <?php echo $selectedProgram === (string)$code ? 'selected' : ''; ?>>
                                        <?php echo ca_results_h($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Select a program.</div>
                            <?php if (!$programOptions): ?>
                                <div class="form-text text-warning">No programs are linked to your assigned courses yet.</div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="Course_Code">Course</label>
                            <select class="form-select" id="Course_Code" name="Course_Code" required <?php echo $selectedProgram === '' ? 'disabled' : ''; ?>>
                                <option value="" disabled <?php echo $selectedCourse === '' ? 'selected' : ''; ?>>Select course</option>
                                <?php foreach ($programCourses as $code => $label): ?>
                                    <option value="<?php echo ca_results_h($code); ?>" <?php echo strcasecmp($selectedCourse, $code) === 0 ? 'selected' : ''; ?>>
                                        <?php echo ca_results_h($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Select a course in this program.</div>
                            <div class="form-text course-hint">Choose a program first to list its courses.</div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="term"><span class="period-label"><?php echo ca_results_h($periodLabel); ?></span></label>
                            <select class="form-select" id="term" name="term" required <?php echo $selectedProgram === '' ? 'disabled' : ''; ?>>
                                <option value="" disabled <?php echo $selectedTerm === '' ? 'selected' : ''; ?>>Select <?php echo ca_results_h(strtolower($periodLabel)); ?></option>
                                <?php foreach ($periodOptions as $periodValue => $periodText): ?>
                                    <option value="<?php echo ca_results_h($periodValue); ?>" <?php echo $selectedTerm === (string)$periodValue ? 'selected' : ''; ?>>
                                        <?php echo ca_results_h($periodText); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback period-feedback">Select a <?php echo ca_results_h(strtolower($periodLabel)); ?>.</div>
                            <div class="form-text period-hint">The period type adapts to the selected program.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Generate Grade Sheet
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="data-table-card h-100 workflow-stat-card">
                        <div class="card-body">
                            <span class="workflow-stat-value"><?php echo number_format(count($records)); ?></span>
                            <span class="workflow-stat-label">Students</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="data-table-card h-100 workflow-stat-card">
                        <div class="card-body">
                            <span class="workflow-stat-value"><?php echo $average === null ? '-' : number_format($average, 2) . '%'; ?></span>
                            <span class="workflow-stat-label">Average CA</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="data-table-card h-100 workflow-stat-card">
                        <div class="card-body">
                            <span class="workflow-stat-value"><?php echo number_format($passed); ?></span>
                            <span class="workflow-stat-label">At 50%+</span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($validationWarnings): ?>
                <div class="alert alert-warning">
                    <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Data Validation Warnings</h5>
                    <ul class="mb-0">
                        <?php foreach ($validationWarnings as $warning): ?>
                            <li><?php echo ca_results_h($warning); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($records): ?>
                <div id="printArea" class="data-table-card official-sheet">
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <img src="../images/itc_logo.png" alt="Institution logo" class="mb-2" style="height:72px;width:auto;max-width:100%">
                            <h4><strong>INDUSTRIAL TRAINING COLLEGE</strong></h4>
                            <h6><?php echo ca_results_h($selectedYear); ?> ACADEMIC YEAR - GRADE SHEET</h6>
                            <p class="mb-0">
                                <strong>Course:</strong> <?php echo ca_results_h($selectedCourse); ?>
                                <span class="mx-2">|</span>
                                <strong><?php echo ca_results_h($periodLabel); ?>:</strong> <?php echo ca_results_h($periodOptions[$selectedTerm] ?? $selectedTerm); ?>
                                <?php if ($selectedProgram !== ''): ?>
                                    <span class="mx-2">|</span>
                                    <strong>Program:</strong> <?php echo ca_results_h($programOptions[$selectedProgram] !== '' && $programOptions[$selectedProgram] !== $selectedProgram ? $selectedProgram . ' - ' . $programOptions[$selectedProgram] : $selectedProgram); ?>
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="table-responsive table-mobile-stack">
                            <table class="table table-hover align-middle text-center">
                                <thead class="table-light">
                                    <tr>
                                        <th rowspan="2">No.</th>
                                        <th rowspan="2">Student ID</th>
                                        <th rowspan="2">Student</th>
                                        <th rowspan="2">Program</th>
                                        <th colspan="5">Local Continuous Assessment Marks</th>
                                        <th colspan="2">Summary</th>
                                    </tr>
                                    <tr>
                                        <th>A1</th>
                                        <th>A2</th>
                                        <th>A3</th>
                                        <th>T1</th>
                                        <th>T2</th>
                                        <th>CA Total</th>
                                        <th>Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($records as $index => $row): ?>
                                        <?php
                                        $total = ca_results_total($row);
                                        $grade = ca_results_grade($total);
                                        $studentName = trim((string)($row->Fname ?? '') . ' ' . (string)($row->Lname ?? ''));
                                        $programCode = trim((string)($row->Program ?? ''));
                                        $programName = trim((string)($row->ProgramName ?? ''));
                                        ?>
                                        <tr>
                                            <td data-label="No."><?php echo $index + 1; ?></td>
                                            <td data-label="Student ID" class="text-start"><?php echo ca_results_h($row->Sid ?? ''); ?></td>
                                            <td data-label="Student" class="text-start"><?php echo ca_results_h($studentName !== '' ? $studentName : '-'); ?></td>
                                            <td data-label="Program" class="text-start">
                                                <?php if ($programCode === ''): ?>
                                                    -
                                                <?php elseif ($programName !== '' && $programName !== $programCode): ?>
                                                    <?php echo ca_results_h($programCode); ?><br><small class="text-muted"><?php echo ca_results_h($programName); ?></small>
                                                <?php else: ?>
                                                    <?php echo ca_results_h($programCode); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="A1"><?php echo ca_results_display($row->A1 ?? null); ?></td>
                                            <td data-label="A2"><?php echo ca_results_display($row->A2 ?? null); ?></td>
                                            <td data-label="A3"><?php echo ca_results_display($row->A3 ?? null); ?></td>
                                            <td data-label="T1"><?php echo ca_results_display($row->T1 ?? null); ?></td>
                                            <td data-label="T2"><?php echo ca_results_display($row->T2 ?? null); ?></td>
                                            <td data-label="CA Total" class="fw-bold text-primary"><?php echo $total === null ? '-' : number_format($total, 2) . '%'; ?></td>
                                            <td data-label="Grade">
                                                <span class="badge <?php echo ($total !== null && $total >= 50) ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo ca_results_h($grade); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-end d-print-none workflow-actions">
                    <a class="btn btn-success" href="viewCaRes.php?download=csv&Course_Code=<?php echo urlencode($selectedCourse); ?>&term=<?php echo urlencode($selectedTerm); ?>&Year=<?php echo urlencode($selectedYear); ?><?php echo $selectedProgram !== '' ? '&program=' . urlencode($selectedProgram) : ''; ?>">
                        <i class="fas fa-file-csv me-2"></i>Download CSV
                    </a>
                    <button class="btn btn-warning" type="button" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print Official Grade Sheet
                    </button>
                </div>
            <?php else: ?>
                <div class="data-table-card empty-workflow-card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                        <h5>No grade sheet loaded</h5>
                        <p class="text-muted mb-0">Choose an academic year, program, course, and term to generate CA results.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    document.querySelectorAll('.needs-validation').forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });

    // Cascade: Program -> Course + Period. Picking a program rebuilds the course
    // list and adapts the period field (Term vs Semester) to that program.
    var coursesByProgram = <?php echo json_encode($coursesByProgram, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
    var periodTypeByProgram = <?php echo json_encode($periodTypeByProgram, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
    var programSelect = document.getElementById('program');
    var courseSelect = document.getElementById('Course_Code');
    var termSelect = document.getElementById('term');
    var courseHint = document.querySelector('.course-hint');
    var periodLabelEl = document.querySelector('.period-label');
    var periodFeedbackEl = document.querySelector('.period-feedback');
    var termLabelEl = termSelect ? document.querySelector('label[for="term"]') : null;

    function periodLabelFor(type) {
        if (type === 'term') { return 'Term'; }
        if (type === 'semester') { return 'Semester'; }
        return 'Term / Semester';
    }
    function periodOptionsFor(type) {
        var word = type === 'semester' ? 'Semester' : (type === 'term' ? 'Term' : 'Period');
        var count = type === 'semester' ? 2 : 3;
        var out = [];
        for (var i = 1; i <= count; i++) { out.push([String(i), word + ' ' + i]); }
        return out;
    }

    function populateCourses(preserve) {
        if (!programSelect || !courseSelect) { return; }
        var program = programSelect.value;
        var courses = coursesByProgram[program] || {};
        var previous = preserve ? courseSelect.value : '';
        courseSelect.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = true;
        placeholder.textContent = program ? 'Select course' : 'Select a program first';
        courseSelect.appendChild(placeholder);

        var codes = Object.keys(courses);
        codes.forEach(function(code) {
            var opt = document.createElement('option');
            opt.value = code;
            opt.textContent = courses[code];
            if (code === previous) { opt.selected = true; placeholder.selected = false; }
            courseSelect.appendChild(opt);
        });

        courseSelect.disabled = !program;
        if (courseHint) {
            courseHint.textContent = !program
                ? 'Choose a program first to list its courses.'
                : (codes.length ? 'Select a course offered in this program.' : 'No courses found for this program.');
        }
    }

    function populatePeriods(preserve) {
        if (!programSelect || !termSelect) { return; }
        var program = programSelect.value;
        var type = periodTypeByProgram[program] || 'period';
        var label = periodLabelFor(type);
        var previous = preserve ? termSelect.value : '';
        termSelect.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = true;
        placeholder.textContent = program ? ('Select ' + label.toLowerCase()) : 'Select a program first';
        termSelect.appendChild(placeholder);

        periodOptionsFor(type).forEach(function(pair) {
            var opt = document.createElement('option');
            opt.value = pair[0];
            opt.textContent = pair[1];
            if (pair[0] === previous) { opt.selected = true; placeholder.selected = false; }
            termSelect.appendChild(opt);
        });

        termSelect.disabled = !program;
        if (periodLabelEl) { periodLabelEl.textContent = label; }
        if (termLabelEl && !periodLabelEl) { termLabelEl.textContent = label; }
        if (periodFeedbackEl) { periodFeedbackEl.textContent = 'Select a ' + label.toLowerCase() + '.'; }
    }

    if (programSelect) {
        programSelect.addEventListener('change', function() {
            populateCourses(false);
            populatePeriods(false);
        });
        // On initial load, keep any server-selected values (e.g. after submit/validation).
        populateCourses(true);
        populatePeriods(true);
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
