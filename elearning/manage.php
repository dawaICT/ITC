<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');

// IMPORTANT: Start session and check auth BEFORE any includes that output HTML
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Quick auth check before loading any navigation
$staffId = $_SESSION['staff_id'] ?? null;
if (!$staffId) {
    header('Location: ../staff_login.php');
    exit;
}

// Now safe to load includes
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/portal_access.php';
require_once __DIR__ . '/../includes/elearning_ui.php';

wuc_require_portal_access($db, 'elearning');

$courseCode = $_GET['course_code'] ?? '';

// Better error messages for debugging
if (!$courseCode) {
    // Redirect to courses list instead of dying
    header('Location: courses.php');
    exit;
}

// Ensure lecturer has access to the course OR has admin permissions
if (!canLecturerAccessElearningCourse($db, $staffId, $courseCode)) {
    header('Location: courses.php');
    exit;
}
$courseOfferingIds = getLecturerCourseOfferingIds($db, $staffId, $courseCode);

// Resolve course name for display
$courseName = $courseCode;
if ($stmt = $db->prepare("SELECT course_name FROM courses WHERE course_code=? LIMIT 1")) {
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $courseName = $row['course_name'] ?: $courseCode; }
    $stmt->close();
}
// Fallback to program_courses if not found
if ($courseName === $courseCode) {
    $tblCheck = $db->query("SHOW TABLES LIKE 'program_courses'");
    if ($tblCheck && $tblCheck->num_rows > 0) {
        $tblCheck->free();
        if ($stmt = $db->prepare("SELECT course_name FROM program_courses WHERE course_code=? AND course_name IS NOT NULL AND course_name != '' LIMIT 1")) {
            $stmt->bind_param('s', $courseCode);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) { $courseName = $row['course_name']; }
            $stmt->close();
        }
    } elseif ($tblCheck) { $tblCheck->free(); }
}

// Fetch modules
$modules = [];
$offeringSql = '';
$types = 's';
$params = [$courseCode];
if (elearningTableHasCourseOffering($db, 'el_course_modules') && !empty($courseOfferingIds)) {
    $offeringPlaceholders = implode(',', array_fill(0, count($courseOfferingIds), '?'));
    $offeringSql = " AND (course_offering_id IN ($offeringPlaceholders) OR course_offering_id IS NULL)";
    $types .= str_repeat('i', count($courseOfferingIds));
    $params = array_merge($params, $courseOfferingIds);
}
if ($stmt = $db->prepare("SELECT * FROM el_course_modules WHERE course_code=? {$offeringSql} ORDER BY position, id")) {
	$stmt->bind_param($types, ...$params);
	$stmt->execute();
	$res = $stmt->get_result();
	while ($row = $res->fetch_assoc()) { $modules[] = $row; }
	$stmt->close();
}

// Fetch quizzes for this course
$quizzes = [];
if ($stmt = $db->prepare("SELECT * FROM el_quizzes WHERE course_code=? ORDER BY id DESC")) {
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $quizzes[] = $row; }
    $stmt->close();
}

