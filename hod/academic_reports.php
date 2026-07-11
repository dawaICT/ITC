<?php
$page_title = 'Academic Reports';

require_once dirname(__DIR__) . '/includes/session_guard.php';
wuc_enforce_session_guard([
    'context' => 'head-of-section',
    'session_keys' => ['staff_id', 'user_id'],
    'activity_keys' => ['last_activity', 'last_active_time'],
    'timeout' => 1800,
    'post_grace' => 30,
    'login_path' => '/wucportal/staff_login.php',
    'flash_key' => 'errorMessage',
    'timeout_message' => 'Your session has expired. Please log in again.',
    'login_message' => 'Please log in to access the Head of Section Portal.',
]);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/portal_access.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/hos_section_helpers.php';
require_once dirname(__DIR__) . '/includes/itc_report_helpers.php';
require_once dirname(__DIR__) . '/includes/report_print.php';
require_once dirname(__DIR__) . '/includes/audit.php';

wuc_require_portal_access($db, 'academic', 'You do not have permission to access Head of Section academic reports from the current portal.');

$staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
if (!hasRole(ROLE_HEAD_OF_DEPARTMENT) && !isSystemsAdmin()) {
    $_SESSION['errorMessage'] = 'Access denied. You do not have permission to access the Head of Section Portal.';
    wuc_safe_redirect('/wucportal/portal_selection.php');
}

hos_hydrate_section_session($db, $staffId);
$activeSectionId = (string)($_SESSION['hos_section_id'] ?? '');
$activeSectionName = (string)($_SESSION['hos_section_name'] ?? '');

function hod_academic_report_department_scope(mysqli $db, string $staffId): array
{
    $scope = [
        'unrestricted' => false,
        'section_ids' => [],
        'department_ids' => [],
        'department_names' => [],
    ];
    if ($staffId === '' || !itc_report_table_exists($db, 'department_assignments')) {
        return $scope;
    }

    $stmt = $db->prepare("
        SELECT DISTINCT da.department_id, d.department_name, d.section_id
        FROM department_assignments da
        INNER JOIN departments d ON d.id = da.department_id
        WHERE da.staff_id = ?
          AND LOWER(COALESCE(da.assignment_type, '')) IN ('hod', 'head_of_department', 'department_head')
          AND COALESCE(da.status, 'active') = 'active'
          AND COALESCE(d.status, 'active') = 'active'
        ORDER BY d.department_name
    ");
    if (!$stmt) {
        return $scope;
    }
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $departmentId = (int)($row['department_id'] ?? 0);
        if ($departmentId > 0) {
            $scope['department_ids'][] = $departmentId;
        }
        $sectionId = trim((string)($row['section_id'] ?? ''));
        if ($sectionId !== '') {
            $scope['section_ids'][] = $sectionId;
        }
        $departmentName = trim((string)($row['department_name'] ?? ''));
        if ($departmentName !== '') {
            $scope['department_names'][] = $departmentName;
        }
    }
    $stmt->close();

    $scope['department_ids'] = array_values(array_unique(array_map('intval', $scope['department_ids'])));
    $scope['section_ids'] = array_values(array_unique($scope['section_ids']));
    $scope['department_names'] = array_values(array_unique($scope['department_names']));
    sort($scope['department_ids']);
    sort($scope['section_ids']);

    return $scope;
}

function hod_academic_report_section_scope(mysqli $db, string $sectionId): array
{
    $scope = [
        'unrestricted' => false,
        'section_ids' => $sectionId !== '' ? [$sectionId] : [],
        'department_ids' => [],
    ];
    if ($sectionId === '') {
        return $scope;
    }

    $stmt = $db->prepare("SELECT id FROM departments WHERE section_id = ? AND COALESCE(status, 'active') = 'active'");
    if ($stmt) {
        $stmt->bind_param('s', $sectionId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $departmentId = (int)($row['id'] ?? 0);
            if ($departmentId > 0) {
                $scope['department_ids'][] = $departmentId;
            }
        }
        $stmt->close();
    }
    return $scope;
}

