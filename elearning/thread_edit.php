<?php
error_reporting(0);
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = $_GET['course_code'] ?? ($_POST['course_code'] ?? '');
$threadId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['thread_id']) ? (int)$_POST['thread_id'] : 0);
if (!$staffId || !$courseCode) { die('Unauthorized'); }

// Verify lecturer has access to this course
enforceLecturerCourseAccess($db, $staffId, $courseCode);
$courseOfferingId = getLecturerCourseOfferingId($db, $staffId, $courseCode);
$threadHasOffering = elearningTableHasCourseOffering($db, 'el_forum_threads');

$errors = [];
$successMsg = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $tags = trim($_POST['tags'] ?? '');
    $isLocked = isset($_POST['is_locked']) ? 1 : 0;
    $moduleId = isset($_POST['module_id']) && $_POST['module_id'] !== '' ? (int)$_POST['module_id'] : null;
    $initialPost = trim($_POST['initial_post'] ?? '');

    if ($title === '') { $errors[] = 'Title is required'; }

    if (!$errors) {
        if ($threadId > 0) {
            if ($threadHasOffering && $courseOfferingId !== null) {
                $stmt = $db->prepare("UPDATE el_forum_threads SET title=?, tags=?, module_id=?, is_locked=?, course_offering_id=COALESCE(course_offering_id, ?) WHERE id=? AND course_code=? AND (course_offering_id=? OR course_offering_id IS NULL)");
                $stmt->bind_param('ssiiiisi', $title, $tags, $moduleId, $isLocked, $courseOfferingId, $threadId, $courseCode, $courseOfferingId);
            } else {
                $stmt = $db->prepare("UPDATE el_forum_threads SET title=?, tags=?, module_id=?, is_locked=? WHERE id=? AND course_code=?");
                $stmt->bind_param('ssiiss', $title, $tags, $moduleId, $isLocked, $threadId, $courseCode);
            }
            $stmt->execute();
            $stmt->close();
            $successMsg = 'Thread updated successfully.';
        } else {
            $createdByType = 'staff';
            if ($threadHasOffering) {
                $stmt = $db->prepare("INSERT INTO el_forum_threads (course_offering_id, course_code, module_id, title, tags, is_locked, created_by, created_by_type) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param('isississ', $courseOfferingId, $courseCode, $moduleId, $title, $tags, $isLocked, $staffId, $createdByType);
            } else {
                $stmt = $db->prepare("INSERT INTO el_forum_threads (course_code, module_id, title, tags, is_locked, created_by, created_by_type) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param('sississ', $courseCode, $moduleId, $title, $tags, $isLocked, $staffId, $createdByType);
            }
            $stmt->execute();
            $threadId = (int)$stmt->insert_id;
            $stmt->close();

            // Add initial post if provided
            if ($initialPost !== '' && $threadId > 0) {
                $stmt = $db->prepare("INSERT INTO el_forum_posts (thread_id, body, created_by, created_by_type) VALUES (?,?,?,?)");
                $stmt->bind_param('isss', $threadId, $initialPost, $staffId, $createdByType);
                $stmt->execute();
                $stmt->close();
            }
            $successMsg = 'Thread created successfully.';
        }
        header('Location: thread.php?id=' . $threadId . '&course_code=' . urlencode($courseCode));
        exit;
    }
}

// Load thread data
$thread = null;
if ($threadId > 0) {
    if ($threadHasOffering && $courseOfferingId !== null) {
        $stmt = $db->prepare("SELECT * FROM el_forum_threads WHERE id=? AND course_code=? AND (course_offering_id=? OR course_offering_id IS NULL) LIMIT 1");
        $stmt->bind_param('isi', $threadId, $courseCode, $courseOfferingId);
    } else {
        $stmt = $db->prepare("SELECT * FROM el_forum_threads WHERE id=? AND course_code=? LIMIT 1");
        $stmt->bind_param('is', $threadId, $courseCode);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $thread = $res->fetch_assoc();
    $stmt->close();
}

// Load modules for dropdown
$modules = [];
$courseOfferingIds = getLecturerCourseOfferingIds($db, $staffId, $courseCode);
$types = 's';
$params = [$courseCode];
$offeringSql = elearningOfferingScopeCondition($db, 'el_course_modules', null, $courseOfferingIds, $types, $params);
if ($stmt = $db->prepare("SELECT id, title FROM el_course_modules WHERE course_code=? {$offeringSql} ORDER BY position, id")) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $modules[] = $row; }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $thread ? 'Edit Thread' : 'New Thread'; ?> - <?php echo htmlspecialchars($courseCode); ?></title>
    <link rel="stylesheet" href="../admin/css/admin-style.css">
    <link rel="stylesheet" href="../admin/css/admin-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php require_once __DIR__ . '/../lecturers/includes/nav.php'; ?>

<div class="content-wrapper">
    <h2><i class="fas fa-comments"></i> <?php echo $thread ? 'Edit Discussion Thread' : 'Create New Discussion'; ?></h2>
    <p class="text-muted">Course: <?php echo htmlspecialchars($courseCode); ?></p>
    <hr>
    
    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
    <?php endif; ?>
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header"><strong>Thread Details</strong></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
                <input type="hidden" name="thread_id" value="<?php echo (int)$threadId; ?>">
                
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Title *</label>
                        <input class="form-control" type="text" name="title" value="<?php echo htmlspecialchars($thread['title'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Module (optional)</label>
                        <select class="form-select" name="module_id">
                            <option value="">-- General Discussion --</option>
                            <?php foreach ($modules as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>" <?php echo (isset($thread['module_id']) && $thread['module_id'] == $m['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Tags (comma-separated)</label>
                        <input class="form-control" type="text" name="tags" value="<?php echo htmlspecialchars($thread['tags'] ?? ''); ?>" placeholder="e.g., assignment, question, help">
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" name="is_locked" id="isLocked" <?php echo ($thread && ($thread['is_locked'] ?? 0)) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="isLocked">Lock Thread (no replies)</label>
                        </div>
                    </div>
                </div>
                
                <?php if (!$thread): ?>
                <div class="mb-3">
                    <label class="form-label">Initial Post (optional)</label>
                    <textarea class="form-control" name="initial_post" rows="5" placeholder="Write the opening post for this discussion..."></textarea>
                </div>
                <?php endif; ?>
                
                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> <?php echo $thread ? 'Update Thread' : 'Create Thread'; ?></button>
                <a class="btn btn-secondary" href="forum.php?course_code=<?php echo urlencode($courseCode); ?>">Cancel</a>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
</body>
</html>
