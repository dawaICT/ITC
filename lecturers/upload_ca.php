<?php
$page_title = 'Upload CA';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';
require_once dirname(__DIR__) . '/includes/ca_helpers.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';
require_once dirname(__DIR__) . '/includes/academic_settings_helper.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';

ca_ensure_schema($db);
if (function_exists('wuc_csrf_token')) {
    wuc_csrf_token();
}

// Backend: ensure required data structures exist for CA uploads
if (isset($db) && $db instanceof mysqli) {
    $tableExists = function(mysqli $db, string $table): bool {
        if ($res = @$db->query("SHOW TABLES LIKE '".$db->real_escape_string($table)."'")) {
            $ok = $res->num_rows > 0; @$res->free(); return $ok;
        }
        return false;
    };
    $columnExists = function(mysqli $db, string $table, string $column): bool {
        if ($res = @$db->query("SHOW COLUMNS FROM `{$table}` LIKE '".$db->real_escape_string($column)."'")) {
            $ok = $res->num_rows > 0; @$res->free(); return $ok;
        }
        return false;
    };

    // Schema for semester_assessment / portal_settings (and the course_registration
    // fee columns) is provisioned centrally by ca_ensure_schema($db) at the top of
    // this file, so no CREATE/ALTER runs inline on every page load.
    // See migrate_ca_unique_key.php for the duplicate-guard unique-key migration.

    $detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
        foreach ($candidates as $col) {
            if ($res = @$db->query("SHOW COLUMNS FROM `{$table}` LIKE '".$db->real_escape_string($col)."'")) {
                if ($res->num_rows > 0) { @$res->free(); return $col; }
                @$res->free();
            }
        }
        return null;
    };

    // Feature flags
    $getSetting = function(mysqli $db, string $key, string $default = '1'): string {
        $val = $default;
        if ($st = @$db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = ? LIMIT 1")) {
            $st->bind_param('s', $key);
            if ($st->execute()) { $res = $st->get_result(); if ($res && $res->num_rows) { $val = (string)$res->fetch_assoc()['setting_value']; } }
            $st->close();
        }
        return $val;
    };
    $manualEnabled = $getSetting($db, 'ca_upload_manual_enabled', '1') === '1';
    $csvEnabled = $getSetting($db, 'ca_upload_csv_enabled', '1') === '1';
    $enforceTerm = $getSetting($db, 'enforce_current_term', '1') === '1';
    $curAy = $getSetting($db, 'current_academic_year', date('Y'));
    $curSem = $getSetting($db, 'current_semester', ((int)date('n')<=6 ? '1':'2'));

    // CA windows
    $caWindows = [];
    foreach (['A1','A2','T1','T2'] as $comp) {
        $enabled = $getSetting($db, "ca_{$comp}_enabled", '1') === '1';
        $start = $getSetting($db, "ca_{$comp}_start", '');
        $dur = (int)$getSetting($db, "ca_{$comp}_duration_days", '5');
        $end = '';
        if ($start !== '' && $dur > 0) {
            $end = date('Y-m-d H:i:s', strtotime($start.' +'.$dur.' days'));
        }
        $caWindows[$comp] = ['enabled'=>$enabled,'start'=>$start,'end'=>$end];
    }

    // === MANUAL SUBMISSION HANDLING (UPDATED: incremental, safe upsert) ===
    $manualMessage = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_submit'])) {
        wuc_verify_csrf();
        if (!$manualEnabled) {
            $manualMessage = '<div class="alert alert-danger">Manual CA entry has been disabled by the administrator.</div>';
        } else {
            $sid = trim($_POST['Sid'] ?? '');
            $course_code = trim($_POST['course_code'] ?? '');
            $year = trim($_POST['Year'] ?? '');
            $program_type = trim($_POST['program_type'] ?? 'semester');
            // Read the period from the field that matches the program type so a
            // stale (empty) semester value can't shadow a real term selection.
            $period = ($program_type === 'term')
                ? trim($_POST['term'] ?? '')
                : trim($_POST['semester'] ?? '');
            $assessment_type = trim($_POST['assessment_type'] ?? '');
            $raw_mark = (float)($_POST['mark_value'] ?? 0);

            if ($sid === '' || $course_code === '' || $period === '' || $year === '' || $assessment_type === '') {
                $manualMessage = '<div class="alert alert-danger">All required fields must be filled.</div>';
            } else {
                $periodCheck = ca_validate_period($period, $year, $program_type);
                $normalized = ca_normalize_component($assessment_type, $raw_mark);
                if (!$periodCheck['ok']) {
                    $manualMessage = '<div class="alert alert-danger">'.htmlspecialchars($periodCheck['message']).'</div>';
                } elseif (!$normalized['ok']) {
                    $manualMessage = '<div class="alert alert-danger">'.htmlspecialchars($normalized['message']).'</div>';
                } else {
                    $studentExists = false;
                    if ($st_stud = $db->prepare("SELECT 1 FROM students WHERE SID = ? LIMIT 1")) {
                        $st_stud->bind_param('s', $sid);
                        if ($st_stud->execute()) {
                            $st_stud->store_result();
                            $studentExists = $st_stud->num_rows > 0;
                        }
                        $st_stud->close();
                    }

                    $window = ca_window_allows($caWindows, (string)$normalized['window'], (float)$normalized['value']);
                    if (!$window['ok']) {
                        $manualMessage = '<div class="alert alert-danger">'.htmlspecialchars($window['message']).'</div>';
                    } elseif (!isLecturerAssignedToCourse($db, (string)($_SESSION['staff_id'] ?? ''), $course_code)) {
                        $manualMessage = '<div class="alert alert-danger">You are not assigned to this course.</div>';
                    } elseif (!$studentExists) {
                        $manualMessage = '<div class="alert alert-danger">Student ID '.htmlspecialchars($sid).' does not exist in the system.</div>';
                    } else {
                        if (ca_is_external_short_course($db, $course_code)) {
                            $manualMessage = '<div class="alert alert-danger">This short course is configured for external examination. CA marks cannot be uploaded on this page.</div>';
                        } else {
                        $eligible = ca_fetch_course_students($db, $course_code, $period, $year);
                        if ($eligible['count'] === 0) {
                            $mode = ca_course_period_mode($db, $course_code);
                            $courseLabel = $course_code;
                            if ($stmt = $db->prepare('SELECT course_name FROM courses WHERE course_code = ? LIMIT 1')) {
                                $stmt->bind_param('s', $course_code);
                                if ($stmt->execute()) {
                                    $cn = $stmt->get_result()->fetch_assoc();
                                    if ($cn && !empty($cn['course_name'])) {
                                        $courseLabel = $course_code . ' – ' . $cn['course_name'];
                                    }
                                }
                                $stmt->close();
                            }
                            $periodLabel = ca_period_label($mode['period_mode'], $period);
                            $diag = ca_registration_diagnostics($db, $course_code);
                            $msg = ca_build_no_students_message($courseLabel, $periodLabel, $year, $mode['period_mode'], $diag);
                            $manualMessage = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>'.htmlspecialchars($msg).'</div>';
                        } elseif (!in_array($sid, array_column($eligible['students'], 'Sid'), true)) {
                            $manualMessage = '<div class="alert alert-warning">The selected student is not registered for this course in the chosen academic year and period. CA marks cannot be uploaded.</div>';
                        } elseif (!ca_student_registered($db, $sid, $course_code, $period, $year)) {
                            $manualMessage = '<div class="alert alert-warning">Student is not registered for this course in the selected period/year.</div>';
                        } else {
                        $elig = is_student_allowed_ca($db, $sid, $year, $period);
                        if (!$elig['allowed']) {
                            $paidPct = rtrim(rtrim(number_format((float)($elig['percent'] ?? 0), 2), '0'), '.');
                            $needPct = rtrim(rtrim(number_format((float)($elig['required_percent'] ?? 50), 2), '0'), '.');
                            $manualMessage = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>This student is not eligible to receive CA marks for this period. Paid '
                                . htmlspecialchars($paidPct, ENT_QUOTES, 'UTF-8') . '% of tuition (minimum '
                                . htmlspecialchars($needPct, ENT_QUOTES, 'UTF-8') . '% required).</div>';
                        } elseif ($enforceTerm && ($year !== $curAy || $period !== $curSem)) {
                            $manualMessage = '<div class="alert alert-warning">Uploads are restricted to current term ('.htmlspecialchars($curAy).' / '.htmlspecialchars($curSem).').</div>';
                        } else {
                            $posted_by = $_SESSION['staff_id'] ?? '';
                            $save = ca_save_component($db, $sid, $course_code, $period, $year, $program_type, (string)$normalized['component'], (float)$normalized['value'], $posted_by);
                            if ($save['ok']) {
                                $msg = 'CA mark saved for ' . htmlspecialchars($sid, ENT_QUOTES, 'UTF-8')
                                    . ' in ' . htmlspecialchars($course_code, ENT_QUOTES, 'UTF-8')
                                    . ' (' . htmlspecialchars((string)$normalized['component'], ENT_QUOTES, 'UTF-8') . ').'
                                    . ' Total CA: ' . htmlspecialchars((string)$save['total_ca'], ENT_QUOTES, 'UTF-8') . '%.';
                                wuc_flash('success', $msg);
                                wuc_safe_redirect('upload_ca.php?tab=manual&course_code=' . urlencode($course_code) . '&Year=' . urlencode($year));
                            } else {
                                $manualMessage = '<div class="alert alert-danger">'.htmlspecialchars($save['message']).'</div>';
                            }
                        }
                        }
                        }
                    }
                }
            }
        }
    }

    // Populate assigned courses
    $assignedCourses = $assignedCourses ?? [];
    $lecturerCourseTable = null;
    if ($tableExists($db, 'course_lecturer')) { $lecturerCourseTable = 'course_lecturer'; }
    if ($lecturerCourseTable) {
        $lcStaffCol = $detectColumn($db, $lecturerCourseTable, ['staff_id','lecturer_id','staffId']);
        $lcCourseCol = $detectColumn($db, $lecturerCourseTable, ['course_code','code']);
        if ($lcStaffCol && $lcCourseCol && isset($_SESSION['staff_id'])) {
            $staffIdVal = $_SESSION['staff_id'];
            $sql = "SELECT DISTINCT c.course_code, COALESCE(c.course_name,'') AS course_name
                    FROM `{$lecturerCourseTable}` lc
                    INNER JOIN courses c ON c.course_code = lc.`{$lcCourseCol}`
                    WHERE lc.`{$lcStaffCol}` = ?
                    ORDER BY c.course_code";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $staffIdVal);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) { $assignedCourses[] = $row; }
                }
                $stmt->close();
            }
        }
    }

    $academicYears = wuc_academic_year_options($db);

    // Admin/registrar-only diagnostics: which of the selectable courses actually
    // have registrations, and in which period. Surfaces the "assigned course has
    // zero registrations" gap that otherwise reads as a silent empty student list.
    $showCaAdminDebug = (function_exists('isSystemsAdmin') && isSystemsAdmin())
        || (isset($_SESSION['role']) && in_array($_SESSION['role'], ['systems_admin', 'admin', 'registrar'], true));
    $caRegistrationOverview = [];
    $caCoverageAll = ['rows' => [], 'capped' => false];
    if ($showCaAdminDebug) {
        $caRegistrationOverview = ca_registration_overview($db, array_column($assignedCourses, 'course_code'));
        $caCoverageAll = ca_registration_coverage_all($db);
    }
}

