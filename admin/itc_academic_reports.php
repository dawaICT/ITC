<?php
$page_title = 'ITC Academic Reports';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/role_helpers.php';
require_once __DIR__ . '/../includes/report_print.php';
require_once __DIR__ . '/../includes/itc_report_helpers.php';
require_once __DIR__ . '/../includes/audit.php';

$staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');

function itc_academic_report_roles(): array
{
    $roles = [];
    foreach (['role', 'user_role', 'assigned_access'] as $key) {
        if (!empty($_SESSION[$key])) {
            $roles[] = (string)$_SESSION[$key];
        }
    }
    foreach (['all_roles', 'all_roles_raw'] as $key) {
        if (!empty($_SESSION[$key]) && is_array($_SESSION[$key])) {
            foreach ($_SESSION[$key] as $role) {
                $roles[] = (string)$role;
            }
        }
    }
    $roles = array_map(static function (string $role): string {
        return function_exists('wuc_normalize_staff_role') ? wuc_normalize_staff_role($role, false) : strtolower(trim($role));
    }, $roles);
    return array_values(array_unique(array_filter($roles)));
}

function itc_academic_report_is_global_viewer(string $staffId): bool
{
    $roles = itc_academic_report_roles();
    return (function_exists('isAdmin') && isAdmin($staffId))
        || hasPermission($staffId, 'admin_all')
        || hasPermission($staffId, 'reports.manage')
        || in_array('systems_admin', $roles, true)
        || in_array('registrar', $roles, true)
        || in_array('dean', $roles, true);
}

