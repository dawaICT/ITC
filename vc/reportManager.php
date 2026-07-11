<?php
$page_title = 'Student Registration Report';
require "includes/nav.php";
require_once __DIR__ . '/../includes/report_print.php';
require_once __DIR__ . '/../includes/ai_portal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$program_code = isset($_GET['program_code']) ? trim((string)$_GET['program_code']) : '';
$intake = isset($_GET['intake']) ? trim((string)$_GET['intake']) : '';
$mode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : '';
$startYear = isset($_GET['startYear']) ? trim((string)$_GET['startYear']) : '';

$programs = [];
if ($results = $db->query("SELECT * FROM programs ORDER BY program_name")) {
    while ($row = $results->fetch_object()) {
        $programs[] = $row;
    }
    $results->free();
}

$records_1 = [];
$error_message = '';
$report_generated = isset($_GET['submit']);

if ($report_generated) {
    $currentYear = (int)date('Y');
    if ($program_code === '' || $intake === '' || $mode === '' || $startYear === '') {
        $error_message = 'Please select program, intake, study mode, and start year before generating a report.';
    } elseif (!in_array($intake, ['January', 'June'], true) ||
        !in_array($mode, ['Full-Time', 'Part-Time(Evening)', 'Distance', 'Short Course'], true) ||
        !ctype_digit($startYear) ||
        (int)$startYear < 2001 ||
        (int)$startYear > $currentYear
    ) {
        $error_message = 'Invalid report filter selected. Please try again.';
    } else {
        $sql = "SELECT students.SID AS Sid, students.Fname, students.Lname, students.sex,
                       programs.program_name, programs.program_type AS level,
                       student_program.intake, student_program.mode, student_program.startYear
                FROM students
                INNER JOIN student_program ON students.SID = student_program.Sid
                INNER JOIN programs ON student_program.program_code = programs.program_code
                WHERE student_program.program_code = ?
                  AND student_program.intake = ?
                  AND student_program.mode = ?
                  AND student_program.startYear = ?
                ORDER BY students.Lname, students.Fname";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('ssss', $program_code, $intake, $mode, $startYear);
            $stmt->execute();
            $results1 = $stmt->get_result();
            while ($row = $results1->fetch_object()) {
                $records_1[] = $row;
            }
            $stmt->close();
        } else {
            $error_message = 'Unable to prepare report query. Please contact the system administrator.';
        }
    }
}

// AI summary calculation
$aiResult = null;
if ($report_generated && empty($error_message) && !empty($records_1) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ai_summary') {
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $aiRows = [];
        foreach ($records_1 as $r) {
            $aiRows[] = [
                'Sid' => $r->Sid,
                'Names' => trim(($r->Fname ?? '') . ' ' . ($r->Lname ?? '')),
                'sex' => $r->sex,
                'program_name' => $r->program_name,
                'level' => $r->level,
                'intake' => $r->intake,
                'mode' => $r->mode,
                'startYear' => $r->startYear
            ];
        }

        $context = [
            'report' => 'Student Registration Report',
            'role' => 'Executive',
            'filters' => [
                'Program' => $program_code,
                'Intake' => $intake,
                'Mode' => $mode,
                'Start Year' => $startYear
            ],
            'columns' => ['Student ID', 'Names', 'Gender', 'Program', 'Level', 'Intake', 'Study mode', 'Start Year'],
            'rows' => array_slice($aiRows, 0, 40),
            'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
        ];
        $ctxJson = wuc_ai_context_json($context, 14000);
        $aiResult = wuc_ai_generate($db, [
            'feature' => 'vc_report_summary',
            'user_role' => 'vc',
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'vc'),
            'input_summary' => 'Executive Student Report Summary',
            'context_hash' => hash('sha256', $ctxJson),
            'messages' => [
                ['role' => 'system', 'content' => 'You summarise a Student Registration Report for the Executive (VC). Use ONLY the supplied rows. Give a concise narrative of total students, gender distribution, and key registration insights.'],
                ['role' => 'user', 'content' => "Report data (JSON):\n{$ctxJson}\n\nWrite the summary."],
            ],
            'fallback' => static function () use ($records_1): string {
                return "Student Registration Report — " . count($records_1) . " students registered. AI summary unavailable.";
            },
        ]);
    }
}

// Totals calculations
$totalStudents = count($records_1);
$maleCount = 0;
$femaleCount = 0;
foreach ($records_1 as $r) {
    if (strtoupper(substr(trim((string)($r->sex ?? '')), 0, 1)) === 'M') {
        $maleCount++;
    } elseif (strtoupper(substr(trim((string)($r->sex ?? '')), 0, 1)) === 'F') {
        $femaleCount++;
    }
}

