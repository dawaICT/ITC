<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
require_once __DIR__ . '/../includes/report_print.php';
require_once __DIR__ . '/../includes/ai_portal.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' data: https:;");

// Auth check BEFORE any processing
if (!checkSessionTimeout(30) || !isAdminAuthenticated()) {
    setFlashMessage('error', 'Session expired or unauthorized access');
    header('Location: /wucportal/staff_login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function admissions_report_table_exists(mysqli $db, string $table): bool
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

function admissions_report_mode_key($mode): string
{
    $mode = strtolower(trim((string)$mode));
    return preg_replace('/[\s_-]+/', '', $mode) ?: '';
}

function admissions_report_mode_label($mode): string
{
    $key = admissions_report_mode_key($mode);
    $labels = [
        'fulltime' => 'Full-time',
        'parttime' => 'Part-time',
        'distance' => 'Distance',
    ];
    return $labels[$key] ?? (trim((string)$mode) !== '' ? ucwords(str_replace(['_', '-'], ' ', (string)$mode)) : 'N/A');
}

require "includes/nav.php";

$program_code = $_GET['program_code'] ?? '';
$mode = $_GET['mode'] ?? '';
$year = $_GET['year'] ?? '';
$term = $_GET['term'] ?? '';
$records_1 = [];
$error_message = null;

// Fetch academic and transport programs
$records = [];
$programRecordsByCode = [];
if (admissions_report_table_exists($db, 'programs') && ($prog_result = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_name"))) {
    while ($row = $prog_result->fetch_assoc()) {
        $code = trim((string)($row['program_code'] ?? ''));
        if ($code !== '') {
            $programRecordsByCode[$code] = [
                'program_code' => $code,
                'program_name' => (string)($row['program_name'] ?? $code),
            ];
        }
    }
    $prog_result->free();
}
if (admissions_report_table_exists($db, 'transport_programs') && ($transport_result = $db->query("SELECT program_code, CONCAT(program_name, ' (Transport)') AS program_name FROM transport_programs WHERE COALESCE(status, 'active') = 'active' ORDER BY program_name"))) {
    while ($row = $transport_result->fetch_assoc()) {
        $code = trim((string)($row['program_code'] ?? ''));
        if ($code !== '' && !isset($programRecordsByCode[$code])) {
            $programRecordsByCode[$code] = [
                'program_code' => $code,
                'program_name' => (string)($row['program_name'] ?? ($code . ' (Transport)')),
            ];
        }
    }
    $transport_result->free();
}
$records = array_values($programRecordsByCode);
usort($records, static function (array $a, array $b): int {
    return strcasecmp((string)$a['program_name'], (string)$b['program_name']);
});

$report_generated = isset($_GET['submit']);

if ($report_generated) {
    $allowed_modes = ['fulltime', 'parttime', 'distance'];
    $allowed_terms = ['1', '2', 'T1', 'T2', 'T3'];
    $current_year = (int)date('Y');
    $allowed_years = range($current_year - 5, $current_year + 1);
    
    $program_code = preg_replace('/[^a-zA-Z0-9\-_]/', '', $program_code);
    $mode = admissions_report_mode_key($mode);
    $mode = in_array($mode, $allowed_modes, true) ? $mode : '';
    $term = in_array($term, $allowed_terms, true) ? $term : '';
    $year = (in_array((int)$year, $allowed_years, true) && is_numeric($year)) ? (int)$year : 0;
    
    if (!empty($program_code)) {
        $valid_program = false;
        foreach ($records as $prog) {
            if ((string)$prog['program_code'] === $program_code) {
                $valid_program = true;
                break;
            }
        }
        if (!$valid_program) {
            $program_code = '';
            $error_message = 'Selected program is invalid. Please select a valid program.';
        }
    }
    
    if (empty($program_code) && empty($mode) && empty($year) && empty($term)) {
        $error_message = $error_message ?? 'Please select at least one filter to generate a report.';
    } else {
        $hasTransportPrograms = admissions_report_table_exists($db, 'transport_programs');
        $transportJoin = $hasTransportPrograms
            ? "LEFT JOIN transport_programs tp ON TRIM(UPPER(tp.program_code)) = TRIM(UPPER(sp.program_code))"
            : "";
        $programNameExpr = $hasTransportPrograms
            ? "COALESCE(p.program_name, CONCAT(tp.program_name, ' (Transport)'), sp.program_code)"
            : "COALESCE(p.program_name, sp.program_code)";

        $query = "SELECT 
            s.SID AS Sid,
            s.Fname,
            s.Lname,
            s.sex,
            {$programNameExpr} AS program_name,
            sp.intake,
            sp.mode,
            sp.startYear,
            sp.term,
            sp.status AS enrollment_status
        FROM students s
        INNER JOIN student_program sp ON TRIM(UPPER(s.SID)) = TRIM(UPPER(sp.Sid))
        LEFT JOIN programs p ON TRIM(UPPER(sp.program_code)) = TRIM(UPPER(p.program_code))
        {$transportJoin}
        WHERE LOWER(COALESCE(sp.status, '')) = 'active'
        AND LOWER(COALESCE(s.status, '')) <> 'deleted'
        AND 1=1";
        
        $params = [];
        $types = '';
        
        if (!empty($program_code)) {
            $query .= " AND sp.program_code = ?";
            $params[] = $program_code;
            $types .= 's';
        }
        if (!empty($mode)) {
            $query .= " AND REPLACE(REPLACE(LOWER(COALESCE(sp.mode, '')), '-', ''), ' ', '') = ?";
            $params[] = $mode;
            $types .= 's';
        }
        if ($year > 0) {
            $query .= " AND sp.startYear = ?";
            $params[] = $year;
            $types .= 'i';
        }
        if (!empty($term)) {
            $query .= " AND sp.term = ?";
            $params[] = $term;
            $types .= 's';
        }
        
        $query .= " ORDER BY program_name, s.Lname, s.Fname";
        
        try {
            $stmt = $db->prepare($query);
            if ($stmt && !empty($params)) {
                $bind_refs = [];
                $bind_refs[] = $types;
                foreach ($params as $key => $value) {
                    $bind_refs[] = &$params[$key];
                }
                call_user_func_array([$stmt, 'bind_param'], $bind_refs);
            }
            
            if (!$stmt || !$stmt->execute()) {
                throw new Exception($stmt ? $stmt->error : $db->error);
            }
            
            $result = $stmt->get_result();
            $records_1 = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
        } catch (Exception $e) {
            $error_message = 'Error generating report. Please contact system administrator.';
        }
    }
}

// AI summary calculation
$aiResult = null;
if ($report_generated && empty($error_message) && !empty($records_1) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ai_summary') {
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $context = [
            'report' => 'Admissions Active Student Report',
            'role' => 'Admissions Officer',
            'filters' => [
                'Program' => $program_code,
                'Mode' => $mode,
                'Year' => $year,
                'Term' => $term
            ],
            'columns' => ['Student ID', 'Names', 'Gender', 'Program', 'Intake', 'Study mode', 'Start Year', 'Status'],
            'rows' => array_slice($records_1, 0, 40),
            'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
        ];
        $ctxJson = wuc_ai_context_json($context, 14000);
        $aiResult = wuc_ai_generate($db, [
            'feature' => 'admissions_report_summary',
            'user_role' => 'admissions',
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'admissions'),
            'input_summary' => 'Admissions Report Summary',
            'context_hash' => hash('sha256', $ctxJson),
            'messages' => [
                ['role' => 'system', 'content' => 'You summarise an Admissions Report of active students. Use ONLY the supplied rows. Give a concise narrative of total students, program enrollments, gender distributions, and key insights.'],
                ['role' => 'user', 'content' => "Report data (JSON):\n{$ctxJson}\n\nWrite the summary."],
            ],
            'fallback' => static function () use ($records_1): string {
                return "Admissions Report — " . count($records_1) . " students found. AI summary unavailable.";
            },
        ]);
    }
}

// Stats variables
$totalStudents = count($records_1);
$maleCount = 0;
$femaleCount = 0;
foreach ($records_1 as $r) {
    if (strtoupper(substr(trim((string)($r['sex'] ?? '')), 0, 1)) === 'M') {
        $maleCount++;
    } elseif (strtoupper(substr(trim((string)($r['sex'] ?? '')), 0, 1)) === 'F') {
        $femaleCount++;
    }
}

$program_name_print = $program_code;
foreach ($records as $rp) {
    if ($rp['program_code'] === $program_code) {
        $program_name_print = $rp['program_name'];
        break;
    }
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <?php
    render_report_print_styles();
    render_report_print_script();
    if ($report_generated && empty($error_message)) {
        render_report_print_header(
            'Admissions Active Student Report',
            $program_name_print,
            [
                'Program' => $program_name_print,
                'Mode'    => admissions_report_mode_label($mode),
                'Year'    => $year > 0 ? $year : 'All',
                'Term'    => $term !== '' ? $term : 'All',
                'Records' => $totalStudents,
            ]
        );
    }
    ?>

    <!-- Page Header -->
    <div class="dashboard-header admissions-section mb-4 d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-chart-bar me-2 text-primary"></i>Generate Admissions Report</h1>
                <p class="text-muted mb-0">Audit admissions register, filter by program of study, study mode, academic year, and semester/term.</p>
            </div>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show d-print-none" role="alert">
            <i class="fas fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Search/Filter form -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
        </div>
        <div class="card-body p-4">
            <form role="form" method="GET" action="reports.php" class="row g-3">
                <div class="col-md-3">
                    <label for="program_code" class="form-label fw-semibold">Program of Study</label>
                    <select class="form-select rounded-3" name="program_code" id="program_code">
                        <option value="">All programs</option>
                        <?php foreach($records as $r): ?>
                            <option value="<?= htmlspecialchars($r['program_code']) ?>" <?= $r['program_code'] === $program_code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['program_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="mode" class="form-label fw-semibold">Study Mode</label>
                    <select class="form-select rounded-3" name="mode" id="mode">
                        <option value="">All modes</option>
                        <option value="fulltime" <?= $mode === 'fulltime' ? 'selected' : '' ?>>Full-time</option>
                        <option value="parttime" <?= $mode === 'parttime' ? 'selected' : '' ?>>Part-time</option>
                        <option value="distance" <?= $mode === 'distance' ? 'selected' : '' ?>>Distance</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="year" class="form-label fw-semibold">Start Year</label>
                    <select class="form-select rounded-3" id="year" name="year">
                        <option value="">All years</option>
                        <?php
                        $current_year = (int)date('Y');
                        for ($y = $current_year; $y >= $current_year - 5; $y--): 
                            $selected = ($year == $y) ? 'selected' : '';
                        ?>
                            <option value="<?= $y ?>" <?= $selected ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="term" class="form-label fw-semibold">Semester/Term</label>
                    <select class="form-select rounded-3" id="term" name="term">
                        <option value="">All terms</option>
                        <option value="1" <?= $term === '1' ? 'selected' : '' ?>>Semester 1</option>
                        <option value="2" <?= $term === '2' ? 'selected' : '' ?>>Semester 2</option>
                        <option value="T1" <?= $term === 'T1' ? 'selected' : '' ?>>Term 1</option>
                        <option value="T2" <?= $term === 'T2' ? 'selected' : '' ?>>Term 2</option>
                        <option value="T3" <?= $term === 'T3' ? 'selected' : '' ?>>Term 3</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" name="submit" value="1" class="btn btn-primary w-100 px-4 py-2 rounded-pill shadow-sm">
                        <i class="fas fa-search me-1"></i>Generate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($report_generated && empty($error_message)): ?>
        <!-- KPI summary stats (on screen) -->
        <div class="row g-4 mb-4 d-print-none">
            <div class="col-md-4">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-primary text-white rounded-circle p-3 me-3">
                            <i class="fas fa-users fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($totalStudents); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">Total Registered</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-info text-white rounded-circle p-3 me-3">
                            <i class="fas fa-mars fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($maleCount); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">Male Students</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-danger text-white rounded-circle p-3 me-3">
                            <i class="fas fa-venus fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($femaleCount); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">Female Students</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Summary Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
            <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>BI Summary Insights</h5>
                <form method="post" action="reports.php?program_code=<?php echo urlencode($program_code); ?>&mode=<?php echo urlencode($mode); ?>&year=<?php echo urlencode((string)$year); ?>&term=<?php echo urlencode($term); ?>&submit=1" class="m-0">
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
                    <span class="text-muted small">Click <strong>Generate AI Insights</strong> to analyze the dataset and output a plain-English report overview.</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Print/Export Actions -->
        <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-list me-2"></i>Admissions Record List</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i>Export Excel
                </button>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" onclick="exportTableToCSV('admitted_students.csv')">
                    <i class="fas fa-file-csv me-1"></i>Export CSV
                </button>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" onclick="printReport('Admissions Active Student Report')">
                    <i class="fas fa-print me-1"></i>Print Report
                </button>
            </div>
        </div>

        <!-- Report Table -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4" id="print">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="reportTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Student ID</th>
                                <th>Names</th>
                                <th>Gender</th>
                                <th>Program</th>
                                <th>Intake</th>
                                <th>Mode</th>
                                <th>Start Year</th>
                                <th>Term/Semester</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $number = 1; foreach ($records_1 as $r):
                                $display_mode = admissions_report_mode_label($r['mode'] ?? '');
                                $status_class = match(strtolower($r['enrollment_status'] ?? '')) {
                                    'active' => 'success',
                                    'completed' => 'info',
                                    'suspended' => 'warning text-dark',
                                    'withdrawn' => 'danger',
                                    default => 'secondary'
                                };
                            ?>
                                <tr>
                                    <td><?= $number++ ?></td>
                                    <td><strong><?= htmlspecialchars($r['Sid'] ?? 'N/A') ?></strong></td>
                                    <td><?= htmlspecialchars(trim(($r['Fname'] ?? '') . ' ' . ($r['Lname'] ?? ''))) ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($r['sex'] === 'M' ? 'info' : ($r['sex'] === 'F' ? 'danger' : 'secondary')); ?> px-2 py-1">
                                            <?php echo htmlspecialchars($r['sex'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($r['program_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($r['intake'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($display_mode) ?></td>
                                    <td><?= htmlspecialchars($r['startYear'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($r['term'] ?? 'N/A') ?></td>
                                    <td><span class="badge bg-<?= $status_class ?>"><?= ucfirst(htmlspecialchars($r['enrollment_status'] ?? 'active')) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
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
                searchPlaceholder: "Search admitted students...",
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
    link.download = 'admissions_students_report.xls';
    link.click();
    document.body.removeChild(link);
}

function exportTableToCSV(filename) {
    const table = document.getElementById('reportTable');
    if (!table) return;
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    // Header
    const headers = [];
    rows[0].querySelectorAll('th').forEach(th => {
        headers.push('"' + th.textContent.replace(/"/g, '""').trim() + '"');
    });
    csv.push(headers.join(','));
    
    // Data
    for (let i = 1; i < rows.length; i++) {
        const row = [];
        rows[i].querySelectorAll('td').forEach(td => {
            let text = td.textContent.trim();
            row.push('"' + text.replace(/"/g, '""') + '"');
        });
        csv.push(row.join(','));
    }
    
    const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const encodedUri = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', filename || 'student_report.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(encodedUri);
}
</script>

<?php require "includes/footer.php"; ?>