function itc_academic_report_csv(string $filename, array $filters, array $report): void
{
    global $db;
    if (isset($db) && $db instanceof mysqli && function_exists('audit_log_current_user')) {
        audit_log_current_user($db, 'reports.academic.export', [
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
    fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, ['Section', 'Item', 'Parent', 'Metric A', 'Metric B', 'Metric C']);
    foreach (itc_report_export_rows($report) as $row) {
        fputcsv($out, [
            $row['section'],
            $row['item'],
            $row['parent'],
            $row['metric_a'],
            $row['metric_b'],
            $row['metric_c'],
        ]);
    }
    fclose($out);
}

$hasGlobalReportScope = itc_academic_report_is_global_viewer($staffId);
$canViewReports = $hasGlobalReportScope
    || hasPermission($staffId, 'reports.view')
    || hasPermission($staffId, 'reports.department.view')
    || hasPermission($staffId, 'reports.section.view');

if (!$canViewReports) {
    wuc_permission_denied('You do not have permission to view ITC academic reports.', '/wucportal/portal_selection.php');
}

$filters = itc_report_normalize_filters($_GET);
$scope = $canViewReports ? itc_report_resolve_scope($db, $staffId, $hasGlobalReportScope) : [
    'unrestricted' => false,
    'section_ids' => [],
    'department_ids' => [],
];
if ($canViewReports && !$hasGlobalReportScope && empty($scope['section_ids']) && empty($scope['department_ids'])) {
    $error = 'No section or department report scope is assigned to your account.';
}
$reportGenerated = isset($_GET['generate']) && $canViewReports;
$options = $canViewReports ? itc_report_filter_options_by_scope(itc_report_fetch_options($db), $scope) : [
    'sections' => [],
    'departments' => [],
    'programs' => [],
    'academic_years' => [],
    'academic_periods' => [],
    'intakes' => [],
    'courses' => [],
    'lecturers' => [],
];
$report = [
    'summary' => [],
    'departments' => [],
    'programs' => [],
    'lecturers' => [],
    'registration_statuses' => [],
    'result_statuses' => [],
    'elearning_activity' => [],
];
$logId = null;

if ($reportGenerated) {
    try {
        $report = itc_report_generate($db, $filters, $scope);
        $logId = itc_report_log_generation($db, $filters, $staffId, $scope);
        if (($_GET['export'] ?? '') === 'csv') {
            itc_academic_report_csv('itc_academic_report_' . date('Ymd_His') . '.csv', $filters, $report);
            exit;
        }
    } catch (Throwable $e) {
        error_log('itc_academic_reports.php: report generation failed: ' . $e->getMessage());
        $error = 'The report could not be generated. Please adjust the filters and try again.';
    }
}

function itc_report_selected($actual, $expected): string
{
    return (string)$actual === (string)$expected ? 'selected' : '';
}

function itc_report_stat(array $summary, string $key): string
{
    return number_format((int)($summary[$key] ?? 0));
}

function itc_report_query_url(array $params): string
{
    return '?' . http_build_query($params);
}

require __DIR__ . '/includes/nav.php';
render_report_print_styles();
render_report_print_script();

$printMeta = [
    'Report Type' => $filters['report_type'],
    'Generated By' => $staffId,
    'Report Log' => $logId ? '#' . $logId : 'Not generated',
];
render_report_print_header('ITC Academic Report', 'TEVETA-aligned academic structure summary', $printMeta);
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4 d-print-none">
        <div>
            <h1 class="dashboard-title">
                <i class="fas fa-chart-pie me-2"></i>ITC Academic Reports
            </h1>
            <p class="text-muted mb-0">Generate section, department, programme, CA, results, lecturer workload, registration and eLearning activity reports from normalized academic data.</p>
        </div>
        <div class="header-actions">
            <?php if ($reportGenerated): ?>
                <?php $exportParams = array_merge($_GET, ['generate' => 1, 'export' => 'csv']); ?>
                <a class="btn btn-outline-primary rounded-pill px-3 me-2" href="<?php echo itc_report_h(itc_report_query_url($exportParams)); ?>">
                    <i class="fas fa-file-csv me-1"></i>Export CSV
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary rounded-pill px-3" onclick="printReport('ITC Academic Report')" <?php echo !$reportGenerated ? 'disabled' : ''; ?>>
                <i class="fas fa-print me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if (!empty($error)): ?>
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
                            <option value="<?php echo itc_report_h($type); ?>" <?php echo itc_report_selected($filters['report_type'], $type); ?>>
                                <?php echo itc_report_h(str_replace('_', ' ', $type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Section</label>
                    <select class="form-select" name="section_id">
                        <option value="">All Sections</option>
                        <?php foreach ($options['sections'] as $row): ?>
                            <option value="<?php echo itc_report_h($row['id']); ?>" <?php echo itc_report_selected($filters['section_id'], $row['id']); ?>>
                                <?php echo itc_report_h($row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Department</label>
                    <select class="form-select" name="department_id">
                        <option value="0">All Departments</option>
                        <?php foreach ($options['departments'] as $row): ?>
                            <option value="<?php echo (int)$row['id']; ?>" <?php echo itc_report_selected($filters['department_id'], $row['id']); ?>>
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
                            <option value="<?php echo itc_report_h($row['id']); ?>" <?php echo itc_report_selected($filters['program_code'], $row['id']); ?>>
                                <?php echo itc_report_h($row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Academic Year</label>
                    <select class="form-select" name="academic_year_id">
                        <option value="0">All Years</option>
                        <?php foreach ($options['academic_years'] as $row): ?>
                            <option value="<?php echo (int)$row['id']; ?>" <?php echo itc_report_selected($filters['academic_year_id'], $row['id']); ?>>
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
                            <option value="<?php echo (int)$row['id']; ?>" <?php echo itc_report_selected($filters['academic_period_id'], $row['id']); ?>>
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
                            <option value="<?php echo (int)$row['id']; ?>" <?php echo itc_report_selected($filters['intake_id'], $row['id']); ?>>
                                <?php echo itc_report_h($row['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Gender</label>
                    <select class="form-select" name="gender">
                        <option value="">All</option>
                        <option value="M" <?php echo itc_report_selected($filters['gender'], 'M'); ?>>Male</option>
                        <option value="F" <?php echo itc_report_selected($filters['gender'], 'F'); ?>>Female</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Registration</label>
                    <select class="form-select" name="registration_status">
                        <?php foreach (['' => 'All', 'REGISTERED' => 'Registered', 'COMPLETED' => 'Completed', 'REPEATING' => 'Repeating', 'DROPPED' => 'Dropped', 'DEFERRED' => 'Deferred'] as $value => $label): ?>
                            <option value="<?php echo itc_report_h($value); ?>" <?php echo itc_report_selected($filters['registration_status'], $value); ?>><?php echo itc_report_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Result</label>
                    <select class="form-select" name="result_status">
                        <?php foreach (['' => 'All', 'PASS' => 'Pass', 'FAIL' => 'Fail', 'INCOMPLETE' => 'Incomplete', 'DEFERRED' => 'Deferred', 'REFERRED' => 'Referred', 'EXEMPTED' => 'Exempted'] as $value => $label): ?>
                            <option value="<?php echo itc_report_h($value); ?>" <?php echo itc_report_selected($filters['result_status'], $value); ?>><?php echo itc_report_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Assessment</label>
                    <select class="form-select" name="assessment_status">
                        <?php foreach (['' => 'All', 'DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'APPROVED_BY_HOD' => 'Approved', 'LOCKED' => 'Locked', 'RETURNED_FOR_CORRECTION' => 'Returned'] as $value => $label): ?>
                            <option value="<?php echo itc_report_h($value); ?>" <?php echo itc_report_selected($filters['assessment_status'], $value); ?>><?php echo itc_report_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Course</label>
                    <select class="form-select" name="course_code">
                        <option value="">All Courses</option>
                        <?php foreach ($options['courses'] as $row): ?>
                            <option value="<?php echo itc_report_h($row['id']); ?>" <?php echo itc_report_selected($filters['course_code'], $row['id']); ?>>
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
                            <option value="<?php echo itc_report_h($row['id']); ?>" <?php echo itc_report_selected($filters['lecturer_id'], $row['id']); ?>>
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
                            <option value="<?php echo $level; ?>" <?php echo itc_report_selected($filters['level_number'], $level); ?>>Level <?php echo $level; ?></option>
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
            <p class="text-muted mb-0">Choose filters and generate a report.</p>
        </div>
    <?php else: ?>
        <div id="print">
            <div class="row g-3 mb-4">
                <?php
                $stats = [
                    ['label' => 'Sections', 'value' => itc_report_stat($report['summary'], 'sections'), 'icon' => 'fa-sitemap'],
                    ['label' => 'Departments', 'value' => itc_report_stat($report['summary'], 'departments'), 'icon' => 'fa-building'],
                    ['label' => 'Programmes', 'value' => itc_report_stat($report['summary'], 'programmes'), 'icon' => 'fa-graduation-cap'],
                    ['label' => 'Students', 'value' => itc_report_stat($report['summary'], 'students'), 'icon' => 'fa-users'],
                    ['label' => 'Offerings', 'value' => itc_report_stat($report['summary'], 'course_offerings'), 'icon' => 'fa-chalkboard-teacher'],
                    ['label' => 'Published Results', 'value' => itc_report_stat($report['summary'], 'published_results'), 'icon' => 'fa-square-poll-vertical'],
                ];
                ?>
                <?php foreach ($stats as $stat): ?>
                    <div class="col-md-2 col-sm-6">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body">
                                <div class="text-muted small"><?php echo itc_report_h($stat['label']); ?></div>
                                <div class="fs-4 fw-bold text-dark"><?php echo itc_report_h($stat['value']); ?></div>
                                <i class="fas <?php echo itc_report_h($stat['icon']); ?> text-primary opacity-50"></i>
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

            <div class="row g-4 mt-1">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-list-check me-2"></i>Registration Status</div>
                        <div class="card-body">
                            <?php foreach ($report['registration_statuses'] as $row): ?>
                                <div class="d-flex justify-content-between border-bottom py-2"><span><?php echo itc_report_h($row['status_label']); ?></span><strong><?php echo (int)$row['total']; ?></strong></div>
                            <?php endforeach; ?>
                            <?php if (!$report['registration_statuses']): ?><p class="text-muted mb-0">No registrations.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-square-poll-horizontal me-2"></i>Result Status</div>
                        <div class="card-body">
                            <?php foreach ($report['result_statuses'] as $row): ?>
                                <div class="d-flex justify-content-between border-bottom py-2"><span><?php echo itc_report_h($row['status_label']); ?></span><strong><?php echo (int)$row['total']; ?></strong></div>
                            <?php endforeach; ?>
                            <?php if (!$report['result_statuses']): ?><p class="text-muted mb-0">No results.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white fw-bold"><i class="fas fa-laptop-code me-2"></i>eLearning Activity</div>
                        <div class="card-body">
                            <?php foreach ($report['elearning_activity'] as $row): ?>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <span><?php echo itc_report_h($row['event_type']); ?></span>
                                    <strong><?php echo (int)$row['total_events']; ?></strong>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$report['elearning_activity']): ?><p class="text-muted mb-0">No tracked eLearning activity for these filters.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