// Fetch assignments for this course
$assignments = [];
if ($stmt = $db->prepare("SELECT * FROM el_assignments WHERE course_code=? ORDER BY id DESC")) {
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $assignments[] = $row; }
    $stmt->close();
}
require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

	<div class="elearning-shell">
		<div class="elearning-header">
			<div>
				<h1 class="elearning-title"><i class="fas fa-book"></i> <?php echo htmlspecialchars($courseCode); ?></h1>
				<p class="elearning-subtitle"><?php echo htmlspecialchars($courseName); ?></p>
			</div>
			<div class="elearning-actions">
				<a class="btn btn-primary" href="module_edit.php?course_code=<?php echo urlencode($courseCode); ?>">
					<i class="fas fa-plus"></i> New Module
				</a>
			</div>
		</div>
		<?php elearningCourseTabs($courseCode, 'manage'); ?>
		<div class="row">
			<div class="col-md-8">
				<section class="elearning-panel">
					<div class="elearning-panel-header"><strong>Modules</strong></div>
					<div class="elearning-panel-body">
						<ul class="elearning-list">
						<?php foreach ($modules as $m): 
							$moduleQuizzes = [];
							foreach ($quizzes as $q) {
								if ((int)($q['module_id'] ?? 0) === (int)$m['id']) {
									$moduleQuizzes[] = $q;
								}
							}
							$moduleAssignments = [];
							foreach ($assignments as $a) {
								if ((int)($a['module_id'] ?? 0) === (int)$m['id']) {
									$moduleAssignments[] = $a;
								}
							}
						?>
							<li class="elearning-list-item" style="flex-direction: column; align-items: stretch; gap: 8px;">
								<div style="display: flex; justify-content: space-between; align-items: center;">
									<div>
										<a href="module.php?course_code=<?php echo urlencode($courseCode); ?>&module_id=<?php echo (int)$m['id']; ?>">
											<strong><?php echo htmlspecialchars($m['title']); ?></strong>
										</a>
										<?php if (!empty($m['release_at'])): ?>
											<div class="elearning-muted">Release: <?php echo htmlspecialchars($m['release_at']); ?></div>
										<?php endif; ?>
									</div>
									<a class="btn btn-sm btn-secondary" href="module_edit.php?course_code=<?php echo urlencode($courseCode); ?>&module_id=<?php echo (int)$m['id']; ?>">
										<i class="fas fa-edit"></i> Edit
									</a>
								</div>
								
								<?php if (count($moduleQuizzes) > 0 || count($moduleAssignments) > 0): ?>
									<div style="margin-left: 15px; padding-left: 10px; border-left: 2px dashed #6f42c1; margin-top: 5px;">
										<?php foreach ($moduleQuizzes as $q): ?>
											<div style="font-size: 0.88rem; padding: 4px 0; color: #6f42c1;">
												<i class="fas fa-pen"></i> Quiz: <strong><?php echo htmlspecialchars($q['title']); ?></strong> 
												<span class="badge bg-light text-dark" style="font-size: 0.75rem; border: 1px solid #ddd;"><?php echo !empty($q['is_published']) ? 'Published' : 'Draft'; ?></span>
											</div>
										<?php endforeach; ?>
										<?php foreach ($moduleAssignments as $a): ?>
											<div style="font-size: 0.88rem; padding: 4px 0; color: #0ea5e9;">
												<i class="fas fa-file-alt"></i> Assignment: <strong><?php echo htmlspecialchars($a['title']); ?></strong>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
						<?php if (count($modules) === 0): ?>
							<li class="elearning-empty">No modules yet. Create one to start sharing course files.</li>
						<?php endif; ?>
						</ul>
					</div>
				</section>
			</div>
			<div class="col-md-4">
				<section class="elearning-panel">
					<div class="elearning-panel-header"><strong>Quick Actions</strong></div>
					<div class="elearning-panel-body">
						<ul class="elearning-list">
							<li><a href="sessions.php?course_code=<?php echo urlencode($courseCode); ?>"><i class="fas fa-video"></i> Live Sessions</a></li>
							<li><a href="recordings.php?course_code=<?php echo urlencode($courseCode); ?>"><i class="fas fa-record-vinyl"></i> Recorded Videos</a></li>
							<li><a href="assessments.php?course_code=<?php echo urlencode($courseCode); ?>"><i class="fas fa-clipboard-check"></i> Assessments</a></li>
							<li><a href="forum.php?course_code=<?php echo urlencode($courseCode); ?>"><i class="fas fa-comments"></i> Discussions</a></li>
							<li><a href="analytics.php?course_code=<?php echo urlencode($courseCode); ?>"><i class="fas fa-chart-line"></i> Analytics</a></li>
						</ul>
					</div>
				</section>
			</div>
		</div>
	</div>
</div>
</div>
</body>
</html>

