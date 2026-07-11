<?php
$page_title = 'Student Progression Alerts';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/student_progression_report.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';

function spr_lecturer_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function spr_lecturer_format_period(string $val, array $labels): string
{
    if (empty($labels)) {
        if ($val === 'short_course') return "Short Course Period";
        return "Semester/Term " . $val;
    }
    $parts = [];
    foreach ($labels as $label) {
        if ($val === 'short_course') {
            $parts[] = "Short Course Period";
        } else {
            $parts[] = $label . " " . $val;
        }
    }
    return implode(' / ', array_unique($parts));
}

$staffId = (string)($_SESSION['staff_id'] ?? '');
$assignedCourses = getLecturerCourseDetails($db, $staffId);

$assignedPeriodLabels = [];
$resTypes = $db->prepare("
    SELECT DISTINCT p.period_mode 
    FROM course_lecturer cl
    JOIN program_courses pc ON TRIM(cl.course_code) = TRIM(pc.course_code)
    JOIN programs p ON pc.program_code = p.program_code
    WHERE cl.staff_id = ? AND cl.status = 'active'
");
if ($resTypes) {
    $resTypes->bind_param('s', $staffId);
    $resTypes->execute();
    $res = $resTypes->get_result();
    while ($row = $res->fetch_assoc()) {
        $assignedPeriodLabels[] = ($row['period_mode'] === 'term') ? 'Term' : 'Semester';
    }
    $resTypes->close();
} else {
    error_log("Failed to prepare period modes: " . $db->error);
}

if (!empty($assignedCourses)) {
    $codes = array_map(fn($c) => $c['course_code'], $assignedCourses);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    
    $sqlShort = "SELECT COUNT(*) FROM short_courses WHERE course_code IN ($placeholders) AND status = 'active'";
    $stmtShort = $db->prepare($sqlShort);
    if ($stmtShort) {
        $stmtShort->bind_param($types, ...$codes);
        $stmtShort->execute();
        $resShort = $stmtShort->get_result();
        if ($resShort && $rowShort = $resShort->fetch_row()) {
            if ($rowShort[0] > 0) {
                $assignedPeriodLabels[] = 'Intake';
            }
        }
        $stmtShort->close();
    }
}
$assignedPeriodLabels = array_unique(array_filter($assignedPeriodLabels));

$periodLabel = 'Academic Period';

// Academic years dropdown query - safely without @
$academicYears = [];
$resYears = $db->query("
    SELECT DISTINCT academic_year FROM student_program WHERE academic_year IS NOT NULL AND academic_year != '' 
    UNION 
    SELECT DISTINCT academic_year FROM semester_registration WHERE academic_year IS NOT NULL AND academic_year != '' 
    ORDER BY academic_year DESC
");
if ($resYears) {
    while ($row = $resYears->fetch_row()) {
        $normalized = spr_normalize_academic_year((string)$row[0]);
        if ($normalized !== '' && !in_array($normalized, $academicYears, true)) {
            $academicYears[] = $normalized;
        }
    }
    $resYears->free();
}
if (empty($academicYears)) {
    $currentYear = (int)date('Y');
    for ($y = $currentYear - 2; $y <= $currentYear + 2; $y++) {
        $academicYears[] = spr_normalize_academic_year((string)$y);
    }
} else {
    $currentYearStr = spr_normalize_academic_year((string)date('Y'));
    if (!in_array($currentYearStr, $academicYears, true)) {
        $academicYears[] = $currentYearStr;
        rsort($academicYears);
    }
}

// Semesters dropdown query - safely without @
$semesters = ['1', '2', '3', 'short_course'];

// Students dropdown query - safely without @
$students = [];
if (!empty($assignedCourses)) {
    $codes = array_map(fn($c) => $c['course_code'], $assignedCourses);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    
    $sqlStud = "
        SELECT DISTINCT student_id, first_name, last_name
        FROM (
            SELECT DISTINCT st.SID AS student_id, st.Fname AS first_name, st.Lname AS last_name
            FROM course_registration cr
            INNER JOIN students st ON cr.Sid = st.SID
            INNER JOIN course_lecturer lec ON cr.course_code = lec.course_code
            WHERE lec.staff_id = ? AND cr.course_code IN ($placeholders)
            
            UNION
            
            SELECT DISTINCT st.SID AS student_id, st.Fname AS first_name, st.Lname AS last_name
            FROM exams e
            INNER JOIN students st ON e.Sid = st.SID
            INNER JOIN course_lecturer lec ON e.Course_Code = lec.course_code
            WHERE lec.staff_id = ? AND e.Course_Code IN ($placeholders)
        ) as combined
        ORDER BY first_name, last_name
    ";
    
    $stmtStud = $db->prepare($sqlStud);
    if ($stmtStud) {
        $bindTypes = 's' . $types . 's' . $types;
        $bindArgs = array_merge([$staffId], $codes, [$staffId], $codes);
        $stmtStud->bind_param($bindTypes, ...$bindArgs);
        $stmtStud->execute();
        $resStud = $stmtStud->get_result();
        if ($resStud) {
            while ($row = $resStud->fetch_assoc()) {
                $students[] = $row;
            }
        }
        $stmtStud->close();
    }
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

$report = student_progression_report($db, $filters, 'lecturer', $staffId);

if (($_GET['export'] ?? '') === 'csv') {
    student_progression_report_csv($report['rows'], 'student_progression_alerts_lecturer_' . date('Ymd_His') . '.csv');
}

require __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard lecturer-workflow-page">
    <div class="dashboard-header lecturer-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title">Student Progression Alerts</h1>
                <p class="text-muted mb-0">Failed and inactive student alerts for your assigned courses.</p>
            </div>
            <div class="col-auto d-flex gap-2">
                <a class="btn btn-sm btn-outline-success" href="?<?php echo spr_lecturer_h(http_build_query(array_merge($filters, ['export' => 'csv']))); ?>">
                    <i class="fas fa-file-csv me-1"></i>Export CSV
                </a>
                <button class="btn btn-sm btn-outline-success" type="button" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i>Export Excel-Compatible File
                </button>
                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>

    <?php if (!$report['assigned_courses']): ?>
        <div class="alert alert-warning">
            <i class="fas fa-circle-info me-2"></i>No active course assignments were found for your lecturer account.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small fw-semibold">Flagged</div>
            <div class="h3 mb-0"><?php echo number_format((int)$report['stats']['flagged']); ?></div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small fw-semibold">Failed</div>
            <div class="h3 mb-0 text-danger"><?php echo number_format((int)$report['stats']['failed']); ?></div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small fw-semibold">Inactive</div>
            <div class="h3 mb-0 text-secondary"><?php echo number_format((int)$report['stats']['inactive']); ?></div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small fw-semibold">Critical</div>
            <div class="h3 mb-0 text-warning"><?php echo number_format((int)$report['stats']['both']); ?></div>
        </div></div></div>
    </div>

    <div class="data-table-card mb-4 d-print-none">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5></div>
        <div class="card-body">
            <form method="get" class="row g-3">
                <!-- Row 1 -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Flag Type</label>
                    <select class="form-select rounded-3" name="flag">
                        <?php foreach (['all' => 'All alerts', 'failed' => 'Failed only', 'inactive' => 'Inactive only', 'both' => 'Failed + inactive'] as $value => $label): ?>
                            <option value="<?php echo spr_lecturer_h($value); ?>" <?php echo ($filters['flag'] === $value) ? 'selected' : ''; ?>><?php echo spr_lecturer_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Course</label>
                    <?php if (empty($assignedCourses)): ?>
                        <input class="form-control" name="course_code" value="<?php echo spr_lecturer_h($filters['course_code']); ?>" placeholder="No assigned courses found" readonly>
                    <?php else: ?>
                        <select class="form-select rounded-3" name="course_code">
                            <option value="">All assigned courses</option>
                            <?php foreach ($assignedCourses as $course): ?>
                                <?php $code = (string)$course['course_code']; ?>
                                <option value="<?php echo spr_lecturer_h($code); ?>" <?php echo strcasecmp($filters['course_code'], $code) === 0 ? 'selected' : ''; ?>>
                                    <?php echo spr_lecturer_h($code . ' - ' . ($course['course_name'] ?: 'No Name')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Student</label>
                    <select class="form-select rounded-3" name="q">
                        <option value="">All students</option>
                        <?php foreach ($students as $student): ?>
                            <?php $sid = (string)$student['student_id']; ?>
                            <option value="<?php echo spr_lecturer_h($sid); ?>" <?php echo strcasecmp($filters['q'], $sid) === 0 ? 'selected' : ''; ?>>
                                <?php echo spr_lecturer_h($sid . ' - ' . $student['first_name'] . ' ' . $student['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Row 2 -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Academic Year</label>
                    <select class="form-select rounded-3" name="academic_year">
                        <option value="">All years</option>
                        <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo spr_lecturer_h($year); ?>" <?php echo $filters['academic_year'] === $year ? 'selected' : ''; ?>><?php echo spr_lecturer_h($year); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Academic Period</label>
                    <select class="form-select rounded-3" name="semester">
                        <option value="">All Periods</option>
                        <?php foreach ($semesters as $sem): ?>
                            <option value="<?php echo spr_lecturer_h($sem); ?>" <?php echo $filters['semester'] === $sem ? 'selected' : ''; ?>>
                                <?php echo spr_lecturer_h(spr_lecturer_format_period($sem, $assignedPeriodLabels)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 mt-3">
                    <button class="btn btn-primary rounded-pill px-4 py-2" type="submit"><i class="fas fa-sync me-2"></i>Generate Report</button>
                    <a class="btn btn-light border rounded-pill px-4 py-2" href="student_progression_report.php">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="data-table-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Progression Alert Report</h5>
            <span class="text-muted small">Generated <?php echo spr_lecturer_h($report['generated_at']); ?></span>
        </div>
        <div class="card-body">
            <?php if (!$report['rows']): ?>
                <?php if (!empty($invalidFilterWarning)): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-triangle-exclamation me-2"></i>One or more filters were invalid and were reset to safe defaults.
                    </div>
                <?php elseif ($filters['flag'] !== 'all' || $filters['academic_year'] !== '' || $filters['semester'] !== '' || $filters['course_code'] !== '' || $filters['program_code'] !== '' || $filters['q'] !== ''): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-circle-info me-2"></i>No records matched the selected filters. Try changing the academic year, course, or academic period.
                    </div>
                <?php else: ?>
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-circle-check me-2"></i>No progression alerts were found. This means there are no failed or inactive student records matching the selected filters.
                    </div>
                <?php endif; ?>
            <?php else: 
                // Group report rows by student_id
                $groupedRows = [];
                foreach ($report['rows'] as $row) {
                    $sid = $row['student_id'];
                    if (!isset($groupedRows[$sid])) {
                        $groupedRows[$sid] = [
                            'student_id' => $sid,
                            'student_name' => $row['student_name'] ?: 'Name not captured',
                            'student_status' => $row['student_status'] ?: 'Active',
                            'program_code' => $row['program_code'] ?: '-',
                            'risk_level' => $row['risk_level'],
                            'courses' => [],
                        ];
                    }
                    $groupedRows[$sid]['courses'][] = $row;
                    
                    $riskOrder = ['Critical' => 0, 'Academic risk' => 1, 'Inactive' => 2, 'Clear' => 3];
                    $currentRiskVal = $riskOrder[$groupedRows[$sid]['risk_level']] ?? 3;
                    $newRiskVal = $riskOrder[$row['risk_level']] ?? 3;
                    if ($newRiskVal < $currentRiskVal) {
                        $groupedRows[$sid]['risk_level'] = $row['risk_level'];
                    }
                }
            ?>
                <div class="accordion" id="progressionAccordion">
                    <?php 
                    $idx = 0;
                    foreach ($groupedRows as $sid => $group): 
                        $idx++;
                        $riskBg = $group['risk_level'] === 'Critical' ? 'bg-danger-subtle text-danger border-danger' : ($group['risk_level'] === 'Academic risk' ? 'bg-warning-subtle text-warning-emphasis border-warning' : 'bg-light text-secondary border-secondary');
                        $cardBorder = $group['risk_level'] === 'Critical' ? 'border-start-danger' : ($group['risk_level'] === 'Academic risk' ? 'border-start-warning' : 'border-start-secondary');
                    ?>
                    <div class="card border border-light-subtle rounded-4 mb-3 shadow-sm overflow-hidden <?php echo $cardBorder; ?>" style="border-left-width: 5px !important;">
                        <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center cursor-pointer" 
                             data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo $idx; ?>" aria-expanded="true">
                            <div class="d-flex align-items-center gap-3">
                                <div class="avatar bg-light text-secondary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark"><?php echo spr_lecturer_h($group['student_name']); ?></h6>
                                    <span class="text-muted small fw-semibold">ID: <?php echo spr_lecturer_h($group['student_id']); ?></span>
                                    <span class="badge bg-light text-secondary border ms-2">Program: <?php echo spr_lecturer_h($group['program_code']); ?></span>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <span class="badge bg-primary-subtle text-primary border rounded-pill px-3 py-1">
                                    <?php echo count($group['courses']); ?> Flagged Course(s)
                                </span>
                                <span class="badge border rounded-pill px-3 py-1 <?php echo $riskBg; ?>">
                                    <?php echo spr_lecturer_h($group['risk_level']); ?>
                                </span>
                                <i class="fas fa-chevron-down text-muted transition-transform"></i>
                            </div>
                        </div>
                        
                        <div id="collapse-<?php echo $idx; ?>" class="collapse show">
                            <div class="card-body bg-light-subtle border-top py-3 px-4">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0 bg-white border rounded-3 overflow-hidden">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Course Details</th>
                                                <th>Period &amp; Year</th>
                                                <th>Assessment Details</th>
                                                <th>Alert Flags</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($group['courses'] as $c): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="text-primary"><i class="fas fa-book-bookmark"></i></div>
                                                        <div>
                                                            <strong class="text-dark"><?php echo spr_lecturer_h($c['course_code']); ?></strong>
                                                            <?php if (!empty($c['course_name'])): ?>
                                                                <br><small class="text-muted"><?php echo spr_lecturer_h($c['course_name']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="small text-dark">
                                                        Year <?php echo spr_lecturer_h($c['year_of_study'] ?: '-'); ?> /
                                                        <?php 
                                                            $semVal = $c['semester'] ?: '-';
                                                            if ($semVal === 'short_course') {
                                                                $semVal = 'Short Course';
                                                            } elseif (preg_match('/^[123]$/', (string)$semVal)) {
                                                                $periodPrefix = (($c['period_mode'] ?? '') === 'term') ? 'Term ' : 'Sem ';
                                                                $semVal = $periodPrefix . $semVal;
                                                            }
                                                        ?>
                                                        <strong><?php echo spr_lecturer_h($semVal); ?></strong>
                                                    </div>
                                                    <div class="text-muted small"><?php echo spr_lecturer_h($c['academic_year'] ?: '-'); ?></div>
                                                </td>
                                                <td>
                                                    <?php if ($c['total_score'] === null): ?>
                                                         <span class="badge bg-secondary-subtle text-secondary border px-2 py-1"><i class="fas fa-circle-minus me-1"></i>No CA result</span>
                                                    <?php else: ?>
                                                         <div class="d-flex flex-row align-items-center gap-3">
                                                             <div class="small">
                                                                 <span class="text-muted fw-semibold">CA:</span> 
                                                                 <span class="fw-bold"><?php echo $c['total_ca'] !== null ? number_format((float)$c['total_ca'], 1) : '-'; ?></span>
                                                             </div>
                                                             <div class="small">
                                                                 <span class="text-muted fw-semibold">Exam:</span> 
                                                                 <span class="fw-bold"><?php echo $c['total_exam'] !== null ? number_format((float)$c['total_exam'], 1) : '-'; ?></span>
                                                             </div>
                                                             <div class="vr bg-secondary-subtle" style="height: 18px;"></div>
                                                             <div class="small">
                                                                 <span class="text-muted fw-semibold">Total:</span> 
                                                                 <strong class="text-dark"><?php echo number_format((float)$c['total_score'], 1); ?></strong>
                                                             </div>
                                                             <span class="badge bg-<?php echo !empty($c['failed']) ? 'danger' : 'success'; ?>"><?php echo spr_lecturer_h($c['grade_letter']); ?></span>
                                                         </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php foreach ($c['flags'] as $flag): ?>
                                                        <span class="badge bg-<?php echo $flag === 'Failed' ? 'danger' : 'secondary'; ?> me-1"><?php echo spr_lecturer_h($flag); ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if (!empty($c['inactive_reasons'])): ?>
                                                        <div class="small text-muted mt-1"><?php echo spr_lecturer_h(implode('; ', $c['inactive_reasons'])); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
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
