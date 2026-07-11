<?php
$page_title = 'Student Progression Alerts';
require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/student_progression_report.php';
require_once __DIR__ . '/../includes/report_print.php';
require_once __DIR__ . '/../includes/ai_portal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function spr_admin_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ── 1. Filter Validation & Normalization ─────────────────────────────────────
$invalidFilterWarning = false;

// flag validation
$flag = trim((string)($_GET['flag'] ?? 'all'));
if (!in_array($flag, ['all', 'failed', 'inactive', 'both'], true)) {
    $flag = 'all';
    $invalidFilterWarning = true;
}

// academic_year validation
$academic_year = trim((string)($_GET['academic_year'] ?? ''));
if ($academic_year !== '') {
    if (!preg_match('/^\d{4}(\/\d{4})?$/', $academic_year)) {
        $academic_year = '';
        $invalidFilterWarning = true;
    } else {
        $academic_year = spr_normalize_academic_year($academic_year);
    }
}

// semester (Academic Period) validation
$semester = trim((string)($_GET['semester'] ?? ''));
if ($semester !== '') {
    $semester = spr_normalize_period($semester);
    if (!in_array($semester, ['1', '2', '3', 'short_course'], true)) {
        $semester = '';
        $invalidFilterWarning = true;
    }
}

// course_code validation
$course_code = trim((string)($_GET['course_code'] ?? ''));
if ($course_code !== '') {
    $course_code = strtoupper($course_code);
    if (strlen($course_code) > 30) {
        $course_code = '';
        $invalidFilterWarning = true;
    } else {
        $stmt = $db->prepare("SELECT 1 FROM courses WHERE course_code = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $course_code);
            $stmt->execute();
            $valid = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if (!$valid) {
                $course_code = '';
                $invalidFilterWarning = true;
            }
        }
    }
}

// program_code validation
$program_code = trim((string)($_GET['program_code'] ?? ''));
if ($program_code !== '') {
    $program_code = strtoupper($program_code);
    if (strlen($program_code) > 30) {
        $program_code = '';
        $invalidFilterWarning = true;
    } else {
        $stmt = $db->prepare("SELECT 1 FROM programs WHERE program_code = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $program_code);
            $stmt->execute();
            $valid = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if (!$valid) {
                $program_code = '';
                $invalidFilterWarning = true;
            }
        }
    }
}

// search query validation
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    if (strlen($q) > 50) {
        $q = substr($q, 0, 50);
        $invalidFilterWarning = true;
    }
}

$filters = [
    'flag' => $flag,
    'q' => $q,
    'academic_year' => $academic_year,
    'semester' => $semester,
    'course_code' => $course_code,
    'program_code' => $program_code,
];

// Generate Report
$report = student_progression_report($db, $filters, 'admin');

// ── Dropdown data for filter selects ─────────────────────────────────────────
$filterAcademicYears = [];
$filterCourses       = [];
$filterPrograms      = [];