function hod_report_csv(string $filename, array $filters, array $report): void
{
    global $db;
    if (isset($db) && $db instanceof mysqli && function_exists('audit_log_current_user')) {
        audit_log_current_user($db, 'reports.hos_academic.export', [
            'filename' => $filename,
            'filters' => $filters,
            'summary' => $report['summary'] ?? [],
        ]);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if (!$out) {
        return;
    }
    fputcsv($out, ['Report Type', $filters['report_type']]);
    fputcsv($out, ['Section', $filters['section_id']]);
    fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, ['Section', 'Item', 'Parent', 'Metric A', 'Metric B', 'Metric C']);
    foreach (itc_report_export_rows($report) as $row) {
        fputcsv($out, [$row['section'], $row['item'], $row['parent'], $row['metric_a'], $row['metric_b'], $row['metric_c']]);
    }
    fclose($out);
}

function hod_report_url(array $params): string
{
    return '?' . http_build_query(array_merge($_GET, $params));
}

$departmentScope = hod_academic_report_department_scope($db, $staffId);
$hasDepartmentScope = !empty($departmentScope['department_ids']) && isSystemsAdmin();
$request = $_GET;
if (!isSystemsAdmin()) {
    unset($request['section_id'], $request['department_id']);
}
if (empty($request['report_type'])) {
    $request['report_type'] = $hasDepartmentScope
        ? 'DEPARTMENT_REPORT'
        : ($activeSectionId === 'TRANSPORT' ? 'TRANSPORT_SECTION_REPORT' : 'SECTION_REPORT');
}
$filters = itc_report_normalize_filters($request);
if ($hasDepartmentScope) {
    $scope = $departmentScope;
    if (count($departmentScope['section_ids']) === 1) {
        $filters['section_id'] = $departmentScope['section_ids'][0];
    } elseif (!in_array($filters['section_id'], $departmentScope['section_ids'], true)) {
        $filters['section_id'] = '';
    }
    if ($filters['department_id'] > 0 && !in_array($filters['department_id'], $departmentScope['department_ids'], true)) {
        $filters['department_id'] = 0;
    }
    if (count($departmentScope['department_ids']) === 1 && $filters['department_id'] === 0) {
        $filters['department_id'] = $departmentScope['department_ids'][0];
    }
} else {
    $filters['section_id'] = $activeSectionId;
    $scope = hod_academic_report_section_scope($db, $activeSectionId);
}
$options = itc_report_filter_options_by_scope(itc_report_fetch_options($db), $scope);

$hasReportScope = $hasDepartmentScope || $activeSectionId !== '';
$scopeTitle = $hasDepartmentScope ? 'Department Academic Report' : 'Head of Section Academic Report';
$scopeLabel = $hasDepartmentScope
    ? implode(', ', $departmentScope['department_names'])
    : $activeSectionName;
$reportGenerated = isset($_GET['generate']) && $hasReportScope;
$report = [
    'summary' => [],
    'departments' => [],
    'programs' => [],
    'lecturers' => [],
    'registration_statuses' => [],
    'result_statuses' => [],
    'elearning_activity' => [],
];
$error = '';
$logId = null;

if (!$hasReportScope) {
    $error = 'No active department or section report scope is assigned to your account.';
} elseif ($reportGenerated) {
    try {
        $report = itc_report_generate($db, $filters, $scope);
        $logId = itc_report_log_generation($db, $filters, $staffId, $scope);
        if (($_GET['export'] ?? '') === 'csv') {
            hod_report_csv('hos_academic_report_' . date('Ymd_His') . '.csv', $filters, $report);
            exit;
        }
    } catch (Throwable $e) {
        error_log('hod/academic_reports.php failed: ' . $e->getMessage());
        $error = 'The academic report could not be generated. Please adjust the filters and try again.';
    }
}

function hod_report_stat(array $summary, string $key): string
{
    return number_format((int)($summary[$key] ?? 0));
}

