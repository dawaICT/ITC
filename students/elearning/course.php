<?php
require_once __DIR__ . '/../../includes/portal_config.php';
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
require_once __DIR__ . '/../../includes/elearning_recordings.php';
require_once __DIR__ . '/../../includes/elearning_assessment_security.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

$sid = $_SESSION['Sid'] ?? null;
$courseCode = trim((string)($_GET['course_code'] ?? ''));
if (!$sid || !$courseCode) { die('Unauthorized'); }
$portalRoot = rtrim(PORTAL_ROOT, '/') . '/';
$studentElearningRoot = $portalRoot . 'students/elearning/';

// Verify student is enrolled in this course
enforceStudentCourseAccess($db, $sid, $courseCode);
$courseOfferingIds = getStudentCourseOfferingIds($db, $sid, $courseCode);

// Fetch course name
$courseName = $courseCode;
$courseFound = false;
if ($stmt = $db->prepare("SELECT course_name FROM courses WHERE course_code=? LIMIT 1")) {
    $stmt->bind_param('s', $courseCode);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $courseName = $row['course_name'];
        $courseFound = true;
    }
    $stmt->close();
} else {
    error_log('students/elearning/course.php: course name prepare failed: ' . $db->error);
}

if (!$courseFound) {
    http_response_code(404);
}

// Fetch modules that are released
$modules = [];
if ($courseFound) {
    $offeringSql = '';
    $types = 's';
    $params = [$courseCode];
    if (elearningTableHasCourseOffering($db, 'el_course_modules') && !empty($courseOfferingIds)) {
        $offeringPlaceholders = implode(',', array_fill(0, count($courseOfferingIds), '?'));
        $offeringSql = " AND (course_offering_id IN ($offeringPlaceholders) OR course_offering_id IS NULL)";
        $types .= str_repeat('i', count($courseOfferingIds));
        $params = array_merge($params, $courseOfferingIds);
    }

    $moduleSql = "SELECT * FROM el_course_modules
                   WHERE course_code=?
                     {$offeringSql}
                     AND (release_at IS NULL OR release_at <= NOW())
                     AND (close_at IS NULL OR close_at >= NOW())
                   ORDER BY position, id";
}
if ($courseFound && ($stmt = $db->prepare($moduleSql))) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute(); $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $modules[] = $row; }
    $stmt->close();
} elseif ($courseFound) {
    error_log('students/elearning/course.php: modules prepare failed: ' . $db->error);
}

// Fetch all module content in one query and group by module_id
$contentsByModule = [];
$moduleIds = array_map(static fn($m) => (int)($m['id'] ?? 0), $modules);
$moduleIds = array_values(array_filter($moduleIds, static fn($id) => $id > 0));

if (!empty($moduleIds)) {
    $placeholders = implode(',', array_fill(0, count($moduleIds), '?'));
    $types = str_repeat('i', count($moduleIds));
    $sql = "SELECT c.*, v.file_path, v.version_no
            FROM el_contents c
            LEFT JOIN el_content_versions v ON v.id = c.current_version_id
            WHERE c.module_id IN ($placeholders)
            ORDER BY c.module_id ASC, c.id ASC";

    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$moduleIds);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $moduleId = (int)($row['module_id'] ?? 0);
            if (!isset($contentsByModule[$moduleId])) {
                $contentsByModule[$moduleId] = [];
            }
            $contentsByModule[$moduleId][] = $row;
        }
        $stmt->close();
    } else {
        error_log('students/elearning/course.php: contents prepare failed: ' . $db->error);
    }
}

