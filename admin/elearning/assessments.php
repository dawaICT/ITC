<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
elearning_require_role(['systems_admin', 'lecturer']);

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = trim($_GET['course_code'] ?? ($_POST['course_code'] ?? ''));
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));

if (!$staffId || !$courseCode) {
	http_response_code(403);
	die('Unauthorized');
}
if ($role !== 'systems_admin') {
	enforceLecturerCourseAccess($db, $staffId, $courseCode);
}

function ensureColumn(mysqli $db, string $table, string $column, string $definition): void {
	$exists = false;
	$stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
	if ($stmt) {
		$stmt->bind_param('ss', $table, $column);
		$stmt->execute();
		$res = $stmt->get_result();
		$row = $res ? $res->fetch_assoc() : null;
		$exists = ((int)($row['cnt'] ?? 0) > 0);
		$stmt->close();
	}
	if (!$exists) { $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition"); }
}

function convertUploadedDocumentToPdf(string $absolutePath, string $ext): ?string {
	$ext = strtolower($ext);
	if (!in_array($ext, ['doc', 'docx'], true)) { return null; }
	$pdfPath = preg_replace('/\.[^.]+$/', '.pdf', $absolutePath);
	if (!$pdfPath) { return null; }

	if (class_exists('COM')) {
		try {
			$word = new COM('Word.Application');
			$word->Visible = false;
			$word->DisplayAlerts = 0;
			$doc = $word->Documents->Open($absolutePath, false, true);
			$doc->ExportAsFixedFormat($pdfPath, 17);
			$doc->Close(false);
			$word->Quit();
			if (is_file($pdfPath)) { return $pdfPath; }
		} catch (Throwable $e) {
			error_log('DOC->PDF COM conversion failed: ' . $e->getMessage());
		}
	}

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

ensureColumn($db, 'el_quizzes', 'assessment_type', "VARCHAR(20) NOT NULL DEFAULT 'quiz'");
ensureColumn($db, 'el_quizzes', 'due_at', "DATETIME NULL");
ensureColumn($db, 'el_quizzes', 'attachment_path', "VARCHAR(255) NULL");
ensureColumn($db, 'el_assignments', 'assessment_type', "VARCHAR(20) NOT NULL DEFAULT 'assignment'");
ensureColumn($db, 'el_assignments', 'attachment_path', "VARCHAR(255) NULL");

$errors = [];
$ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'quick_create')) {
	$type = trim($_POST['assessment_type'] ?? 'quiz');
	$title = trim($_POST['title'] ?? '');
	$dueAtInput = trim($_POST['due_at'] ?? '');
	$description = trim($_POST['description'] ?? '');
	$dueAt = null;
	$attachmentPath = null;

	if (!in_array($type, ['quiz', 'assignment', 'test'], true)) { $type = 'quiz'; }
	if ($title === '') { $errors[] = 'Title is required.'; }

	if ($dueAtInput !== '') {
		$dueAt = str_replace('T', ' ', $dueAtInput);
		if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dueAt)) { $dueAt .= ':00'; }
		if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$dueAt)) { $errors[] = 'Invalid due date format.'; }
	}

	if (isset($_FILES['assessment_file']) && is_array($_FILES['assessment_file']) && (int)($_FILES['assessment_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
		$fileErr = (int)($_FILES['assessment_file']['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($fileErr !== UPLOAD_ERR_OK) {
			$errors[] = 'File upload failed.';
		} else {
			$original = (string)($_FILES['assessment_file']['name'] ?? '');
			$tmp = (string)($_FILES['assessment_file']['tmp_name'] ?? '');
			$ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
			$allowed = ['pdf', 'doc', 'docx'];
			if (!in_array($ext, $allowed, true)) {
				$errors[] = 'Only PDF, DOC and DOCX files are allowed.';
			} else {
				$uploadDir = __DIR__ . '/../../uploads/assessment_docs';
				if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
				$cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($original, PATHINFO_FILENAME));
				$filename = $cleanName . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
				$target = $uploadDir . '/' . $filename;
				if (!@move_uploaded_file($tmp, $target)) {
					$errors[] = 'Unable to save uploaded file.';
				} else {
					$convertedPdf = convertUploadedDocumentToPdf($target, $ext);
					if ($convertedPdf && is_file($convertedPdf)) {
						$attachmentPath = str_replace('\\', '/', substr($convertedPdf, strlen(__DIR__ . '/../../')));
					} else {
						$attachmentPath = 'uploads/assessment_docs/' . $filename;
					}
				}
			}
		}
	}

	if (!$errors) {
		if ($type === 'assignment') {
			$stmt = $db->prepare("INSERT INTO el_assignments (course_code, title, description, due_at, assessment_type, attachment_path, created_by) VALUES (?,?,?,?,?,?,?)");
			if ($stmt) {
				$kind = 'assignment';
				$stmt->bind_param('sssssss', $courseCode, $title, $description, $dueAt, $kind, $attachmentPath, $staffId);
				if ($stmt->execute()) { $ok = 'Assignment created successfully.'; } else { $errors[] = 'Failed to create assignment.'; }
				$stmt->close();
			} else {
				$errors[] = 'Could not prepare assignment create query.';
			}
		} else {
			$stmt = $db->prepare("INSERT INTO el_quizzes (course_code, title, description, due_at, assessment_type, attachment_path, is_published, created_by) VALUES (?,?,?,?,?,?,1,?)");
			if ($stmt) {
				$kind = ($type === 'test') ? 'test' : 'quiz';
				$stmt->bind_param('sssssss', $courseCode, $title, $description, $dueAt, $kind, $attachmentPath, $staffId);
				if ($stmt->execute()) { $ok = ucfirst($kind) . ' created successfully.'; } else { $errors[] = 'Failed to create ' . $kind . '.'; }
				$stmt->close();
			} else {
				$errors[] = 'Could not prepare quiz/test create query.';
			}
		}
	}
}

function buildQuizDTO(array $row): array {
	$dueTs = !empty($row['due_at']) ? strtotime($row['due_at']) : null;
	$isPublished = !empty($row['is_published']) && (int)$row['is_published'] === 1;
	return [
		'id' => (int)($row['id'] ?? 0),
		'title' => $row['title'] ?? 'Untitled',
		'assessment_type' => $row['assessment_type'] ?? 'quiz',
		'status_label' => $isPublished ? 'Published' : 'Draft',
		'status_class' => $isPublished ? 'badge-success' : 'badge-draft',
		'due_label' => $dueTs ? date('M d, Y \a\t h:i A', $dueTs) : 'No due date',
		'attachment_path' => $row['attachment_path'] ?? null,
	];
}
function buildAssignmentDTO(array $row): array {
	$dueTs = !empty($row['due_at']) ? strtotime($row['due_at']) : null;
	return [
		'id' => (int)($row['id'] ?? 0),
		'title' => $row['title'] ?? 'Untitled',
		'due_label' => $dueTs ? date('M d, Y \a\t h:i A', $dueTs) : 'No due date',
		'attachment_path' => $row['attachment_path'] ?? null,
	];
}

$quizzes = [];
if ($stmt = $db->prepare("SELECT * FROM el_quizzes WHERE course_code=? ORDER BY id DESC")) {
	$stmt->bind_param('s', $courseCode);
	$stmt->execute();
	$res = $stmt->get_result();
	while ($row = $res->fetch_assoc()) { $quizzes[] = buildQuizDTO($row); }
	$stmt->close();
}
$assignments = [];
if ($stmt = $db->prepare("SELECT * FROM el_assignments WHERE course_code=? ORDER BY due_at ASC, id DESC")) {
	$stmt->bind_param('s', $courseCode);
	$stmt->execute();
	$res = $stmt->get_result();
	while ($row = $res->fetch_assoc()) { $assignments[] = buildAssignmentDTO($row); }
	$stmt->close();
}

$attemptRegister = [];
if ($stmt = $db->prepare("SELECT q.assessment_type, q.title, a.Sid, a.score, a.submitted_at FROM el_attempts a INNER JOIN el_quizzes q ON q.id=a.quiz_id WHERE q.course_code=?")) {
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
if ($stmt = $db->prepare("SELECT a.title, s.Sid, g.total_points, s.submitted_at FROM el_submissions s INNER JOIN el_assignments a ON a.id=s.assignment_id LEFT JOIN el_grades g ON g.assignment_id=s.assignment_id AND g.Sid=s.Sid WHERE a.course_code=?")) {
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

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../css/admin-dashboard.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<style>
	.badge-success { background: #198754; color: #fff; }
	.badge-draft { background: #6c757d; color: #fff; }
	.assessment-item { border: 1px solid #dee2e6; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px; background: #fff; }
	.assessment-item .item-title { font-weight: 600; font-size: 0.95rem; margin-bottom: 4px; }
	.assessment-item .item-meta { font-size: 0.8rem; }
	.assessment-item .item-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
	.empty-state { text-align: center; padding: 24px 12px; color: #6c757d; }
</style>
<div class="container-fluid px-4 portal-dashboard">
	<div class="d-flex align-items-center justify-content-between mb-2">
		<h2 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Assessments</h2>
		<a href="manage.php?course_code=<?php echo urlencode($courseCode); ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Course</a>
	</div>
	<p class="text-muted mb-3">Course: <strong><?php echo htmlspecialchars($courseCode); ?></strong></p>

	<?php if ($errors): ?><div class="alert alert-danger"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div><?php endif; ?>
	<?php if ($ok): ?><div class="alert alert-success"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

	<div class="card mb-4">
		<div class="card-header"><strong><i class="fas fa-plus-circle me-1"></i>Quick Create (Quiz / Assignment / Test)</strong></div>
		<div class="card-body">
			<form method="post" enctype="multipart/form-data">
				<input type="hidden" name="action" value="quick_create">
				<input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
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
						<label class="form-label">Document (PDF/Word)</label>
						<input type="file" class="form-control" name="assessment_file" accept=".pdf,.doc,.docx">
					</div>
					<div class="col-md-12">
						<label class="form-label">Instructions (optional)</label>
						<textarea class="form-control" name="description" rows="2"></textarea>
					</div>
					<div class="col-md-12 text-end">
						<button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Create</button>
					</div>
				</div>
			</form>
		</div>
	</div>

	<div class="row g-4">
		<div class="col-md-6">
			<div class="card h-100">
				<div class="card-header"><strong><i class="fas fa-question-circle me-1"></i>Quizzes & Tests</strong></div>
				<div class="card-body">
					<?php if (empty($quizzes)): ?><div class="empty-state">No quizzes or tests yet.</div>
					<?php else: foreach ($quizzes as $q): ?>
						<div class="assessment-item">
							<div class="d-flex align-items-start justify-content-between">
								<div class="item-title"><?php echo htmlspecialchars($q['title']); ?> <span class="badge bg-info ms-1"><?php echo htmlspecialchars(strtoupper((string)$q['assessment_type'])); ?></span></div>
								<span class="badge <?php echo $q['status_class']; ?>"><?php echo $q['status_label']; ?></span>
							</div>
							<div class="item-meta text-muted">Due: <?php echo htmlspecialchars($q['due_label']); ?></div>
							<div class="item-actions">
								<a href="../../elearning/quiz_edit.php?course_code=<?php echo urlencode($courseCode); ?>&id=<?php echo (int)$q['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Edit</a>
								<?php if (!empty($q['attachment_path'])): ?><a href="../../<?php echo htmlspecialchars((string)$q['attachment_path']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file"></i> File</a><?php endif; ?>
							</div>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</div>
		</div>
		<div class="col-md-6">
			<div class="card h-100">
				<div class="card-header"><strong><i class="fas fa-file-alt me-1"></i>Assignments</strong></div>
				<div class="card-body">
					<?php if (empty($assignments)): ?><div class="empty-state">No assignments yet.</div>
					<?php else: foreach ($assignments as $a): ?>
						<div class="assessment-item">
							<div class="item-title"><?php echo htmlspecialchars($a['title']); ?></div>
							<div class="item-meta text-muted">Due: <?php echo htmlspecialchars($a['due_label']); ?></div>
							<div class="item-actions">
								<a href="../../elearning/assignment_edit.php?course_code=<?php echo urlencode($courseCode); ?>&assignment_id=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Edit</a>
								<?php if (!empty($a['attachment_path'])): ?><a href="../../<?php echo htmlspecialchars((string)$a['attachment_path']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file"></i> File</a><?php endif; ?>
							</div>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="card mt-4">
		<div class="card-header"><strong><i class="fas fa-list-check me-1"></i>Attempt Register</strong></div>
		<div class="card-body table-responsive">
			<table class="table table-hover align-middle mb-0">
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
	</div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>


