<?php
error_reporting(0);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';

$sid = $_SESSION['Sid'] ?? null;
$courseCode = trim((string)($_GET['course_code'] ?? ($_POST['course_code'] ?? '')));
$threadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$sid || !$courseCode || !$threadId) { die('Unauthorized'); }
$baseHref = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/wucportal/students/elearning/student_thread.php'))), '/') . '/';

// Verify student is enrolled in this course
enforceStudentCourseAccess($db, $sid, $courseCode);
$courseOfferingIds = getStudentCourseOfferingIds($db, $sid, $courseCode);

// Get forum permissions for this student
$forumPerms = getForumPermissions($db, $courseCode, null, $sid);

$errors = [];
$successMsg = '';

// Load thread
$thread = null;
$threadTypes = 'is';
$threadParams = [$threadId, $courseCode];
$threadOfferingSql = elearningOfferingScopeCondition($db, 'el_forum_threads', null, $courseOfferingIds, $threadTypes, $threadParams);
$stmt = $db->prepare("SELECT * FROM el_forum_threads WHERE id=? AND course_code=? {$threadOfferingSql} LIMIT 1");
if ($stmt) {
    $stmt->bind_param($threadTypes, ...$threadParams);
    $stmt->execute();
    $res = $stmt->get_result();
    $thread = $res->fetch_assoc();
    $stmt->close();
} else {
    error_log('students/elearning/student_thread.php: thread load prepare failed: ' . $db->error);
}

if (!$thread) { die('Thread not found'); }

// Handle new post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_post') {
    if ($thread['is_locked']) {
        $errors[] = 'This thread is locked.';
    } else {
        $body = trim($_POST['body'] ?? '');
        $parentPostId = isset($_POST['parent_post_id']) && $_POST['parent_post_id'] !== '' ? (int)$_POST['parent_post_id'] : null;

        if ($body === '') { $errors[] = 'Post content is required'; }

        if (!$errors) {
            $createdByType = 'student';
            $stmt = $db->prepare("INSERT INTO el_forum_posts (thread_id, parent_post_id, body, created_by, created_by_type) VALUES (?,?,?,?,?)");
            if ($stmt) {
                $stmt->bind_param('iisss', $threadId, $parentPostId, $body, $sid, $createdByType);
                $stmt->execute();
                $stmt->close();
            } else {
                $errors[] = 'Unable to add reply.';
                error_log('students/elearning/student_thread.php: post insert prepare failed: ' . $db->error);
            }

            // Update thread timestamp
            $db->query("UPDATE el_forum_threads SET updated_at=NOW() WHERE id=" . (int)$threadId);

            $successMsg = 'Reply posted successfully.';
            
            // Track event
            if (function_exists('ElearnTrack')) {
                // Client-side tracking will handle this
            }
        }
    }
}

// Load posts
$posts = [];
if ($stmt = $db->prepare("SELECT * FROM el_forum_posts WHERE thread_id=? ORDER BY created_at ASC")) {
    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $posts[] = $row; }
    $stmt->close();
} else {
    error_log('students/elearning/student_thread.php: posts fetch prepare failed: ' . $db->error);
}

// Build nested structure for replies
function buildPostTree($posts, $parentId = null) {
    $branch = [];
    foreach ($posts as $post) {
        if ($post['parent_post_id'] == $parentId) {
            $children = buildPostTree($posts, $post['id']);
            if ($children) { $post['replies'] = $children; }
            $branch[] = $post;
        }
    }
    return $branch;
}
$postTree = buildPostTree($posts, null);

