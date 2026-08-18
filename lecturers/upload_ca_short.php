<?php
$page_title = 'Upload Short-Course CA';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/short_course_ca.php';

sc_ca_ensure_schema($db);

$staffId = (string)($_SESSION['staff_id'] ?? '');
$manualMessage = '';
$csvLog = [];

// Short courses this lecturer owns (created). Used for both tabs.
$shortCourses = sc_ca_staff_courses($db, $staffId);
$ownedCourseByCode = [];
foreach ($shortCourses as $sc) {
    $ownedCourseByCode[(string)$sc['course_code']] = (int)$sc['id'];
}

// ── Manual single-student CA submission ────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['manual_submit'])) {
    wuc_verify_csrf();
    $shortCourseId = (int)($_POST['short_course_id'] ?? 0);
    $studentId = trim($_POST['student_id'] ?? '');
    $components = [
        'A1' => $_POST['A1'] ?? '',
        'A2' => $_POST['A2'] ?? '',
        'A3' => $_POST['A3'] ?? '',
        'T1' => $_POST['T1'] ?? '',
        'T2' => $_POST['T2'] ?? '',
    ];
    $hasAnyMark = false;
    foreach ($components as $v) {
        if (trim((string)$v) !== '') { $hasAnyMark = true; break; }
    }

    if ($shortCourseId <= 0 || $studentId === '') {
        $manualMessage = '<div class="alert alert-danger">Please select a short course and a student.</div>';
    } elseif (!$hasAnyMark) {
        $manualMessage = '<div class="alert alert-danger">Enter at least one CA component.</div>';
    } elseif (!sc_ca_staff_owns($db, $staffId, $shortCourseId)) {
        $manualMessage = '<div class="alert alert-danger">You are not assigned to this short course.</div>';
    } elseif (!sc_ca_student_enrolled($db, $shortCourseId, $studentId)) {
        $manualMessage = '<div class="alert alert-warning">That student is not enrolled on this short course.</div>';
    } else {
        $meta = sc_ca_course_meta($db, $shortCourseId);
        $save = sc_ca_save($db, $shortCourseId, $meta['course_code'] ?? '', $studentId, $components, $staffId);
        $manualMessage = $save['ok']
            ? '<div class="alert alert-success">' . htmlspecialchars($save['message']) . ' Total CA: ' . htmlspecialchars((string)$save['total_ca']) . '</div>'
            : '<div class="alert alert-danger">' . htmlspecialchars($save['message']) . '</div>';
    }
}