// CSV processing (upload_ca_csv.php) redirects back here with ?tab=csv, so this
// page surfaces its flash message + per-row processing log.
$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;
$csvLog = [];
if (!empty($_SESSION['_upload_log'])) {
    $csvLog = (array)$_SESSION['_upload_log'];
    unset($_SESSION['_upload_log']);
}
$activeTab = (($_GET['tab'] ?? 'manual') === 'csv') ? 'csv' : 'manual';
if ($activeTab === 'manual' && empty($manualEnabled) && !empty($csvEnabled)) { $activeTab = 'csv'; }
if ($activeTab === 'csv' && empty($csvEnabled) && !empty($manualEnabled)) { $activeTab = 'manual'; }
$manualTabActive = ($activeTab === 'manual');
$csvTabActive = ($activeTab === 'csv');
$assignedCourses = $assignedCourses ?? [];
$academicYears = $academicYears ?? [];
$manualEnabled = $manualEnabled ?? true;
$csvEnabled = $csvEnabled ?? true;
$showCaAdminDebug = $showCaAdminDebug ?? false;

// Deep-link support from lecturer dashboard (?course_code=&Year=)
$preselectCourse = trim((string)($_GET['course_code'] ?? ''));
$preselectYear = trim((string)($_GET['Year'] ?? $_GET['year'] ?? ''));
if ($preselectYear === '' && count($academicYears) === 1) {
    $preselectYear = (string)$academicYears[0];
}
if ($preselectCourse !== '' && $assignedCourses !== []) {
    $allowedCodes = array_column($assignedCourses, 'course_code');
    if (!in_array($preselectCourse, $allowedCodes, true)) {
        $preselectCourse = '';
    }
}
?>

