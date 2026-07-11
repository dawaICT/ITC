<?php
$page_title = 'Discussions';
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_ui.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = $_GET['course_code'] ?? '';
if (!$staffId || !$courseCode) { die('Unauthorized'); }

// Verify lecturer has access to this course
enforceLecturerCourseAccess($db, $staffId, $courseCode);

// Get forum permissions for staff
$forumPerms = getForumPermissions($db, $courseCode, $staffId, null);

// Simple thread list
$threads = [];
if ($stmt = $db->prepare("SELECT * FROM el_forum_threads WHERE course_code=? ORDER BY updated_at DESC")) {
    $stmt->bind_param('s', $courseCode);
    $stmt->execute(); $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $threads[] = $row; }
    $stmt->close();
}

require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

<div class="elearning-shell">
    <div class="elearning-header">
        <div>
            <h1 class="elearning-title"><i class="fas fa-comments"></i> Discussions</h1>
            <p class="elearning-subtitle">Course: <?php echo htmlspecialchars($courseCode); ?></p>
        </div>
        <div class="elearning-actions">
            <a class="btn btn-primary" href="thread_edit.php?course_code=<?php echo urlencode($courseCode); ?>">
                <i class="fas fa-plus"></i> New Thread
            </a>
        </div>
    </div>
    <?php elearningCourseTabs($courseCode, 'forum'); ?>

    <section class="elearning-panel">
        <div class="elearning-panel-header"><strong>Threads</strong></div>
        <div class="elearning-panel-body">
            <ul class="elearning-thread-list">
                <?php foreach ($threads as $t): ?>
                    <li class="elearning-thread-item">
                        <div>
                            <a class="elearning-thread-title" href="thread.php?id=<?php echo (int)$t['id']; ?>&course_code=<?php echo urlencode($courseCode); ?>">
                                <?php echo htmlspecialchars($t['title']); ?>
                            </a>
                            <div class="elearning-muted">
                                Updated <?php echo !empty($t['updated_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string)$t['updated_at']))) : 'recently'; ?>
                            </div>
                            <?php if (!empty($t['tags'])): ?>
                                <div class="elearning-thread-tags">Tags: <?php echo htmlspecialchars($t['tags']); ?></div>
                            <?php endif; ?>
                        </div>
                        <a class="btn btn-sm btn-secondary" href="thread.php?id=<?php echo (int)$t['id']; ?>&course_code=<?php echo urlencode($courseCode); ?>">
                            Open
                        </a>
                    </li>
                <?php endforeach; ?>
                <?php if (count($threads) === 0): ?>
                    <li><p class="elearning-empty">No threads yet. Start a discussion for this course.</p></li>
                <?php endif; ?>
            </ul>
        </div>
    </section>
</div>
</div>
</div>
</body>
</html>


