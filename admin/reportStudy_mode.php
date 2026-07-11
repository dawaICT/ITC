<?php
require "includes/admin.php";
require_once __DIR__ . '/../includes/report_print.php';
require_once __DIR__ . '/../includes/ai_portal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$page_title = 'Admitted Students by Study Mode';
$records = [];
$summary = [
    'total' => 0,
    'male' => 0,
    'female' => 0,
    'program' => 0,
    'short_course' => 0,
];
$mode_summary = [];
$number = 1;
$errors = [];
$report_generated = isset($_POST['search']) || isset($_GET['search']);

function study_mode_report_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function study_mode_report_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function study_mode_report_key($mode): string
{
    $mode = strtolower(trim((string)$mode));
    $mode = preg_replace('/[\s_-]+/', ' ', $mode);
    return $mode ?: 'not set';
}

function study_mode_report_label($mode): string
{
    $key = study_mode_report_key($mode);
    $labels = [
        'full time' => 'Full Time',
        'fulltime' => 'Full Time',
        'part time' => 'Part Time',
        'part time(evening)' => 'Part Time (Evening)',
        'distance' => 'Distance',
        'online' => 'Online',
        'blended' => 'Blended',
        'short course' => 'Short Course',
        'not set' => 'Not Set',
    ];

    return $labels[$key] ?? ucwords($key);
}

function study_mode_report_bind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') {
        return;
    }
    $bind = [$types];
    foreach ($params as $idx => $value) {
        $params[$idx] = $value;
        $bind[] = &$params[$idx];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

$program_options = [];
if (study_mode_report_table_exists($db, 'programs')) {
    $where = '';
    if ($res = @$db->query("SHOW COLUMNS FROM programs LIKE 'is_active'")) {
        $where = $res->num_rows > 0 ? 'WHERE COALESCE(is_active, 1) = 1' : '';
        $res->free();
    }
    if ($result = $db->query("SELECT program_code, program_name FROM programs {$where} ORDER BY program_name")) {
        while ($row = $result->fetch_assoc()) {
            $program_options[] = $row;
        }
        $result->free();
    }
}

$academic_years = [];
$yearSql = "SELECT DISTINCT year_value FROM (
        SELECT NULLIF(academic_year, '') AS year_value FROM students
        UNION
        SELECT NULLIF(academic_year, '') AS year_value FROM student_program
        UNION
        SELECT startYear AS year_value FROM student_program
        UNION
        SELECT YEAR(enrollment_date) AS year_value FROM short_course_enrollments
        UNION
        SELECT YEAR(created_at) AS year_value FROM short_course_enrollments
    ) y
    WHERE year_value IS NOT NULL AND year_value <> ''
    ORDER BY year_value DESC";
if ($result = @$db->query($yearSql)) {
    while ($row = $result->fetch_assoc()) {
        if (ctype_digit((string)$row['year_value'])) {
            $academic_years[(int)$row['year_value']] = (int)$row['year_value'];
        }
    }
    $result->free();
}
krsort($academic_years, SORT_NUMERIC);
$academic_years = array_values($academic_years);

$mode_options = [];
$modeSources = [
    "SELECT mode AS mode_value FROM student_program",
    "SELECT mode AS mode_value FROM students",
    "SELECT study_mode AS mode_value FROM programs",
    "SELECT delivery_mode AS mode_value FROM short_courses",
];
foreach ($modeSources as $modeSql) {
    if ($result = @$db->query($modeSql)) {
        while ($row = $result->fetch_assoc()) {
            $key = study_mode_report_key($row['mode_value'] ?? '');
            $mode_options[$key] = study_mode_report_label($row['mode_value'] ?? '');
        }
        $result->free();
    }
}
ksort($mode_options, SORT_NATURAL | SORT_FLAG_CASE);

$reqData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$filters = [
    'mode' => trim((string)($reqData['mode'] ?? 'all')),
    'source' => trim((string)($reqData['source'] ?? 'all')),
    'program_code' => trim((string)($reqData['program_code'] ?? '')),
    'academic_year' => trim((string)($reqData['academic_year'] ?? 'all')),
    'gender' => trim((string)($reqData['gender'] ?? '')),
    'status' => trim((string)($reqData['status'] ?? 'all')),
];

