<?php
$page_title = 'Discussions';
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_ui.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = trim((string) ($_GET['course_code'] ?? ''));
if (!$staffId) {
    header('Location: ../staff_login.php');
    exit;
}

$availableCourses = [];
$threads = [];
$forumPerms = [
    'can_view' => false,
    'can_post' => false,
    'can_moderate' => false,
    'can_create_thread' => false,
];

if ($courseCode === '') {
    // The global navigation links here without a course code. Present the
    // lecturer's assigned courses instead of terminating with "Unauthorized".
    $availableCourses = getLecturerCourseDetails($db, (string) $staffId);
} else {
    enforceLecturerCourseAccess($db, $staffId, $courseCode);
    $forumPerms = getForumPermissions($db, $courseCode, $staffId, null);

    $types = 's';
    $params = [$courseCode];
    $offeringIds = getLecturerCourseOfferingIds($db, $staffId, $courseCode);
    $offeringScope = elearningOfferingScopeCondition(
        $db,
        'el_forum_threads',
        null,
        $offeringIds,
        $types,
        $params
    );

    $sql = "SELECT id, course_code, title, tags, updated_at
              FROM el_forum_threads
             WHERE course_code=?{$offeringScope}
             ORDER BY updated_at DESC";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $threads[] = $row;
        }
        $stmt->close();
    }
}

require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

<div class="elearning-shell">
    <div class="elearning-header">
        <div>
            <h1 class="elearning-title"><i class="fas fa-comments"></i> Discussions</h1>
            <p class="elearning-subtitle">
                <?php if ($courseCode !== ''): ?>
                    Course: <?php echo htmlspecialchars($courseCode); ?>
                <?php else: ?>
                    Select one of your assigned courses to view its discussions.
                <?php endif; ?>
            </p>
        </div>
        <?php if ($courseCode !== '' && !empty($forumPerms['can_create_thread'])): ?>
        <div class="elearning-actions">
            <a class="btn btn-primary" href="thread_edit.php?course_code=<?php echo urlencode($courseCode); ?>">
                <i class="fas fa-plus"></i> New Thread
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($courseCode !== ''): ?>
        <?php elearningCourseTabs($courseCode, 'forum'); ?>
    <?php endif; ?>

    <?php if ($courseCode === ''): ?>
        <?php elearningCourseSelection($availableCourses, 'forum.php', 'View Discussions', 'fas fa-comments'); ?>
    <?php else: ?>
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
    <?php endif; ?>
</div>
</div>
</div>
</body>
</html>


