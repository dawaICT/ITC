<?php
error_reporting(0);
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = $_GET['course_code'] ?? ($_POST['course_code'] ?? '');
$moduleId = isset($_GET['module_id']) ? (int)$_GET['module_id'] : (isset($_POST['module_id']) ? (int)$_POST['module_id'] : 0);
if (!$staffId || !$courseCode) { die('Unauthorized'); }

// Verify lecturer has access to this course
enforceLecturerCourseAccess($db, $staffId, $courseCode);
$courseOfferingId = getLecturerCourseOfferingId($db, $staffId, $courseCode);
$moduleHasOffering = elearningTableHasCourseOffering($db, 'el_course_modules');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $release_at = trim($_POST['release_at'] ?? '');
    $close_at = trim($_POST['close_at'] ?? '');
    $position = (int)($_POST['position'] ?? 0);

    if ($title === '') { $errors[] = 'Title is required'; }

    if (!$errors) {
        $releaseAtValue = $release_at !== '' ? $release_at : null;
        $closeAtValue = $close_at !== '' ? $close_at : null;

        if ($moduleId > 0) {
            if ($moduleHasOffering && $courseOfferingId !== null) {
                $stmt = $db->prepare("UPDATE el_course_modules SET title=?, description=?, release_at=?, close_at=?, position=?, course_offering_id = COALESCE(course_offering_id, ?) WHERE id=? AND course_code=? AND (course_offering_id = ? OR course_offering_id IS NULL)");
                $stmt->bind_param('ssssiiisi', $title, $description, $releaseAtValue, $closeAtValue, $position, $courseOfferingId, $moduleId, $courseCode, $courseOfferingId);
            } else {
                $stmt = $db->prepare("UPDATE el_course_modules SET title=?, description=?, release_at=?, close_at=?, position=? WHERE id=? AND course_code=?");
                $stmt->bind_param('ssssiss', $title, $description, $releaseAtValue, $closeAtValue, $position, $moduleId, $courseCode);
            }
            $stmt->execute();
            $stmt->close();
        } else {
            if ($moduleHasOffering) {
                $stmt = $db->prepare("INSERT INTO el_course_modules (course_offering_id, course_code, title, description, release_at, close_at, position, created_by) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param('isssssis', $courseOfferingId, $courseCode, $title, $description, $releaseAtValue, $closeAtValue, $position, $staffId);
            } else {
                $stmt = $db->prepare("INSERT INTO el_course_modules (course_code, title, description, release_at, close_at, position, created_by) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param('sssssis', $courseCode, $title, $description, $releaseAtValue, $closeAtValue, $position, $staffId);
            }
            $stmt->execute();
            $moduleId = (int)$stmt->insert_id;
            $stmt->close();
        }
        header('Location: manage.php?course_code=' . urlencode($courseCode));
        exit;
    }
}

$module = null;
if ($moduleId > 0) {
    if ($moduleHasOffering && $courseOfferingId !== null) {
        $stmt = $db->prepare("SELECT * FROM el_course_modules WHERE id=? AND course_code=? AND (course_offering_id = ? OR course_offering_id IS NULL) LIMIT 1");
        $stmt->bind_param('isi', $moduleId, $courseCode, $courseOfferingId);
    } else {
        $stmt = $db->prepare("SELECT * FROM el_course_modules WHERE id=? AND course_code=? LIMIT 1");
        $stmt->bind_param('is', $moduleId, $courseCode);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $module = $res->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $module ? 'Edit Module' : 'New Module'; ?></title>
    <link rel="stylesheet" href="../admin/css/admin-style.css">
    <link rel="stylesheet" href="../admin/css/admin-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php require_once __DIR__ . '/../lecturers/includes/nav.php'; ?>

<div class="content-wrapper">
    <h2><i class="fas fa-layer-group"></i> <?php echo $module ? 'Edit' : 'Create'; ?> Module</h2>
    <hr>
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?>
        </div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
        <input type="hidden" name="module_id" value="<?php echo (int)$moduleId; ?>">
        <div class="mb-3">
            <label class="form-label">Title</label>
            <input class="form-control" type="text" name="title" value="<?php echo htmlspecialchars($module['title'] ?? ''); ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="4"><?php echo htmlspecialchars($module['description'] ?? ''); ?></textarea>
        </div>
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">Release At</label>
                <input class="form-control" type="datetime-local" name="release_at" value="<?php echo isset($module['release_at']) ? date('Y-m-d\TH:i', strtotime($module['release_at'])) : ''; ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Close At</label>
                <input class="form-control" type="datetime-local" name="close_at" value="<?php echo isset($module['close_at']) ? date('Y-m-d\TH:i', strtotime($module['close_at'])) : ''; ?>">
            </div>
            <div class="col-md-2 mb-3">
                <label class="form-label">Position</label>
                <input class="form-control" type="number" name="position" value="<?php echo (int)($module['position'] ?? 0); ?>">
            </div>
        </div>
        <div class="mt-3">
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save</button>
            <a class="btn btn-secondary" href="manage.php?course_code=<?php echo urlencode($courseCode); ?>">Cancel</a>
        </div>
    </form>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
</body>
</html>