// Academic years - Query safely without @
$ayRes = $db->query("
    SELECT DISTINCT academic_year FROM student_program WHERE academic_year IS NOT NULL AND academic_year <> ''
    UNION
    SELECT DISTINCT academic_year FROM course_registration WHERE academic_year IS NOT NULL AND academic_year <> ''
");
if ($ayRes) {
    while ($row = $ayRes->fetch_row()) {
        $normalized = spr_normalize_academic_year((string)$row[0]);
        if ($normalized !== '' && !in_array($normalized, $filterAcademicYears, true)) {
            $filterAcademicYears[] = $normalized;
        }
    }
    $ayRes->free();
} else {
    error_log("Failed to fetch academic years: " . $db->error);
}
rsort($filterAcademicYears);

// Courses - Query safely without @
$cRes = $db->query("SELECT course_code, course_name FROM courses ORDER BY course_code");
if ($cRes) {
    while ($row = $cRes->fetch_assoc()) {
        $filterCourses[] = ['code' => (string)$row['course_code'], 'name' => (string)$row['course_name']];
    }
    $cRes->free();
} else {
    error_log("Failed to fetch courses: " . $db->error);
}

// Programs - Query safely without @
$pRes = $db->query("SELECT program_code, program_name FROM programs WHERE is_active = 1 ORDER BY program_code");
if (!$pRes) {
    $pRes = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_code");
}
if ($pRes) {
    while ($row = $pRes->fetch_assoc()) {
        $filterPrograms[] = ['code' => (string)$row['program_code'], 'name' => (string)$row['program_name']];
    }
    $pRes->free();
} else {
    error_log("Failed to fetch programs: " . $db->error);
}
// ─────────────────────────────────────────────────────────────────────────────

if (($_GET['export'] ?? '') === 'csv') {
    student_progression_report_csv($report['rows'], 'student_progression_alerts_admin_' . date('Ymd_His') . '.csv');
}

// AI summary calculation
$aiResult = null;
$aiError = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ai_summary') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $aiError = "Security validation failed. Please refresh the page and try again.";
    } elseif (empty($report['rows'])) {
        $aiError = "No data available for AI summary using the selected filters.";
    } else {
        $aiRows = [];
        foreach ($report['rows'] as $r) {
            $aiRows[] = [
                'student_id' => $r['student_id'],
                'student_name' => $r['student_name'],
                'course' => $r['course_code'],
                'program' => $r['program_code'],
                'academic_year' => $r['academic_year'],
                'score' => $r['total_score'],
                'grade' => $r['grade_letter'],
                'flags' => $r['flags'],
                'risk' => $r['risk_level']
            ];
        }

        $context = [
            'report' => 'Student Progression Alerts Report',
            'role' => 'Administrator',
            'filters' => $filters,
            'stats' => $report['stats'],
            'columns' => ['student_id', 'student_name', 'course', 'program', 'academic_year', 'score', 'grade', 'flags', 'risk'],
            'rows' => array_slice($aiRows, 0, 40),
            'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
        ];
        $ctxJson = wuc_ai_context_json($context, 14000);
        $aiResult = wuc_ai_generate($db, [
            'feature' => 'admin_progression_summary',
            'user_role' => 'admin',
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'admin'),
            'input_summary' => 'Progression Alert Summary',
            'context_hash' => hash('sha256', $ctxJson),
            'messages' => [
                ['role' => 'system', 'content' => 'You summarise a Student Progression Alerts Report. Use ONLY the supplied rows. Give a concise narrative of total flagged students, critical risk cases (failed latest attempt or inactive registrations), and recommendations.'],
                ['role' => 'user', 'content' => "Progression data (JSON):\n{$ctxJson}\n\nWrite the summary."],
            ],
            'fallback' => static function () use ($report): string {
                return "Student Progression alerts — " . count($report['rows']) . " students flagged. AI summary unavailable.";
            },
        ]);
    }
}