// ── CSV bulk submission ────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['csv_submit'])) {
    wuc_verify_csrf();

    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        wuc_flash('danger', 'CSV upload failed. Please select a valid CSV file.');
        wuc_safe_redirect('upload_ca_short.php?tab=csv');
    }
    if ((int)($upload['size'] ?? 0) <= 0 || (int)$upload['size'] > 5 * 1024 * 1024) {
        wuc_flash('danger', 'CSV file is empty or larger than the 5MB limit.');
        wuc_safe_redirect('upload_ca_short.php?tab=csv');
    }
    if (strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
        wuc_flash('danger', 'Only CSV files are allowed.');
        wuc_safe_redirect('upload_ca_short.php?tab=csv');
    }
    $tmp = (string)($upload['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        wuc_flash('danger', 'No valid uploaded file was found.');
        wuc_safe_redirect('upload_ca_short.php?tab=csv');
    }

    $fh = fopen($tmp, 'r');
    if (!$fh) {
        wuc_flash('danger', 'Could not read the uploaded file.');
        wuc_safe_redirect('upload_ca_short.php?tab=csv');
    }

    $header = fgetcsv($fh, 10000, ',');
    if ($header === false || $header === null) {
        fclose($fh);
        wuc_flash('danger', 'CSV file is empty or missing a header row.');
        wuc_safe_redirect('upload_ca_short.php?tab=csv');
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
    $required = ['sid', 'course_code', 'a1', 'a2', 'a3', 't1', 't2'];
    foreach ($required as $col) {
        if (!in_array($col, $header, true)) {
            fclose($fh);
            wuc_flash('danger', "Missing required CSV column: {$col}");
            wuc_safe_redirect('upload_ca_short.php?tab=csv');
        }
    }
    $idx = array_flip($header);

    $readMark = static function ($value, string $label, string $sid, array &$log) {
        $value = trim((string)$value);
        if ($value === '') { return null; }
        if (!is_numeric($value)) { $log[] = "Skipped {$sid}: {$label} must be numeric."; return false; }
        $mark = round((float)$value, 2);
        if ($mark < 0 || $mark > 100) { $log[] = "Skipped {$sid}: {$label} must be between 0 and 100."; return false; }
        return $mark;
    };

    $success = 0; $skipped = 0; $errors = 0;
    while (($row = fgetcsv($fh, 10000, ',')) !== false) {
        if ($row === [null] || count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        $first = trim((string)($row[0] ?? ''));
        if ($first !== '' && (strncmp($first, '---', 3) === 0 || preg_match('/^instructions:?$/i', $first) || preg_match('/^\d+\.\s/', $first))) {
            continue;
        }

        $Sid = trim((string)($row[$idx['sid']] ?? ''));
        $Course_Code = trim((string)($row[$idx['course_code']] ?? ''));
        if ($Sid === '' || $Course_Code === '') {
            $csvLog[] = 'Skipped row: SID and Course_Code are required.';
            $skipped++; continue;
        }
        if (!isset($ownedCourseByCode[$Course_Code])) {
            $csvLog[] = "Skipped {$Sid}: {$Course_Code} is not a short course assigned to you.";
            $skipped++; continue;
        }
        $shortCourseId = $ownedCourseByCode[$Course_Code];
        if (!sc_ca_student_enrolled($db, $shortCourseId, $Sid)) {
            $csvLog[] = "Skipped {$Sid}: not enrolled on {$Course_Code}.";
            $skipped++; continue;
        }

        $marks = [
            'A1' => $readMark($row[$idx['a1']] ?? '', 'A1', $Sid, $csvLog),
            'A2' => $readMark($row[$idx['a2']] ?? '', 'A2', $Sid, $csvLog),
            'A3' => $readMark($row[$idx['a3']] ?? '', 'A3', $Sid, $csvLog),
            'T1' => $readMark($row[$idx['t1']] ?? '', 'T1', $Sid, $csvLog),
            'T2' => $readMark($row[$idx['t2']] ?? '', 'T2', $Sid, $csvLog),
        ];
        if (in_array(false, $marks, true)) {
            $skipped++; continue;
        }
        if ($marks['A1'] === null && $marks['A2'] === null && $marks['A3'] === null && $marks['T1'] === null && $marks['T2'] === null) {
            $csvLog[] = "Skipped {$Sid}: no CA marks provided.";
            $skipped++; continue;
        }

        $save = sc_ca_save($db, $shortCourseId, $Course_Code, $Sid, $marks, $staffId);
        if ($save['ok']) { $success++; } else { $csvLog[] = "Error {$Sid}: " . $save['message']; $errors++; }
    }
    fclose($fh);

    if (!empty($csvLog)) {
        $_SESSION['_sc_upload_log'] = $csvLog;
    }
    wuc_flash('success', "Short-course CSV CA upload complete: {$success} processed, {$skipped} skipped, {$errors} errors.");
    wuc_safe_redirect('upload_ca_short.php?tab=csv');
}

require "includes/nav.php";

$flash = wuc_get_flash();
if (!empty($_SESSION['_sc_upload_log'])) {
    $csvLog = (array)$_SESSION['_sc_upload_log'];
    unset($_SESSION['_sc_upload_log']);
}
$activeTab = (($_GET['tab'] ?? 'manual') === 'csv') ? 'csv' : 'manual';
?>

<div class="container-fluid px-4 portal-dashboard lecturer-workflow-page upload-ca-page">
	<div class="dashboard-header lecturer-section mb-4">
		<div class="row align-items-center">
			<div class="col">
				<h1 class="dashboard-title">Short-Course CA</h1>
				<p class="text-muted">Enter continuous assessment marks for short-course enrollees</p>
			</div>
			<div class="col-auto header-actions">
				<a class="btn btn-outline-primary" href="upload_ca.php"><i class="fas fa-arrow-left me-2"></i>Mainstream CA</a>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-lg-10 mx-auto">
			<div class="data-table-card">
				<div class="card-header">
					<h5 class="mb-0"><i class="fas fa-graduation-cap me-2"></i>Short-Course CA</h5>
				</div>
				<div class="card-body">
					<?php if ($flash): ?>
					<div class="alert alert-<?php echo htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
						<i class="fas fa-<?php echo ($flash['type'] ?? '') === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
						<?php echo htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
						<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
					</div>
					<?php endif; ?>

					<?php if (empty($shortCourses)): ?>
						<div class="alert alert-info mb-0">
							<i class="fas fa-info-circle me-2"></i>You have no assigned short courses. Ask Admin or HOD to assign you via Short Courses → Assign Lecturer, then return here to enter CA.
						</div>
					<?php else: ?>
					<ul class="nav nav-tabs" id="scCaTabs" role="tablist">
						<li class="nav-item" role="presentation">
							<button class="nav-link<?php echo $activeTab === 'manual' ? ' active' : ''; ?>" id="sc-manual-tab" data-bs-toggle="tab" data-bs-target="#sc-manual" type="button" role="tab">Manual Entry</button>
						</li>
						<li class="nav-item" role="presentation">
							<button class="nav-link<?php echo $activeTab === 'csv' ? ' active' : ''; ?>" id="sc-csv-tab" data-bs-toggle="tab" data-bs-target="#sc-csv" type="button" role="tab">CSV Upload</button>
						</li>
					</ul>
					<div class="tab-content p-3 border border-top-0" id="scCaTabsContent">

						<!-- Manual tab -->
						<div class="tab-pane fade<?php echo $activeTab === 'manual' ? ' show active' : ''; ?>" id="sc-manual" role="tabpanel">
							<?php if (!empty($manualMessage)) { echo $manualMessage; } ?>
							<form method="post" id="scManualForm" class="upload-ca-form">
								<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="form-label">Short Course <span class="required-star">*</span></label>
										<select class="form-select" name="short_course_id" id="scCourseSelect" required>
											<option value="" disabled selected>Select short course</option>
											<?php foreach ($shortCourses as $sc): ?>
												<option value="<?php echo (int)$sc['id']; ?>"><?php echo htmlspecialchars($sc['course_code'] . ' - ' . $sc['course_name']); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-md-6">
										<label class="form-label">Student <span class="required-star">*</span></label>
										<select class="form-select" name="student_id" id="scStudentSelect" required disabled>
											<option value="" disabled selected>Select a short course first</option>
										</select>
										<small id="scStudentCount" class="hint-muted"></small>
									</div>
								</div>

								<div id="scLoadingSpinner" class="hidden-block text-center py-3">
									<i class="fas fa-spinner fa-spin"></i> Loading enrolled students...
								</div>
								<div id="scNoStudentsInfo" class="alert alert-info hidden-block">
									<i class="fas fa-info-circle me-2"></i><span id="scNoStudentsMessage"></span>
								</div>
								<div id="scErrorAlert" class="alert alert-danger hidden-block">
									<i class="fas fa-exclamation-triangle me-2"></i><span id="scErrorMessage"></span>
								</div>

								<div id="scMarksSection" class="hidden-block mt-3">
									<p class="hint-muted">Enter each local CA component out of 100. Leave blank any that do not apply — existing values are preserved.</p>
									<div class="row g-3">
										<div class="col-md-2 col-4"><label class="form-label">A1</label><input type="number" step="0.01" min="0" max="100" class="form-control" name="A1"></div>
										<div class="col-md-2 col-4"><label class="form-label">A2</label><input type="number" step="0.01" min="0" max="100" class="form-control" name="A2"></div>
										<div class="col-md-2 col-4"><label class="form-label">A3</label><input type="number" step="0.01" min="0" max="100" class="form-control" name="A3"></div>
										<div class="col-md-2 col-4"><label class="form-label">T1</label><input type="number" step="0.01" min="0" max="100" class="form-control" name="T1"></div>
										<div class="col-md-2 col-4"><label class="form-label">T2</label><input type="number" step="0.01" min="0" max="100" class="form-control" name="T2"></div>
									</div>
									<div class="form-actions mt-3">
										<button type="submit" name="manual_submit" class="btn btn-success">Save CA</button>
									</div>
								</div>
							</form>
						</div>

						<!-- CSV tab -->
						<div class="tab-pane fade<?php echo $activeTab === 'csv' ? ' show active' : ''; ?>" id="sc-csv" role="tabpanel">
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
								<i class="fas fa-info-circle me-2"></i>
								<strong>Format:</strong> SID, Course_Code, A1, A2, A3, T1, T2<br>
								<small>First row is the header. Course_Code must be one of your short courses. Each component is a local mark out of 100; leave blank where not applicable.</small>
							</div>
							<form method="post" action="upload_ca_short.php?tab=csv" enctype="multipart/form-data">
								<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
								<div class="row g-3 align-items-end">
									<div class="col-md-6">
										<label class="form-label"><i class="fas fa-file-csv me-1"></i>CSV File</label>
										<input type="file" name="file" accept=".csv" class="form-control" required>
									</div>
									<div class="col-md-6 d-flex gap-2">
										<button class="btn btn-success" type="submit" name="csv_submit" value="1"><i class="fas fa-upload me-1"></i>Upload CSV</button>
										<a href="download_ca_short_template.php" class="btn btn-outline-primary"><i class="fas fa-download me-1"></i>Download Template</a>
									</div>
								</div>
							</form>
						</div>

					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div><!-- /.container-fluid -->

<script>
document.addEventListener('DOMContentLoaded', function () {
	const courseSelect  = document.getElementById('scCourseSelect');
	const studentSelect = document.getElementById('scStudentSelect');
	const studentCount  = document.getElementById('scStudentCount');
	const marksSection  = document.getElementById('scMarksSection');
	const loading       = document.getElementById('scLoadingSpinner');
	const noStudents    = document.getElementById('scNoStudentsInfo');
	const noStudentsMsg = document.getElementById('scNoStudentsMessage');
	const errorAlert    = document.getElementById('scErrorAlert');
	const errorMsg      = document.getElementById('scErrorMessage');
	if (!courseSelect) { return; }

	function show(el) { if (el) el.classList.remove('hidden-block'); }
	function hide(el) { if (el) el.classList.add('hidden-block'); }

	async function loadStudents() {
		const id = courseSelect.value;
		studentSelect.innerHTML = '<option value="" disabled selected>Select a student</option>';
		studentSelect.disabled = true;
		hide(marksSection); hide(noStudents); hide(errorAlert);
		studentCount.textContent = '';
		if (!id) { return; }
		show(loading);
		try {
			const resp = await fetch(`ajax_get_short_course_students.php?short_course_id=${encodeURIComponent(id)}`);
			const data = await resp.json();
			hide(loading);
			if (data.success && data.students && data.students.length > 0) {
				data.students.forEach(s => {
					const opt = document.createElement('option');
					opt.value = s.Sid;
					opt.textContent = s.Sid + (s.name ? ' - ' + s.name : '');
					studentSelect.appendChild(opt);
				});
				studentSelect.disabled = false;
				const nameLabel = data.short_course_name ? ` for ${data.short_course_name}` : '';
				studentCount.textContent = `${data.students.length} enrolled student(s)${nameLabel}`;
			} else if (data.success === false) {
				errorMsg.textContent = data.error || 'Unable to load students.';
				show(errorAlert);
			} else {
				// Use the server-supplied info message (includes course name) when available.
				noStudentsMsg.textContent = data.info
					|| (data.short_course_name
						? `No students are enrolled in ${data.short_course_name}. Check enrollments in the Short Course management area.`
						: 'No students are enrolled on this short course yet.');
				show(noStudents);
			}
		} catch (err) {
			hide(loading);
			errorMsg.textContent = `Connection error: ${err.message}.`;
			show(errorAlert);
		}
	}

	courseSelect.addEventListener('change', loadStudents);
	studentSelect.addEventListener('change', function () {
		if (studentSelect.value) { show(marksSection); } else { hide(marksSection); }
	});
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
