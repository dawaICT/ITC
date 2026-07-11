<?php
$page_title = 'Assessments';
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', 1);

require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_ui.php';
require_once __DIR__ . '/../includes/elearning_assessment_security.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = trim($_GET['course_code'] ?? ($_POST['course_code'] ?? ''));

if (!$staffId || !$courseCode) {
	http_response_code(403);
	die('Unauthorized');
}
enforceLecturerCourseAccess($db, $staffId, $courseCode);
elearningAssessmentEnsureSchema($db);

function convertUploadedDocumentToPdf(string $absolutePath, string $ext): ?string {
	$ext = strtolower($ext);
	if (!in_array($ext, ['doc', 'docx'], true)) { return null; }
	$pdfPath = preg_replace('/\.[^.]+$/', '.pdf', $absolutePath);
	if (!$pdfPath) { return null; }

	// Attempt Windows MS Word COM automation first.
	if (class_exists('COM')) {
		try {
			$word = new COM('Word.Application');
			$word->Visible = false;
			$word->DisplayAlerts = 0;
			$doc = $word->Documents->Open($absolutePath, false, true);
			$doc->ExportAsFixedFormat($pdfPath, 17); // 17 = wdExportFormatPDF
			$doc->Close(false);
			$word->Quit();
			if (is_file($pdfPath)) { return $pdfPath; }
		} catch (Throwable $e) {
			error_log('DOC->PDF COM conversion failed: ' . $e->getMessage());
		}
	}

	// Fallback: LibreOffice CLI if available.
	$soffice = trim((string)@shell_exec('where soffice 2>NUL'));
	if ($soffice !== '') {
		$outDir = escapeshellarg(dirname($absolutePath));
		$src = escapeshellarg($absolutePath);
		@shell_exec("soffice --headless --convert-to pdf --outdir $outDir $src");
		if (is_file($pdfPath)) { return $pdfPath; }
	}

	error_log('DOC->PDF conversion skipped: no converter available.');
	return null;
}