<?php require "includes/nav.php"; ?>

<div class="container-fluid px-4 portal-dashboard lecturer-workflow-page upload-ca-page">
	<div class="dashboard-header lecturer-section mb-4">
		<div class="row align-items-center">
			<div class="col">
				<h1 class="dashboard-title">Upload CA</h1>
				<p class="text-muted">Enter continuous assessment marks manually or by CSV for your assigned courses.</p>
			</div>
			<div class="col-auto header-actions">
				<a class="btn btn-outline-primary" href="assessments.php">
					<i class="fas fa-inbox me-2"></i>Submissions
				</a>
				<a class="btn btn-outline-primary" href="upload_ca_short.php"><i class="fas fa-graduation-cap me-2"></i>Short-Course CA</a>
					<a class="btn btn-primary" href="viewCaRes.php">
					<i class="fas fa-eye me-2"></i>View CA
				</a>
			</div>
		</div>
	</div>

	<div class="assignment-command-bar mb-4" role="navigation" aria-label="Assessment navigation">
		<a class="command-link" href="assessments.php"><i class="fas fa-file-alt"></i><span>Student Submissions</span></a>
		<a class="command-link active" href="upload_ca.php" aria-current="page"><i class="fas fa-upload"></i><span>Upload CA</span></a>
		<a class="command-link" href="post_assign.php"><i class="fas fa-tasks"></i><span>Post Assignments</span></a>
		<a class="command-link" href="viewCaRes.php"><i class="fas fa-eye"></i><span>View CA Results</span></a>
	</div>

	<?php if (!empty($showCaAdminDebug)): ?>
	<div class="row">
		<div class="col-lg-10 mx-auto">
			<div class="card border-warning mb-4" id="caAdminDebugPanel">
				<div class="card-header bg-warning-subtle d-flex justify-content-between align-items-center">
					<h6 class="mb-0"><i class="fas fa-user-shield me-2"></i>Admin diagnostics — registration coverage</h6>
					<span class="badge bg-secondary">Visible to admins/registrars only</span>
				</div>
				<div class="card-body">
					<p class="small text-muted mb-3">Where do active registrations exist for the courses in the dropdown? A course with <strong>no registrations</strong> can never load students on this page — that is usually why the list is empty.</p>
					<div class="table-responsive">
						<table class="table table-sm align-middle mb-0">
							<thead><tr><th style="width:180px;">Course</th><th>Registrations (academic year / period → count)</th></tr></thead>
							<tbody>
							<?php if (empty($caRegistrationOverview)): ?>
								<tr><td colspan="2" class="text-muted">No courses are assigned to this account, so there is nothing to load.</td></tr>
							<?php else: foreach ($caRegistrationOverview as $overviewCode => $overviewRows): ?>
								<tr>
									<td class="fw-semibold"><?php echo htmlspecialchars((string)$overviewCode, ENT_QUOTES, 'UTF-8'); ?></td>
									<td>
										<?php if (empty($overviewRows)): ?>
											<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>No registrations — students cannot be loaded for this course.</span>
										<?php else: foreach ($overviewRows as $overviewRow): ?>
										<span class="badge bg-light text-dark border me-1 mb-1">
											<?php echo htmlspecialchars($overviewRow['academic_year'], ENT_QUOTES, 'UTF-8'); ?> / P<?php echo htmlspecialchars($overviewRow['semester'], ENT_QUOTES, 'UTF-8'); ?>
											<span class="badge bg-primary ms-1"><?php echo (int)$overviewRow['count']; ?></span>
											<span class="ms-1 text-muted"><?php echo htmlspecialchars($overviewRow['source'], ENT_QUOTES, 'UTF-8'); ?></span>
										</span>
										<?php endforeach; endif; ?>
									</td>
								</tr>
							<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>
					<p class="small text-muted mb-0 mt-2">"P" = term or semester number (per the course's programme calendar). "Normalized" uses the current course-offering registration model; "Legacy" identifies courses not yet migrated. This panel is not shown to lecturers.</p>

					<hr class="my-3">
					<h6 class="mb-2"><i class="fas fa-database me-2"></i>System-wide registration coverage</h6>
					<p class="small text-muted mb-2">Every course that currently has active registrations, regardless of lecturer assignment. Current normalized registrations take precedence; legacy registrations remain visible for courses not yet migrated.</p>
					<div class="table-responsive">
						<table class="table table-sm align-middle mb-0">
							<thead><tr><th style="width:180px;">Course</th><th>Academic year</th><th>Period</th><th>Source</th><th class="text-end" style="width:90px;">Students</th></tr></thead>
							<tbody>
							<?php if (empty($caCoverageAll['rows'])): ?>
								<tr><td colspan="5" class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>No active registrations exist in the normalized or legacy registration records.</td></tr>
							<?php else: foreach ($caCoverageAll['rows'] as $coverageRow): ?>
								<tr>
									<td class="fw-semibold"><?php echo htmlspecialchars($coverageRow['course_code'], ENT_QUOTES, 'UTF-8'); ?></td>
									<td><?php echo htmlspecialchars($coverageRow['academic_year'], ENT_QUOTES, 'UTF-8'); ?></td>
									<td>P<?php echo htmlspecialchars($coverageRow['semester'], ENT_QUOTES, 'UTF-8'); ?></td>
									<td><span class="badge <?php echo $coverageRow['source'] === 'Normalized' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo htmlspecialchars($coverageRow['source'], ENT_QUOTES, 'UTF-8'); ?></span></td>
									<td class="text-end"><span class="badge bg-primary"><?php echo (int)$coverageRow['count']; ?></span></td>
								</tr>
							<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>
					<?php if (!empty($caCoverageAll['capped'])): ?>
						<p class="small text-warning mb-0 mt-2"><i class="fas fa-exclamation-triangle me-1"></i>List truncated to the first 100 course/period groups.</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="row">
		<div class="col-lg-10 mx-auto">
			<div class="data-table-card">
				<div class="card-header">
					<div class="d-flex justify-content-between align-items-center">
						<h5 class="mb-0"><i class="fas fa-upload me-2"></i>Upload CA Results</h5>
					</div>
				</div>
				<div class="card-body">
					<?php if ($assignedCourses === []): ?>
						<div class="alert alert-warning mb-3">
							<i class="fas fa-exclamation-triangle me-2"></i>
							No courses are assigned to your staff account. Ask your HOD/Registrar to allocate courses before uploading CA.
						</div>
					<?php endif; ?>
					<?php if (!$manualEnabled && !$csvEnabled): ?>
						<div class="alert alert-warning mb-3">All CA upload options are currently disabled by the administrator.</div>
					<?php endif; ?>
					<?php if ($flash): ?>
						<div class="alert alert-<?php echo htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
							<i class="fas fa-<?php echo ($flash['type'] ?? '') === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
							<?php echo htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
							<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
						</div>
						<?php endif; ?>
						<ul class="nav nav-tabs" id="caTabs" role="tablist">
						<li class="nav-item" role="presentation">
							<button class="nav-link<?php echo $manualTabActive ? ' active' : ''; ?>" id="manual-tab" data-bs-toggle="tab" data-bs-target="#manual" type="button" role="tab" <?php echo $manualEnabled ? '' : 'disabled'; ?>>Manual Entry</button>
						</li>
						<li class="nav-item" role="presentation">
							<button class="nav-link<?php echo $csvTabActive ? ' active' : ''; ?>" id="csv-tab" data-bs-toggle="tab" data-bs-target="#csv" type="button" role="tab" <?php echo $csvEnabled ? '' : 'disabled'; ?>>CSV Upload</button>
						</li>
					</ul>
					<div class="tab-content p-3 border border-top-0" id="caTabsContent">
						<div class="tab-pane fade<?php echo $manualTabActive ? ' show active' : ''; ?>" id="manual" role="tabpanel">
							<?php if (!empty($manualMessage)) { echo $manualMessage; } ?>
							<form method="post" id="caManualForm" class="upload-ca-form">
								<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
								<input type="hidden" name="program_type" id="programType" value="">

								<div class="row g-3">
									<div class="col-md-4">
										<label class="form-label">1. Academic Year <span class="required-star">*</span></label>
										<select class="form-select" name="Year" id="yearSelect" required>
											<option value="" disabled <?php echo $preselectYear === '' ? 'selected' : ''; ?>>Select academic year</option>
											<?php foreach (($academicYears ?? []) as $academicYear): ?>
												<?php $ay = (string)$academicYear; ?>
												<option value="<?php echo htmlspecialchars($ay, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $preselectYear === $ay ? 'selected' : ''; ?>><?php echo htmlspecialchars($ay, ENT_QUOTES, 'UTF-8'); ?></option>
											<?php endforeach; ?>
										</select>
										<small class="hint-muted">Choose the academic year first.</small>
									</div>
									<div class="col-md-4">
										<label class="form-label">2. Course <span class="required-star">*</span></label>
										<select class="form-select" name="course_code" id="courseSelect" required <?php echo $preselectYear !== '' ? '' : 'disabled'; ?>>
											<option value="" disabled <?php echo $preselectCourse === '' ? 'selected' : ''; ?>><?php echo $preselectYear !== '' ? 'Select course' : 'Select academic year first'; ?></option>
											<?php foreach (($assignedCourses ?? []) as $c): ?>
												<option value="<?php echo htmlspecialchars($c['course_code'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $preselectCourse === (string)$c['course_code'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['course_code'].' - '.$c['course_name'], ENT_QUOTES, 'UTF-8'); ?></option>
											<?php endforeach; ?>
										</select>
										<small id="courseTypeHint" class="hint-muted">Select a course to load its students.</small>
									</div>
									<div class="col-md-4 hidden-block" id="semesterRow">
										<label class="form-label">Semester <span class="required-star">*</span></label>
										<select class="form-select" name="semester" id="semesterSelect">
											<option value="" disabled selected>Select</option>
											<option value="1">1</option>
											<option value="2">2</option>
										</select>
									</div>
									<div class="col-md-4 hidden-block" id="termRow">
										<label class="form-label">Term <span class="required-star">*</span></label>
										<select class="form-select" name="term" id="termSelect">
											<option value="" disabled selected>Select</option>
											<option value="1">Term 1</option>
											<option value="2">Term 2</option>
											<option value="3">Term 3 (Final)</option>
										</select>
									</div>
								</div>

								<div id="filterSummary" class="alert alert-light border hidden-block mb-3" role="status" aria-live="polite">
									<div class="d-flex justify-content-between align-items-start gap-3">
										<div>
											<strong><i class="fas fa-filter me-2"></i>Selected filters</strong>
											<ul class="mb-0 mt-2 small" id="filterSummaryList"></ul>
										</div>
										<button type="button" id="resetFiltersBtn" class="btn btn-sm btn-outline-secondary">Reset filters</button>
									</div>
								</div>

								<div id="loadingSpinner" class="hidden-block text-center py-3">
									<i class="fas fa-spinner fa-spin"></i> Loading students, please wait...
								</div>

								<div id="noStudentsInfo" class="alert alert-warning hidden-block" role="alert">
									<div class="d-flex gap-2 align-items-start">
										<i class="fas fa-user-slash mt-1" aria-hidden="true"></i>
										<div>
											<strong>No eligible students</strong>
											<p id="noStudentsMessage" class="mb-0 mt-1 small"></p>
										</div>
									</div>
								</div>

								<div id="courseConfigAlert" class="alert alert-warning hidden-block" role="alert">
									<i class="fas fa-info-circle me-2"></i><strong id="courseConfigTitle">Course not available for CA upload</strong>
									<p id="courseConfigMessage" class="mb-2 mt-2"></p>
									<a id="shortCourseCaLink" href="upload_ca_short.php" class="btn btn-sm btn-outline-primary hidden-block">Open Short-Course CA Upload</a>
								</div>

								<div id="errorAlert" class="alert alert-danger hidden-block">
									<i class="fas fa-exclamation-triangle me-2"></i><strong>Error Loading Students</strong><br>
									<span id="errorMessage"></span>
								</div>

								<div id="studentSection" class="hidden-block mt-3">
									<div class="row g-3">
										<div class="col-md-4">
											<label class="form-label">Student <span class="required-star">*</span></label>
											<select class="form-select" name="Sid" id="studentSelect" required>
												<option value="" disabled selected>Select a student</option>
											</select>
											<small id="studentCount" class="hint-muted"></small>
										</div>
										<div class="col-md-4">
											<label class="form-label">Assessment Type <span class="required-star">*</span></label>
											<select class="form-select" name="assessment_type" id="assessmentType" required disabled>
												<option value="" disabled selected>Select course first</option>
											</select>
										</div>
										<div class="col-md-4">
											<label class="form-label">Mark for <span id="assessmentLabel" class="hint-primary">Assessment</span></label>
											<input type="number" step="0.01" min="0" max="100" name="mark_value" id="markValue" value="0" class="form-control">
											<small id="maxMarkHint" class="hint-muted d-block">Enter the raw mark out of 100.</small>
											<small id="scaledMarkPreview" class="hint-primary d-block"></small>
										</div>
										<div class="col-12">
											<div class="form-actions">
												<button type="submit" name="manual_submit" id="saveCaBtn" class="btn btn-success" disabled>Save CA Mark</button>
												<button type="button" id="resetFormBtn" class="btn btn-outline-secondary">Reset</button>
											</div>
											<small id="uploadBlockedHint" class="hint-muted d-block mt-2">Select a course with registered students before saving CA marks.</small>
										</div>
									</div>
								</div>
							</form>
						</div>

						<!-- CSV tab -->
						<div class="tab-pane fade<?php echo $csvTabActive ? ' show active' : ''; ?>" id="csv" role="tabpanel">
								<?php if (!empty($csvLog)): ?>
								<div class="card mb-3">
									<div class="card-header bg-light py-2">
										<h6 class="mb-0"><i class="fas fa-list me-2"></i>Processing Details (<?php echo count($csvLog); ?> notes)</h6>
									</div>
									<div class="card-body py-2" style="max-height:300px;overflow-y:auto;">
										<?php foreach ($csvLog as $msg): ?>
										<div class="text-warning small"><i class="fas fa-exclamation-triangle me-1"></i><?php echo htmlspecialchars((string)$msg, ENT_QUOTES, 'UTF-8'); ?></div>
										<?php endforeach; ?>
									</div>
								</div>
								<?php endif; ?>
							<div class="alert alert-info mb-3">
								<i class="fas fa-info-circle me-2"></i><strong>CSV Upload Instructions:</strong>
								<ul class="mb-0 mt-2">
									<li>Download the template below to ensure correct formatting</li>
									<li><strong>CA entry:</strong> Enter each raw component mark out of 100. Course weights are applied automatically.</li>
									<li><strong>Term courses:</strong>
										<ul>
											<li>Term 1 & 2: A1, A2 (Assignments), T1/T2 (Test 1 or Test 2)</li>
											<li>Term 3: A1 and A2 only where applicable. CA does not include external examination marks.</li>
										</ul>
									</li>
									<li>Do not include % symbols or text in score columns</li>
								</ul>
							</div>
							<form method="post" action="upload_ca_csv.php" enctype="multipart/form-data">
								<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
								<div class="row g-3 align-items-end">
									<div class="col-md-6">
										<label class="form-label"><i class="fas fa-file-csv me-1"></i>CSV File</label>
										<input type="file" name="file" accept=".csv" class="form-control" <?php echo $csvEnabled ? 'required' : 'disabled'; ?>>
									</div>
									<div class="col-md-6 d-flex gap-2">
										<button class="btn btn-success" type="submit" name="submit" value="1" <?php echo $csvEnabled ? '' : 'disabled'; ?>>
											<i class="fas fa-upload me-1"></i>Upload CSV
										</button>
										<a href="download_ca_template.php" class="btn btn-outline-primary" <?php echo $csvEnabled ? '' : 'disabled'; ?>>
											<i class="fas fa-download me-1"></i>Download Template
										</a>
									</div>
								</div>
								<p class="mt-3 text-muted small">
									<i class="fas fa-exclamation-triangle me-1"></i>
									Required columns: SID, Course_Code, A1, A2, A3, T1, T2, semester, Year
								</p>
							</form>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div><!-- /.container-fluid upload-ca-page -->



<script>
document.addEventListener('DOMContentLoaded', function() {
	const programType = document.getElementById('programType');
	const courseSelect = document.getElementById('courseSelect');
	const assessmentType = document.getElementById('assessmentType');
	const semesterSelect = document.getElementById('semesterSelect');
	const semesterRow = document.getElementById('semesterRow');
	const termSelect = document.getElementById('termSelect');
	const termRow = document.getElementById('termRow');
	const yearSelect = document.getElementById('yearSelect');
	const studentSelect = document.getElementById('studentSelect');
	const studentSection = document.getElementById('studentSection');
	const noStudentsInfo = document.getElementById('noStudentsInfo');
	const noStudentsMessage = document.getElementById('noStudentsMessage');
	const errorAlert = document.getElementById('errorAlert');
	const errorMessage = document.getElementById('errorMessage');
	const loadingSpinner = document.getElementById('loadingSpinner');
	const studentCount = document.getElementById('studentCount');
	const assessmentLabel = document.getElementById('assessmentLabel');
	const markValue = document.getElementById('markValue');
	const resetFormBtn = document.getElementById('resetFormBtn');
	const resetFiltersBtn = document.getElementById('resetFiltersBtn');
	const maxMarkHint = document.getElementById('maxMarkHint');
	const scaledMarkPreview = document.getElementById('scaledMarkPreview');
	const courseTypeHint = document.getElementById('courseTypeHint');
	const saveCaBtn = document.getElementById('saveCaBtn');
	const uploadBlockedHint = document.getElementById('uploadBlockedHint');
	const filterSummary = document.getElementById('filterSummary');
	const filterSummaryList = document.getElementById('filterSummaryList');
	const caManualForm = document.getElementById('caManualForm');
	const courseConfigAlert = document.getElementById('courseConfigAlert');
	const courseConfigMessage = document.getElementById('courseConfigMessage');
	const courseConfigTitle = document.getElementById('courseConfigTitle');
	const shortCourseCaLink = document.getElementById('shortCourseCaLink');

	let studentsLoaded = false;

	function getSelectedPeriod() {
		const pType = programType.value;
		if (pType === 'term') {
			return termSelect.value || '';
		}
		return semesterSelect.value || '';
	}

	function setUploadEnabled(enabled) {
		studentsLoaded = enabled;
		if (saveCaBtn) {
			saveCaBtn.disabled = !enabled;
		}
		if (uploadBlockedHint) {
			uploadBlockedHint.textContent = enabled
				? 'Select a student and assessment type, then enter the mark.'
				: 'CA upload is disabled until at least one eligible student is found for the selected filters.';
		}
	}

	function updateFilterSummary(data) {
		if (!filterSummary || !filterSummaryList) return;
		const summary = data && data.filter_summary ? data.filter_summary : null;
		if (!summary) {
			hide(filterSummary);
			filterSummaryList.innerHTML = '';
			return;
		}
		const items = [
			['Course', summary.course || courseSelect.value || '—'],
			['Programme', summary.program || '—'],
			['Academic year', summary.academic_year || yearSelect.value || '—'],
			['Period', summary.period || '—'],
			['Lecturer', summary.lecturer || '—'],
		];
		filterSummaryList.innerHTML = items.map(([label, value]) =>
			`<li><strong>${label}:</strong> ${String(value).replace(/</g, '&lt;')}</li>`
		).join('');
		show(filterSummary);
	}

	function updateAssessmentOptions() {
		const pType = programType.value;
		const term = termSelect.value || '';
		assessmentType.innerHTML = '<option value="" disabled selected>Select</option>';
		
		if (pType === 'semester' || pType === 'short_course') {
			// Semester/Short Course: Assignment 1, Assignment 2, Test
			assessmentType.innerHTML += '<option value="CA1_SEM">Assignment 1</option>';
			assessmentType.innerHTML += '<option value="CA2_SEM">Assignment 2</option>';
			assessmentType.innerHTML += '<option value="Test_SEM">Test</option>';
			maxMarkHint.textContent = 'Enter the raw component mark out of 100.';
		} else if (pType === 'term') {
			// Term-based: Different components per term
			if (term === '1') {
				// Term 1: A1, A2, Test1
				assessmentType.innerHTML += '<option value="CA1_TERM">Assignment 1</option>';
				assessmentType.innerHTML += '<option value="CA2_TERM">Assignment 2</option>';
				assessmentType.innerHTML += '<option value="Test1_TERM">Test 1</option>';
				maxMarkHint.textContent = 'Term 1: enter each raw component mark out of 100.';
			} else if (term === '2') {
				// Term 2: A1, A2, Test2
				assessmentType.innerHTML += '<option value="CA1_TERM">Assignment 1</option>';
				assessmentType.innerHTML += '<option value="CA2_TERM">Assignment 2</option>';
				assessmentType.innerHTML += '<option value="Test2_TERM">Test 2</option>';
				maxMarkHint.textContent = 'Term 2: enter each raw component mark out of 100.';
			} else if (term === '3') {
				// Term 3: A1, A2 only (no test - contributes to final exam)
				assessmentType.innerHTML += '<option value="CA1_TERM">Assignment 1</option>';
				assessmentType.innerHTML += '<option value="CA2_TERM">Assignment 2</option>';
				maxMarkHint.textContent = 'Term 3: enter each raw component mark out of 100.';
			} else {
				assessmentType.innerHTML += '<option value="CA1_TERM">Assignment 1</option>';
				assessmentType.innerHTML += '<option value="CA2_TERM">Assignment 2</option>';
				maxMarkHint.textContent = 'Select a term to see available assessments';
			}
		}
	}

	// Toggle the .hidden-block class instead of inline display so the CSS rule
	// (.upload-ca-form .hidden-block { display:none }) and JS stay in agreement —
	// setting style.display='' previously left the class on and kept rows hidden.
	function show(el) { if (el) el.classList.remove('hidden-block'); }
	function hide(el) { if (el) el.classList.add('hidden-block'); }

	function handleProgramTypeChange(pType) {
		programType.value = pType;
		if (pType === 'semester') {
			show(semesterRow);
			hide(termRow);
			// Only the active period field is enabled/required, so the inactive
			// one is never submitted and can't shadow the real value server-side.
			semesterSelect.disabled = false;
			semesterSelect.required = true;
			termSelect.disabled = true;
			termSelect.required = false;
			termSelect.selectedIndex = 0;
		} else if (pType === 'term') {
			show(termRow);
			hide(semesterRow);
			termSelect.disabled = false;
			termSelect.required = true;
			semesterSelect.disabled = true;
			semesterSelect.required = false;
			semesterSelect.selectedIndex = 0;
		} else {
			hide(semesterRow);
			hide(termRow);
			semesterSelect.disabled = true;
			semesterSelect.required = false;
			termSelect.disabled = true;
			termSelect.required = false;
		}
		assessmentType.disabled = (pType === '');
		updateAssessmentOptions();
		autoLoadStudentsIfReady();
	}

	// Auto-select the period (semester/term) + academic year where the chosen
	// course actually has registrations, then load its students. This is what
	// makes every assigned course show its students, not just the one whose
	// offering semester happened to match the dropdown.
	function applyCoursePeriodDefaults(pType, period, year) {
		// Academic year is chosen first by the user — never override it here.
		if (period) {
			if (pType === 'term') {
				termSelect.value = String(period);
				updateAssessmentOptions();
			} else {
				semesterSelect.value = String(period);
			}
		}
		autoLoadStudentsIfReady();
	}

	async function autoLoadStudentsIfReady() {
		const pType = programType.value;
		if (!pType) return;
		const course = courseSelect.value;
		if (!course) return;
		const period = getSelectedPeriod();
		if (!period) return;
		const year = yearSelect.value;
		if (!year) return;

		setUploadEnabled(false);
		hide(studentSection);
		hide(noStudentsInfo);
		hide(errorAlert);
		show(loadingSpinner);

		try {
			const url = `ajax_get_course_students.php?course_code=${encodeURIComponent(course)}&semester=${encodeURIComponent(period)}&year=${encodeURIComponent(year)}`;
			const response = await fetch(url);
			
			if (!response.ok) {
				throw new Error(`Server returned ${response.status}: ${response.statusText}`);
			}
			
			const data = await response.json();

			hide(loadingSpinner);
			updateFilterSummary(data);

			if (data.success && data.students && data.students.length > 0) {
				studentSelect.innerHTML = '<option value="" disabled selected>Select a student</option>';
				let payableCount = 0;
				data.students.forEach(student => {
					const opt = document.createElement('option');
					opt.value = student.Sid;
					const paid = typeof student.payment_percent === 'number' ? student.payment_percent : null;
					const need = typeof student.required_percent === 'number' ? student.required_percent : null;
					const eligible = student.ca_eligible !== false;
					if (eligible) {
						payableCount += 1;
					} else {
						opt.disabled = true;
					}
					let label = student.Sid + (student.name ? ' - ' + student.name : '');
					if (paid !== null && need !== null) {
						label += eligible
							? ` (paid ${paid}% ≥ ${need}%)`
							: ` (paid ${paid}% — needs ${need}%)`;
					} else if (!eligible) {
						label += ' (payment incomplete)';
					}
					opt.textContent = label;
					studentSelect.appendChild(opt);
				});
				studentCount.textContent = payableCount > 0
					? `${payableCount} of ${data.students.length} student(s) eligible for CA entry`
					: `${data.students.length} student(s) found, but none meet the fee threshold for this period`;
				show(studentSection);
				setUploadEnabled(payableCount > 0);
				updateAssessmentLabel();
			} else if (data.success === false && data.error) {
				errorMessage.textContent = data.error || 'Unable to load students.';
				show(errorAlert);
				setUploadEnabled(false);
			} else {
				const coursePart = data.course_name || course;
				const periodPart = data.period_label || `Period ${period}`;
				const yearPart   = data.year || year;
				noStudentsMessage.textContent = data.info
					|| `No students registered for ${coursePart} · ${periodPart} · Academic Year ${yearPart}.`;
				show(noStudentsInfo);
				setUploadEnabled(false);
			}
		} catch (error) {
			hide(loadingSpinner);
			errorMessage.textContent = `Connection error: ${error.message}. Please check that the server is running and try again.`;
			show(errorAlert);
			setUploadEnabled(false);
			console.error('Student loading error:', error);
		}
	}

	function updateAssessmentLabel() {
		const labels = {
			'CA1_SEM': 'Assignment 1',
			'CA2_SEM': 'Assignment 2',
			'Test_SEM': 'Test',
			'CA1_TERM': 'Assignment 1',
			'CA2_TERM': 'Assignment 2',
			'Test1_TERM': 'Test 1',
			'Test2_TERM': 'Test 2'
		};
		assessmentLabel.textContent = labels[assessmentType.value] || 'Assessment';
		updateScaledPreview();
	}

	function updateScaledPreview() {
		const mark = parseFloat(markValue.value) || 0;
		const assessment = assessmentType.value;
		const pType = programType.value;
		
		scaledMarkPreview.textContent = mark > 0 ? `Local CA mark: ${mark.toFixed(2)}%` : '';
	}

	// Course selection - detect type and reset student list
	courseSelect.addEventListener('change', async function() {
		const course = this.value;
		setUploadEnabled(false);
		hide(studentSection);
		hide(noStudentsInfo);
		hide(errorAlert);
		hide(courseConfigAlert);
		if (shortCourseCaLink) hide(shortCourseCaLink);
		studentSelect.innerHTML = '<option value="" disabled selected>Select a student</option>';
		studentCount.textContent = '';
		
		if (!course) {
			courseTypeHint.innerHTML = 'Select a course to detect type';
			handleProgramTypeChange('');
			return;
		}
		
		courseTypeHint.innerHTML = 'Detecting course type...';
		assessmentType.disabled = true;
		
		try {
			const response = await fetch(`ajax_get_course_type.php?course_code=${encodeURIComponent(course)}`);
			const data = await response.json();
			
			if (data.success) {
				const pType = data.type;
				const examType = data.examination_type || 'internal';

				if (data.ca_upload_blocked) {
					courseTypeHint.innerHTML = '<span class="hint-primary">Short Course</span> (External Examination)';
					if (courseConfigTitle) courseConfigTitle.textContent = 'External short course — CA upload not permitted';
					if (courseConfigMessage) {
						courseConfigMessage.textContent = 'This course is configured for external examination. Continuous Assessment marks cannot be uploaded here. Contact the examinations office or administrator if CA entry is required.';
					}
					show(courseConfigAlert);
					setUploadEnabled(false);
					handleProgramTypeChange('');
					return;
				}

				if (data.is_short_course && pType === 'short_course') {
					courseTypeHint.innerHTML = '<span class="hint-primary">Short Course</span> (Internal Examination)';
					if (courseConfigTitle) courseConfigTitle.textContent = 'Use Short-Course CA Upload';
					if (courseConfigMessage) {
						courseConfigMessage.textContent = 'This course is a short course. Use the dedicated Short-Course CA page to upload marks for enrolled students.';
					}
					if (shortCourseCaLink) {
						shortCourseCaLink.href = data.short_course_upload_url || 'upload_ca_short.php';
						show(shortCourseCaLink);
					}
					show(courseConfigAlert);
					setUploadEnabled(false);
					handleProgramTypeChange('');
					return;
				}
				
				if (pType === 'short_course') {
					courseTypeHint.innerHTML = `<span class="hint-primary">Short Course</span> (${examType === 'internal' ? 'Internal' : 'External'} Exam)`;
				} else {
					courseTypeHint.innerHTML = pType === 'term'
						? '<span class="hint-primary">Term-Based</span> program'
						: '<span class="hint-primary">Semester-Based</span> program';
				}
				
				if (pType === 'short_course') {
					semesterSelect.innerHTML = '<option value="" disabled selected>Select intake batch</option>';
					if (data.periods && data.periods.length) {
						data.periods.forEach(p => {
							semesterSelect.innerHTML += `<option value="${p}">${p}</option>`;
						});
					} else {
						semesterSelect.innerHTML += '<option value="Short Course Batch">Short Course Batch</option>';
					}
					
					const semesterLabel = semesterRow.querySelector('label');
					if (semesterLabel) semesterLabel.innerHTML = 'Intake Batch <span class="required-star">*</span>';
					
					show(semesterRow);
					hide(termRow);
					semesterSelect.disabled = false;
					semesterSelect.required = true;
					termSelect.disabled = true;
					termSelect.required = false;
					
					programType.value = 'short_course';
					assessmentType.disabled = false;
					updateAssessmentOptions();
					applyCoursePeriodDefaults('short_course', data.period, data.year);
				} else {
					const semesterLabel = semesterRow.querySelector('label');
					if (semesterLabel) semesterLabel.innerHTML = 'Semester <span class="required-star">*</span>';
					
					semesterSelect.innerHTML = '<option value="" disabled selected>Select</option><option value="1">1</option><option value="2">2</option>';
					
					handleProgramTypeChange(pType);
					applyCoursePeriodDefaults(pType, data.period, data.year);
				}
			} else {
				courseTypeHint.innerHTML = '<span class="hint-primary">Semester-Based</span> (default)';
				handleProgramTypeChange('semester');
			}
		} catch (error) {
			console.error('Error detecting course type:', error);
			courseTypeHint.innerHTML = '<span class="hint-primary">Semester-Based</span> (default)';
			handleProgramTypeChange('semester');
		}
	});

	termSelect.addEventListener('change', function() {
		updateAssessmentOptions();
		autoLoadStudentsIfReady();
	});

	semesterSelect.addEventListener('change', autoLoadStudentsIfReady);
	yearSelect.addEventListener('change', function () {
		if (yearSelect.value) {
			courseSelect.disabled = false;
			if (courseSelect.options[0]) { courseSelect.options[0].textContent = 'Select course'; }
		} else {
			courseSelect.disabled = true;
			courseSelect.selectedIndex = 0;
		}
		autoLoadStudentsIfReady();
	});
	assessmentType.addEventListener('change', () => {
		updateAssessmentLabel();
		updateScaledPreview();
	});
	markValue.addEventListener('input', updateScaledPreview);

	if (caManualForm) {
		caManualForm.addEventListener('submit', function (event) {
			if (!studentsLoaded) {
				event.preventDefault();
				noStudentsMessage.textContent = 'Select a course with registered students before saving CA marks.';
				show(noStudentsInfo);
				return false;
			}
			if (!studentSelect.value) {
				event.preventDefault();
				errorMessage.textContent = 'Please select a student before saving CA marks.';
				show(errorAlert);
				return false;
			}
			if (!assessmentType.value) {
				event.preventDefault();
				errorMessage.textContent = 'Please select an assessment type before saving CA marks.';
				show(errorAlert);
				return false;
			}
			return true;
		});
	}

	// Reset form
	resetFormBtn.addEventListener('click', function() {
		programType.value = '';
		courseSelect.selectedIndex = 0;
		courseSelect.disabled = true;
		if (courseSelect.options[0]) { courseSelect.options[0].textContent = 'Select academic year first'; }
		assessmentType.innerHTML = '<option value="" disabled selected>Select course first</option>';
		assessmentType.disabled = true;
		semesterSelect.selectedIndex = 0;
		termSelect.selectedIndex = 0;
		yearSelect.selectedIndex = 0;
		studentSelect.innerHTML = '<option value="" disabled selected>Select a student</option>';
		markValue.value = '0';
		hide(studentSection);
		hide(noStudentsInfo);
		hide(errorAlert);
		hide(loadingSpinner);
		studentCount.textContent = '';
		scaledMarkPreview.textContent = '';
		hide(semesterRow);
		hide(termRow);
		setUploadEnabled(false);
		hide(filterSummary);
		if (filterSummaryList) filterSummaryList.innerHTML = '';
		hide(courseConfigAlert);
		if (shortCourseCaLink) hide(shortCourseCaLink);
		// Clear the period fields' active/required state on reset.
		semesterSelect.disabled = true;
		semesterSelect.required = false;
		termSelect.disabled = true;
		termSelect.required = false;
		courseTypeHint.textContent = 'Select a course to detect type';
	});

	if (resetFiltersBtn) {
		resetFiltersBtn.addEventListener('click', function () {
			resetFormBtn.click();
		});
	}

	setUploadEnabled(false);

	// Honour deep-links / single-year defaults so lecturers land ready to work.
	if (yearSelect.value) {
		courseSelect.disabled = false;
		if (courseSelect.options[0] && !courseSelect.value) {
			courseSelect.options[0].textContent = 'Select course';
		}
		if (courseSelect.value) {
			courseSelect.dispatchEvent(new Event('change'));
		}
	}
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
