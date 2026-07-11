<?php
/**
 * Admitted Students Report
 */
$page_title = "Admitted Students Report";

require_once "includes/admin.php";                        // session, auth, $db (emits no HTML)
require_once __DIR__ . '/../includes/auth_helpers.php';   // wuc_csrf_token() / wuc_validate_csrf()
require_once __DIR__ . '/../includes/report_print.php';   // print helpers (definitions only)
require_once __DIR__ . '/includes/admitted_report_helpers.php';
require_once __DIR__ . '/../includes/ai_portal.php';

// ---- Request/bootstrap state ----------------------------------------------
$records          = [];
$errors           = [];
$report_notice    = '';
$report_generated = false;   // true once a valid "Generate" request has run
$bootstrap_error  = '';      // fatal, pre-render problem -> controlled error page

$program_options        = [];
$short_course_options   = [];
$academic_year_options  = [];
$term_options           = [];
$short_duration_options = [];
$available_columns      = admitted_report_available_columns();

// Normalise filters up-front so the screen, query, labels and export URLs agree.
$filters       = admitted_report_normalise_filters(admitted_report_default_filters($_POST));
$summary       = admitted_report_summarise([]);
$filter_labels = [];

// CSRF token for the filter form (session token, generated on demand).
$csrf_token = function_exists('wuc_csrf_token') ? wuc_csrf_token() : '';

// ---- Validate dependencies + database BEFORE rendering --------------------
if (!isset($db) || !($db instanceof mysqli)) {
    $bootstrap_error = 'Database connection failed. Please check your database configuration.';
}

if ($bootstrap_error === '' && !@$db->ping()) {
    $bootstrap_error = 'Lost connection to the database. Please try again later.';
}

if ($bootstrap_error === '') {
    $missing_fns = function_exists('admitted_report_missing_functions')
        ? admitted_report_missing_functions()
        : ['admitted_report_missing_functions'];
    if (!empty($missing_fns)) {
        error_log('admittedStud_report: missing helper functions: ' . implode(', ', $missing_fns));
        $bootstrap_error = 'The report module is unavailable (missing components). Please contact support.';
    }
}

if ($bootstrap_error === '') {
    $missing_schema = admitted_report_missing_schema($db);
    if (!empty($missing_schema)) {
        error_log('admittedStud_report: schema problems: ' . implode(', ', $missing_schema));
        $bootstrap_error = 'The report cannot run because the database schema is incomplete: '
            . implode(', ', $missing_schema) . '. Please check your database setup.';
    }
}

$aiResult = null;

// ---- Load option lists + handle submission (only when healthy) ------------
if ($bootstrap_error === '') {
    $program_options        = admitted_report_fetch_programs($db);
    $short_course_options   = admitted_report_fetch_short_courses($db);
    $academic_year_options  = admitted_report_fetch_academic_years($db);
    $term_options           = admitted_report_fetch_terms($db);
    $short_duration_options = admitted_report_fetch_short_course_durations($db);
    $filter_labels          = admitted_report_filter_label($filters, $program_options, $short_course_options);

    if (isset($_POST['generate_report']) || (isset($_POST['action']) && $_POST['action'] === 'ai_summary')) {
        // Reject forged or expired submissions safely.
        if (!function_exists('wuc_validate_csrf') || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Your session has expired or the request could not be verified. Please reload the page and try again.';
        } else {
            $errors = admitted_report_validate_filters($filters);

            if (empty($errors)) {
                try {
                    $records          = admitted_report_fetch_rows($db, $filters);
                    $summary          = admitted_report_summarise($records);
                    $filter_labels    = admitted_report_filter_label($filters, $program_options, $short_course_options);
                    $report_generated = true;

                    error_log(sprintf(
                        'admitted_report viewed by %s: type=%s rows=%d',
                        $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'unknown',
                        $filters['report_type'],
                        $summary['total']
                    ));

                    if ($summary['total'] === 0) {
                        $report_notice = 'No admitted students found matching the specified criteria.';
                    } else {
                        // Check if AI Summary is requested
                        if (isset($_POST['action']) && $_POST['action'] === 'ai_summary') {
                            $aiRows = [];
                            foreach ($records as $r) {
                                $aiRows[] = [
                                    'SID' => $r['SID'] ?? $r['Sid'] ?? '',
                                    'Name' => trim(($r['Fname'] ?? '') . ' ' . ($r['Lname'] ?? '')),
                                    'sex' => $r['sex'] ?? '',
                                    'program' => $r['program_name'] ?? '',
                                    'mode' => $r['mode'] ?? $r['study_mode'] ?? '',
                                    'academic_year' => $r['academic_year'] ?? '',
                                    'status' => $r['enrollment_status'] ?? $r['record_status'] ?? ''
                                ];
                            }
                            $context = [
                                'report' => 'Admitted Students Report',
                                'role' => 'Administrator',
                                'filters' => $filters,
                                'columns' => ['SID', 'Name', 'sex', 'program', 'mode', 'academic_year', 'status'],
                                'rows' => array_slice($aiRows, 0, 40),
                                'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
                            ];
                            $ctxJson = wuc_ai_context_json($context, 14000);
                            $aiResult = wuc_ai_generate($db, [
                                'feature' => 'admin_admitted_students_summary',
                                'user_role' => 'admin',
                                'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'admin'),
                                'input_summary' => 'Admitted Students Report Summary',
                                'context_hash' => hash('sha256', $ctxJson),
                                'messages' => [
                                    ['role' => 'system', 'content' => 'You summarise an Admitted Students Report. Use ONLY the supplied rows. Give a concise narrative of total students, program/short-course breakdown, gender ratio, and registration insights.'],
                                    ['role' => 'user', 'content' => "Report data (JSON):\n{$ctxJson}\n\nWrite the summary."],
                                ],
                                'fallback' => static function () use ($records): string {
                                    return "Admitted Students Report — " . count($records) . " records found. AI summary unavailable.";
                                },
                            ]);
                        }
                    }
                } catch (Throwable $e) {
                    error_log('admittedStud_report failed: ' . $e->getMessage());
                    $errors[] = 'Error generating report. Please try again or contact support.';
                }
            }
        }
    }
}