require __DIR__ . '/includes/nav.php';
render_report_print_styles();
render_report_print_script();
render_report_print_header($scopeTitle, $scopeLabel, [
    $hasDepartmentScope ? 'Department' : 'Section' => $scopeLabel,
    'Report Type' => $filters['report_type'],
    'Generated By' => $staffId,
    'Report Log' => $logId ? '#' . $logId : 'Not generated',
]);
?>

<div class="container-fluid px-4 portal-dashboard hod-page">
    <div class="dashboard-header admin-section mb-4 d-print-none">
        <div>
            <h1 class="dashboard-title"><i class="fas fa-chart-pie me-2"></i>Academic Reports</h1>
            <p class="text-muted mb-0"><?php echo itc_report_h($scopeLabel ?: 'Your assigned scope'); ?> normalized academic reports.</p>
        </div>
        <div class="header-actions">
            <?php if ($reportGenerated && $error === ''): ?>
                <a class="btn btn-outline-primary rounded-pill px-3 me-2" href="<?php echo itc_report_h(hod_report_url(['generate' => 1, 'export' => 'csv'])); ?>">
                    <i class="fas fa-file-csv me-1"></i>Export CSV
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary rounded-pill px-3" onclick="printReport('<?php echo itc_report_h($scopeTitle); ?>')" <?php echo !$reportGenerated ? 'disabled' : ''; ?>>
                <i class="fas fa-print me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger d-print-none" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo itc_report_h($error); ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-body p-4">
            <form method="get" class="row g-3 align-items-end">
                <input type="hidden" name="generate" value="1">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Report Type</label>
                    <select class="form-select" name="report_type">
                        <?php foreach (['SECTION_REPORT','DEPARTMENT_REPORT','PROGRAMME_REPORT','LECTURER_WORKLOAD_REPORT','CA_SUBMISSION_REPORT','STUDENT_REGISTRATION_REPORT','EXAMINATION_REPORT','TRANSPORT_SECTION_REPORT','ELEARNING_ACTIVITY_REPORT'] as $type): ?>
                            <option value="<?php echo itc_report_h($type); ?>" <?php echo (string)$filters['report_type'] === $type ? 'selected' : ''; ?>>
                                <?php echo itc_report_h(str_replace('_', ' ', $type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Department</label>
                    <select class="form-select" name="department_id">
                        <option value="0"><?php echo $hasDepartmentScope ? 'All Assigned Departments' : 'All Section Departments'; ?></option>
                        <?php foreach ($options['departments'] as $row): ?>
                            <option value="<?php echo (int)$row['id']; ?>" <?php echo (int)$filters['department_id'] === (int)$row['id'] ? 'selected' : ''; ?>>
                                <?php echo itc_report_h($row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Programme</label>
                    <select class="form-select" name="program_code">
                        <option value="">All Programmes</option>
                        <?php foreach ($options['programs'] as $row): ?>
                            <option value="<?php echo itc_report_h($row['id']); ?>" <?php echo (string)$filters['program_code'] === (string)$row['id'] ? 'selected' : ''; ?>>
                                <?php echo itc_report_h($row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Academic Year</label>
                    <select class="form-select" name="academic_year_id">
                        <option value="0">All Years</option>
                        <?php foreach ($options['academic_years'] as $row): ?>
                            <option value="<?php echo (int)$row['id']; ?>" <?php echo (int)$filters['academic_year_id'] === (int)$row['id'] ? 'selected' : ''; ?>>
                                <?php echo itc_report_h($row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Period</label>
                    <select class="form-select" name="academic_period_id">
                        <option value="0">All Periods</option>
                        <?php foreach ($options['academic_periods'] as $row): ?>
                            <option value="<?php echo (int)$row['id']; ?>" <?php echo (int)$filters['academic_period_id'] === (int)$row['id'] ? 'selected' : ''; ?>>
                                <?php echo itc_report_h($row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Intake</label>
                    <select class="form-select" name="intake_id">
                        <option value="0">All Intakes</option>
                        <?php foreach ($options['intakes'] as $row): ?>
                            <option value="<?php echo (int)$row['id']; ?>" <?php echo (int)$filters['intake_id'] === (int)$row['id'] ? 'selected' : ''; ?>>
                                <?php echo itc_report_h($row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Gender</label>
                    <select class="form-select" name="gender">
                        <option value="">All</option>
                        <option value="M" <?php echo (string)$filters['gender'] === 'M' ? 'selected' : ''; ?>>Male</option>
                        <option value="F" <?php echo (string)$filters['gender'] === 'F' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Registration</label>
                    <select class="form-select" name="registration_status">
                        <?php foreach (['' => 'All', 'REGISTERED' => 'Registered', 'COMPLETED' => 'Completed', 'REPEATING' => 'Repeating', 'DROPPED' => 'Dropped', 'DEFERRED' => 'Deferred'] as $value => $label): ?>
                            <option value="<?php echo itc_report_h($value); ?>" <?php echo (string)$filters['registration_status'] === (string)$value ? 'selected' : ''; ?>><?php echo itc_report_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Assessment</label>
                    <select class="form-select" name="assessment_status">
                        <?php foreach (['' => 'All', 'DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'APPROVED_BY_HOD' => 'Approved', 'LOCKED' => 'Locked', 'RETURNED_FOR_CORRECTION' => 'Returned'] as $value => $label): ?>
                            <option value="<?php echo itc_report_h($value); ?>" <?php echo (string)$filters['assessment_status'] === (string)$value ? 'selected' : ''; ?>><?php echo itc_report_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Result</label>
                    <select class="form-select" name="result_status">
                        <?php foreach (['' => 'All', 'PASS' => 'Pass', 'FAIL' => 'Fail', 'INCOMPLETE' => 'Incomplete', 'DEFERRED' => 'Deferred', 'REFERRED' => 'Referred', 'EXEMPTED' => 'Exempted'] as $value => $label): ?>
                            <option value="<?php echo itc_report_h($value); ?>" <?php echo (string)$filters['result_status'] === (string)$value ? 'selected' : ''; ?>><?php echo itc_report_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Course</label>
                    <select class="form-select" name="course_code">
                        <option value="">All Courses</option>
                        <?php foreach ($options['courses'] as $row): ?>
                            <option value="<?php echo itc_report_h($row['id']); ?>" <?php echo (string)$filters['course_code'] === (string)$row['id'] ? 'selected' : ''; ?>>
                                <?php echo itc_report_h($row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Lecturer</label>
                    <select class="form-select" name="lecturer_id">
                        <option value="">All Lecturers</option>
                        <?php foreach ($options['lecturers'] as $row): ?>
                            <option value="<?php echo itc_report_h($row['id']); ?>" <?php echo (string)$filters['lecturer_id'] === (string)$row['id'] ? 'selected' : ''; ?>>
                                <?php echo itc_report_h($row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Student ID</label>
                    <input class="form-control" name="student_id" value="<?php echo itc_report_h($filters['student_id']); ?>" placeholder="e.g. CSE26012345">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Trade Test Level</label>
                    <select class="form-select" name="level_number">
                        <option value="0">All Levels</option>
                        <?php foreach ([1, 2, 3] as $level): ?>
                            <option value="<?php echo $level; ?>" <?php echo (int)$filters['level_number'] === $level ? 'selected' : ''; ?>>Level <?php echo $level; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100 rounded-pill" type="submit">
                        <i class="fas fa-chart-line me-1"></i>Generate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$reportGenerated): ?>
        <div class="empty-state py-5 text-center">
            <i class="fas fa-filter fa-3x text-muted mb-3"></i>
            <p class="text-muted mb-0">Choose filters and generate a section-scoped academic report.</p>
        </div>
    <?php else: ?>
        <div id="print">
            <div class="row g-3 mb-4">
                <?php foreach ([
                    ['Sections', 'sections', 'fa-sitemap'],
                    ['Departments', 'departments', 'fa-building'],
                    ['Programmes', 'programmes', 'fa-graduation-cap'],
                    ['Students', 'students', 'fa-users'],
                    ['Offerings', 'course_offerings', 'fa-chalkboard-teacher'],
                    ['Published Results', 'published_results', 'fa-square-poll-vertical'],
                ] as $stat): ?>
                    <div class="col-md-2 col-sm-6">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body">
                                <div class="text-muted small"><?php echo itc_report_h($stat[0]); ?></div>
                                <div class="fs-4 fw-bold text-dark"><?php echo hod_report_stat($report['summary'], $stat[1]); ?></div>
                                <i class="fas <?php echo itc_report_h($stat[2]); ?> text-primary opacity-50"></i>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-building me-2"></i>Department Summary</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Section</th><th>Department</th><th>Programmes</th><th>Students</th><th>Results</th></tr></thead>
                                <tbody>
                                <?php foreach ($report['departments'] as $row): ?>
                                    <tr>
                                        <td><?php echo itc_report_h($row['section_name']); ?></td>
                                        <td><?php echo itc_report_h($row['department_name']); ?></td>
                                        <td><?php echo (int)$row['programmes']; ?></td>
                                        <td><?php echo (int)$row['students']; ?></td>
                                        <td><?php echo (int)$row['results']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$report['departments']): ?><tr><td colspan="5" class="text-muted text-center py-3">No matching records.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-user-tie me-2"></i>Lecturer Workload</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Staff ID</th><th>Name</th><th>Offerings</th><th>Courses</th><th>Registrations</th></tr></thead>
                                <tbody>
                                <?php foreach ($report['lecturers'] as $row): ?>
                                    <tr>
                                        <td><?php echo itc_report_h($row['staff_id']); ?></td>
                                        <td><?php echo itc_report_h($row['lecturer_name'] ?: 'Unassigned'); ?></td>
                                        <td><?php echo (int)$row['offerings']; ?></td>
                                        <td><?php echo (int)$row['courses']; ?></td>
                                        <td><?php echo (int)$row['registered_students']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$report['lecturers']): ?><tr><td colspan="5" class="text-muted text-center py-3">No matching records.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-graduation-cap me-2"></i>Programme Breakdown</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Department</th><th>Code</th><th>Programme</th><th>Structure</th><th>Offerings</th><th>Registrations</th><th>Students</th><th>Lecturers</th></tr></thead>
                                <tbody>
                                <?php foreach ($report['programs'] as $row): ?>
                                    <tr>
                                        <td><?php echo itc_report_h($row['department_name']); ?></td>
                                        <td><?php echo itc_report_h($row['program_code']); ?></td>
                                        <td><?php echo itc_report_h($row['program_name']); ?></td>
                                        <td><?php echo itc_report_h($row['structure_type']); ?></td>
                                        <td><?php echo (int)$row['offerings']; ?></td>
                                        <td><?php echo (int)$row['registrations']; ?></td>
                                        <td><?php echo (int)$row['students']; ?></td>
                                        <td><?php echo (int)$row['lecturers']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$report['programs']): ?><tr><td colspan="8" class="text-muted text-center py-3">No matching records.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php
    // Section-scoped rule-based insight block (Sprint 4). Uses the same
    // department scope as the report above; persists to report_insights when a
    // report was generated so history stays auditable.
    if ($hasReportScope && !empty($scope['department_ids'])) {
        try {
            require_once dirname(__DIR__) . '/includes/hos_report_insights.php';
            $hosInsights = wuc_hos_report_insights($db, $scope['department_ids']);
            if ($reportGenerated && $error === '') {
                wuc_hos_persist_report_insights(
                    $db,
                    'hos_academic_report',
                    $hasDepartmentScope ? 'department' : 'section',
                    $hasDepartmentScope ? implode(',', $scope['department_ids']) : $activeSectionId,
                    $hosInsights,
                    $staffId
                );
            }
            echo wuc_hos_render_report_insights($hosInsights, $scopeLabel);
        } catch (Throwable $e) {
            error_log('hod/academic_reports.php insights failed: ' . $e->getMessage());
        }
    }
    ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