require __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <?php
    render_report_print_styles();
    render_report_print_script();
    render_report_print_header(
        'Student Progression Alerts',
        'Academic Risk Status',
        [
            'Flag Type'       => ucfirst($filters['flag']),
            'Academic Year'   => $filters['academic_year'] !== '' ? $filters['academic_year'] : 'All',
            'Academic Period' => ($filters['semester'] === 'short_course') ? 'Short Course' : ($filters['semester'] !== '' ? 'Semester/Term ' . $filters['semester'] : 'All'),
            'Course Code'     => $filters['course_code'] !== '' ? $filters['course_code'] : 'All',
            'Program Code'    => $filters['program_code'] !== '' ? $filters['program_code'] : 'All',
            'Risk Cohort'     => count($report['rows']),
        ]
    );
    ?>

    <!-- Page Header -->
    <div class="dashboard-header admin-section mb-4 mt-3 d-print-none">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-0">
                    <i class="fas fa-triangle-exclamation me-2 text-warning"></i>Student Progression Alerts
                </h1>
                <p class="text-muted mb-0 mt-1 small">Students with failed latest attempts, inactive records, or inactive course registrations.</p>
            </div>
            <div class="col-auto">
                <a href="index.php" class="btn btn-primary d-flex align-items-center gap-2 rounded-pill shadow-sm">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card border rounded-4 bg-white p-4 shadow-sm h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary rounded-circle p-3 me-3 text-white">
                        <i class="fas fa-flag fa-2x"></i>
                    </div>
                    <div>
                        <h3 class="fw-bold mb-0"><?php echo number_format((int)$report['stats']['flagged']); ?></h3>
                        <p class="text-muted mb-0 small fw-semibold">Flagged Students/Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card border rounded-4 bg-white p-4 shadow-sm h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger rounded-circle p-3 me-3 text-white">
                        <i class="fas fa-circle-xmark fa-2x"></i>
                    </div>
                    <div>
                        <h3 class="fw-bold mb-0 text-danger"><?php echo number_format((int)$report['stats']['failed']); ?></h3>
                        <p class="text-muted mb-0 small fw-semibold">Failed Attempts</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card border rounded-4 bg-white p-4 shadow-sm h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-secondary rounded-circle p-3 me-3 text-white">
                        <i class="fas fa-user-slash fa-2x"></i>
                    </div>
                    <div>
                        <h3 class="fw-bold mb-0 text-secondary"><?php echo number_format((int)$report['stats']['inactive']); ?></h3>
                        <p class="text-muted mb-0 small fw-semibold">Inactive Flags</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card border rounded-4 bg-white p-4 shadow-sm h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning rounded-circle p-3 me-3 text-white">
                        <i class="fas fa-circle-exclamation fa-2x"></i>
                    </div>
                    <div>
                        <h3 class="fw-bold mb-0 text-warning"><?php echo number_format((int)$report['stats']['both']); ?></h3>
                        <p class="text-muted mb-0 small fw-semibold">Failed + Inactive</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
        </div>
        <div class="card-body p-4">
            <form method="get" class="row g-3">
                <!-- Row 1 -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Flag Type</label>
                    <select class="form-select rounded-3" name="flag">
                        <?php foreach (['all' => 'All alerts', 'failed' => 'Failed only', 'inactive' => 'Inactive only', 'both' => 'Failed + inactive'] as $value => $label): ?>
                            <option value="<?php echo spr_admin_h($value); ?>" <?php echo ($filters['flag'] === $value) ? 'selected' : ''; ?>><?php echo spr_admin_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Program</label>
                    <select class="form-select rounded-3" name="program_code">
                        <option value="">All programs</option>
                        <?php foreach ($filterPrograms as $p): ?>
                            <option value="<?php echo spr_admin_h($p['code']); ?>" <?php echo (strtoupper($filters['program_code']) === strtoupper($p['code'])) ? 'selected' : ''; ?>>
                                <?php echo spr_admin_h($p['code'] . ($p['name'] !== '' ? ' – ' . $p['name'] : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Course</label>
                    <select class="form-select rounded-3" name="course_code">
                        <option value="">All courses</option>
                        <?php foreach ($filterCourses as $c): ?>
                            <option value="<?php echo spr_admin_h($c['code']); ?>" <?php echo (strtoupper($filters['course_code']) === strtoupper($c['code'])) ? 'selected' : ''; ?>>
                                <?php echo spr_admin_h($c['code'] . ($c['name'] !== '' ? ' – ' . $c['name'] : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Row 2 -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Academic Year</label>
                    <select class="form-select rounded-3" name="academic_year">
                        <option value="">All years</option>
                        <?php foreach ($filterAcademicYears as $ay): ?>
                            <option value="<?php echo spr_admin_h($ay); ?>" <?php echo ($filters['academic_year'] === $ay) ? 'selected' : ''; ?>><?php echo spr_admin_h($ay); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Academic Period</label>
                    <select class="form-select rounded-3" name="semester">
                        <option value="">All Periods</option>
                        <option value="1" <?php echo ($filters['semester'] === '1') ? 'selected' : ''; ?>>Semester 1 / Term 1</option>
                        <option value="2" <?php echo ($filters['semester'] === '2') ? 'selected' : ''; ?>>Semester 2 / Term 2</option>
                        <option value="3" <?php echo ($filters['semester'] === '3') ? 'selected' : ''; ?>>Semester 3 / Term 3</option>
                        <option value="short_course" <?php echo ($filters['semester'] === 'short_course') ? 'selected' : ''; ?>>Short Course Period</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Search Student or Course</label>
                    <input class="form-control rounded-3" name="q" value="<?php echo spr_admin_h($filters['q']); ?>" placeholder="Enter ID, name, or course">
                </div>

                <div class="col-12 text-end mt-3">
                    <button class="btn btn-primary rounded-pill shadow-sm px-4 py-2" type="submit"><i class="fas fa-search me-1"></i>Generate Report</button>
                    <a class="btn btn-light border rounded-pill px-4 py-2" href="student_progression_report.php">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- AI Summary Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>BI Summary Insights</h5>
            <form method="post" action="student_progression_report.php?flag=<?php echo urlencode($filters['flag']); ?>&academic_year=<?php echo urlencode($filters['academic_year']); ?>&semester=<?php echo urlencode($filters['semester']); ?>&course_code=<?php echo urlencode($filters['course_code']); ?>&program_code=<?php echo urlencode($filters['program_code']); ?>&q=<?php echo urlencode($filters['q']); ?>" class="m-0">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="ai_summary">
                <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3" <?php echo empty($report['rows']) ? 'disabled' : ''; ?>>
                    <i class="fas fa-arrows-rotate me-1"></i>Generate AI Insights
                </button>
            </form>
        </div>
        <div class="card-body p-4">
            <?php if ($aiError !== null): ?>
                <div class="alert alert-danger mb-0">
                    <i class="fas fa-circle-exclamation me-2"></i><?php echo htmlspecialchars($aiError); ?>
                </div>
            <?php elseif ($aiResult !== null): ?>
                <?php if (empty($aiResult['used_ai'])): ?>
                    <div class="alert alert-warning small py-2 mb-3"><?php echo htmlspecialchars(wuc_ai_fallback_notice($aiResult)); ?></div>
                <?php endif; ?>
                <div class="bg-light border rounded-3 p-3">
                    <?php echo wuc_ai_output_block((string)$aiResult['text']); ?>
                </div>
            <?php else: ?>
                <span class="text-muted small">Click <strong>Generate AI Insights</strong> to analyze the progression alerts dataset and output a plain-English report overview.</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Print/Export Actions -->
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h5 class="fw-bold text-primary mb-0"><i class="fas fa-list me-2"></i>Progression Alerts</h5>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-success rounded-pill px-3 d-flex align-items-center gap-1" href="?<?php echo spr_admin_h(http_build_query(array_merge($filters, ['export' => 'csv']))); ?>">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-1"></i>Export Excel-Compatible File
            </button>
            <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" onclick="printReport('Student Progression Alerts')">
                <i class="fas fa-print me-1"></i>Print Report
            </button>
        </div>
    </div>

    <!-- Report Table -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4" id="print">
            <?php if (!$report['rows']): ?>
                <?php if (!empty($invalidFilterWarning)): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-triangle-exclamation me-2"></i>One or more filters were invalid and were reset to safe defaults.
                    </div>
                <?php elseif ($filters['flag'] !== 'all' || $filters['academic_year'] !== '' || $filters['semester'] !== '' || $filters['course_code'] !== '' || $filters['program_code'] !== '' || $filters['q'] !== ''): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-circle-info me-2"></i>No records matched the selected filters. Try changing the academic year, programme, course, or academic period.
                    </div>
                <?php else: ?>
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-circle-check me-2"></i>No progression alerts were found. This means there are no failed or inactive student records matching the selected filters.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="reportTable" class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-muted fw-normal">#</th>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Program</th>
                                <th>Course</th>
                                <th>Academic Period</th>
                                <th class="text-end">CA Total</th>
                                <th class="text-end">Total Score</th>
                                <th>Risk</th>
                                <th>Flags</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rowNum = 0; foreach ($report['rows'] as $row): $rowNum++;
                                $riskRowClass = $row['risk_level'] === 'Critical' ? 'table-danger' : ($row['risk_level'] === 'Academic risk' ? 'table-warning' : '');
                                $semDisplay = $row['semester'] ?: '-';
                                if ($semDisplay === 'short_course') {
                                    $semDisplay = 'Short Course';
                                } elseif (preg_match('/^[123]$/', (string)$semDisplay)) {
                                    $periodPrefix = (($row['period_mode'] ?? '') === 'term') ? 'Term ' : 'Sem ';
                                    $semDisplay = $periodPrefix . $semDisplay;
                                }
                                $riskBadge = $row['risk_level'] === 'Critical' ? 'bg-danger' : ($row['risk_level'] === 'Academic risk' ? 'bg-warning text-dark' : 'bg-secondary');
                            ?>
                            <tr class="<?php echo $riskRowClass; ?>">
                                <td class="text-muted small"><?php echo $rowNum; ?></td>
                                <td class="fw-semibold small"><?php echo spr_admin_h($row['student_id']); ?></td>
                                <td><?php echo spr_admin_h($row['student_name'] ?: 'Name not captured'); ?></td>
                                <td><span class="badge bg-light text-secondary border"><?php echo spr_admin_h($row['program_code'] ?: '-'); ?></span></td>
                                <td>
                                    <strong class="small"><?php echo spr_admin_h($row['course_code']); ?></strong>
                                    <?php if (!empty($row['course_name'])): ?><br><small class="text-muted"><?php echo spr_admin_h($row['course_name']); ?></small><?php endif; ?>
                                </td>
                                <td class="small text-nowrap">
                                    <?php echo spr_admin_h($row['academic_year'] ?: '-'); ?> / <?php echo spr_admin_h($semDisplay); ?>
                                </td>
                                <td class="text-end small"><?php echo $row['total_ca'] !== null ? number_format((float)$row['total_ca'], 1) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="text-end small fw-bold"><?php echo $row['total_score'] !== null ? number_format((float)$row['total_score'], 1) : '<span class="text-muted">–</span>'; ?></td>
                                <td><span class="badge <?php echo $riskBadge; ?>"><?php echo spr_admin_h($row['risk_level']); ?></span></td>
                                <td>
                                    <?php foreach ($row['flags'] as $flag): ?>
                                        <span class="badge bg-<?php echo $flag === 'Failed' ? 'danger' : 'secondary'; ?> me-1"><?php echo spr_admin_h($flag); ?></span>
                                    <?php endforeach; ?>
                                    <?php if (!empty($row['inactive_reasons'])): ?>
                                        <div class="small text-muted mt-1"><?php echo spr_admin_h(implode('; ', $row['inactive_reasons'])); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    if ($('#reportTable tbody tr').length > 0 && typeof $.fn.DataTable !== 'undefined') {
        $('#reportTable').DataTable({
            pageLength: 25,
            responsive: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            language: {
                search: "",
                searchPlaceholder: "Search progression alerts...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
            }
        });
    }
});

function exportToExcel() {
    var table = document.querySelector('#reportTable');
    if (!table) { alert('No data to export.'); return; }
    var html = table.outerHTML;
    var url  = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    var link = document.createElement('a');
    document.body.appendChild(link);
    link.href     = url;
    link.download = 'student_progression_alerts.xls';
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