if ($filters['mode'] !== 'all' && !isset($mode_options[$filters['mode']])) {
    $filters['mode'] = 'all';
}
if (!in_array($filters['source'], ['all', 'program', 'short_course'], true)) {
    $filters['source'] = 'all';
}
if ($filters['academic_year'] !== 'all' && !ctype_digit($filters['academic_year'])) {
    $filters['academic_year'] = 'all';
}
if (!in_array($filters['gender'], ['', 'M', 'F'], true)) {
    $filters['gender'] = '';
}
if (!in_array($filters['status'], ['all', 'active', 'inactive'], true)) {
    $filters['status'] = 'all';
}
if ($filters['source'] === 'short_course') {
    $filters['program_code'] = '';
}

if ($report_generated) {
    $queries = [];
    $types = '';
    $params = [];

    if (in_array($filters['source'], ['all', 'program'], true)
        && study_mode_report_table_exists($db, 'students')
        && study_mode_report_table_exists($db, 'student_program')) {
        $programWhere = ["1=1"];
        if ($filters['program_code'] !== '') {
            $programWhere[] = "sp.program_code = ?";
            $types .= 's';
            $params[] = $filters['program_code'];
        }
        if ($filters['academic_year'] !== 'all') {
            $programWhere[] = "COALESCE(NULLIF(sp.academic_year, ''), NULLIF(s.academic_year, ''), sp.startYear) = ?";
            $types .= 'i';
            $params[] = (int)$filters['academic_year'];
        }
        if ($filters['gender'] !== '') {
            $programWhere[] = "s.sex = ?";
            $types .= 's';
            $params[] = $filters['gender'];
        }
        if ($filters['status'] !== 'all') {
            $programWhere[] = "COALESCE(s.status, 'active') = ?";
            $types .= 's';
            $params[] = $filters['status'];
        }

        $queries[] = "SELECT
                CONVERT(CONCAT('program:', sp.id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS row_key,
                CONVERT('program' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
                CONVERT(s.SID USING utf8mb4) COLLATE utf8mb4_unicode_ci AS SID,
                CONVERT(COALESCE(s.Fname, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Fname,
                CONVERT(COALESCE(s.Lname, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Lname,
                CONVERT(COALESCE(s.sex, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS sex,
                CONVERT(COALESCE(p.program_name, sp.program_code, s.program, 'Unassigned Program') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS program_name,
                CONVERT(COALESCE(sp.program_code, s.program, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS program_code,
                CONVERT(COALESCE(NULLIF(sp.mode, ''), NULLIF(s.mode, ''), NULLIF(p.study_mode, ''), 'Not set') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS study_mode,
                CONVERT(COALESCE(NULLIF(sp.intake, ''), NULLIF(s.intake, ''), 'Not set') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS intake,
                COALESCE(NULLIF(s.year, 0), sp.startYear) AS year_of_study,
                NULL AS semester,
                COALESCE(NULLIF(sp.academic_year, ''), NULLIF(s.academic_year, ''), sp.startYear) AS academic_year,
                CONVERT(COALESCE(s.status, 'active') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS record_status
            FROM student_program sp
            INNER JOIN students s ON TRIM(UPPER(s.SID)) = TRIM(UPPER(sp.Sid))
            LEFT JOIN programs p ON TRIM(UPPER(p.program_code)) = TRIM(UPPER(sp.program_code))
            WHERE " . implode(' AND ', $programWhere);
    }

    if (in_array($filters['source'], ['all', 'short_course'], true)
        && study_mode_report_table_exists($db, 'short_courses')
        && study_mode_report_table_exists($db, 'short_course_enrollments')) {
        $shortWhere = ["1=1"];
        if ($filters['academic_year'] !== 'all') {
            $shortWhere[] = "YEAR(COALESCE(e.enrollment_date, e.created_at, sc.start_date)) = ?";
            $types .= 'i';
            $params[] = (int)$filters['academic_year'];
        }
        if ($filters['gender'] !== '') {
            $shortWhere[] = "s.sex = ?";
            $types .= 's';
            $params[] = $filters['gender'];
        }
        if ($filters['status'] !== 'all') {
            $shortWhere[] = "COALESCE(e.status, 'enrolled') = ?";
            $types .= 's';
            $params[] = $filters['status'];
        }

        $queries[] = "SELECT
                CONVERT(CONCAT('short:', e.id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS row_key,
                CONVERT('short_course' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
                CONVERT(e.student_id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS SID,
                CONVERT(COALESCE(s.Fname, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Fname,
                CONVERT(COALESCE(s.Lname, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS Lname,
                CONVERT(COALESCE(s.sex, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS sex,
                CONVERT(sc.course_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS program_name,
                CONVERT(sc.course_code USING utf8mb4) COLLATE utf8mb4_unicode_ci AS program_code,
                CONVERT(COALESCE(NULLIF(sc.delivery_mode, ''), 'Short Course') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS study_mode,
                CONVERT('Short Course' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS intake,
                NULL AS year_of_study,
                NULL AS semester,
                YEAR(COALESCE(e.enrollment_date, e.created_at, sc.start_date)) AS academic_year,
                CONVERT(COALESCE(e.status, 'enrolled') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS record_status
            FROM short_course_enrollments e
            INNER JOIN short_courses sc ON sc.id = e.short_course_id
            LEFT JOIN students s ON TRIM(UPPER(s.SID)) = TRIM(UPPER(e.student_id))
            WHERE " . implode(' AND ', $shortWhere);
    }

    if (empty($queries)) {
        $errors[] = 'No study mode data source is available.';
    } else {
        $sql = implode("\nUNION ALL\n", $queries) . "\nORDER BY study_mode, source_type, program_name, Lname, Fname, SID";
        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException($db->error);
            }
            study_mode_report_bind($stmt, $types, $params);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();

            foreach ($rows as $row) {
                $modeKey = study_mode_report_key($row['study_mode'] ?? '');
                if ($filters['mode'] !== 'all' && $modeKey !== $filters['mode']) {
                    continue;
                }
                $row['mode_key'] = $modeKey;
                $row['mode_label'] = study_mode_report_label($row['study_mode'] ?? '');
                $records[] = $row;
            }

            $summary['total'] = count($records);
            foreach ($records as $row) {
                if (($row['sex'] ?? '') === 'M') {
                    $summary['male']++;
                } elseif (($row['sex'] ?? '') === 'F') {
                    $summary['female']++;
                }
                $sourceType = (string)($row['source_type'] ?? 'program');
                if (isset($summary[$sourceType])) {
                    $summary[$sourceType]++;
                }
                $label = (string)($row['mode_label'] ?? 'Not Set');
                $mode_summary[$label] = ($mode_summary[$label] ?? 0) + 1;
            }
            ksort($mode_summary, SORT_NATURAL | SORT_FLAG_CASE);
        } catch (Throwable $e) {
            error_log('study mode report failed: ' . $e->getMessage());
            $errors[] = 'Error generating study mode report. Please try again or contact support.';
        }
    }
}

// AI Summary
$aiResult = null;
if ($report_generated && empty($errors) && !empty($records) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ai_summary') {
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $aiRows = [];
        foreach ($records as $r) {
            $aiRows[] = [
                'SID' => $r['SID'],
                'Name' => trim(($r['Fname'] ?? '') . ' ' . ($r['Lname'] ?? '')),
                'type' => $r['source_type'],
                'program' => $r['program_name'],
                'mode' => $r['mode_label'],
                'intake' => $r['intake'],
                'academic_year' => $r['academic_year'],
                'sex' => $r['sex']
            ];
        }

        $context = [
            'report' => 'Study Mode Report',
            'role' => 'Administrator',
            'filters' => $filters,
            'columns' => ['SID', 'Name', 'type', 'program', 'mode', 'intake', 'academic_year', 'sex'],
            'rows' => array_slice($aiRows, 0, 40),
            'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
        ];
        $ctxJson = wuc_ai_context_json($context, 14000);
        $aiResult = wuc_ai_generate($db, [
            'feature' => 'admin_study_mode_summary',
            'user_role' => 'admin',
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'admin'),
            'input_summary' => 'Study Mode Report Summary',
            'context_hash' => hash('sha256', $ctxJson),
            'messages' => [
                ['role' => 'system', 'content' => 'You summarise a Study Mode Report of admitted students and short course trainees. Use ONLY the supplied rows. Give a concise narrative of total trainees, study mode distributions, and recommendation actions.'],
                ['role' => 'user', 'content' => "Report data (JSON):\n{$ctxJson}\n\nWrite the summary."],
            ],
            'fallback' => static function () use ($records): string {
                return "Study Mode Report — " . count($records) . " records found. AI summary unavailable.";
            },
        ]);
    }
}

$additional_styles = [
    'https://cdn.jsdelivr.net/gh/bbbootstrap/libraries@main/choices.min.css'
];
$additional_scripts = [
    'https://cdn.jsdelivr.net/gh/bbbootstrap/libraries@main/choices.min.js'
];

require 'includes/header.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <?php
    render_report_print_styles();
    render_report_print_script();
    if ($report_generated && empty($errors)) {
        render_report_print_header(
            'Study Mode Report',
            study_mode_report_label($filters['mode']),
            [
                'Study Mode'   => study_mode_report_label($filters['mode']),
                'Type'         => $filters['source'] === 'all' ? 'All' : ($filters['source'] === 'program' ? 'Programmes' : 'Short Courses'),
                'Academic Year' => $filters['academic_year'] === 'all' ? 'All Years' : $filters['academic_year'],
                'Gender'       => $filters['gender'] === '' ? 'All' : ($filters['gender'] === 'M' ? 'Male' : 'Female'),
                'Records'      => $summary['total'],
            ]
        );
    }
    ?>

    <!-- Page Header -->
    <div class="dashboard-header admin-section mb-4 d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-chart-pie me-2 text-primary"></i>Study Mode Report</h1>
                <p class="text-muted mb-0">Generate reports for all programme and short-course study mode types.</p>
            </div>
            <div class="col-auto">
                <a href="index.php" class="btn btn-primary d-flex align-items-center gap-2 rounded-pill">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger d-print-none" role="alert">
            <?php foreach ($errors as $error): ?>
                <div><?php echo study_mode_report_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
        </div>
        <div class="card-body p-4">
            <form action="reportStudy_mode.php" method="POST" class="row g-3 needs-validation" id="studyModeReportForm" novalidate>
                <input type="hidden" name="search" value="1">
                <div class="col-md-3">
                    <label for="mode" class="form-label fw-semibold">Mode of Study</label>
                    <select class="form-select rounded-3" name="mode" id="mode">
                        <option value="all" <?php echo $filters['mode'] === 'all' ? 'selected' : ''; ?>>All study modes</option>
                        <?php foreach ($mode_options as $modeKey => $modeLabel): ?>
                            <option value="<?php echo study_mode_report_h($modeKey); ?>" <?php echo $filters['mode'] === $modeKey ? 'selected' : ''; ?>>
                                <?php echo study_mode_report_h($modeLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="source" class="form-label fw-semibold">Registration Type</label>
                    <select class="form-select rounded-3" name="source" id="source">
                        <option value="all" <?php echo $filters['source'] === 'all' ? 'selected' : ''; ?>>All types</option>
                        <option value="program" <?php echo $filters['source'] === 'program' ? 'selected' : ''; ?>>Programmes</option>
                        <option value="short_course" <?php echo $filters['source'] === 'short_course' ? 'selected' : ''; ?>>Short Courses</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="program_code" class="form-label fw-semibold">Program</label>
                    <select class="form-select rounded-3" name="program_code" id="program_code">
                        <option value="">All programs</option>
                        <?php foreach ($program_options as $program): ?>
                            <option value="<?php echo study_mode_report_h($program['program_code']); ?>" <?php echo $filters['program_code'] === (string)$program['program_code'] ? 'selected' : ''; ?>>
                                <?php echo study_mode_report_h($program['program_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="academic_year" class="form-label fw-semibold">Academic Year</label>
                    <select class="form-select rounded-3" id="academic_year" name="academic_year">
                        <option value="all" <?php echo $filters['academic_year'] === 'all' ? 'selected' : ''; ?>>All years</option>
                        <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo (int)$year; ?>" <?php echo $filters['academic_year'] === (string)$year ? 'selected' : ''; ?>>
                                <?php echo (int)$year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="gender" class="form-label fw-semibold">Gender</label>
                    <select class="form-select rounded-3" id="gender" name="gender">
                        <option value="" <?php echo $filters['gender'] === '' ? 'selected' : ''; ?>>All</option>
                        <option value="M" <?php echo $filters['gender'] === 'M' ? 'selected' : ''; ?>>Male</option>
                        <option value="F" <?php echo $filters['gender'] === 'F' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label fw-semibold">Status</label>
                    <select class="form-select rounded-3" id="status" name="status">
                        <option value="all" <?php echo $filters['status'] === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filters['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end offset-md-8">
                    <button class="btn btn-primary w-100 rounded-pill shadow-sm py-2" type="submit" id="generateStudyModeReportBtn">
                        <i class="fas fa-search me-1"></i> View report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($report_generated && empty($errors)): ?>
        <!-- KPI summary stats (on screen) -->
        <div class="row g-4 mb-4 d-print-none">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-primary text-white rounded-circle p-3 me-3">
                            <i class="fas fa-users fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($summary['total']); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">Total Records</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success text-white rounded-circle p-3 me-3">
                            <i class="fas fa-graduation-cap fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($summary['program']); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">Programme Records</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-warning text-white rounded-circle p-3 me-3">
                            <i class="fas fa-certificate fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($summary['short_course']); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">Short-Course Records</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-info text-white rounded-circle p-3 me-3">
                            <i class="fas fa-venus-mars fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($summary['male']); ?> / <?php echo number_format($summary['female']); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">Male / Female</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($mode_summary)): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
            <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-chart-pie me-2"></i>Study Mode Breakdown</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <?php foreach ($mode_summary as $label => $count): ?>
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded-3 p-3 h-100 bg-light text-center">
                                <div class="text-muted small fw-semibold"><?php echo study_mode_report_h($label); ?></div>
                                <div class="fs-4 fw-bold text-primary"><?php echo number_format($count); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- AI Summary Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
            <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>BI Summary Insights</h5>
                <form method="post" action="reportStudy_mode.php" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="ai_summary">
                    <input type="hidden" name="search" value="1">
                    <input type="hidden" name="mode" value="<?php echo study_mode_report_h($filters['mode']); ?>">
                    <input type="hidden" name="source" value="<?php echo study_mode_report_h($filters['source']); ?>">
                    <input type="hidden" name="program_code" value="<?php echo study_mode_report_h($filters['program_code']); ?>">
                    <input type="hidden" name="academic_year" value="<?php echo study_mode_report_h($filters['academic_year']); ?>">
                    <input type="hidden" name="gender" value="<?php echo study_mode_report_h($filters['gender']); ?>">
                    <input type="hidden" name="status" value="<?php echo study_mode_report_h($filters['status']); ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                        <i class="fas fa-arrows-rotate me-1"></i>Generate AI Insights
                    </button>
                </form>
            </div>
            <div class="card-body p-4">
                <?php if ($aiResult !== null): ?>
                    <?php if (empty($aiResult['used_ai'])): ?>
                        <div class="alert alert-warning small py-2 mb-3"><?php echo htmlspecialchars(wuc_ai_fallback_notice($aiResult)); ?></div>
                    <?php endif; ?>
                    <div class="bg-light border rounded-3 p-3">
                        <?php echo wuc_ai_output_block((string)$aiResult['text']); ?>
                    </div>
                <?php else: ?>
                    <span class="text-muted small">Click <strong>Generate AI Insights</strong> to analyze the dataset and output a plain-English report overview.</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Print/Export Actions -->
        <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-list me-2"></i>Study Mode Trainees</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i>Export Excel
                </button>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" onclick="printReport('Study Mode Report')">
                    <i class="fas fa-print me-1"></i>Print Report
                </button>
            </div>
        </div>

        <!-- Report Table -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4" id="print">
                <!-- Summary counts — hidden on screen but included in print -->
                <div class="study-mode-print-summary d-none d-print-block mb-3">
                    <h6 class="text-center fw-bold mb-2">Summary</h6>
                    <table class="table table-bordered table-sm w-auto mx-auto text-center align-middle">
                        <thead>
                            <tr>
                                <th>Total</th>
                                <th>Programme</th>
                                <th>Short Course</th>
                                <th>Male</th>
                                <th>Female</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo number_format($summary['total']); ?></td>
                                <td><?php echo number_format($summary['program']); ?></td>
                                <td><?php echo number_format($summary['short_course']); ?></td>
                                <td><?php echo number_format($summary['male']); ?></td>
                                <td><?php echo number_format($summary['female']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="studyModeTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px">No</th>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Type</th>
                                <th>Program / Course</th>
                                <th>Study Mode</th>
                                <th>Intake</th>
                                <th>Year</th>
                                <th>Academic Year</th>
                                <th>Gender</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $row):
                                $isShort = ($row['source_type'] ?? '') === 'short_course';
                            ?>
                                <tr>
                                    <td><?php echo $number++; ?></td>
                                    <td><strong><?php echo study_mode_report_h($row['SID'] ?? ''); ?></strong></td>
                                    <td><?php echo study_mode_report_h(trim((string)($row['Fname'] ?? '') . ' ' . (string)($row['Lname'] ?? '')) ?: 'Name not captured'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $isShort ? 'warning text-dark' : 'primary'; ?> px-2 py-1">
                                            <?php echo $isShort ? 'Short Course' : 'Programme'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo study_mode_report_h($row['program_name'] ?? ''); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($row['mode_key'] ?? '') === 'full time' ? 'success' : 'secondary'; ?> px-2 py-1">
                                            <?php echo study_mode_report_h($row['mode_label'] ?? 'Not Set'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo study_mode_report_h($row['intake'] ?? ''); ?></td>
                                    <td><?php echo ($row['year_of_study'] ?? '') !== '' && $row['year_of_study'] !== null ? 'Year ' . study_mode_report_h($row['year_of_study']) : 'N/A'; ?></td>
                                    <td><?php echo study_mode_report_h($row['academic_year'] ?? ''); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($row['sex'] ?? '') === 'M' ? 'info' : (($row['sex'] ?? '') === 'F' ? 'danger' : 'secondary'); ?> px-2 py-1">
                                            <?php echo ($row['sex'] ?? '') === 'M' ? 'Male' : (($row['sex'] ?? '') === 'F' ? 'Female' : 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo study_mode_report_h(ucwords(str_replace('_', ' ', (string)($row['record_status'] ?? '')))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted py-4">No records found for the selected study mode filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
$(document).ready(function(){
    if (window.Choices) {
        ['mode', 'source', 'program_code'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                new Choices(el, { removeItemButton: false, shouldSort: false });
            }
        });
    }

    var source = document.getElementById('source');
    var program = document.getElementById('program_code');
    function syncSourceFilter() {
        var shortOnly = source && source.value === 'short_course';
        if (program) {
            program.disabled = shortOnly;
            if (shortOnly) {
                program.value = '';
            }
            var group = program.closest('.col-md-3') || program.closest('.form-group');
            if (group) {
                group.classList.toggle('opacity-50', shortOnly);
            }
        }
    }
    if (source) {
        syncSourceFilter();
        source.addEventListener('change', syncSourceFilter);
    }

    if (window.jQuery && jQuery.fn && jQuery.fn.DataTable && document.getElementById('studyModeTable')) {
        jQuery('#studyModeTable').DataTable({
            pageLength: 25,
            responsive: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            language: {
                search: "",
                searchPlaceholder: "Search study mode records...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ records"
            }
        });
    }
});

function exportToExcel() {
    var table = document.querySelector('#studyModeTable');
    if (!table) { alert('No data to export.'); return; }
    
    var isDt = window.jQuery && jQuery.fn && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable(table);
    var dt, oldLen;
    if (isDt) {
        dt = jQuery(table).DataTable();
        oldLen = dt.page.len();
        dt.page.len(-1).draw(false);
    }
    
    var html = table.outerHTML;
    
    if (isDt) {
        dt.page.len(oldLen).draw(false);
    }
    
    var url  = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    var link = document.createElement('a');
    document.body.appendChild(link);
    link.href     = url;
    link.download = 'study_mode_report.xls';
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require_once 'includes/footer.php'; ?>