// Fetch published quizzes for this course
$quizzes = [];
$quizTypes = 'ss';
$quizParams = [$sid, $courseCode];
$quizOfferingSql = elearningOfferingScopeCondition($db, 'el_quizzes', 'q', $courseOfferingIds, $quizTypes, $quizParams);
if ($courseFound && ($stmt = $db->prepare("SELECT q.*,
        COALESCE(att.attempt_count, 0) AS attempt_count,
        att.best_score,
        COALESCE(CAST(NULLIF(
            CASE
                WHEN JSON_VALID(q.settings_json) THEN JSON_UNQUOTE(JSON_EXTRACT(q.settings_json, '$.max_attempts'))
                ELSE NULL
            END,
            ''
        ) AS UNSIGNED), 0) AS max_attempts
    FROM el_quizzes q
    LEFT JOIN (
        SELECT quiz_id, COUNT(*) AS attempt_count, MAX(score) AS best_score
        FROM el_attempts
        WHERE Sid=? AND submitted_at IS NOT NULL
        GROUP BY quiz_id
    ) att ON att.quiz_id = q.id
    WHERE q.course_code=? AND q.is_published=1
    {$quizOfferingSql}
    ORDER BY q.id DESC"))) {
    $stmt->bind_param($quizTypes, ...$quizParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $quizzes[] = $row; }
    $stmt->close();
} elseif ($courseFound) {
    error_log('students/elearning/course.php: quizzes prepare failed: ' . $db->error);
}

// Fetch assignments for this course
$assignments = [];
$assignmentTypes = 'sss';
$assignmentParams = [$sid, $sid, $courseCode];
$assignmentOfferingSql = elearningOfferingScopeCondition($db, 'el_assignments', 'a', $courseOfferingIds, $assignmentTypes, $assignmentParams);
if ($courseFound && ($stmt = $db->prepare("SELECT a.*,
        COALESCE(sub.submission_count, 0) AS submission_count,
        grd.grade
    FROM el_assignments a
    LEFT JOIN (
        SELECT assignment_id, COUNT(*) AS submission_count
        FROM el_submissions
        WHERE Sid=?
        GROUP BY assignment_id
    ) sub ON sub.assignment_id = a.id
    LEFT JOIN (
        SELECT assignment_id, MAX(total_points) AS grade
        FROM el_grades
        WHERE Sid=?
        GROUP BY assignment_id
    ) grd ON grd.assignment_id = a.id
    WHERE a.course_code=?
    {$assignmentOfferingSql}
    ORDER BY a.due_at ASC, a.id DESC"))) {
    $stmt->bind_param($assignmentTypes, ...$assignmentParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $assignments[] = $row; }
    $stmt->close();
} elseif ($courseFound) {
    error_log('students/elearning/course.php: assignments prepare failed: ' . $db->error);
}

$recordings = $courseFound ? elearningGetPublishedRecordingsForCourse($db, $courseCode) : [];

// Fetch forum threads
$threads = [];
$threadTotal = 0;
$threadTypes = 's';
$threadParams = [$courseCode];
$threadOfferingSql = elearningOfferingScopeCondition($db, 'el_forum_threads', 't', $courseOfferingIds, $threadTypes, $threadParams);
if ($courseFound && ($stmt = $db->prepare("SELECT t.*, COALESCE(pc.post_count, 0) AS post_count
    FROM el_forum_threads t
    LEFT JOIN (
        SELECT thread_id, COUNT(*) AS post_count
        FROM el_forum_posts
        GROUP BY thread_id
    ) pc ON pc.thread_id = t.id
    WHERE t.course_code=?
    {$threadOfferingSql}
    ORDER BY t.updated_at DESC
    LIMIT 5"))) {
    $stmt->bind_param($threadTypes, ...$threadParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $threads[] = $row; }
    $stmt->close();
} elseif ($courseFound) {
    error_log('students/elearning/course.php: threads prepare failed: ' . $db->error);
}

$threadCountTypes = 's';
$threadCountParams = [$courseCode];
$threadCountOfferingSql = elearningOfferingScopeCondition($db, 'el_forum_threads', null, $courseOfferingIds, $threadCountTypes, $threadCountParams);
if ($courseFound && ($stmt = $db->prepare("SELECT COUNT(*) AS total FROM el_forum_threads WHERE course_code=? {$threadCountOfferingSql}"))) {
    $stmt->bind_param($threadCountTypes, ...$threadCountParams);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $threadTotal = (int)($row['total'] ?? 0); }
    $stmt->close();
} elseif ($courseFound) {
    error_log('students/elearning/course.php: thread count prepare failed: ' . $db->error);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($courseName); ?> &mdash; eLearning</title>
    <base href="<?php echo htmlspecialchars($portalRoot); ?>">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>window.WUC_PORTAL_ROOT = <?php echo json_encode($portalRoot); ?>;</script>
    <script src="<?php echo htmlspecialchars($studentElearningRoot); ?>track_event.js" defer></script>
    <style>
        .course-header { background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%); color: #fff; padding: 30px; border-radius: 12px; margin-bottom: 25px; }
        .course-header h2 { margin: 0 0 5px; }
        .course-header p { margin: 0; opacity: 0.9; }
        .section-title { margin: 25px 0 15px; font-size: 1.2rem; color: #333; display: flex; align-items: center; gap: 10px; }
        .section-title i { color: #2196F3; }
        .module-card { background: #fff; border: 1px solid #e9ecef; border-radius: 10px; margin-bottom: 15px; overflow: hidden; }
        .module-card-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e9ecef; font-weight: 600; }
        .module-card-body { padding: 20px; }
        .content-item { display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .content-item:last-child { border-bottom: none; }
        .content-icon { width: 40px; height: 40px; background: #e3f2fd; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 15px; color: #1976D2; }
        .content-info { flex: 1; }
        .content-info h6 { margin: 0 0 3px; font-size: 0.95rem; }
        .content-info small { color: #666; }
        .btn-open { padding: 6px 14px; background: #2196F3; color: #fff; border-radius: 6px; text-decoration: none; font-size: 0.85rem; }
        .btn-open:hover { background: #1976D2; color: #fff; }
        .assessment-card { background: #fff; border: 1px solid #e9ecef; border-radius: 10px; padding: 20px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
        .assessment-info h5 { margin: 0 0 5px; font-size: 1rem; }
        .assessment-info small { color: #666; }
        .badge-score { background: #28a745; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; }
        .badge-pending { background: #ffc107; color: #000; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; }
        .badge-due { background: #dc3545; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; }
        .badge-almost-due { background: #f97316; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-variant-numeric: tabular-nums; }
        .sidebar-card { background: #fff; border: 1px solid #e9ecef; border-radius: 10px; padding: 20px; margin-bottom: 15px; }
        .sidebar-card h5 { margin: 0 0 15px; font-size: 1rem; color: #333; }
        .thread-item { padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .thread-item:last-child { border-bottom: none; }
        .thread-item a { color: #2196F3; text-decoration: none; }
        .thread-item small { color: #666; display: block; }
        .back-link { margin-bottom: 20px; }
        .back-link a { color: #666; text-decoration: none; }
        .back-link a:hover { color: #2196F3; }
        .row { display: flex; gap: 30px; flex-wrap: wrap; }
        .col-main { flex: 2; min-width: 300px; }
        .col-side { flex: 1; min-width: 280px; }
        @media (max-width: 900px) { .row { flex-direction: column; } }
    </style>

<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="content-wrapper">
    <div class="back-link">
        <a href="<?php echo htmlspecialchars($studentElearningRoot); ?>index.php"><i class="fas fa-arrow-left"></i> Back to My Courses</a>
    </div>
    
    <div class="course-header">
        <h2><i class="fas fa-book"></i> <?php echo htmlspecialchars($courseCode); ?></h2>
        <p><?php echo htmlspecialchars($courseName); ?></p>
    </div>
    
    <?php if (!$courseFound): ?>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Course not found.</div>
    <?php else: ?>
    <div class="row">
        <div class="col-main">
            <!-- Modules Section -->
            <h4 class="section-title"><i class="fas fa-layer-group"></i> Course Modules</h4>
            <?php if (count($modules) > 0): ?>
                <?php foreach ($modules as $m): ?>
                    <div class="module-card">
                        <div class="module-card-header">
                            <i class="fas fa-folder-open"></i> <?php echo htmlspecialchars($m['title']); ?>
                        </div>
                        <div class="module-card-body">
                            <?php if (!empty($m['description'])): ?>
                                <p style="color: #666; margin-bottom: 15px;"><?php echo nl2br(htmlspecialchars($m['description'])); ?></p>
                            <?php endif; ?>
                            <?php
                            $contents = $contentsByModule[(int)$m['id']] ?? [];
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
                            <?php foreach ($contents as $c): 
                                $icon = 'fa-file';
                                if ($c['content_type'] === 'video') $icon = 'fa-video';
                                elseif ($c['content_type'] === 'pdf') $icon = 'fa-file-pdf';
                                elseif ($c['content_type'] === 'docx') $icon = 'fa-file-word';
                                elseif ($c['content_type'] === 'link') $icon = 'fa-link';
                                $contentHref = !empty($c['file_path']) ? $portalRoot . 'elearning/download.php?content_id=' . (int)$c['id'] : null;
                            ?>
                                <div class="content-item">
                                    <div class="content-icon"><i class="fas <?php echo $icon; ?>"></i></div>
                                    <div class="content-info">
                                        <h6><?php echo htmlspecialchars($c['title']); ?></h6>
                                        <small><?php echo ucfirst($c['content_type']); ?></small>
                                    </div>
                                    <?php if ($contentHref !== null): ?>
                                        <a href="<?php echo htmlspecialchars($contentHref); ?>" target="_blank" class="btn-open" onclick="window.ElearnTrack?.contentView(<?php echo json_encode($courseCode); ?>, <?php echo (int)$m['id']; ?>, <?php echo (int)$c['id']; ?>)">Open</a>
                                    <?php elseif ($c['content_type'] !== 'link' && empty($c['file_path'])): ?>
                                        <span class="text-muted">Not yet available</span>
                                    <?php else: ?>
                                        <span class="text-muted">Unavailable</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <!-- Inline Quizzes -->
                            <?php foreach ($moduleQuizzes as $q):
                                $attemptCount = (int)($q['attempt_count'] ?? 0);
                                $maxAttempts = (int)($q['max_attempts'] ?? 0);
                                $attemptsExhausted = $maxAttempts > 0 && $attemptCount >= $maxAttempts;
                                $quizSettings = elearningAssessmentSettings($q['settings_json'] ?? null, ['available_from' => '']);
                                $openTs = !empty($quizSettings['available_from']) ? strtotime((string)$quizSettings['available_from']) : false;
                                $dueTs = !empty($q['due_at']) ? strtotime((string)$q['due_at']) : false;
                                $notOpen = $openTs && $openTs > time();
                                $closed = $dueTs && $dueTs < time();
                                $qHref = $studentElearningRoot . 'quiz.php?quiz_id=' . (int)$q['id'];
                            ?>
                                <div class="content-item" style="border-left: 3px solid #6f42c1; padding-left: 10px;">
                                    <div class="content-icon" style="color: #6f42c1; background: #f3e8ff;"><i class="fas fa-pen"></i></div>
                                    <div class="content-info">
                                        <h6 style="font-weight: 600;"><?php echo htmlspecialchars($q['title']); ?></h6>
                                        <small>
                                            Quiz 
                                            <?php if ($q['time_limit_minutes']): ?> | <?php echo (int)$q['time_limit_minutes']; ?> min<?php endif; ?>
                                            <?php if ($dueTs): ?> | Due: <?php echo date('M j, Y g:i A', $dueTs); ?><?php endif; ?>
                                            <?php if ($q['best_score'] !== null): ?> | Best: <?php echo round($q['best_score'], 1); ?>%<?php endif; ?>
                                        </small>
                                    </div>
                                    <?php if ($notOpen): ?>
                                        <span class="badge bg-secondary text-white"><i class="fas fa-lock"></i> Locked</span>
                                    <?php elseif ($closed): ?>
                                        <span class="badge bg-danger text-white"><i class="fas fa-calendar-times"></i> Closed</span>
                                    <?php elseif ($attemptsExhausted): ?>
                                        <span class="badge bg-secondary text-white">Done</span>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars($qHref); ?>" class="btn-open" style="background: #6f42c1;"><i class="fas fa-play"></i> <?php echo $attemptCount > 0 ? 'Retake' : 'Start'; ?></a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <!-- Inline Assignments -->
                            <?php foreach ($moduleAssignments as $a):
                                $dueTs = !empty($a['due_at']) ? strtotime((string)$a['due_at']) : null;
                                $isDue = $dueTs && $dueTs < time();
                                $submissionCount = (int)($a['submission_count'] ?? 0);
                                $aHref = $studentElearningRoot . 'assignment.php?assignment_id=';
                            ?>
                                <div class="content-item" style="border-left: 3px solid #0ea5e9; padding-left: 10px;">
                                    <div class="content-icon" style="color: #0ea5e9; background: #e0f2fe;"><i class="fas fa-file-alt"></i></div>
                                    <div class="content-info">
                                        <h6 style="font-weight: 600;"><?php echo htmlspecialchars($a['title']); ?></h6>
                                        <small>
                                            Assignment 
                                            <?php if ($dueTs): ?> | Due: <?php echo date('M j, Y g:i A', $dueTs); ?><?php endif; ?>
                                            <?php if ($a['grade'] !== null): ?> | Grade: <?php echo round($a['grade'], 1); ?> pts<?php endif; ?>
                                        </small>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <?php if ($submissionCount > 0): ?>
                                            <span class="badge bg-success text-white">Submitted</span>
                                        <?php elseif ($isDue): ?>
                                            <span class="badge bg-danger text-white">Overdue</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pending</span>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars($aHref . (int)$a['id']); ?>" class="btn-open" style="background: #0ea5e9;"><i class="fas fa-eye"></i> View</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (count($contents) === 0 && count($moduleQuizzes) === 0 && count($moduleAssignments) === 0): ?>
                                <p class="text-muted">No content or tasks in this module yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> No modules have been released yet.</div>
            <?php endif; ?>

            <?php if (count($recordings) > 0): ?>
            <h4 class="section-title"><i class="fas fa-record-vinyl"></i> Recorded Videos</h4>
            <?php foreach ($recordings as $recording): ?>
                <?php
                $recordedAt = !empty($recording['recorded_at']) ? strtotime((string)$recording['recorded_at']) : strtotime((string)$recording['created_at']);
                ?>
                <div class="assessment-card">
                    <div class="assessment-info">
                        <h5><?php echo htmlspecialchars((string)$recording['title']); ?></h5>
                        <small>
                            <i class="fas fa-calendar"></i> <?php echo date('M j, Y', $recordedAt); ?>
                            <?php if (!empty($recording['duration_minutes'])): ?>
                                | <i class="fas fa-clock"></i> <?php echo (int)$recording['duration_minutes']; ?> min
                            <?php endif; ?>
                            | <i class="fas fa-film"></i> <?php echo htmlspecialchars(elearningVideoFormatLabel($recording['video_format'] ?? null)); ?>
                        </small>
                    </div>
                    <a href="<?php echo htmlspecialchars($studentElearningRoot); ?>recordings.php?course_code=<?php echo urlencode($courseCode); ?>" class="btn-open">
                        <i class="fas fa-play"></i> Watch
                    </a>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Quizzes Section -->
            <?php
            $unassignedQuizzes = [];
            foreach ($quizzes as $q) {
                if (empty($q['module_id'])) {
                    $unassignedQuizzes[] = $q;
                }
            }
            if (count($unassignedQuizzes) > 0): ?>
            <h4 class="section-title"><i class="fas fa-pen"></i> Quizzes (Unassigned)</h4>
            <?php foreach ($unassignedQuizzes as $q): ?>
                <?php
                $attemptCount = (int)($q['attempt_count'] ?? 0);
                $maxAttempts = (int)($q['max_attempts'] ?? 0);
                $attemptsExhausted = $maxAttempts > 0 && $attemptCount >= $maxAttempts;
                $quizSettings = elearningAssessmentSettings($q['settings_json'] ?? null, ['available_from' => '']);
                $openTs = !empty($quizSettings['available_from']) ? strtotime((string)$quizSettings['available_from']) : false;
                $dueTs = !empty($q['due_at']) ? strtotime((string)$q['due_at']) : false;
                $notOpen = $openTs && $openTs > time();
                $closed = $dueTs && $dueTs < time();
                ?>
                <div class="assessment-card">
                    <div class="assessment-info">
                        <h5><?php echo htmlspecialchars($q['title']); ?></h5>
                        <small>
                            <?php if ($q['time_limit_minutes']): ?>
                                <i class="fas fa-clock"></i> <?php echo (int)$q['time_limit_minutes']; ?> min
                            <?php endif; ?>
                            <?php if ($attemptCount > 0): ?>
                                | Attempts: <?php echo $attemptCount; ?><?php echo $maxAttempts > 0 ? ' / ' . $maxAttempts : ''; ?>
                            <?php endif; ?>
                            <?php if ($openTs): ?>
                                | Opens: <?php echo date('M j, Y g:i A', $openTs); ?>
                            <?php endif; ?>
                            <?php if ($dueTs): ?>
                                | Due: <?php echo date('M j, Y g:i A', $dueTs); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div>
                        <?php if ($q['best_score'] !== null): ?>
                            <span class="badge-score"><?php echo round($q['best_score'], 1); ?>%</span>
                        <?php endif; ?>
                        <?php if ($notOpen): ?>
                            <button type="button" class="btn btn-secondary btn-sm" style="margin-left: 10px;" disabled>
                                <i class="fas fa-lock"></i> Not open yet
                            </button>
                        <?php elseif ($closed): ?>
                            <button type="button" class="btn btn-secondary btn-sm" style="margin-left: 10px;" disabled>
                                <i class="fas fa-calendar-times"></i> Closed
                            </button>
                        <?php elseif ($attemptsExhausted): ?>
                            <button type="button" class="btn btn-secondary btn-sm" style="margin-left: 10px;" disabled>
                                <i class="fas fa-ban"></i> Max attempts reached
                            </button>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($studentElearningRoot); ?>quiz.php?quiz_id=<?php echo (int)$q['id']; ?>" class="btn-open" style="margin-left: 10px;">
                                <i class="fas fa-play"></i> <?php echo $attemptCount > 0 ? 'Retake' : 'Start'; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Assignments Section -->
            <?php
            $unassignedAssignments = [];
            foreach ($assignments as $a) {
                if (empty($a['module_id'])) {
                    $unassignedAssignments[] = $a;
                }
            }
            if (count($unassignedAssignments) > 0): ?>
            <h4 class="section-title"><i class="fas fa-file-alt"></i> Assignments (Unassigned)</h4>
            <?php foreach ($unassignedAssignments as $a): 
                $dueTs = !empty($a['due_at']) ? strtotime((string)$a['due_at']) : null;
                $isDue = $dueTs && $dueTs < time();
                $isAlmostDue = $dueTs && !$isDue && ($dueTs - time()) <= 24 * 60 * 60;
                $isDueSoon = $dueTs && !$isDue && !$isAlmostDue && $dueTs <= strtotime('+7 days');
                $submissionCount = (int)($a['submission_count'] ?? 0);
            ?>
                <div class="assessment-card">
                    <div class="assessment-info">
                        <h5><?php echo htmlspecialchars($a['title']); ?></h5>
                        <small>
                            <?php if (!empty($a['due_at'])): ?>
                                <i class="fas fa-calendar"></i> Due: <?php echo date('M j, Y g:i A', strtotime($a['due_at'])); ?>
                                <?php if ($isDue): ?>
                                    <span class="badge-due" style="margin-left: 10px;">Overdue</span>
                                <?php elseif ($isAlmostDue): ?>
                                    <span class="badge-almost-due due-countdown" data-due="<?php echo htmlspecialchars(date('c', $dueTs)); ?>" style="margin-left: 10px;">Calculating...</span>
                                <?php elseif ($isDueSoon): ?>
                                    <span class="badge-pending" style="margin-left: 10px;">Due Soon</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <i class="fas fa-infinity"></i> No deadline
                                <span class="badge-pending" style="margin-left: 10px;">No deadline</span>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div>
                        <?php if ($a['grade'] !== null): ?>
                            <span class="badge-score"><?php echo round($a['grade'], 1); ?> pts</span>
                        <?php endif; ?>
                        <?php if ($submissionCount > 0): ?>
                            <span class="badge-pending">Submitted</span>
                        <?php else: ?>
                            <span class="badge-pending">Not Submitted</span>
                        <?php endif; ?>
                        <a href="<?php echo htmlspecialchars($studentElearningRoot); ?>assignment.php?assignment_id=<?php echo (int)$a['id']; ?>" class="btn-open" style="margin-left: 10px;">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="col-side">
            <!-- Recent Discussions -->
            <div class="sidebar-card">
                <h5><i class="fas fa-comments"></i> Recent Discussions</h5>
                <?php if (count($threads) > 0): ?>
                    <?php foreach ($threads as $t): ?>
                        <div class="thread-item">
                            <a href="<?php echo htmlspecialchars($studentElearningRoot); ?>student_thread.php?id=<?php echo (int)$t['id']; ?>&course_code=<?php echo urlencode($courseCode); ?>">
                                <?php echo htmlspecialchars($t['title']); ?>
                            </a>
                            <small><?php echo (int)$t['post_count']; ?> posts</small>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($threadTotal > 5): ?>
                        <small class="text-muted">
                            Showing 5 of <?php echo (int)$threadTotal; ?> discussions.
                            <a href="<?php echo htmlspecialchars($studentElearningRoot); ?>student_forum.php?course_code=<?php echo urlencode($courseCode); ?>">All Discussions</a>
                        </small>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted">No discussions yet.</p>
                <?php endif; ?>
            </div>
            
            <!-- Quick Links -->
            <div class="sidebar-card">
                <h5><i class="fas fa-link"></i> Quick Links</h5>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                        <a href="<?php echo htmlspecialchars($studentElearningRoot); ?>competencies.php?course_code=<?php echo urlencode($courseCode); ?>" style="color: #6f42c1; text-decoration: none; font-weight: 600;">
                            <i class="fas fa-tasks text-purple"></i> Competency Checklist
                        </a>
                    </li>
                    <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                        <a href="<?php echo htmlspecialchars($studentElearningRoot); ?>recordings.php?course_code=<?php echo urlencode($courseCode); ?>" style="color: #2196F3; text-decoration: none;">
                            <i class="fas fa-record-vinyl"></i> Recorded Videos
                        </a>
                    </li>
                    <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                        <a href="<?php echo htmlspecialchars($studentElearningRoot); ?>student_forum.php?course_code=<?php echo urlencode($courseCode); ?>" style="color: #2196F3; text-decoration: none;">
                            <i class="fas fa-comments"></i> All Discussions
                        </a>
                    </li>
                    <li style="padding: 8px 0;">
                        <a href="<?php echo htmlspecialchars($studentElearningRoot); ?>progress.php?course_code=<?php echo urlencode($courseCode); ?>" style="color: #2196F3; text-decoration: none;">
                            <i class="fas fa-chart-line"></i> My Progress
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="<?php echo htmlspecialchars($portalRoot); ?>dist/js/bootstrap.min.js"></script>
<script>
function formatDueCountdown(ms) {
    if (ms <= 0) return 'Overdue';
    const totalSeconds = Math.floor(ms / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return 'Due in ' + String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
}

function updateAssignmentCountdowns() {
    document.querySelectorAll('.due-countdown').forEach(function(el) {
        const due = new Date(el.dataset.due || '').getTime();
        if (Number.isNaN(due)) return;
        const remaining = due - Date.now();
        el.textContent = formatDueCountdown(remaining);
        if (remaining <= 0) {
            el.classList.remove('badge-almost-due');
            el.classList.add('badge-due');
        }
    });
}

updateAssignmentCountdowns();
setInterval(updateAssignmentCountdowns, 1000);
</script>
</body>
</html>

