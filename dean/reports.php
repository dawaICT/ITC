<?php
$page_title = 'Institutional Reports';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/department_schema_helpers.php';
require "includes/nav.php";
require_once __DIR__ . '/../includes/report_print.php';
require_once __DIR__ . '/../includes/ai_portal.php';

error_reporting(0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ─── Helper: safe query returning single scalar ───
function qval(mysqli $db, string $sql, $default = 0) {
    if ($r = @$db->query($sql)) {
        $v = $r->fetch_row();
        $r->free();
        return $v ? $v[0] : $default;
    }
    return $default;
}

// ─── Helper: safe query returning assoc rows ───
function qrows(mysqli $db, string $sql): array {
    $out = [];
    if ($r = @$db->query($sql)) {
        while ($row = $r->fetch_assoc()) { $out[] = $row; }
        $r->free();
    }
    return $out;
}

// ---- Filters Setup ----
$filters = [
    'academic_year' => trim((string)($_GET['academic_year'] ?? 'all')),
    'program_type'  => trim((string)($_GET['program_type'] ?? 'all')),
    'intake'        => trim((string)($_GET['intake'] ?? 'all')),
];

// Fetch academic years option list
$academic_years = [];
$yearSql = "SELECT DISTINCT year_value FROM (
        SELECT NULLIF(academic_year, '') AS year_value FROM students
        UNION
        SELECT NULLIF(academic_year, '') AS year_value FROM student_program
        UNION
        SELECT startYear AS year_value FROM student_program
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

// Fetch program types
$program_types = [];
if ($result = @$db->query("SELECT DISTINCT program_type FROM programs WHERE program_type IS NOT NULL AND program_type <> '' ORDER BY program_type")) {
    while ($row = $result->fetch_assoc()) {
        $program_types[] = $row['program_type'];
    }
    $result->free();
}

// Fetch intakes
$intakes = [];
if ($result = @$db->query("SELECT DISTINCT intake FROM student_program WHERE intake IS NOT NULL AND intake <> '' ORDER BY intake")) {
    while ($row = $result->fetch_assoc()) {
        $intakes[] = $row['intake'];
    }
    $result->free();
}

// Build query conditions
$escAy = $db->real_escape_string($filters['academic_year']);
$escPt = $db->real_escape_string($filters['program_type']);
$escIn = $db->real_escape_string($filters['intake']);

$spCond = "1=1";
$studCond = "1=1";
$progCond = "1=1";

if ($filters['academic_year'] !== 'all') {
    $spCond .= " AND (sp.academic_year = '{$escAy}' OR sp.startYear = '{$escAy}')";
    $studCond .= " AND (s.academic_year = '{$escAy}' OR EXISTS(SELECT 1 FROM student_program sp WHERE sp.Sid = s.SID AND (sp.academic_year = '{$escAy}' OR sp.startYear = '{$escAy}')))";
}
if ($filters['program_type'] !== 'all') {
    $spCond .= " AND p.program_type = '{$escPt}'";
    $studCond .= " AND EXISTS(SELECT 1 FROM student_program sp INNER JOIN programs p ON sp.program_code = p.program_code WHERE sp.Sid = s.SID AND p.program_type = '{$escPt}')";
    $progCond .= " AND p.program_type = '{$escPt}'";
}
if ($filters['intake'] !== 'all') {
    $spCond .= " AND sp.intake = '{$escIn}'";
    $studCond .= " AND EXISTS(SELECT 1 FROM student_program sp WHERE sp.Sid = s.SID AND sp.intake = '{$escIn}')";
}

// ═══════════════════════════════════════════════════
//  DATA COLLECTION
// ═══════════════════════════════════════════════════

// --- Top-line KPIs ---
$totalStudents    = (int) qval($db, "SELECT COUNT(DISTINCT s.SID) FROM students s WHERE {$studCond}");
$activeStudents   = (int) qval($db, "SELECT COUNT(DISTINCT sp.Sid) FROM student_program sp LEFT JOIN programs p ON sp.program_code = p.program_code WHERE sp.status = 'active' AND {$spCond}");
$totalPrograms    = (int) qval($db, "SELECT COUNT(*) FROM programs p WHERE {$progCond}");
$activePrograms   = (int) qval($db, "SELECT COUNT(*) FROM programs p WHERE p.is_active = 1 AND {$progCond}");
$totalStaff       = (int) qval($db, "SELECT COUNT(*) FROM staff");
$totalLecturers   = (int) qval($db, "SELECT COUNT(DISTINCT sp.staff_id) FROM staff_positions sp INNER JOIN positions p ON sp.PosID = p.PosID WHERE p.PosName = 'Lecturer'");
$totalDepartments = (int) qval($db, "SELECT COUNT(*) FROM departments");
$totalCourses     = (int) qval($db, "SELECT COUNT(*) FROM courses");

if ($totalCourses === 0) {
    $totalCourses = (int) qval($db, "SELECT COUNT(DISTINCT course_code) FROM program_courses");
}

// Student-to-Lecturer ratio
$studentLecturerRatio = $totalLecturers > 0 ? round($activeStudents / $totalLecturers, 1) : '—';

// --- Gender distribution ---
$genderRows = qrows($db, "SELECT UPPER(TRIM(s.sex)) as g, COUNT(DISTINCT s.SID) as cnt FROM students s WHERE {$studCond} GROUP BY g");
$genderMap = ['M' => 0, 'F' => 0];
foreach ($genderRows as $gr) {
    $key = substr($gr['g'] ?? '', 0, 1);
    if ($key === 'M') $genderMap['M'] += (int)$gr['cnt'];
    elseif ($key === 'F') $genderMap['F'] += (int)$gr['cnt'];
}
$maleCount = $genderMap['M'];
$femaleCount = $genderMap['F'];
$malePercent  = $totalStudents > 0 ? round($maleCount / $totalStudents * 100, 1) : 0;
$femalePercent = $totalStudents > 0 ? round($femaleCount / $totalStudents * 100, 1) : 0;

// --- Students per program ---
$programEnrollment = qrows($db,
    "SELECT p.program_name, p.program_type, COUNT(DISTINCT sp.Sid) as cnt
     FROM student_program sp
     INNER JOIN programs p ON sp.program_code = p.program_code
     WHERE {$spCond}
     GROUP BY sp.program_code
     ORDER BY cnt DESC");

// --- Program types distribution ---
$programTypes = qrows($db, "SELECT p.program_type, COUNT(*) as cnt FROM programs p WHERE {$progCond} GROUP BY p.program_type ORDER BY cnt DESC");

// --- Study mode distribution ---
$studyModes = qrows($db,
    "SELECT CASE WHEN sp.mode = '' OR sp.mode IS NULL THEN 'Not Specified' ELSE sp.mode END as study_mode, COUNT(DISTINCT sp.Sid) as cnt
     FROM student_program sp
     LEFT JOIN programs p ON sp.program_code = p.program_code
     WHERE {$spCond}
     GROUP BY study_mode ORDER BY cnt DESC");

// --- Staff by position ---
$staffPositions = qrows($db,
    "SELECT COALESCE(p.PosName, sp.PosID) as position_name, COUNT(DISTINCT sp.staff_id) as cnt
     FROM staff_positions sp
     LEFT JOIN positions p ON sp.PosID = p.PosID
     GROUP BY sp.PosID
     ORDER BY cnt DESC");

// --- Intake trends ---
$intakeTrends = qrows($db,
    "SELECT sp.intake, COUNT(DISTINCT sp.Sid) as cnt
     FROM student_program sp
     LEFT JOIN programs p ON sp.program_code = p.program_code
     WHERE sp.intake IS NOT NULL AND sp.intake != '' AND {$spCond}
     GROUP BY sp.intake
     ORDER BY sp.intake ASC");

// --- Student status breakdown ---
$studentStatuses = qrows($db,
    "SELECT COALESCE(sp.status, 'unknown') as sts, COUNT(DISTINCT sp.Sid) as cnt
     FROM student_program sp
     LEFT JOIN programs p ON sp.program_code = p.program_code
     WHERE {$spCond}
     GROUP BY sts ORDER BY cnt DESC");

// --- Semester registration stats ---
// semester_registration has no financial_status column in the live schema and
// keys students by SID (not Sid); guard so the widget falls back to its empty
// state instead of a fatal if the column never ships.
$semRegStats = [];
$finStatusCol = @$db->query("SHOW COLUMNS FROM semester_registration LIKE 'financial_status'");
if ($finStatusCol && $finStatusCol->num_rows > 0) {
    $semRegStats = qrows($db,
        "SELECT sr.financial_status, COUNT(DISTINCT sr.SID) as cnt
         FROM semester_registration sr
         INNER JOIN student_program sp ON sr.SID = sp.Sid
         LEFT JOIN programs p ON sp.program_code = p.program_code
         WHERE {$spCond}
         GROUP BY sr.financial_status
         ORDER BY cnt DESC");
}
if ($finStatusCol) { $finStatusCol->free(); }

// --- Recent registrations (last 30 days) ---
$recentRegs = 0;
$semRegDateCol = wuc_semester_registration_date_column($db);
if ($semRegDateCol !== null) {
    $recentRegs = (int) qval($db,
        "SELECT COUNT(DISTINCT sr.Sid) FROM semester_registration sr INNER JOIN student_program sp ON sr.Sid = sp.Sid LEFT JOIN programs p ON sp.program_code = p.program_code WHERE sr.`{$semRegDateCol}` >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND {$spCond}");
}

// --- Course registration count ---
$totalCourseRegs = (int) qval($db, "SELECT COUNT(*) FROM course_registration cr INNER JOIN student_program sp ON cr.Sid = sp.Sid LEFT JOIN programs p ON sp.program_code = p.program_code WHERE {$spCond}");

// --- Department programs mapping ---
$deptPkCol = wuc_department_pk_column($db);
$deptPrograms = qrows($db,
    "SELECT d.department_name, COUNT(DISTINCT p.program_code) as prog_count, 
            (SELECT COUNT(DISTINCT sp.Sid) FROM student_program sp INNER JOIN programs pp ON sp.program_code = pp.program_code WHERE pp.department_id = d.`{$deptPkCol}` AND {$spCond}) as student_count
     FROM departments d
     LEFT JOIN programs p ON p.department_id = d.`{$deptPkCol}`
     WHERE d.status IN ('Active', 'active')
     GROUP BY d.`{$deptPkCol}`, d.department_name
     ORDER BY student_count DESC");

// AI Summary
$aiResult = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ai_summary') {
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $context = [
            'report' => 'Institutional Reports',
            'role' => 'Dean / Administrator',
            'filters' => $filters,
            'kpis' => [
                'total_students' => $totalStudents,
                'active_students' => $activeStudents,
                'total_programs' => $totalPrograms,
                'active_programs' => $activePrograms,
                'total_lecturers' => $totalLecturers,
                'student_lecturer_ratio' => $studentLecturerRatio,
            ],
            'gender' => ['male' => $maleCount, 'female' => $femaleCount],
            'program_enrollment_excerpt' => array_slice($programEnrollment, 0, 10),
            'study_modes' => $studyModes,
            'student_statuses' => $studentStatuses,
            'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
        ];
        $ctxJson = wuc_ai_context_json($context, 14000);
        $aiResult = wuc_ai_generate($db, [
            'feature' => 'dean_report_summary',
            'user_role' => 'dean',
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'dean'),
            'input_summary' => 'Institutional Report Summary',
            'context_hash' => hash('sha256', $ctxJson),
            'messages' => [
                ['role' => 'system', 'content' => 'You summarise an Institutional Academic Performance Report for the Dean. Use ONLY the supplied data. Give a concise narrative of key KPIs, gender trends, program health, and action points.'],
                ['role' => 'user', 'content' => "Institutional data (JSON):\n{$ctxJson}\n\nWrite the summary."],
            ],
            'fallback' => static function () use ($totalStudents, $activeStudents): string {
                return "Institutional Report Summary — Total Students: {$totalStudents}, Active Students: {$activeStudents}. AI summary unavailable.";
            },
        ]);
    }
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <?php
    render_report_print_styles();
    render_report_print_script();
    render_report_print_header(
        'Institutional Reports',
        'Academic Dashboard',
        [
            'Academic Year' => $filters['academic_year'] === 'all' ? 'All' : $filters['academic_year'],
            'Program Type' => $filters['program_type'] === 'all' ? 'All' : ucfirst($filters['program_type']),
            'Intake' => $filters['intake'] === 'all' ? 'All' : $filters['intake'],
            'Total Cohort' => $totalStudents,
        ]
    );
    ?>

    <!-- Dashboard Header -->
    <div class="dashboard-header dean-section mb-4 d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-chart-column me-2 text-primary"></i>Institutional Reports</h1>
                <p class="text-muted mb-0">Comprehensive overview of ITC academic data &amp; performance</p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <button class="btn btn-secondary rounded-pill px-3" onclick="printReport('Institutional Reports')">
                        <i class="fas fa-print me-2"></i>Print Dashboard
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters (Dean specific) -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
        </div>
        <div class="card-body p-4">
            <form action="reports.php" method="GET" class="row g-3">
                <div class="col-md-4">
                    <label for="academic_year" class="form-label fw-semibold">Academic Year</label>
                    <select class="form-select rounded-3" name="academic_year" id="academic_year">
                        <option value="all" <?php echo $filters['academic_year'] === 'all' ? 'selected' : ''; ?>>All Academic Years</option>
                        <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo (int)$year; ?>" <?php echo $filters['academic_year'] === (string)$year ? 'selected' : ''; ?>>
                                <?php echo (int)$year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="program_type" class="form-label fw-semibold">Program Type</label>
                    <select class="form-select rounded-3" name="program_type" id="program_type">
                        <option value="all" <?php echo $filters['program_type'] === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <?php foreach ($program_types as $pt): ?>
                            <option value="<?php echo htmlspecialchars($pt); ?>" <?php echo $filters['program_type'] === $pt ? 'selected' : ''; ?>>
                                <?php echo ucfirst(htmlspecialchars($pt)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="intake" class="form-label fw-semibold">Intake</label>
                    <select class="form-select rounded-3" name="intake" id="intake">
                        <option value="all" <?php echo $filters['intake'] === 'all' ? 'selected' : ''; ?>>All Intakes</option>
                        <?php foreach ($intakes as $in): ?>
                            <option value="<?php echo htmlspecialchars($in); ?>" <?php echo $filters['intake'] === $in ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($in); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 text-end mt-3">
                    <button class="btn btn-primary rounded-pill shadow-sm px-4 py-2" type="submit">
                        <i class="fas fa-search me-1"></i>Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- AI Summary Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>BI Summary Insights</h5>
            <form method="post" action="reports.php?academic_year=<?php echo urlencode($filters['academic_year']); ?>&program_type=<?php echo urlencode($filters['program_type']); ?>&intake=<?php echo urlencode($filters['intake']); ?>" class="m-0">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="ai_summary">
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
                <span class="text-muted small">Click <strong>Generate AI Insights</strong> to analyze the institutional records and output a plain-English report overview.</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ TOP-LINE KPI CARDS ═══ -->
    <div class="row g-3 mb-4">
        <?php
        $kpis = [
            ['icon' => 'fas fa-user-graduate', 'value' => number_format($totalStudents), 'label' => 'Total Students',       'sub' => "$activeStudents active", 'color' => 'bg-dean'],
            ['icon' => 'fas fa-chalkboard-teacher', 'value' => number_format($totalLecturers), 'label' => 'Lecturers',       'sub' => number_format($totalStaff).' total staff', 'color' => 'bg-info'],
            ['icon' => 'fas fa-graduation-cap', 'value' => number_format($totalPrograms), 'label' => 'Programs Offered',     'sub' => "$activePrograms active", 'color' => 'bg-success'],
            ['icon' => 'fas fa-building',       'value' => number_format($totalDepartments), 'label' => 'Departments',        'sub' => number_format($totalCourses).' courses',  'color' => 'bg-warning'],
            ['icon' => 'fas fa-balance-scale',  'value' => $studentLecturerRatio.':1', 'label' => 'Student:Lecturer Ratio',   'sub' => 'Institutional average', 'color' => 'bg-dean'],
            ['icon' => 'fas fa-clipboard-check','value' => number_format($totalCourseRegs), 'label' => 'Course Registrations','sub' => "$recentRegs in last 30 days", 'color' => 'bg-primary'],
        ];
        foreach ($kpis as $k): ?>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stat-card h-100 rounded-4 bg-white p-3 shadow-sm border" style="transition:transform .2s">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon <?php echo $k['color']; ?> rounded-circle p-2 me-2 d-flex align-items-center justify-content-center" style="width:42px;height:42px">
                        <i class="<?php echo $k['icon']; ?> text-white"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?php echo $k['value']; ?></h4>
                    </div>
                </div>
                <p class="text-muted mb-0 small fw-semibold"><?php echo $k['label']; ?></p>
                <small class="text-muted" style="font-size:.75rem"><?php echo $k['sub']; ?></small>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ═══ ROW 1: Gender + Program Types + Study Mode ═══ -->
    <div class="row g-4 mb-4">
        <!-- Gender Distribution -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0"><h5 class="fw-bold text-primary mb-0"><i class="fas fa-venus-mars me-2"></i>Gender Distribution</h5></div>
                <div class="card-body p-4 d-flex flex-column align-items-center">
                    <div style="position:relative;width:200px;height:200px">
                        <canvas id="genderChart"></canvas>
                    </div>
                    <div class="mt-3 text-center">
                        <span class="badge bg-primary me-1">Male: <?php echo "$maleCount ($malePercent%)"; ?></span>
                        <span class="badge bg-danger">Female: <?php echo "$femaleCount ($femalePercent%)"; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Program Types -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0"><h5 class="fw-bold text-primary mb-0"><i class="fas fa-layer-group me-2"></i>Programs by Type</h5></div>
                <div class="card-body p-4 d-flex flex-column align-items-center">
                    <div style="position:relative;width:200px;height:200px">
                        <canvas id="programTypesChart"></canvas>
                    </div>
                    <div class="mt-3 text-center">
                        <?php foreach ($programTypes as $pt): ?>
                        <span class="badge bg-secondary me-1"><?php echo ucfirst(htmlspecialchars($pt['program_type'])); ?>: <?php echo $pt['cnt']; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Study Mode -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0"><h5 class="fw-bold text-primary mb-0"><i class="fas fa-clock me-2"></i>Students by Study Mode</h5></div>
                <div class="card-body p-4 d-flex flex-column align-items-center">
                    <div style="position:relative;width:200px;height:200px">
                        <canvas id="studyModeChart"></canvas>
                    </div>
                    <div class="mt-3 text-center">
                        <?php foreach ($studyModes as $sm): ?>
                        <span class="badge bg-info me-1"><?php echo htmlspecialchars($sm['study_mode']); ?>: <?php echo $sm['cnt']; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ ROW 2: Enrollment by Program (Bar Chart) ═══ -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold text-primary mb-0"><i class="fas fa-chart-bar me-2"></i>Student Enrollment by Program</h5>
                        <span class="badge bg-dean"><?php echo count($programEnrollment); ?> Programs</span>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div style="position:relative;height:320px">
                        <canvas id="enrollmentChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ ROW 3: Staff Composition + Enrollment Status ═══ -->
    <div class="row g-4 mb-4">
        <!-- Staff Composition -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0"><h5 class="fw-bold text-primary mb-0"><i class="fas fa-user-tie me-2"></i>Staff Composition</h5></div>
                <div class="card-body p-4">
                    <div style="position:relative;height:280px">
                        <canvas id="staffChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enrollment Status -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0"><h5 class="fw-bold text-primary mb-0"><i class="fas fa-check-circle me-2"></i>Student Enrollment Status</h5></div>
                <div class="card-body p-4">
                    <?php if (!empty($studentStatuses)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Status</th>
                                    <th class="text-end">Students</th>
                                    <th style="width:50%">Distribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $statusColors = ['active' => 'success', 'inactive' => 'secondary', 'completed' => 'primary', 'suspended' => 'warning', 'withdrawn' => 'danger'];
                                foreach ($studentStatuses as $ss):
                                    $pct = $activeStudents > 0 ? round($ss['cnt'] / $activeStudents * 100, 1) : 0;
                                    $clr = $statusColors[$ss['sts']] ?? 'secondary';
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-<?php echo $clr; ?>"><?php echo ucfirst(htmlspecialchars($ss['sts'])); ?></span>
                                    </td>
                                    <td class="text-end fw-bold"><?php echo number_format($ss['cnt']); ?></td>
                                    <td>
                                        <div class="progress" style="height:20px">
                                            <div class="progress-bar bg-<?php echo $clr; ?>" style="width:<?php echo $pct; ?>%" role="progressbar"><?php echo $pct; ?>%</div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No enrollment status data available.</div>
                    <?php endif; ?>

                    <?php if (!empty($semRegStats)): ?>
                    <h6 class="mt-4 mb-2 text-muted fw-bold text-uppercase small">Financial Clearance Status</h6>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php
                        $finColors = ['Clear' => 'success', 'Pending' => 'warning', 'Blocked' => 'danger'];
                        foreach ($semRegStats as $sr):
                            $c = $finColors[$sr['financial_status']] ?? 'secondary';
                        ?>
                        <div class="border rounded-3 p-3 text-center flex-fill" style="min-width:100px">
                            <h5 class="mb-1 text-<?php echo $c; ?> fw-bold"><?php echo number_format($sr['cnt']); ?></h5>
                            <small class="text-muted"><?php echo htmlspecialchars($sr['financial_status']); ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ ROW 4: Department Summary Table ═══ -->
    <?php if (!empty($deptPrograms)): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-sitemap me-2"></i>Department Overview</h5>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Department</th>
                                    <th class="text-center">Programs</th>
                                    <th class="text-center">Students</th>
                                    <th style="width:40%">Student Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deptPrograms as $dp):
                                    $share = $activeStudents > 0 ? round($dp['student_count'] / $activeStudents * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($dp['department_name']); ?></td>
                                    <td class="text-center"><span class="badge bg-dean"><?php echo $dp['prog_count']; ?></span></td>
                                    <td class="text-center fw-bold"><?php echo number_format($dp['student_count']); ?></td>
                                    <td>
                                        <div class="progress" style="height:22px">
                                            <div class="progress-bar bg-dean" style="width:<?php echo $share; ?>%" role="progressbar"><?php echo $share; ?>%</div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ ROW 5: Program Details Table ═══ -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold text-primary mb-0"><i class="fas fa-list-alt me-2"></i>Detailed Program Enrollment</h5>
                    </div>
                </div>
                <div class="card-body p-4">
                    <?php if (!empty($programEnrollment)): ?>
                    <div class="table-responsive">
                        <table id="programTable" class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Program Name</th>
                                    <th class="text-center">Type</th>
                                    <th class="text-center">Enrolled</th>
                                    <th style="width:35%">Enrollment Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($programEnrollment as $pe):
                                    $share = $activeStudents > 0 ? round($pe['cnt'] / $activeStudents * 100, 1) : 0;
                                    $typeColor = ['degree' => 'primary', 'diploma' => 'info', 'certificate' => 'warning'][$pe['program_type']] ?? 'secondary';
                                ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($pe['program_name']); ?></td>
                                    <td class="text-center"><span class="badge bg-<?php echo $typeColor; ?>"><?php echo ucfirst(htmlspecialchars($pe['program_type'])); ?></span></td>
                                    <td class="text-center fw-bold"><?php echo number_format($pe['cnt']); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height:18px">
                                                <div class="progress-bar bg-dean" style="width:<?php echo $share; ?>%" role="progressbar"><?php echo $share; ?>%</div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No program enrollment data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Report Footer ═══ -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="border-top pt-3 text-muted small d-flex justify-content-between">
                <span><i class="fas fa-clock me-1"></i>Report generated: <?php echo date('F d, Y \a\t h:i A'); ?></span>
                <span><i class="fas fa-university me-1"></i>Industrial Training Centre — Dean's Office</span>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── Shared chart defaults ──
    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    const deanBlue   = '#1e3c72';
    const deanLight  = '#2a5298';
    const palette = ['#1e3c72','#2a5298','#3b82f6','#06b6d4','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#6366f1'];

    // ── Gender Doughnut ──
    new Chart(document.getElementById('genderChart'), {
        type: 'doughnut',
        data: {
            labels: ['Male', 'Female'],
            datasets: [{
                data: [<?php echo $maleCount; ?>, <?php echo $femaleCount; ?>],
                backgroundColor: ['#3b82f6', '#ec4899'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom', labels: { padding: 15 } }
            }
        }
    });

    // ── Program Types Doughnut ──
    new Chart(document.getElementById('programTypesChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_map(static function($pt) { return ucfirst((string)($pt['program_type'] ?? 'Unknown')); }, $programTypes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            datasets: [{
                data: [<?php echo implode(',', array_column($programTypes, 'cnt')); ?>],
                backgroundColor: ['#1e3c72','#10b981','#f59e0b','#ef4444'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom', labels: { padding: 15 } }
            }
        }
    });

    // ── Study Mode Doughnut ──
    new Chart(document.getElementById('studyModeChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_map(static function($sm) { return (string)($sm['study_mode'] ?? 'Not Specified'); }, $studyModes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            datasets: [{
                data: [<?php echo implode(',', array_column($studyModes, 'cnt')); ?>],
                backgroundColor: ['#06b6d4','#8b5cf6','#f59e0b','#10b981','#ef4444'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom', labels: { padding: 15 } }
            }
        }
    });

    // ── Enrollment Bar Chart ──
    new Chart(document.getElementById('enrollmentChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(static function($pe) {
                $name = strlen($pe['program_name']) > 30 ? substr($pe['program_name'], 0, 28) . '…' : $pe['program_name'];
                return (string)$name;
            }, $programEnrollment), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            datasets: [{
                label: 'Students Enrolled',
                data: [<?php echo implode(',', array_column($programEnrollment, 'cnt')); ?>],
                backgroundColor: palette.slice(0, <?php echo count($programEnrollment); ?>).map(c => c + 'CC'),
                borderColor: palette.slice(0, <?php echo count($programEnrollment); ?>),
                borderWidth: 1,
                borderRadius: 6,
                barPercentage: 0.7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, precision: 0 },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                x: {
                    grid: { display: false },
                    ticks: { maxRotation: 45, minRotation: 20 }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) { return ctx.parsed.y + ' student' + (ctx.parsed.y !== 1 ? 's' : ''); }
                    }
                }
            }
        }
    });

    // ── Staff Horizontal Bar ──
    new Chart(document.getElementById('staffChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(static function($sp) { return (string)($sp['position_name'] ?? 'Unknown'); }, $staffPositions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            datasets: [{
                label: 'Staff Count',
                data: [<?php echo implode(',', array_column($staffPositions, 'cnt')); ?>],
                backgroundColor: palette.slice(0, <?php echo count($staffPositions); ?>).map(c => c + 'CC'),
                borderColor: palette.slice(0, <?php echo count($staffPositions); ?>),
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, precision: 0 },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                y: { grid: { display: false } }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });

    // ── DataTable on program table ──
    if ($.fn.DataTable && document.getElementById('programTable')) {
        $('#programTable').DataTable({
            paging: false,
            searching: false,
            info: false,
            order: [[3, 'desc']]
        });
    }
});
</script>

<style>
/* Print-specific style overrides */
@media print {
    .bg-dean, .bg-info, .bg-success, .bg-warning, .bg-primary {
        background-color: transparent !important;
        color: #000 !important;
    }
    .progress-bar {
        background-color: #555 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(30,60,114,.12) !important; }
.progress { background-color: #e9ecef; border-radius: 6px; }
.progress-bar.bg-dean { background: linear-gradient(90deg, #1e3c72, #2a5298) !important; }
</style>

<?php require "includes/footer.php"; ?>