function renderStudentPosts($posts, $sid, $courseCode, $threadId, $level = 0) {
    foreach ($posts as $post) {
        $indent = $level * 25;
        $isOwn = ($post['created_by'] === $sid && $post['created_by_type'] === 'student');
        $bgColor = $isOwn ? '#e3f2fd' : ($level > 0 ? '#f8f9fa' : '#fff');
        $borderColor = $post['created_by_type'] === 'staff' ? '#6f42c1' : '#e9ecef';
        
        echo '<div class="post-item" style="margin-left: '.$indent.'px; background: '.$bgColor.'; border: 1px solid '.$borderColor.'; border-radius: 8px; padding: 15px; margin-bottom: 10px;">';
        echo '<div class="post-header" style="display: flex; justify-content: space-between; margin-bottom: 10px;">';
        echo '<div>';
        echo '<strong>'.htmlspecialchars($post['created_by']).'</strong> ';
        if ($post['created_by_type'] === 'staff') {
            echo '<span style="background: #6f42c1; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem;">Instructor</span> ';
        }
        echo '<small style="color: #666;">'.htmlspecialchars($post['created_at']).'</small>';
        echo '</div>';
        echo '<div>';
        echo '<button class="btn btn-sm btn-outline-secondary" onclick="toggleReply('.$post['id'].')"><i class="fas fa-reply"></i> Reply</button>';
        echo '</div></div>';
        echo '<div class="post-body">'.nl2br(htmlspecialchars($post['body'])).'</div>';
        echo '<div class="reply-form" id="reply-'.$post['id'].'" style="display:none; margin-top: 15px;">';
        echo '<form method="post"><input type="hidden" name="action" value="add_post"><input type="hidden" name="course_code" value="'.htmlspecialchars($courseCode).'"><input type="hidden" name="parent_post_id" value="'.(int)$post['id'].'">';
        echo '<textarea class="form-control mb-2" name="body" rows="2" placeholder="Write a reply..." required></textarea>';
        echo '<button class="btn btn-sm btn-primary"><i class="fas fa-paper-plane"></i> Reply</button></form></div>';
        echo '</div>';
        if (!empty($post['replies'])) {
            renderStudentPosts($post['replies'], $sid, $courseCode, $threadId, $level + 1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($thread['title']); ?> - Discussion</title>
    <base href="<?php echo htmlspecialchars($baseHref); ?>">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .thread-header { background: #f8f9fa; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .thread-header h2 { margin: 0 0 10px; font-size: 1.5rem; }
        .badge-locked { background: #dc3545; color: #fff; padding: 3px 10px; border-radius: 4px; font-size: 0.8rem; }
        .tag { display: inline-block; background: #e9ecef; padding: 2px 8px; border-radius: 4px; margin-right: 5px; font-size: 0.85rem; }
    </style>

<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="content-wrapper">
    <div style="margin-bottom: 20px;">
        <a href="elearning/student_forum.php?course_code=<?php echo urlencode($courseCode); ?>" style="color: #666; text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Back to Discussions
        </a>
    </div>
    
    <div class="thread-header">
        <h2>
            <?php echo htmlspecialchars($thread['title']); ?>
            <?php if ($thread['is_locked']): ?><span class="badge-locked"><i class="fas fa-lock"></i> Locked</span><?php endif; ?>
        </h2>
        <p style="margin: 0; color: #666;">
            Started by <?php echo htmlspecialchars($thread['created_by']); ?> 
            on <?php echo date('M j, Y g:i A', strtotime($thread['created_at'])); ?>
        </p>
        <?php if (!empty($thread['tags'])): ?>
            <div style="margin-top: 10px;">
                <?php foreach (explode(',', $thread['tags']) as $tag): ?>
                    <span class="tag"><?php echo htmlspecialchars(trim($tag)); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
    <?php endif; ?>
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>
    
    <!-- Posts -->
    <div class="posts-container">
        <?php if (count($posts) > 0): ?>
            <?php renderStudentPosts($postTree, $sid, $courseCode, $threadId); ?>
        <?php else: ?>
            <div class="alert alert-info">No posts in this thread yet.</div>
        <?php endif; ?>
    </div>
    
    <!-- New Post Form -->
    <?php if (!$thread['is_locked']): ?>
    <div class="card mt-4">
        <div class="card-header"><strong>Add a Reply</strong></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="add_post">
                <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
                <textarea class="form-control mb-3" name="body" rows="4" placeholder="Write your reply..." required></textarea>
                <button class="btn btn-primary"><i class="fas fa-paper-plane"></i> Post Reply</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-warning mt-4"><i class="fas fa-lock"></i> This thread is locked. No new replies can be added.</div>
    <?php endif; ?>
</div>

<script>
function toggleReply(postId) {
    var el = document.getElementById('reply-' + postId);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
<script src="track_event.js"></script>
<script>
if (typeof ElearnTrack !== 'undefined') {
    ElearnTrack.forumPost('<?php echo htmlspecialchars($courseCode); ?>', <?php echo (int)$threadId; ?>);
}
</script>
</body>
</html>
