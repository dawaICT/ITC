<?php
error_reporting(0);
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = $_GET['course_code'] ?? ($_POST['course_code'] ?? '');
$threadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$staffId || !$courseCode || !$threadId) { die('Unauthorized'); }

// Verify lecturer has access to this course
enforceLecturerCourseAccess($db, $staffId, $courseCode);

// Get forum permissions
$forumPerms = getForumPermissions($db, $courseCode, $staffId, null);

$errors = [];
$successMsg = '';

// Load thread
$thread = null;
$stmt = $db->prepare("SELECT * FROM el_forum_threads WHERE id=? AND course_code=? LIMIT 1");
$stmt->bind_param('is', $threadId, $courseCode);
$stmt->execute();
$res = $stmt->get_result();
$thread = $res->fetch_assoc();
$stmt->close();

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
            $createdByType = 'staff';
            $stmt = $db->prepare("INSERT INTO el_forum_posts (thread_id, parent_post_id, body, created_by, created_by_type) VALUES (?,?,?,?,?)");
            $stmt->bind_param('iisss', $threadId, $parentPostId, $body, $staffId, $createdByType);
            $stmt->execute();
            $stmt->close();

            // Update thread timestamp
            $db->query("UPDATE el_forum_threads SET updated_at=NOW() WHERE id=" . (int)$threadId);

            $successMsg = 'Reply posted successfully.';
        }
    }
}

// Handle post deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_post') {
    $delPostId = (int)($_POST['delete_post_id'] ?? 0);
    if ($delPostId > 0) {
        // Only allow deletion if staff owns the post or has admin rights
        $stmt = $db->prepare("SELECT created_by, created_by_type FROM el_forum_posts WHERE id=? AND thread_id=?");
        $stmt->bind_param('ii', $delPostId, $threadId);
        $stmt->execute();
        $res = $stmt->get_result();
        $post = $res->fetch_assoc();
        $stmt->close();

        if ($post && ($post['created_by'] === $staffId || hasPermission($staffId, 'elearn_admin_all'))) {
            $stmt = $db->prepare("DELETE FROM el_forum_posts WHERE id=?");
            $stmt->bind_param('i', $delPostId);
            $stmt->execute();
            $stmt->close();
            $successMsg = 'Post deleted.';
        } else {
            $errors[] = 'Cannot delete this post.';
        }
    }
}

// Handle report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'report_post') {
    $reportPostId = (int)($_POST['report_post_id'] ?? 0);
    $reportReason = trim($_POST['report_reason'] ?? 'Reported by staff');
    if ($reportPostId > 0) {
        $stmt = $db->prepare("UPDATE el_forum_posts SET is_reported=1, report_reason=? WHERE id=? AND thread_id=?");
        $stmt->bind_param('sii', $reportReason, $reportPostId, $threadId);
        $stmt->execute();
        $stmt->close();
        $successMsg = 'Post reported.';
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

function renderPosts($posts, $staffId, $courseCode, $threadId, $level = 0) {
    foreach ($posts as $post) {
        $indent = $level * 30;
        $bgColor = $post['is_reported'] ? '#fff3cd' : ($level > 0 ? '#f8f9fa' : '#fff');
        echo '<div class="post-item" style="margin-left: '.$indent.'px; background: '.$bgColor.'; border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; margin-bottom: 10px;">';
        echo '<div class="post-header" style="display: flex; justify-content: space-between; margin-bottom: 10px;">';
        echo '<div><strong>'.htmlspecialchars($post['created_by']).'</strong> <small class="text-muted">('.htmlspecialchars($post['created_by_type']).') - '.htmlspecialchars($post['created_at']).'</small></div>';
        echo '<div>';
        if ($post['is_reported']) { echo '<span class="badge bg-warning text-dark"><i class="fas fa-flag"></i> Reported</span> '; }
        echo '<button class="btn btn-sm btn-outline-secondary" onclick="toggleReply('.$post['id'].')"><i class="fas fa-reply"></i></button> ';
        if ($post['created_by'] === $staffId || hasPermission($staffId, 'elearn_admin_all')) {
            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this post?\');"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="course_code" value="'.htmlspecialchars($courseCode).'"><input type="hidden" name="delete_post_id" value="'.(int)$post['id'].'"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>';
        }
        echo '</div></div>';
        echo '<div class="post-body">'.nl2br(htmlspecialchars($post['body'])).'</div>';
        echo '<div class="reply-form" id="reply-'.$post['id'].'" style="display:none; margin-top: 15px;">';
        echo '<form method="post"><input type="hidden" name="action" value="add_post"><input type="hidden" name="course_code" value="'.htmlspecialchars($courseCode).'"><input type="hidden" name="parent_post_id" value="'.(int)$post['id'].'">';
        echo '<textarea class="form-control mb-2" name="body" rows="2" placeholder="Write a reply..." required></textarea>';
        echo '<button class="btn btn-sm btn-primary"><i class="fas fa-paper-plane"></i> Reply</button></form></div>';
        echo '</div>';
        if (!empty($post['replies'])) {
            renderPosts($post['replies'], $staffId, $courseCode, $threadId, $level + 1);
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
    <link rel="stylesheet" href="../admin/css/admin-style.css">
    <link rel="stylesheet" href="../admin/css/admin-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .thread-header { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .badge-locked { background: #dc3545; color: #fff; padding: 3px 10px; border-radius: 4px; }
        .tag { display: inline-block; background: #e9ecef; padding: 2px 8px; border-radius: 4px; margin-right: 5px; font-size: 0.85rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../lecturers/includes/nav.php'; ?>

<div class="content-wrapper">
    <div class="thread-header">
        <h2><i class="fas fa-comments"></i> <?php echo htmlspecialchars($thread['title']); ?></h2>
        <p class="text-muted mb-2">
            Started by <?php echo htmlspecialchars($thread['created_by']); ?> on <?php echo htmlspecialchars($thread['created_at']); ?>
            <?php if ($thread['is_locked']): ?><span class="badge-locked"><i class="fas fa-lock"></i> Locked</span><?php endif; ?>
        </p>
        <?php if (!empty($thread['tags'])): ?>
            <div class="mb-2"><?php foreach (explode(',', $thread['tags']) as $tag): ?><span class="tag"><?php echo htmlspecialchars(trim($tag)); ?></span><?php endforeach; ?></div>
        <?php endif; ?>
        <a class="btn btn-sm btn-secondary" href="forum.php?course_code=<?php echo urlencode($courseCode); ?>"><i class="fas fa-arrow-left"></i> Back to Forum</a>
        <a class="btn btn-sm btn-primary" href="thread_edit.php?id=<?php echo (int)$threadId; ?>&course_code=<?php echo urlencode($courseCode); ?>"><i class="fas fa-edit"></i> Edit Thread</a>
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
            <?php renderPosts($postTree, $staffId, $courseCode, $threadId); ?>
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
</body>
</html>
