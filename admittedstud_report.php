<?php
/**
 * Admitted Students Report
 *
 * Bootstrap order (spec #1): authentication, dependencies, helper validation and
 * schema validation all run BEFORE any HTML is emitted. The portal header (which
 * outputs markup) is only included once the module is known to be usable.
 *
 * Logic is intentionally thin here: the heavy lifting lives in the shared helper
 * library (admin/includes/admitted_report_helpers.php) and the export endpoint
 * (admin/ajax/student_report.php), so the screen and exports never diverge.
 */

define('IS_SCRIPT', false);

// 1. Auth + DB + role hydration + security headers (no HTML output from this file).
require_once __DIR__ . '/admin/includes/admin.php';
// 2. Shared dependencies.
require_once __DIR__ . '/includes/csrf_guard.php';
require_once __DIR__ . '/includes/finance_helpers.php';      // log_audit()
require_once __DIR__ . '/includes/report_print.php';
require_once __DIR__ . '/admin/includes/admitted_report_helpers.php';

$page_title = 'Admitted Students Report';

// --- Access control (admin-facing report). Guests were already redirected by
//     admin.php; here we restrict authenticated staff to the report's roles. ---
$allRoles = $_SESSION['all_roles'] ?? [];
$primaryRole = (string)($_SESSION['role'] ?? '');
$allowedRoles = ['systems_admin', 'registrar', 'dean'];
$canView = !empty($isAdmin)
    || in_array($primaryRole, $allowedRoles, true)
    || (bool)array_intersect($allowedRoles, $allRoles);

// --- 3. Validate helper dependencies + 4. validate database schema (pre-HTML) ---
$bootErrors = [];

$missingFns = function_exists('admitted_report_missing_functions')
    ? admitted_report_missing_functions()
    : ['(report helper library failed to load)'];
if (!empty($missingFns)) {
    $bootErrors[] = 'Report helper functions are missing: ' . implode(', ', $missingFns)
        . '. Please restore admin/includes/admitted_report_helpers.php.';
}

if (!isset($db) || !($db instanceof mysqli) || $db->connect_errno) {
    $bootErrors[] = 'Database connection is unavailable. Please try again later.';
} elseif (empty($missingFns)) {
    $missingSchema = admitted_report_missing_schema($db);
    if (!empty($missingSchema)) {
        $bootErrors[] = 'Required database tables/columns are missing: '
            . implode(', ', $missingSchema) . '. Please check the database setup.';
    }
}

// --- 5. Filters (normalised before validation / querying / labels / export URLs) ---
$csrf_token   = wuc_ajax_csrf_token();
$records      = [];
$errors       = [];
$report_notice = '';
$generated    = false;
$filters      = admitted_report_normalise_filters(admitted_report_default_filters($_POST));
$summary      = admitted_report_summarise([]);
$program_options = [];
$short_course_options = [];
$academic_year_options = [];
$filter_labels = [];

if ($canView && empty($bootErrors)) {
    $program_options       = admitted_report_fetch_programs($db);
    $short_course_options  = admitted_report_fetch_short_courses($db);
    $academic_year_options = admitted_report_fetch_academic_years($db);
    $filter_labels         = admitted_report_filter_label($filters, $program_options, $short_course_options);

    if (isset($_POST['generate_report'])) {
        // 2. CSRF validation (controlled error, never a hard failure).
        $sessionToken  = (string)($_SESSION['csrf_token'] ?? '');
        $providedToken = (string)($_POST['csrf_token'] ?? '');
        if ($sessionToken === '' || $providedToken === '' || !hash_equals($sessionToken, $providedToken)) {
            $errors[] = 'Security token mismatch. Please refresh the page and try again.';
        } else {
            $errors = admitted_report_validate_filters($filters);
            if (empty($errors)) {
                try {
                    $records       = admitted_report_fetch_rows($db, $filters);
                    $summary       = admitted_report_summarise($records);
                    $filter_labels = admitted_report_filter_label($filters, $program_options, $short_course_options);
                    $generated     = true;
                    if ($summary['total'] === 0) {
                        $report_notice = 'No admitted students found matching the specified criteria.';
                    }
                    // 13. Audit the report generation (best effort).
                    if (function_exists('log_audit')) {
                        try {
                            log_audit($db, $_SESSION['user_id'] ?? 'unknown', 'admitted_report_view', json_encode([
                                'filters' => $filters,
                                'rows'    => $summary['total'],
                            ]));
                        } catch (Throwable $e) {
                            error_log('admitted report view audit failed: ' . $e->getMessage());
                        }
                    }
                } catch (Throwable $e) {
                    // 12. Never expose SQL/internal detail to the browser.
                    error_log('admittedStud_report failed: ' . $e->getMessage());
                    $errors[] = 'Error generating report. Please try again or contact support.';
                }
            }
        }
    }
}

