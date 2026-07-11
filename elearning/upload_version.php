<?php
$page_title = 'Upload Version';
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_files.php';
require_once __DIR__ . '/../includes/elearning_ui.php';

$staffId = $_SESSION['staff_id'] ?? null;
$contentId = isset($_GET['content_id']) ? (int)$_GET['content_id'] : (int)($_POST['content_id'] ?? 0);
$courseCode = $_GET['course_code'] ?? ($_POST['course_code'] ?? '');
if (!$staffId || !$contentId || !$courseCode) { die('Unauthorized'); }

// Load content & module
$stmt = $db->prepare("SELECT c.*, m.course_code FROM el_contents c INNER JOIN el_course_modules m ON c.module_id=m.id WHERE c.id=? LIMIT 1");
$stmt->bind_param('i', $contentId); $stmt->execute();
$res = $stmt->get_result(); $content = $res->fetch_assoc(); $stmt->close();
if (!$content || $content['course_code'] !== $courseCode) { die('Not found'); }

// Verify lecturer has access to this course
enforceLecturerCourseAccess($db, $staffId, $courseCode);

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { $errors[] = 'File upload required'; }
    if (!$errors) {
        $validation = elearningValidateUploadedFile($_FILES['file'], (string)($content['content_type'] ?? ''));
        if (!$validation['ok']) {
            $errors[] = $validation['error'];
        }
    }

    if (!$errors) {
        $uploadDir = __DIR__ . '/../uploads/elearning/';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $basename = elearningSafeUploadName('C' . $contentId, $ext);
        $dest = $uploadDir . $basename;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            $errors[] = 'Failed to move uploaded file';
        } else {
            // Determine next version number
            $ver = 1; $res = $db->query("SELECT MAX(version_no) AS mx FROM el_content_versions WHERE content_id=" . (int)$contentId);
            if ($res && ($row=$res->fetch_assoc()) && $row['mx']) { $ver = (int)$row['mx'] + 1; }
            $res && $res->free();

            $filePath = 'uploads/elearning/' . $basename;
            $size = filesize($dest) ?: null;
            $checksum = hash_file('sha256', $dest);

            $stmt = $db->prepare("INSERT INTO el_content_versions (content_id, version_no, file_path, file_size, checksum_sha256, created_by) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('iisiss', $contentId, $ver, $filePath, $size, $checksum, $staffId);
            $stmt->execute(); $versionId = (int)$stmt->insert_id; $stmt->close();

            // Set as current
            $stmt = $db->prepare("UPDATE el_contents SET current_version_id=? WHERE id=?");
            $stmt->bind_param('ii', $versionId, $contentId); $stmt->execute(); $stmt->close();

            header('Location: versions.php?content_id='.$contentId.'&course_code='.urlencode($courseCode)); exit;
        }
    }
}
require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

    <div class="elearning-shell">
    <div class="elearning-header">
        <div>
            <h1 class="elearning-title"><i class="fas fa-upload"></i> Upload New Version</h1>
            <p class="elearning-subtitle"><?php echo htmlspecialchars($content['title'] ?? 'Content'); ?></p>
        </div>
    </div>
    <?php elearningCourseTabs($courseCode, 'manage'); ?>
    <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="elearning-form elearning-card">
        <input type="hidden" name="content_id" value="<?php echo (int)$contentId; ?>">
        <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
        <div class="mb-3">
            <label class="form-label">File</label>
            <input class="form-control" type="file" name="file" required>
            <small class="elearning-muted">The new file must match this content type.</small>
        </div>
        <div class="elearning-actions">
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save</button>
            <a class="btn btn-secondary" href="versions.php?content_id=<?php echo (int)$contentId; ?>&course_code=<?php echo urlencode($courseCode); ?>">Cancel</a>
        </div>
    </form>
    </div>
</div>
</div>
</body>
</html>