require_once "includes/header.php";   // emits the HTML <head> + page chrome (safe now)
?>

<div class="container-fluid px-4 portal-dashboard">
    <?php
    render_report_print_styles();
    render_report_print_script();
    ?>

    <!-- Dashboard Header -->
    <div class="dashboard-header admin-section mb-4 d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-chart-line me-2 text-primary"></i>Admitted Students Report</h1>
                <p class="text-muted mb-0">Generate and analyze student enrollment statistics across academic programmes and short courses.</p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2">
                    <a href="index.php" class="btn btn-primary d-flex align-items-center gap-2 rounded-pill shadow-sm">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($bootstrap_error !== ''): ?>
        <div class="alert alert-danger d-print-none" role="alert">
            <div class="fw-semibold mb-1"><i class="fas fa-triangle-exclamation me-1"></i>Report unavailable</div>
            <div><?php echo admitted_report_h($bootstrap_error); ?></div>
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

        <?php if ($report_notice !== ''): ?>
            <div class="alert alert-warning d-print-none" role="alert">
                <i class="fas fa-info-circle me-1"></i><?php echo admitted_report_h($report_notice); ?>
            </div>
        <?php endif; ?>

        <!-- Search Form -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
            <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold text-primary mb-0">
                    <i class="fas fa-filter me-2"></i>Report Filters
                </h5>
            </div>
            <div class="card-body p-4">
                <form action="<?php echo admitted_report_h($_SERVER['PHP_SELF'] ?? 'admittedStud_report.php'); ?>" method="POST" class="row g-3" id="reportForm">
                    <input type="hidden" name="csrf_token" value="<?php echo admitted_report_h($csrf_token); ?>">
                    <input type="hidden" name="generate_report" value="1">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="report_type" class="form-label fw-semibold">Report Type</label>
                            <select name="report_type" id="report_type" class="form-select rounded-3">
                                <option value="all" <?php echo $filters['report_type'] === 'all' ? 'selected' : ''; ?>>All Admissions</option>
                                <option value="program" <?php echo $filters['report_type'] === 'program' ? 'selected' : ''; ?>>Programs</option>
                                <option value="short_course" <?php echo $filters['report_type'] === 'short_course' ? 'selected' : ''; ?>>Short Courses</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="program_code" class="form-label fw-semibold">Program</label>
                            <select name="program_code" id="program_code" class="form-select rounded-3">
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
                            <label for="short_course_id" class="form-label fw-semibold">Short Course</label>
                            <select name="short_course_id" id="short_course_id" class="form-select rounded-3">
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
                            <label for="year_of_study" class="form-label fw-semibold">Year of Study</label>
                            <select name="year_of_study" id="year_of_study" class="form-select rounded-3">
                                <option value="all" <?php echo $filters['year_of_study'] === 'all' ? 'selected' : ''; ?>>All Years</option>
                                <?php for ($year = 1; $year <= 4; $year++): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $filters['year_of_study'] === (string)$year ? 'selected' : ''; ?>>Year <?php echo $year; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="academic_year" class="form-label fw-semibold">Academic Year</label>
                            <select name="academic_year" id="academic_year" class="form-select rounded-3">
                                <option value="all" <?php echo $filters['academic_year'] === 'all' ? 'selected' : ''; ?>>All Years</option>
                                <?php foreach ($academic_year_options as $year): ?>
                                    <option value="<?php echo (int)$year; ?>" <?php echo $filters['academic_year'] === (string)$year ? 'selected' : ''; ?>><?php echo (int)$year; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="semester" class="form-label fw-semibold">Semester</label>
                            <select name="semester" id="semester" class="form-select rounded-3">
                                <option value="all" <?php echo $filters['semester'] === 'all' ? 'selected' : ''; ?>>All Semesters</option>
                                <option value="1" <?php echo $filters['semester'] === '1' ? 'selected' : ''; ?>>Semester 1</option>
                                <option value="2" <?php echo $filters['semester'] === '2' ? 'selected' : ''; ?>>Semester 2</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="term" class="form-label fw-semibold">Term</label>
                            <select name="term" id="term" class="form-select rounded-3">
                                <option value="all" <?php echo ($filters['term'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All Terms</option>
                                <?php foreach ($term_options as $term): ?>
                                    <option value="<?php echo (int)$term; ?>" <?php echo ($filters['term'] ?? 'all') === (string)$term ? 'selected' : ''; ?>>Term <?php echo (int)$term; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="short_course_duration" class="form-label fw-semibold">Short Course Duration</label>
                            <select name="short_course_duration" id="short_course_duration" class="form-select rounded-3">
                                <option value="all" <?php echo ($filters['short_course_duration'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All Durations</option>
                                <?php foreach ($short_duration_options as $duration): ?>
                                    <option value="<?php echo admitted_report_h($duration['value']); ?>" <?php echo (string)($filters['short_course_duration'] ?? 'all') === (string)$duration['value'] ? 'selected' : ''; ?>>
                                        <?php echo admitted_report_h(ucfirst($duration['label'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="gender" class="form-label fw-semibold">Gender</label>
                            <select name="gender" id="gender" class="form-select rounded-3">
                                <option value="">All</option>
                                <option value="M" <?php echo $filters['gender'] === 'M' ? 'selected' : ''; ?>>Male</option>
                                <option value="F" <?php echo $filters['gender'] === 'F' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end offset-md-4">
                        <div class="form-group w-100">
                            <button type="submit" class="btn btn-primary w-100 rounded-pill shadow-sm" id="generateReportBtn">
                                <i class="fas fa-search me-1"></i>Generate Report
                            </button>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="border rounded-3 p-3 bg-light">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <label class="form-label fw-semibold mb-0">Table Columns</label>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Column selection actions">
                                    <button type="button" class="btn btn-outline-secondary" id="selectAllColumns">Select All</button>
                                    <button type="button" class="btn btn-outline-secondary" id="resetColumns">Default</button>
                                </div>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($available_columns as $columnKey => $columnLabel): ?>
                                    <div class="col-sm-6 col-md-4 col-lg-3">
                                        <div class="form-check">
                                            <input class="form-check-input report-column"
                                                   type="checkbox"
                                                   name="columns[]"
                                                   id="column_<?php echo admitted_report_h($columnKey); ?>"
                                                   value="<?php echo admitted_report_h($columnKey); ?>"
                                                   <?php echo in_array($columnKey, $filters['columns'], true) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="column_<?php echo admitted_report_h($columnKey); ?>">
                                                <?php echo admitted_report_h($columnLabel); ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($report_generated): ?>
        <!-- Report Content -->
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
                    'Term'          => $filter_labels['Term'] ?? 'All Terms',
                    'Duration'      => $filter_labels['Duration'] ?? 'All Durations',
                    'Columns'       => implode(', ', array_map(function ($key) use ($available_columns) {
                        return $available_columns[$key] ?? $key;
                    }, $filters['columns'])),
                    'Total Records' => $summary['total'],
                ]
            );
            ?>

            <!-- Statistics Summary -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-4 bg-white p-4 shadow-sm border">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-primary rounded-circle p-3 me-3 text-white">
                                <i class="fas fa-users fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-1 fw-bold"><?php echo number_format($summary['total']); ?></h3>
                                <p class="text-muted mb-0 small fw-semibold">Total Records</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-4 bg-white p-4 shadow-sm border">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-info rounded-circle p-3 me-3 text-white">
                                <i class="fas fa-mars fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-1 fw-bold"><?php echo number_format($summary['male']); ?></h3>
                                <p class="text-muted mb-0 small fw-semibold">Male Students</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-4 bg-white p-4 shadow-sm border">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-danger rounded-circle p-3 me-3 text-white">
                                <i class="fas fa-venus fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-1 fw-bold"><?php echo number_format($summary['female']); ?></h3>
                                <p class="text-muted mb-0 small fw-semibold">Female Students</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-4 bg-white p-4 shadow-sm border">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-success rounded-circle p-3 me-3 text-white">
                                <i class="fas fa-graduation-cap fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-1 fw-bold"><?php echo number_format($summary['short_course']); ?></h3>
                                <p class="text-muted mb-0 small fw-semibold">Short-Course Records</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AI Summary Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>BI Summary Insights</h5>
                    <form method="post" action="<?php echo admitted_report_h($_SERVER['PHP_SELF'] ?? 'admittedStud_report.php'); ?>" class="m-0" id="aiForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="ai_summary">
                        <input type="hidden" name="report_type" value="<?php echo admitted_report_h($filters['report_type']); ?>">
                        <input type="hidden" name="program_code" value="<?php echo admitted_report_h($filters['program_code']); ?>">
                        <input type="hidden" name="short_course_id" value="<?php echo admitted_report_h($filters['short_course_id']); ?>">
                        <input type="hidden" name="year_of_study" value="<?php echo admitted_report_h($filters['year_of_study']); ?>">
                        <input type="hidden" name="academic_year" value="<?php echo admitted_report_h($filters['academic_year']); ?>">
                        <input type="hidden" name="semester" value="<?php echo admitted_report_h($filters['semester']); ?>">
                        <input type="hidden" name="term" value="<?php echo admitted_report_h($filters['term']); ?>">
                        <input type="hidden" name="short_course_duration" value="<?php echo admitted_report_h($filters['short_course_duration']); ?>">
                        <input type="hidden" name="gender" value="<?php echo admitted_report_h($filters['gender']); ?>">
                        <?php foreach ($filters['columns'] as $c): ?>
                            <input type="hidden" name="columns[]" value="<?php echo admitted_report_h($c); ?>">
                        <?php endforeach; ?>
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

            <?php if (!empty($records)): ?>
            <!-- Student List -->
            <div class="card border-0 shadow-sm rounded-4 mb-4" id="student-list-card">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0">
                        <i class="fas fa-list me-2"></i>Student List
                    </h5>
                    <div class="header-actions d-flex gap-2">
                        <?php $exportQuery = http_build_query(array_merge($filters, ['format' => 'csv'])); ?>
                        <a class="btn btn-sm btn-outline-primary rounded-pill px-3"
                           target="_blank" rel="noopener"
                           href="<?php echo 'ajax/student_report.php?' . admitted_report_h($exportQuery); ?>">
                            <i class="fas fa-file-csv"></i> CSV
                        </a>
                        <a class="btn btn-sm btn-success rounded-pill px-3 d-flex align-items-center gap-1"
                           target="_blank" rel="noopener"
                           href="<?php echo 'ajax/student_report.php?' . admitted_report_h($exportQuery); ?>">
                            <i class="fas fa-file-excel"></i> Excel
                        </a>
                        <?php $exportQueryPdf = http_build_query(array_merge($filters, ['format' => 'pdf'])); ?>
                        <a class="btn btn-sm btn-outline-danger rounded-pill px-3 d-flex align-items-center gap-1"
                           target="_blank" rel="noopener"
                           href="<?php echo 'ajax/student_report.php?' . admitted_report_h($exportQueryPdf); ?>">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                        <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3 d-flex align-items-center gap-1" onclick="printReport('Admitted Students Report')">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table id="studentsTable" class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <?php foreach ($filters['columns'] as $columnKey): ?>
                                        <th><?php echo admitted_report_h($available_columns[$columnKey] ?? $columnKey); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $counter = 1;
                                foreach($records as $student):
                                ?>
                                <tr>
                                    <?php foreach ($filters['columns'] as $columnKey): ?>
                                        <td><?php echo admitted_report_cell_html($student, $columnKey, $counter); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php $counter++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Empty-state: report ran, but nothing matched the filters -->
            <div class="card border-0 shadow-sm rounded-4 mb-4" id="student-list-card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                    <h5 class="mb-1">No records found</h5>
                    <p class="text-muted mb-0">No admitted students match the selected filters. Try widening the criteria and generating the report again.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; // report_generated ?>

    <?php endif; // bootstrap_error ?>
</div>

<script>
(function () {
    // Guard: without jQuery the page still renders; only the optional
    // interactive niceties (sync, validation, DataTable) are skipped.
    if (typeof window.jQuery === 'undefined') {
        console.warn('Admitted report: jQuery unavailable — interactive features disabled.');
        return;
    }

    // Error popup that degrades to a native alert when SweetAlert is absent.
    function reportAlert(message) {
        if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: message,
                confirmButtonColor: '#4e73df'
            });
        } else {
            window.alert(message);
        }
    }

    jQuery(function ($) {
        const $reportType = $('#report_type');
        const $program = $('#program_code');
        const $shortCourse = $('#short_course_id');
        const $year = $('#year_of_study');
        const $semester = $('#semester');
        const $term = $('#term');
        const $duration = $('#short_course_duration');
        const defaultColumns = <?php echo json_encode(admitted_report_default_columns()); ?>;

        function syncReportFilters() {
            const type = $reportType.val();
            const shortOnly = type === 'short_course';
            const programOnly = type === 'program';

            // Programme-only dimensions (program, year, semester, term) are
            // irrelevant to short courses; the short-course duration is
            // irrelevant to programmes. Disable + grey out whichever don't apply.
            $program.prop('disabled', shortOnly);
            $year.prop('disabled', shortOnly);
            $semester.prop('disabled', shortOnly);
            $term.prop('disabled', shortOnly);
            $shortCourse.prop('disabled', programOnly);
            $duration.prop('disabled', programOnly);

            // Clear conflicting values so the UI matches server-side normalisation.
            if (shortOnly) {
                $program.val('');
                $year.val('all');
                $semester.val('all');
                $term.val('all');
            }
            if (programOnly) {
                $shortCourse.val('');
                $duration.val('all');
            }

            $program.closest('.form-group').toggleClass('opacity-50', shortOnly);
            $year.closest('.form-group').toggleClass('opacity-50', shortOnly);
            $semester.closest('.form-group').toggleClass('opacity-50', shortOnly);
            $term.closest('.form-group').toggleClass('opacity-50', shortOnly);
            $shortCourse.closest('.form-group').toggleClass('opacity-50', programOnly);
            $duration.closest('.form-group').toggleClass('opacity-50', programOnly);
        }

        syncReportFilters();
        $reportType.on('change', syncReportFilters);

        $('#selectAllColumns').on('click', function () {
            $('.report-column').prop('checked', true);
        });

        $('#resetColumns').on('click', function () {
            $('.report-column').each(function () {
                $(this).prop('checked', defaultColumns.includes($(this).val()));
            });
        });

        // Client-side validation (server re-validates regardless).
        $('#reportForm').on('submit', function (e) {
            let isValid = true;
            const numericOrAllFields = ['year_of_study', 'academic_year', 'semester', 'term'];

            numericOrAllFields.forEach(function (field) {
                const $field = $('[name="' + field + '"]');
                if ($field.prop('disabled')) return;
                const value = $field.val();
                if (!value || (value !== 'all' && !/^\d+$/.test(value))) {
                    isValid = false;
                    $field.addClass('is-invalid');
                } else {
                    $field.addClass('is-valid');
                }
            });

            if ($('.report-column:checked').length === 0) {
                isValid = false;
                $('.report-column').first().focus();
            }

            if (!isValid) {
                e.preventDefault();
                reportAlert('Please correct the highlighted filters and select at least one table column.');
            } else {
                $('#generateReportBtn').prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-2"></i>Generating...');
            }
        });

        $('select').on('change', function () {
            $(this).removeClass('is-invalid is-valid');
        });

        // DataTable is optional — initialise only when the plugin and table exist.
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
</script>

<?php require_once "includes/footer.php"; ?>