// --- Display layer starts here (header emits HTML) ---
require_once __DIR__ . '/admin/includes/header.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <?php
    render_report_print_styles();
    render_report_print_script();
    ?>

    <!-- Dashboard Header -->
    <div class="dashboard-header student-section mb-4 d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Admitted Students Report</h1>
                <p class="text-muted">Generate and analyze student enrollment statistics</p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2">
                    <a href="admin/index.php" class="btn btn-primary d-flex align-items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$canView): ?>
        <div class="alert alert-danger d-print-none" role="alert">
            <i class="fas fa-lock me-1"></i>
            You do not have permission to view the Admitted Students Report.
            This report is limited to Systems Admin, Registrar and Dean accounts.
        </div>
    <?php elseif (!empty($bootErrors)): ?>
        <div class="alert alert-danger d-print-none" role="alert">
            <div class="fw-semibold mb-1"><i class="fas fa-triangle-exclamation me-1"></i>The report cannot run</div>
            <ul class="mb-0">
                <?php foreach ($bootErrors as $bootError): ?>
                    <li><?php echo admitted_report_h($bootError); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger d-print-none" role="alert">
                <div class="fw-semibold mb-1"><i class="fas fa-circle-exclamation me-1"></i>Report could not be generated</div>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo admitted_report_h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="data-table-card mb-4 d-print-none">
            <div class="card-body text-center">
                <img src="/wucportal/images/itc_logo.png" alt="ITC Logo" class="mb-3" style="height: 100px;">
                <h4 class="mb-3">Industrial Training Centre</h4>
                <h5 class="text-muted">Admitted Students Report</h5>
            </div>
        </div>

        <!-- Search Form -->
        <div class="data-table-card mb-4 d-print-none">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
                </div>
            </div>
            <div class="card-body">
                <form action="<?php echo admitted_report_h($_SERVER['PHP_SELF'] ?? 'admittedstud_report.php'); ?>" method="POST" class="row g-3" id="reportForm">
                    <input type="hidden" name="csrf_token" value="<?php echo admitted_report_h($csrf_token); ?>">

                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="report_type" class="form-label">Report Type</label>
                            <select name="report_type" id="report_type" class="form-select">
                                <option value="all" <?php echo $filters['report_type'] === 'all' ? 'selected' : ''; ?>>All Admissions</option>
                                <option value="program" <?php echo $filters['report_type'] === 'program' ? 'selected' : ''; ?>>Programs</option>
                                <option value="short_course" <?php echo $filters['report_type'] === 'short_course' ? 'selected' : ''; ?>>Short Courses</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="program_code" class="form-label">Program</label>
                            <select name="program_code" id="program_code" class="form-select">
                                <option value="">All Programs</option>
                                <?php foreach ($program_options as $program): ?>
                                    <option value="<?php echo admitted_report_h($program['program_code']); ?>" <?php echo strcasecmp((string)$filters['program_code'], (string)$program['program_code']) === 0 ? 'selected' : ''; ?>>
                                        <?php echo admitted_report_h($program['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="short_course_id" class="form-label">Short Course</label>
                            <select name="short_course_id" id="short_course_id" class="form-select">
                                <option value="">All Short Courses</option>
                                <?php foreach ($short_course_options as $course): ?>
                                    <option value="<?php echo (int)$course['id']; ?>" <?php echo (string)$filters['short_course_id'] === (string)$course['id'] ? 'selected' : ''; ?>>
                                        <?php echo admitted_report_h($course['course_name'] . ' (' . $course['course_code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="year_of_study" class="form-label">Year of Study</label>
                            <select name="year_of_study" id="year_of_study" class="form-select">
                                <option value="all" <?php echo $filters['year_of_study'] === 'all' ? 'selected' : ''; ?>>All Years</option>
                                <?php for ($year = 1; $year <= 6; $year++): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $filters['year_of_study'] === (string)$year ? 'selected' : ''; ?>>Year <?php echo $year; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="academic_year" class="form-label">Academic Year</label>
                            <select name="academic_year" id="academic_year" class="form-select">
                                <option value="all" <?php echo $filters['academic_year'] === 'all' ? 'selected' : ''; ?>>All Years</option>
                                <?php foreach ($academic_year_options as $year): ?>
                                    <option value="<?php echo (int)$year; ?>" <?php echo $filters['academic_year'] === (string)$year ? 'selected' : ''; ?>><?php echo (int)$year; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="semester" class="form-label">Semester</label>
                            <select name="semester" id="semester" class="form-select">
                                <option value="all" <?php echo $filters['semester'] === 'all' ? 'selected' : ''; ?>>All Semesters</option>
                                <option value="1" <?php echo $filters['semester'] === '1' ? 'selected' : ''; ?>>Semester 1</option>
                                <option value="2" <?php echo $filters['semester'] === '2' ? 'selected' : ''; ?>>Semester 2</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="gender" class="form-label">Gender</label>
                            <select name="gender" id="gender" class="form-select">
                                <option value="">All</option>
                                <option value="M" <?php echo $filters['gender'] === 'M' ? 'selected' : ''; ?>>Male</option>
                                <option value="F" <?php echo $filters['gender'] === 'F' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-group w-100">
                            <button type="submit" name="generate_report" class="btn btn-primary w-100" id="generateReportBtn">
                                <i class="fas fa-sync-alt me-2"></i>Generate Report
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($generated): ?>
        <!-- Report Content (rendered even when empty, per controlled empty-state) -->
        <div id="report-content">
            <?php
            $reportHeading = $filter_labels['Report Type'] ?? 'All Admissions';
            render_report_print_header(
                'Admitted Students Report',
                $reportHeading,
                [
                    'Report Type'   => $filter_labels['Report Type'] ?? 'All Admissions',
                    'Program'       => $filter_labels['Program'] ?? 'All Programs',
                    'Short Course'  => $filter_labels['Short Course'] ?? 'All Short Courses',
                    'Year of Study' => $filter_labels['Year of Study'] ?? 'All Years',
                    'Academic Year' => $filter_labels['Academic Year'] ?? 'All Years',
                    'Semester'      => $filter_labels['Semester'] ?? 'All Semesters',
                    'Total Records' => $summary['total'],
                ]
            );
            ?>

            <!-- On-screen header card (hidden on print) -->
            <div class="data-table-card mb-4 d-print-none">
                <div class="card-body text-center">
                    <img src="/wucportal/images/itc_logo.png" alt="ITC Logo" class="mb-3" style="height: 100px;">
                    <h4 class="mb-3">Admitted Students Report</h4>
                    <div class="row justify-content-center">
                        <div class="col-lg-10">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <p class="mb-1"><strong>Report Type:</strong> <?php echo admitted_report_h($filter_labels['Report Type'] ?? 'All Admissions'); ?></p>
                                    <p class="mb-1"><strong>Program:</strong> <?php echo admitted_report_h($filter_labels['Program'] ?? 'All Programs'); ?></p>
                                </div>
                                <div class="col-md-4">
                                    <p class="mb-1"><strong>Short Course:</strong> <?php echo admitted_report_h($filter_labels['Short Course'] ?? 'All Short Courses'); ?></p>
                                    <p class="mb-1"><strong>Year of Study:</strong> <?php echo admitted_report_h($filter_labels['Year of Study'] ?? 'All Years'); ?></p>
                                </div>
                                <div class="col-md-4">
                                    <p class="mb-1"><strong>Academic Year:</strong> <?php echo admitted_report_h($filter_labels['Academic Year'] ?? 'All Years'); ?></p>
                                    <p class="mb-1"><strong>Semester:</strong> <?php echo admitted_report_h($filter_labels['Semester'] ?? 'All Semesters'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Summary -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-primary rounded-circle p-3 me-3"><i class="fas fa-users fa-2x text-white"></i></div>
                            <div>
                                <h3 class="mb-1"><?php echo number_format($summary['total']); ?></h3>
                                <p class="text-muted mb-0">Total Records</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-info rounded-circle p-3 me-3"><i class="fas fa-male fa-2x text-white"></i></div>
                            <div>
                                <h3 class="mb-1"><?php echo number_format($summary['male']); ?></h3>
                                <p class="text-muted mb-0">Male Students</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-danger rounded-circle p-3 me-3"><i class="fas fa-female fa-2x text-white"></i></div>
                            <div>
                                <h3 class="mb-1"><?php echo number_format($summary['female']); ?></h3>
                                <p class="text-muted mb-0">Female Students</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-success rounded-circle p-3 me-3"><i class="fas fa-graduation-cap fa-2x text-white"></i></div>
                            <div>
                                <h3 class="mb-1"><?php echo number_format($summary['short_course']); ?></h3>
                                <p class="text-muted mb-0">Short-Course Records</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Student List -->
            <div class="data-table-card mb-4">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Student List</h5>
                        <?php if (!empty($records)): ?>
                        <div class="header-actions d-print-none">
                            <?php $csvQuery = http_build_query(array_merge($filters, ['format' => 'csv'])); ?>
                            <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
                               href="admin/ajax/student_report.php?<?php echo admitted_report_h($csvQuery); ?>">
                                <i class="fas fa-file-csv"></i> CSV
                            </a>
                            <?php $pdfQuery = http_build_query(array_merge($filters, ['format' => 'pdf'])); ?>
                            <a class="btn btn-sm btn-outline-danger d-flex align-items-center gap-2" target="_blank" rel="noopener"
                               href="admin/ajax/student_report.php?<?php echo admitted_report_h($pdfQuery); ?>">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                            <button type="button" class="btn btn-sm btn-secondary d-flex align-items-center gap-2" onclick="printReport('Admitted Students Report')">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($records)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-folder-open fa-3x mb-3 d-block"></i>
                            <p class="mb-0"><?php echo admitted_report_h($report_notice !== '' ? $report_notice : 'No records to display.'); ?></p>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table id="studentsTable" class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Source</th>
                                    <th>Student ID</th>
                                    <th>Student Name</th>
                                    <th>Gender</th>
                                    <th>Program / Short Course</th>
                                    <th>Year of Study</th>
                                    <th>Study Mode</th>
                                    <th>Status</th>
                                    <th>Admission Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $counter = 1;
                                foreach ($records as $student):
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($student['report_source'] ?? '') === 'short_course' ? 'warning text-dark' : 'primary'; ?>">
                                            <?php echo admitted_report_h(admitted_report_source_label((string)($student['report_source'] ?? 'program'))); ?>
                                        </span>
                                    </td>
                                    <td><?php echo admitted_report_h($student['SID'] ?? ''); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-circle me-2 bg-primary text-white">
                                                <?php echo admitted_report_h(strtoupper(substr((string)($student['Fname'] ?? $student['SID'] ?? '?'), 0, 1))); ?>
                                            </div>
                                            <div>
                                                <?php
                                                $studentName = trim((string)($student['Fname'] ?? '') . ' ' . (string)($student['Lname'] ?? ''));
                                                echo admitted_report_h($studentName !== '' ? $studentName : 'Name not captured');
                                                ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo ($student['sex'] ?? '') == 'M' ? 'info' : (($student['sex'] ?? '') == 'F' ? 'danger' : 'secondary'); ?>">
                                            <?php echo ($student['sex'] ?? '') == 'M' ? 'Male' : (($student['sex'] ?? '') == 'F' ? 'Female' : 'Not set'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo admitted_report_h($student['program_name'] ?? ''); ?></div>
                                        <small class="text-muted"><?php echo admitted_report_h($student['program_code'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo ($student['study_year'] ?? '') !== '' ? 'Year ' . admitted_report_h($student['study_year']) : '<span class="text-muted">N/A</span>'; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo admitted_report_mode_key($student['mode'] ?? '') == 'full time' ? 'success' : 'secondary'; ?>">
                                            <?php echo admitted_report_h(admitted_report_mode_label($student['mode'] ?? '')); ?>
                                        </span>
                                    </td>
                                    <td><?php echo admitted_report_h(ucwords(str_replace('_', ' ', (string)($student['admission_status'] ?? '')))); ?></td>
                                    <td><?php echo admitted_report_h(admitted_report_safe_date($student['registration_date'] ?? '', 'Y-m-d', 'N/A')); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; /* $generated */ ?>

    <?php endif; /* access / boot errors */ ?>
</div>

<script>
// Frontend guards: nothing here may throw if jQuery, DataTables or SweetAlert
// failed to load. The plain HTML form still submits without any of them.
(function () {
    function notify(title, text) {
        if (typeof window.Swal !== 'undefined' && typeof window.Swal.fire === 'function') {
            window.Swal.fire({ icon: 'error', title: title, text: text, confirmButtonColor: '#6f42c1' });
        } else {
            window.alert(title + '\n\n' + text);
        }
    }

    if (typeof window.jQuery === 'undefined') {
        return; // No jQuery: skip enhancements, leave the native form intact.
    }
    var $ = window.jQuery;

    $(function () {
        var $reportType  = $('#report_type');
        var $program     = $('#program_code');
        var $shortCourse = $('#short_course_id');
        var $year        = $('#year_of_study');
        var $semester    = $('#semester');

        function syncReportFilters() {
            var type = $reportType.val();
            var shortOnly   = type === 'short_course';
            var programOnly = type === 'program';

            // Disable AND clear conflicting filters so stale values never submit.
            $program.prop('disabled', shortOnly);
            $year.prop('disabled', shortOnly);
            $semester.prop('disabled', shortOnly);
            $shortCourse.prop('disabled', programOnly);

            if (shortOnly) {
                $program.val('');
                $year.val('all');
                $semester.val('all');
            }
            if (programOnly) {
                $shortCourse.val('');
            }

            $program.closest('.form-group').toggleClass('opacity-50', shortOnly);
            $year.closest('.form-group').toggleClass('opacity-50', shortOnly);
            $semester.closest('.form-group').toggleClass('opacity-50', shortOnly);
            $shortCourse.closest('.form-group').toggleClass('opacity-50', programOnly);
        }

        syncReportFilters();
        $reportType.on('change', syncReportFilters);

        $('#reportForm').on('submit', function (e) {
            var isValid = true;
            ['year_of_study', 'academic_year', 'semester'].forEach(function (field) {
                var $field = $('[name="' + field + '"]');
                if ($field.prop('disabled')) return;
                var value = $field.val();
                if (!value || (value !== 'all' && !/^\d+$/.test(value))) {
                    isValid = false;
                    $field.addClass('is-invalid');
                } else {
                    $field.addClass('is-valid');
                }
            });

            if (!isValid) {
                e.preventDefault();
                notify('Validation Error', 'Please correct the highlighted filters.');
            } else {
                $('#generateReportBtn').prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-2"></i>Generating...');
            }
        });

        $('select').on('change', function () {
            $(this).removeClass('is-invalid is-valid');
        });

        // DataTable only if the plugin and the table are both present.
        if ($.fn && $.fn.DataTable && $('#studentsTable').length) {
            $('#studentsTable').DataTable({
                pageLength: 10,
                responsive: true,
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
                language: {
                    search: "",
                    searchPlaceholder: "Search records...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ records",
                    paginate: {
                        first: '<i class="fas fa-angle-double-left"></i>',
                        last: '<i class="fas fa-angle-double-right"></i>',
                        next: '<i class="fas fa-angle-right"></i>',
                        previous: '<i class="fas fa-angle-left"></i>'
                    }
                }
            });
        }
    });
})();
// printReport() comes from includes/report_print.php
</script>

<?php require_once __DIR__ . '/admin/includes/footer.php'; ?>