$program_name_print = $program_code;
foreach ($programs as $rp) {
    if ($rp->program_code === $program_code) {
        $program_name_print = $rp->program_name;
        break;
    }
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <?php
    render_report_print_styles();
    render_report_print_script();
    if ($report_generated && $error_message === '') {
        render_report_print_header(
            'Student Report',
            $program_name_print,
            [
                'Program' => $program_name_print,
                'Intake'  => $intake,
                'Mode'    => $mode,
                'Year From' => $startYear,
                'Records' => $totalStudents,
            ]
        );
    }
    ?>

    <!-- Page Header -->
    <div class="dashboard-header admin-section mb-4 d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-chart-line me-2 text-primary"></i>Executive Student Report</h1>
                <p class="text-muted mb-0">Generate and print student registration lists and statistics by program, intake, mode, and enrolment year.</p>
            </div>
        </div>
    </div>

    <?php if ($error_message !== ''): ?>
        <div class="alert alert-danger d-print-none" role="alert">
            <i class="fas fa-circle-exclamation me-2"></i><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
        </div>
        <div class="card-body p-4">
            <form role="form" method="GET" action="reportManager.php" class="row g-3">
                <div class="col-md-4">
                    <label for="program_code" class="form-label fw-semibold">Program of study:</label>
                    <select class="form-select rounded-3" name="program_code" id="program_code" required>
                        <option value="" disabled <?php echo $program_code === '' ? 'selected' : ''; ?>>Select program</option>
                        <?php foreach ($programs as $r): ?>
                            <option value="<?php echo htmlspecialchars($r->program_code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $r->program_code === $program_code ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r->program_name, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="intake" class="form-label fw-semibold">Intake:</label>
                    <select class="form-select rounded-3" id="intake" name="intake" required>
                        <option value="" disabled <?php echo $intake === '' ? 'selected' : ''; ?>>Select Intake</option>
                        <option <?php echo $intake === 'January' ? 'selected' : ''; ?>>January</option>
                        <option <?php echo $intake === 'June' ? 'selected' : ''; ?>>June</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="mode" class="form-label fw-semibold">Study Mode:</label>
                    <select class="form-select rounded-3" name="mode" id="mode" required>
                        <option value="" disabled <?php echo $mode === '' ? 'selected' : ''; ?>>Select Mode</option>
                        <option <?php echo $mode === 'Full-Time' ? 'selected' : ''; ?>>Full-Time</option>
                        <option <?php echo $mode === 'Part-Time(Evening)' ? 'selected' : ''; ?>>Part-Time(Evening)</option>
                        <option <?php echo $mode === 'Distance' ? 'selected' : ''; ?>>Distance</option>
                        <option <?php echo $mode === 'Short Course' ? 'selected' : ''; ?>>Short Course</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="startYear" class="form-label fw-semibold">Start Year:</label>
                    <select class="form-select rounded-3" id="startYear" name="startYear" required>
                        <option value="" disabled <?php echo $startYear === '' ? 'selected' : ''; ?>>Pick year</option>
                    </select>
                </div>
                <div class="col-12 text-end mt-3">
                    <button type="submit" name="submit" value="1" class="btn btn-primary px-4 py-2 rounded-pill shadow-sm">
                        <i class="fas fa-search me-2"></i>Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($report_generated && $error_message === ''): ?>
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
                <form method="post" action="reportManager.php?program_code=<?php echo urlencode($program_code); ?>&intake=<?php echo urlencode($intake); ?>&mode=<?php echo urlencode($mode); ?>&startYear=<?php echo urlencode($startYear); ?>&submit=1" class="m-0">
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
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-list me-2"></i>Registered Trainees</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i>Export Excel
                </button>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" onclick="printReport('Student Report')">
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
                                <th>No.</th>
                                <th>Student ID</th>
                                <th>Names</th>
                                <th>Gender</th>
                                <th>Program</th>
                                <th>Level/Type</th>
                                <th>Intake</th>
                                <th>Study mode</th>
                                <th>Start Year</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $number = 1; foreach ($records_1 as $r): ?>
                                <tr>
                                    <td><?php echo $number++; ?>.</td>
                                    <td><strong><?php echo htmlspecialchars($r->Sid ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td><?php echo htmlspecialchars(trim(($r->Fname ?? '') . ' ' . ($r->Lname ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($r->sex === 'M' ? 'info' : ($r->sex === 'F' ? 'danger' : 'secondary')); ?> px-2 py-1">
                                            <?php echo htmlspecialchars($r->sex ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($r->program_name ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-capitalize"><?php echo htmlspecialchars($r->level ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r->intake ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r->mode ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r->startYear ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($records_1)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
                                        <i class="fas fa-folder-open fa-2x mb-2 d-block"></i>
                                        No registered students found for the selected filters.
                                    </td>
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
    let selectedStartYear = <?php echo json_encode($startYear); ?>;
    let startYear = 2000;
    let endYear = new Date().getFullYear();
    for (var i = endYear; i > startYear; i--) {
        $('#startYear').append($('<option />').val(i).html(i).prop('selected', String(i) === selectedStartYear));
    }

    if ($('#reportTable tbody tr').length > 0 && typeof $.fn.DataTable !== 'undefined') {
        $('#reportTable').DataTable({
            pageLength: 25,
            responsive: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            language: {
                search: "",
                searchPlaceholder: "Search registered students...",
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
    link.download = 'student_registration_report.xls';
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