$errors = [];
$ok = null;
$csrfToken = elearningAssessmentCsrfToken();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (($_POST['action'] ?? '') === 'quick_create')) {
	if (!elearningAssessmentValidateCsrf($_POST)) {
		$errors[] = 'Security check failed. Please refresh the page and try again.';
	}
	$type = trim($_POST['assessment_type'] ?? 'quiz');
	$title = trim($_POST['title'] ?? '');
	$description = trim($_POST['description'] ?? '');
	$dueAt = elearningAssessmentNormalizeDateTime(trim($_POST['due_at'] ?? ''), $errors, 'Due date');
	$availableFrom = elearningAssessmentNormalizeDateTime(trim($_POST['available_from'] ?? ''), $errors, 'Open date');
	$attachmentPath = null;
	$quizSettings = elearningAssessmentQuizSettingsFromPost($_POST);
	$assignmentSettings = elearningAssessmentAssignmentSettingsFromPost($_POST);

	if (!in_array($type, ['quiz', 'assignment', 'test'], true)) { $type = 'quiz'; }
	if ($title === '') { $errors[] = 'Title is required.'; }
	if ($availableFrom && $dueAt && strtotime($availableFrom) >= strtotime($dueAt)) {
		$errors[] = 'Open date must be before due date.';
	}
	$quizSettings['available_from'] = $availableFrom ?? '';

	if (isset($_FILES['assessment_file']) && is_array($_FILES['assessment_file']) && (int)($_FILES['assessment_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
		$fileErr = (int)($_FILES['assessment_file']['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($fileErr !== UPLOAD_ERR_OK) {
			$errors[] = 'File upload failed.';
		} elseif ((int)($_FILES['assessment_file']['size'] ?? 0) > 12 * 1024 * 1024) {
			$errors[] = 'Assessment document must be 12MB or smaller.';
		} else {
			$original = (string)($_FILES['assessment_file']['name'] ?? '');
			$tmp = (string)($_FILES['assessment_file']['tmp_name'] ?? '');
			$ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
			$allowed = ['pdf', 'doc', 'docx'];
			if (!in_array($ext, $allowed, true)) {
				$errors[] = 'Only PDF, DOC and DOCX files are allowed.';
			} else {
				$uploadDir = __DIR__ . '/../uploads/assessment_docs';
				if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
				$cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($original, PATHINFO_FILENAME));
				$filename = $cleanName . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
				$target = $uploadDir . '/' . $filename;
				if (!@move_uploaded_file($tmp, $target)) {
					$errors[] = 'Unable to save uploaded file.';
				} else {
					$convertedPdf = convertUploadedDocumentToPdf($target, $ext);
					if ($convertedPdf && is_file($convertedPdf)) {
						$attachmentPath = str_replace('\\', '/', substr($convertedPdf, strlen(__DIR__ . '/../')));
					} else {
						$attachmentPath = 'uploads/assessment_docs/' . $filename;
					}
				}
			}
		}
	}

	if (!$errors) {
		if ($type === 'assignment') {
			$assignmentSettingsJson = elearningAssessmentJson($assignmentSettings);
			$stmt = $db->prepare("INSERT INTO el_assignments (course_code, title, description, due_at, assessment_type, attachment_path, settings_json, created_by) VALUES (?,?,?,?,?,?,?,?)");
			if ($stmt) {
				$kind = 'assignment';
				$stmt->bind_param('ssssssss', $courseCode, $title, $description, $dueAt, $kind, $attachmentPath, $assignmentSettingsJson, $staffId);
				if ($stmt->execute()) { $ok = 'Assignment created successfully.'; } else { $errors[] = 'Failed to create assignment.'; }
				$stmt->close();
			} else {
				$errors[] = 'Could not prepare assignment create query.';
				error_log('assessments.php: create assignment prepare failed: ' . $db->error);
			}
		} else {
			$quizSettingsJson = elearningAssessmentJson($quizSettings);
			$timeLimit = $quizSettings['time_limit_minutes'];
			$shuffleQuestions = !empty($quizSettings['shuffle_questions']) ? 1 : 0;
			$shuffleOptions = !empty($quizSettings['shuffle_options']) ? 1 : 0;
			$isPublished = !empty($_POST['is_published']) ? 1 : 0;
			$stmt = $db->prepare("INSERT INTO el_quizzes (course_code, title, description, due_at, assessment_type, attachment_path, time_limit_minutes, shuffle_questions, shuffle_options, is_published, settings_json, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
			if ($stmt) {
				$kind = ($type === 'test') ? 'test' : 'quiz';
				$stmt->bind_param('ssssssiiiiss', $courseCode, $title, $description, $dueAt, $kind, $attachmentPath, $timeLimit, $shuffleQuestions, $shuffleOptions, $isPublished, $quizSettingsJson, $staffId);
				if ($stmt->execute()) { $ok = ucfirst($kind) . ' created successfully.'; } else { $errors[] = 'Failed to create ' . $kind . '.'; }
				$stmt->close();
			} else {
				$errors[] = 'Could not prepare quiz/test create query.';
				error_log('assessments.php: create quiz/test prepare failed: ' . $db->error);
			}
		}
	}
}

function buildQuizDTO(array $row): array {
	$isPublished = !empty($row['is_published']) && (int)$row['is_published'] === 1;
	$dueTs = !empty($row['due_at']) ? strtotime($row['due_at']) : null;
	return [
		'id' => (int)($row['id'] ?? 0),
		'title' => $row['title'] ?? 'Untitled',
		'assessment_type' => $row['assessment_type'] ?? 'quiz',
		'is_published' => $isPublished,
		'status_label' => $isPublished ? 'Published' : 'Draft',
		'status_class' => $isPublished ? 'badge-success' : 'badge-draft',
		'due_label' => $dueTs ? date('M d, Y \a\t h:i A', $dueTs) : 'No due date',
		'attachment_path' => $row['attachment_path'] ?? null,
		'created_at' => isset($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '-',
	];
}

function buildAssignmentDTO(array $row): array {
	$dueTs = !empty($row['due_at']) ? strtotime($row['due_at']) : null;
	return [
		'id' => (int)($row['id'] ?? 0),
		'title' => $row['title'] ?? 'Untitled',
		'due_label' => $dueTs ? date('M d, Y \a\t h:i A', $dueTs) : 'No due date',
		'attachment_path' => $row['attachment_path'] ?? null,
		'created_at' => isset($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '-',
	];
}

$quizzes = [];
$stmt = $db->prepare("SELECT * FROM el_quizzes WHERE course_code = ? ORDER BY id DESC");
if ($stmt) {
	$stmt->bind_param('s', $courseCode);
	$stmt->execute();
	$res = $stmt->get_result();
	while ($row = $res->fetch_assoc()) { $quizzes[] = buildQuizDTO($row); }
	$stmt->close();
}

$assignments = [];
$stmt = $db->prepare("SELECT * FROM el_assignments WHERE course_code = ? ORDER BY due_at ASC, id DESC");
if ($stmt) {
	$stmt->bind_param('s', $courseCode);
	$stmt->execute();
	$res = $stmt->get_result();
	while ($row = $res->fetch_assoc()) { $assignments[] = buildAssignmentDTO($row); }
	$stmt->close();
}

$attemptRegister = [];
if ($stmt = $db->prepare("SELECT a.id, q.assessment_type, q.title, a.Sid, a.score, a.submitted_at FROM el_attempts a INNER JOIN el_quizzes q ON q.id=a.quiz_id WHERE q.course_code=?")) {
	$stmt->bind_param('s', $courseCode);
	$stmt->execute();
	$res = $stmt->get_result();
	while ($r = $res->fetch_assoc()) {
		$attemptRegister[] = [
			'type' => ($r['assessment_type'] ?? 'quiz'),
			'title' => $r['title'] ?? 'Untitled',
			'student' => $r['Sid'] ?? '-',
			'score' => isset($r['score']) ? (float)$r['score'] : null,
			'attempted_at' => $r['submitted_at'] ?? null,
		];
	}
	$stmt->close();
}
if ($stmt = $db->prepare("SELECT s.id, a.title, s.Sid, g.total_points, s.submitted_at FROM el_submissions s INNER JOIN el_assignments a ON a.id=s.assignment_id LEFT JOIN el_grades g ON g.assignment_id=s.assignment_id AND g.Sid=s.Sid WHERE a.course_code=?")) {
	$stmt->bind_param('s', $courseCode);
	$stmt->execute();
	$res = $stmt->get_result();
	while ($r = $res->fetch_assoc()) {
		$attemptRegister[] = [
			'type' => 'assignment',
			'title' => $r['title'] ?? 'Untitled',
			'student' => $r['Sid'] ?? '-',
			'score' => isset($r['total_points']) ? (float)$r['total_points'] : null,
			'attempted_at' => $r['submitted_at'] ?? null,
		];
	}
	$stmt->close();
}
usort($attemptRegister, function($a, $b) {
	return strtotime((string)($b['attempted_at'] ?? '1970-01-01')) <=> strtotime((string)($a['attempted_at'] ?? '1970-01-01'));
});
require_once __DIR__ . '/../lecturers/includes/nav.php';
?>
<div class="elearning-shell assessments-page">
	<div class="elearning-header">
		<div>
			<h1 class="elearning-title"><i class="fas fa-clipboard-check"></i> Assessments</h1>
			<p class="elearning-subtitle">Course: <strong><?php echo htmlspecialchars($courseCode); ?></strong></p>
		</div>
		<div class="elearning-actions">
			<a href="manage.php?course_code=<?php echo urlencode($courseCode); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Course</a>
		</div>
	</div>
	<?php elearningCourseTabs($courseCode, 'assessments'); ?>

	<?php if ($errors): ?><div class="alert alert-danger"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div><?php endif; ?>
	<?php if ($ok): ?><div class="alert alert-success"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

	<section class="elearning-panel mb-4">
		<div class="elearning-panel-header"><strong><i class="fas fa-plus-circle me-1"></i>Quick Create</strong></div>
		<div class="elearning-panel-body">
			<form method="post" enctype="multipart/form-data">
				<input type="hidden" name="action" value="quick_create">
				<input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
				<?php echo elearningAssessmentCsrfField(); ?>
				<div class="row g-3">
					<div class="col-md-2">
						<label class="form-label">Type</label>
						<select class="form-select" name="assessment_type">
							<option value="quiz">Quiz</option>
							<option value="assignment">Assignment</option>
							<option value="test">Test</option>
						</select>
					</div>
					<div class="col-md-4">
						<label class="form-label">Title</label>
						<input class="form-control" name="title" required>
					</div>
					<div class="col-md-3">
						<label class="form-label">Due Date</label>
						<input type="datetime-local" class="form-control" name="due_at">
					</div>
					<div class="col-md-3">
						<label class="form-label">Open From</label>
						<input type="datetime-local" class="form-control" name="available_from">
					</div>
					<div class="col-md-3">
						<label class="form-label">Document (PDF/Word)</label>
						<input type="file" class="form-control" name="assessment_file" accept=".pdf,.doc,.docx">
					</div>
					<div class="col-md-12">
						<label class="form-label">Instructions (optional)</label>
						<textarea class="form-control" name="description" rows="2"></textarea>
					</div>
					<div class="col-md-12">
						<div class="row g-3 p-3 bg-light rounded">
							<div class="col-md-2">
								<label class="form-label">Time Limit</label>
								<input type="number" class="form-control" name="time_limit" min="1" max="480" value="30">
							</div>
							<div class="col-md-2">
								<label class="form-label">Max Attempts</label>
								<input type="number" class="form-control" name="max_attempts" min="1" max="10" value="1">
							</div>
							<div class="col-md-2">
								<label class="form-label">Max File MB</label>
								<input type="number" class="form-control" name="max_file_mb" min="1" max="50" value="10">
							</div>
							<div class="col-md-3">
								<label class="form-label">Allowed Files</label>
								<input type="text" class="form-control" name="allowed_file_types" value="pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,png">
							</div>
							<div class="col-md-3">
								<label class="form-label">Release</label>
								<div class="form-check">
									<input class="form-check-input" type="checkbox" name="is_published" id="quickPublished">
									<label class="form-check-label" for="quickPublished">Publish quiz/test now</label>
								</div>
							</div>
							<div class="col-md-12 d-flex flex-wrap gap-3">
								<label class="form-check"><input class="form-check-input" type="checkbox" name="shuffle_questions" checked> Shuffle questions</label>
								<label class="form-check"><input class="form-check-input" type="checkbox" name="shuffle_options" checked> Shuffle options</label>
								<label class="form-check"><input class="form-check-input" type="checkbox" name="require_fullscreen" checked> Monitor fullscreen exits</label>
								<label class="form-check"><input class="form-check-input" type="checkbox" name="disable_copy_paste" checked> Block copy/paste</label>
								<label class="form-check"><input class="form-check-input" type="checkbox" name="allow_file_upload" checked> Assignment file upload</label>
								<label class="form-check"><input class="form-check-input" type="checkbox" name="allow_text_response" checked> Assignment text response</label>
								<label class="form-check"><input class="form-check-input" type="checkbox" name="ai_check_enabled" checked> AI writing check</label>
								<label class="form-check"><input class="form-check-input" type="checkbox" name="plagiarism_check_enabled" checked> Plagiarism check</label>
							</div>
						</div>
					</div>
					<div class="col-md-12">
						<button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Create</button>
					</div>
				</div>
			</form>
		</div>
	</section>

	<div class="row g-4">
		<div class="col-md-6">
			<section class="elearning-panel h-100">
				<div class="elearning-panel-header"><strong><i class="fas fa-question-circle me-1"></i>Quizzes & Tests</strong></div>
				<div class="elearning-panel-body">
					<?php if (empty($quizzes)): ?>
						<div class="empty-state">No quizzes or tests yet.</div>
					<?php else: foreach ($quizzes as $q): ?>
						<div class="elearning-assessment-item">
							<div class="d-flex align-items-start justify-content-between">
								<div class="item-title"><?php echo htmlspecialchars($q['title']); ?> <span class="badge bg-info ms-1"><?php echo htmlspecialchars(strtoupper((string)$q['assessment_type'])); ?></span></div>
								<span class="badge <?php echo $q['status_class']; ?>"><?php echo $q['status_label']; ?></span>
							</div>
							<div class="item-meta text-muted">Due: <?php echo htmlspecialchars($q['due_label']); ?> &middot; Created <?php echo $q['created_at']; ?></div>
							<div class="item-actions">
								<a href="quiz_edit.php?course_code=<?php echo urlencode($courseCode); ?>&quiz_id=<?php echo (int)$q['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Edit</a>
								<?php if (!empty($q['attachment_path'])): ?><a href="../<?php echo htmlspecialchars((string)$q['attachment_path']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file"></i> File</a><?php endif; ?>
							</div>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</section>
		</div>

		<div class="col-md-6">
			<section class="elearning-panel h-100">
				<div class="elearning-panel-header"><strong><i class="fas fa-file-alt me-1"></i>Assignments</strong></div>
				<div class="elearning-panel-body">
					<?php if (empty($assignments)): ?>
						<div class="empty-state">No assignments yet.</div>
					<?php else: foreach ($assignments as $a): ?>
						<div class="elearning-assessment-item">
							<div class="item-title"><?php echo htmlspecialchars($a['title']); ?></div>
							<div class="item-meta text-muted">Due: <?php echo htmlspecialchars($a['due_label']); ?> &middot; Created <?php echo $a['created_at']; ?></div>
							<div class="item-actions">
								<a href="assignment_edit.php?course_code=<?php echo urlencode($courseCode); ?>&assignment_id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Edit</a>
								<?php if (!empty($a['attachment_path'])): ?><a href="../<?php echo htmlspecialchars((string)$a['attachment_path']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file"></i> File</a><?php endif; ?>
							</div>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</section>
		</div>
	</div>

	<section class="elearning-panel mt-4">
		<div class="elearning-panel-header"><strong><i class="fas fa-list-check me-1"></i>Attempt Register</strong></div>
		<div class="elearning-panel-body table-responsive">
			<table class="table table-hover align-middle elearning-table">
				<thead class="table-light"><tr><th>Type</th><th>Title</th><th>Student</th><th>Score</th><th>Attempted At</th></tr></thead>
				<tbody>
					<?php if (empty($attemptRegister)): ?>
						<tr><td colspan="5" class="text-center text-muted">No attempts or submissions recorded yet.</td></tr>
					<?php else: foreach ($attemptRegister as $r): ?>
						<tr>
							<td><?php echo htmlspecialchars(strtoupper((string)$r['type'])); ?></td>
							<td><?php echo htmlspecialchars((string)$r['title']); ?></td>
							<td><?php echo htmlspecialchars((string)$r['student']); ?></td>
							<td><?php echo $r['score'] !== null ? htmlspecialchars((string)$r['score']) : '-'; ?></td>
							<td><?php echo !empty($r['attempted_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string)$r['attempted_at']))) : '-'; ?></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
	</section>
</div>
</div>
</div>
</body>
</html>


