<?php
$page_title = 'Upload Content';
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_files.php';
require_once __DIR__ . '/../includes/elearning_ui.php';

$staffId = $_SESSION['staff_id'] ?? null;
$moduleId = isset($_GET['module_id']) ? (int)$_GET['module_id'] : (int)($_POST['module_id'] ?? 0);
$courseCode = $_GET['course_code'] ?? ($_POST['course_code'] ?? '');
if (!$staffId || !$moduleId || !$courseCode) { die('Unauthorized'); }

// Verify lecturer has access to this course
enforceLecturerCourseAccess($db, $staffId, $courseCode);

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $contentType = $_POST['content_type'] ?? 'pdf';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $captions = null;

    if ($title === '') { $errors[] = 'Title is required'; }
    $allowedTypes = ['video','pdf','docx','scorm','link'];
    if (!in_array($contentType, $allowedTypes, true)) { $errors[] = 'Invalid content type'; }

    $filePath = null; $mime = null; $size = null; $checksum = null;
    if (!$errors) {
        if ($contentType === 'link') {
            $filePath = trim($_POST['external_url'] ?? '');
            if ($filePath === '' || !elearningValidateExternalUrl($filePath)) { $errors[] = 'A valid http or https URL is required for link content'; }
        } elseif ($contentType === 'scorm') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { $errors[] = 'SCORM zip required'; }
        } else {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { $errors[] = 'File upload required'; }
        }

        if ($contentType !== 'link' && isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $validation = elearningValidateUploadedFile($_FILES['file'], $contentType);
            if (!$validation['ok']) {
                $errors[] = $validation['error'];
            }
        }
    }

    if (!$errors) {
        if ($contentType !== 'link') {
            $uploadDir = __DIR__ . '/../uploads/elearning/';
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $basename = elearningSafeUploadName('M' . $moduleId, $ext);
            $dest = $uploadDir . $basename;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                $errors[] = 'Failed to move uploaded file';
            } else {
                $filePath = 'uploads/elearning/' . $basename;
                $mime = mime_content_type($dest) ?: null;
                $size = filesize($dest) ?: null;
                $checksum = hash_file('sha256', $dest);
            }
        }
    }

    if (!$errors) {
        // Insert content
        $stmt = $db->prepare("INSERT INTO el_contents (module_id, content_type, title, description, mime_type, captions_url, created_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('issssss', $moduleId, $contentType, $title, $description, $mime, $captions, $staffId);
        $stmt->execute();
        $contentId = (int)$stmt->insert_id; $stmt->close();

        // Insert version
        $versionNo = 1;
        $stmt = $db->prepare("INSERT INTO el_content_versions (content_id, version_no, file_path, file_size, checksum_sha256, created_by) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('iisiss', $contentId, $versionNo, $filePath, $size, $checksum, $staffId);
        $stmt->execute();
        $versionId = (int)$stmt->insert_id; $stmt->close();

        // Set current version
        $stmt = $db->prepare("UPDATE el_contents SET current_version_id=? WHERE id=?");
        $stmt->bind_param('ii', $versionId, $contentId);
        $stmt->execute();
        $stmt->close();

        header('Location: module.php?course_code=' . urlencode($courseCode) . '&module_id=' . $moduleId);
        exit;
    }
}

require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

    <div class="elearning-shell">
    <div class="elearning-header">
        <div>
            <h1 class="elearning-title"><i class="fas fa-upload"></i> Upload Content</h1>
            <p class="elearning-subtitle">Share a file or external learning link with students in this module.</p>
        </div>
    </div>
    <?php elearningCourseTabs($courseCode, 'manage'); ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="elearning-form elearning-card">
        <input type="hidden" name="module_id" value="<?php echo (int)$moduleId; ?>">
        <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode); ?>">
        <div class="mb-3">
            <label class="form-label">Content Type</label>
            <select class="form-select" name="content_type">
                <option value="video">Video (MP4)</option>
                <option value="pdf">PDF</option>
                <option value="docx">DOCX</option>
                <option value="scorm">SCORM 1.2/2004 (ZIP)</option>
                <option value="link">External Link</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Title</label>
            <input class="form-control" type="text" name="title" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="4"></textarea>
        </div>
        <div class="mb-3" id="fileRow">
            <label class="form-label">File</label>
            <input class="form-control" type="file" name="file">
            <small class="elearning-muted">Allowed: MP4/WebM/MOV for video, PDF, DOC/DOCX, or ZIP for SCORM. Maximum 50 MB.</small>
        </div>
        <div class="mb-3 d-none" id="linkRow">
            <label class="form-label">External URL</label>
            <input class="form-control" type="url" name="external_url" placeholder="https://...">
        </div>
        <div class="elearning-actions">
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save</button>
            <a class="btn btn-secondary" href="module.php?course_code=<?php echo urlencode($courseCode); ?>&module_id=<?php echo (int)$moduleId; ?>">Cancel</a>
        </div>
    </form>
    </div>
</div>
</div>
<script>
document.querySelector('select[name="content_type"]').addEventListener('change', function(){
  var v=this.value; var fileRow=document.getElementById('fileRow'); var linkRow=document.getElementById('linkRow');
  if(v==='link'){ fileRow.classList.add('d-none'); linkRow.classList.remove('d-none'); }
  else { fileRow.classList.remove('d-none'); linkRow.classList.add('d-none'); }
});
</script>
</body>
</html>



