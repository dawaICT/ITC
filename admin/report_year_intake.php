<?php
require "includes/admin.php";
require_once __DIR__ . '/../includes/report_print.php';
require_once __DIR__ . '/../includes/ai_portal.php';
require_once __DIR__ . '/../includes/sponsorship_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$page_title = 'Student Sponsorship Report';
$records = [];
$summary = [
    'total' => 0,
    'male' => 0,
    'female' => 0,
    'specified' => 0,
    'not_specified' => 0,
    'cdf' => 0,
    'teveta' => 0,
    'self' => 0,
    'other' => 0,
];
$number = 1;
$errors = [];
$report_generated = isset($_POST['search']) || isset($_GET['search']);

function sponsorship_report_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sponsorship_report_category(?string $label): string
{
    $value = strtolower(trim((string)$label));
    if ($value === '' || $value === 'not specified') {
        return 'not_specified';
    }
    if (strpos($value, 'cdf') !== false || strpos($value, 'constituency') !== false) {
        return 'cdf';
    }
    if (strpos($value, 'teveta') !== false) {
        return 'teveta';
    }
    if (strpos($value, 'self') !== false) {
        return 'self';
    }
    return 'other';
}

function sponsorship_report_table_exists(mysqli $db, string $table): bool
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

$programs = [];
if (sponsorship_report_table_exists($db, 'programs')) {
    $where = '';
    if ($res = @$db->query("SHOW COLUMNS FROM programs LIKE 'is_active'")) {
        $where = $res->num_rows > 0 ? 'WHERE COALESCE(is_active, 1) = 1' : '';
        $res->free();
    }
    $sql = "SELECT program_code, program_name FROM programs {$where} ORDER BY program_name";
    if ($result = $db->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $programs[] = $row;
        }
        $result->free();
    }
}

$sponsor_options = [];
// 1. Load active database-driven sponsor types
if ($result = $db->query("SELECT id, name, code FROM sponsor_types WHERE is_active = 1 ORDER BY sort_order, name")) {
    while ($row = $result->fetch_assoc()) {
        $sponsor_options[$row['code']] = $row['name'];
    }
    $result->free();
}

// 2. Load legacy distinct sponsor labels
if ($result = $db->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(sponsor), ''), 'Not specified') AS sponsor_label FROM students ORDER BY sponsor_label")) {
    while ($row = $result->fetch_assoc()) {
        $label = $row['sponsor_label'];
        if ($label === 'Not specified') { continue; }
        
        // Resolve to see if it maps to any database-driven type
        $resolved = sps_resolve_sponsor_type_from_string($db, $label);
        if ($resolved && isset($sponsor_options[$resolved['code']])) {
            continue;
        }
        
        $sponsor_options[$label] = $label;
    }
    $result->free();
}

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

$reqData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$filters = [
    'sponsor' => trim((string)($reqData['sponsor'] ?? 'all')),
    'program_code' => trim((string)($reqData['program_code'] ?? '')),
    'academic_year' => trim((string)($reqData['academic_year'] ?? 'all')),
    'gender' => trim((string)($reqData['gender'] ?? '')),
    'status' => trim((string)($reqData['status'] ?? 'active')),
];

if (!in_array($filters['gender'], ['', 'M', 'F'], true)) {
    $filters['gender'] = '';
}
if (!in_array($filters['status'], ['all', 'active', 'inactive'], true)) {
    $filters['status'] = 'active';
}
if ($filters['academic_year'] !== 'all' && !ctype_digit($filters['academic_year'])) {
    $filters['academic_year'] = 'all';
}

if ($report_generated) {
    $where = ["1=1"];
    $types = '';
    $params = [];

    if ($filters['sponsor'] !== 'all') {
        if ($filters['sponsor'] === '__not_specified__') {
            $where[] = "(s.sponsor IS NULL OR TRIM(s.sponsor) = '') AND (st.name IS NULL)";
        } else {
            $where[] = "(TRIM(s.sponsor) = ? OR TRIM(st.name) = ? OR TRIM(st.code) = ?)";
            $types .= 'sss';
            $params[] = $filters['sponsor'];
            $params[] = $filters['sponsor'];
            $params[] = $filters['sponsor'];
        }
    }
    if ($filters['program_code'] !== '') {
        $where[] = "sp.program_code = ?";
        $types .= 's';
        $params[] = $filters['program_code'];
    }
    if ($filters['academic_year'] !== 'all') {
        $where[] = "COALESCE(NULLIF(sp.academic_year, ''), NULLIF(s.academic_year, ''), sp.startYear) = ?";
        $types .= 'i';
        $params[] = (int)$filters['academic_year'];
    }
    if ($filters['gender'] !== '') {
        $where[] = "s.sex = ?";
        $types .= 's';
        $params[] = $filters['gender'];
    }
    if ($filters['status'] !== 'all') {
        $where[] = "COALESCE(s.status, 'active') = ?";
        $types .= 's';
        $params[] = $filters['status'];
    }

    $sql = "SELECT
                s.SID,
                s.Fname,
                s.Lname,
                s.sex,
                COALESCE(st.name, NULLIF(TRIM(s.sponsor), ''), 'Not specified') AS sponsor_label,
                COALESCE(s.status, 'active') AS student_status,
                COALESCE(p.program_name, sp.program_code, s.program, 'Unassigned Program') AS program_name,
                COALESCE(sp.program_code, s.program, '') AS program_code,
                COALESCE(NULLIF(sp.mode, ''), NULLIF(s.mode, ''), 'Not set') AS study_mode,
                COALESCE(NULLIF(sp.intake, ''), NULLIF(s.intake, ''), 'Not set') AS intake,
                COALESCE(NULLIF(sp.academic_year, ''), NULLIF(s.academic_year, ''), sp.startYear) AS academic_year,
                fss.coverage_percent,
                fss.amount_approved,
                fss.amount_released,
                fss.approval_status
            FROM students s
            LEFT JOIN student_program sp ON sp.id = (
                SELECT sp2.id
                  FROM student_program sp2
                 WHERE TRIM(UPPER(sp2.Sid)) = TRIM(UPPER(s.SID))
                 ORDER BY
                    CASE WHEN COALESCE(sp2.status, 'active') = 'active' THEN 0 ELSE 1 END,
                    sp2.term_start_date DESC,
                    sp2.id DESC
                 LIMIT 1
            )
            LEFT JOIN programs p ON TRIM(UPPER(p.program_code)) = TRIM(UPPER(sp.program_code))
            LEFT JOIN finance_student_sponsors fss ON fss.student_id = s.SID AND fss.approval_status = 'approved'
            LEFT JOIN sponsor_types st ON st.id = fss.sponsor_type_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sponsor_label, program_name, s.Lname, s.Fname, s.SID";

    try {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $summary['total'] = count($records);
        foreach ($records as $row) {
            if (($row['sex'] ?? '') === 'M') {
                $summary['male']++;
            } elseif (($row['sex'] ?? '') === 'F') {
                $summary['female']++;
            }

            $category = sponsorship_report_category($row['sponsor_label'] ?? '');
            $summary[$category]++;
            if ($category !== 'not_specified') {
                $summary['specified']++;
            }
        }
    } catch (Throwable $e) {
        error_log('sponsorship report failed: ' . $e->getMessage());
        $errors[] = 'Error generating sponsorship report. Please try again or contact support.';
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
                'sex' => $r['sex'],
                'sponsor' => $r['sponsor_label'],
                'program' => $r['program_name'],
                'mode' => $r['study_mode'],
                'academic_year' => $r['academic_year']
            ];
        }

        $context = [
            'report' => 'Student Sponsorship Report',
            'role' => 'Administrator',
            'filters' => $filters,
            'columns' => ['SID', 'Name', 'sex', 'sponsor', 'program', 'mode', 'academic_year'],
            'rows' => array_slice($aiRows, 0, 40),
            'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
        ];
        $ctxJson = wuc_ai_context_json($context, 14000);
        $aiResult = wuc_ai_generate($db, [
            'feature' => 'admin_sponsorship_summary',
            'user_role' => 'admin',
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'admin'),
            'input_summary' => 'Sponsorship Report Summary',
            'context_hash' => hash('sha256', $ctxJson),
            'messages' => [
                ['role' => 'system', 'content' => 'You summarise a Student Sponsorship Report. Use ONLY the supplied rows. Give a concise narrative of total students, sponsor-type distributions (CDF, TEVETA, self-sponsored), and action points.'],
                ['role' => 'user', 'content' => "Report data (JSON):\n{$ctxJson}\n\nWrite the summary."],
            ],
            'fallback' => static function () use ($records): string {
                return "Sponsorship Report — " . count($records) . " records found. AI summary unavailable.";
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
            'Student Sponsorship Report',
            $filters['sponsor'] === 'all' ? 'All Sponsorships' : ($filters['sponsor'] === '__not_specified__' ? 'Not Specified' : $filters['sponsor']),
            [
                'Sponsorship' => $filters['sponsor'] === 'all' ? 'All' : ($filters['sponsor'] === '__not_specified__' ? 'Not Specified' : $filters['sponsor']),
                'Program'     => $filters['program_code'] === '' ? 'All' : $program_name_print,
                'Academic Year' => $filters['academic_year'] === 'all' ? 'All' : $filters['academic_year'],
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
                <h1 class="dashboard-title"><i class="fas fa-landmark me-2 text-primary"></i>Student Sponsorship Report</h1>
                <p class="text-muted mb-0">Generate and analyze sponsorship coverage by student, programme, gender, and academic year.</p>
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
                <div><?php echo sponsorship_report_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
        </div>
        <div class="card-body p-4">
            <form action="report_year_intake.php" method="POST" class="row g-3 needs-validation" id="sponsorshipReportForm" novalidate>
                <input type="hidden" name="search" value="1">
                <div class="col-md-3">
                    <label for="sponsor" class="form-label fw-semibold">Sponsorship</label>
                    <select class="form-select rounded-3" name="sponsor" id="sponsor">
                        <option value="all" <?php echo $filters['sponsor'] === 'all' ? 'selected' : ''; ?>>All sponsorships</option>
                        <option value="__not_specified__" <?php echo $filters['sponsor'] === '__not_specified__' ? 'selected' : ''; ?>>Not specified</option>
                        <?php foreach ($sponsor_options as $val => $label): ?>
                            <option value="<?php echo sponsorship_report_h($val); ?>" <?php echo $filters['sponsor'] === (string)$val ? 'selected' : ''; ?>>
                                <?php echo sponsorship_report_h($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="program_code" class="form-label fw-semibold">Program</label>
                    <select class="form-select rounded-3" name="program_code" id="program_code">
                        <option value="">All programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?php echo sponsorship_report_h($program['program_code']); ?>" <?php echo $filters['program_code'] === (string)$program['program_code'] ? 'selected' : ''; ?>>
                                <?php echo sponsorship_report_h($program['program_name']); ?>
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
                    <label for="status" class="form-label fw-semibold">Student Status</label>
                    <select class="form-select rounded-3" id="status" name="status">
                        <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="all" <?php echo $filters['status'] === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <option value="inactive" <?php echo $filters['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-12 text-end mt-3">
                    <button class="btn btn-primary rounded-pill shadow-sm px-4 py-2" type="submit" id="generateSponsorshipReportBtn">
                        <i class="fas fa-search me-1"></i> View report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($report_generated && empty($errors)): ?>
        <!-- KPI summary stats (on screen) -->
        <div class="row g-4 mb-4 d-print-none">
            <div class="col-xl-4 col-md-6">
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
            <div class="col-xl-4 col-md-6">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success text-white rounded-circle p-3 me-3">
                            <i class="fas fa-landmark fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($summary['cdf']); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">CDF Sponsored</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-info text-white rounded-circle p-3 me-3">
                            <i class="fas fa-building-columns fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($summary['teveta']); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">TEVETA Sponsored</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-secondary text-white rounded-circle p-3 me-3">
                            <i class="fas fa-user-graduate fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($summary['self']); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">Self Sponsored</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-warning text-white rounded-circle p-3 me-3">
                            <i class="fas fa-circle-question fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($summary['other'] + $summary['not_specified']); ?></h3>
                            <p class="text-muted mb-0 small fw-semibold">Other / Unspecified</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="stat-card bg-white rounded-4 p-4 shadow-sm border h-100">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-danger text-white rounded-circle p-3 me-3">
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

        <!-- AI Summary Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
            <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>BI Summary Insights</h5>
                <form method="post" action="report_year_intake.php" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="action" value="ai_summary">
                    <input type="hidden" name="search" value="1">
                    <input type="hidden" name="sponsor" value="<?php echo sponsorship_report_h($filters['sponsor']); ?>">
                    <input type="hidden" name="program_code" value="<?php echo sponsorship_report_h($filters['program_code']); ?>">
                    <input type="hidden" name="academic_year" value="<?php echo sponsorship_report_h($filters['academic_year']); ?>">
                    <input type="hidden" name="gender" value="<?php echo sponsorship_report_h($filters['gender']); ?>">
                    <input type="hidden" name="status" value="<?php echo sponsorship_report_h($filters['status']); ?>">
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
            <h5 class="fw-bold text-primary mb-0"><i class="fas fa-list me-2"></i>Sponsorship Records</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i>Export Excel
                </button>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" onclick="printReport('Student Sponsorship Report')">
                    <i class="fas fa-print me-1"></i>Print Report
                </button>
            </div>
        </div>

        <!-- Report Table -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4" id="print">
                <!-- Summary table - print only -->
                <div class="sponsorship-print-summary d-none d-print-block mb-3">
                    <h6 class="text-center fw-bold mb-2">Sponsorship Summary</h6>
                    <table class="table table-bordered table-sm w-auto mx-auto text-center align-middle">
                        <thead>
                            <tr>
                                <th>Total</th>
                                <th>CDF</th>
                                <th>TEVETA</th>
                                <th>Self</th>
                                <th>Other / Unspecified</th>
                                <th>Male</th>
                                <th>Female</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo number_format($summary['total']); ?></td>
                                <td><?php echo number_format($summary['cdf']); ?></td>
                                <td><?php echo number_format($summary['teveta']); ?></td>
                                <td><?php echo number_format($summary['self']); ?></td>
                                <td><?php echo number_format($summary['other'] + $summary['not_specified']); ?></td>
                                <td><?php echo number_format($summary['male']); ?></td>
                                <td><?php echo number_format($summary['female']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>                 <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="sponsorshipTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px">No</th>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Gender</th>
                                <th>Sponsorship</th>
                                <th>Coverage</th>
                                <th>Approved</th>
                                <th>Released</th>
                                <th>Outstanding</th>
                                <th>Program</th>
                                <th>Study Mode</th>
                                <th>Academic Year</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $row): ?>
                                <tr>
                                    <td><?php echo $number++; ?></td>
                                    <td><strong><?php echo sponsorship_report_h($row['SID']); ?></strong></td>
                                    <td><?php echo sponsorship_report_h(trim(($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? '')) ?: 'Name not captured'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($row['sex'] ?? '') === 'M' ? 'info' : (($row['sex'] ?? '') === 'F' ? 'danger' : 'secondary'); ?> px-2 py-1">
                                            <?php echo ($row['sex'] ?? '') === 'M' ? 'Male' : (($row['sex'] ?? '') === 'F' ? 'Female' : 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo ($row['sponsor_label'] ?? '') === 'Not specified' ? 'secondary' : 'success'; ?> px-2 py-1">
                                            <?php echo sponsorship_report_h($row['sponsor_label'] ?? 'Not specified'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo isset($row['coverage_percent']) ? number_format($row['coverage_percent']) . '%' : 'N/A'; ?></td>
                                    <td class="text-success fw-semibold"><?php echo isset($row['amount_approved']) ? 'K' . number_format($row['amount_approved'], 2) : 'N/A'; ?></td>
                                    <td class="text-info fw-semibold"><?php echo isset($row['amount_released']) ? 'K' . number_format($row['amount_released'], 2) : 'N/A'; ?></td>
                                    <td class="text-danger fw-semibold">
                                        <?php 
                                        if (isset($row['amount_approved'], $row['amount_released'])) {
                                            echo 'K' . number_format(max(0.0, $row['amount_approved'] - $row['amount_released']), 2);
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo sponsorship_report_h($row['program_name'] ?? ''); ?></td>
                                    <td><?php echo sponsorship_report_h($row['study_mode'] ?? ''); ?></td>
                                    <td><?php echo sponsorship_report_h($row['academic_year'] ?? ''); ?></td>
                                    <td><?php echo sponsorship_report_h(ucwords(str_replace('_', ' ', (string)($row['student_status'] ?? '')))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($records)): ?>
                                <tr>
                                     <td colspan="13" class="text-center text-muted py-4">No sponsorship records found for the selected criteria.</td>
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
        ['sponsor', 'program_code'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                new Choices(el, { removeItemButton: false, shouldSort: false });
            }
        });
    }

    if (window.jQuery && jQuery.fn && jQuery.fn.DataTable && document.getElementById('sponsorshipTable')) {
        jQuery('#sponsorshipTable').DataTable({
            pageLength: 25,
            responsive: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            language: {
                search: "",
                searchPlaceholder: "Search sponsorship records...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ records"
            }
        });
    }
});

function exportToExcel() {
    var table = document.querySelector('#sponsorshipTable');
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
    link.download = 'sponsorship_report.xls';
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require_once 'includes/footer.php'; ?>
