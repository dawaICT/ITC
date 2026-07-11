<?php
$page_title = 'Content Versions';
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_files.php';
require_once __DIR__ . '/../includes/elearning_ui.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = trim($_GET['course_code'] ?? '');
$contentId = isset($_GET['content_id']) ? (int)$_GET['content_id'] : 0;
if (!$staffId || $courseCode === '' || $contentId <= 0) {
    die('Unauthorized');
}

$stmt = $db->prepare("SELECT c.*, m.course_code FROM el_contents c INNER JOIN el_course_modules m ON c.module_id = m.id WHERE c.id = ? LIMIT 1");
$stmt->bind_param('i', $contentId);
$stmt->execute();
$content = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$content || $content['course_code'] !== $courseCode) {
    die('Not found');
}

enforceLecturerCourseAccess($db, $staffId, $courseCode);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['set_current'])) {
    $versionId = (int)($_POST['version_id'] ?? 0);
    if ($versionId > 0) {
        $stmt = $db->prepare("UPDATE el_contents SET current_version_id = ? WHERE id = ?");
        $stmt->bind_param('ii', $versionId, $contentId);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: versions.php?content_id=' . $contentId . '&course_code=' . urlencode($courseCode));
    exit;
}

$versions = [];
if ($stmt = $db->prepare("SELECT * FROM el_content_versions WHERE content_id = ? ORDER BY version_no DESC")) {
    $stmt->bind_param('i', $contentId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $versions[] = $row;
    }
    $stmt->close();
}

require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

<div class="elearning-shell">
    <div class="elearning-header">
        <div>
            <h1 class="elearning-title"><i class="fas fa-history"></i> Versions</h1>
            <p class="elearning-subtitle">
                Course: <?php echo htmlspecialchars($courseCode); ?> &middot;
                Content: <?php echo htmlspecialchars($content['title'] ?? ''); ?>
            </p>
        </div>
        <div class="elearning-actions">
            <a class="btn btn-primary" href="upload_version.php?content_id=<?php echo (int)$contentId; ?>&course_code=<?php echo urlencode($courseCode); ?>">
                <i class="fas fa-upload"></i> Upload New Version
            </a>
        </div>
    </div>

    <?php elearningCourseTabs($courseCode, 'manage'); ?>

    <section class="elearning-panel">
        <div class="elearning-panel-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle elearning-table">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>File</th>
                            <th>Size</th>
                            <th>Checksum</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($versions as $v): ?>
                            <tr>
                                <td>
                                    <?php echo (int)$v['version_no']; ?>
                                    <?php if ((int)$content['current_version_id'] === (int)$v['id']): ?>
                                        <span class="badge bg-success">current</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(basename((string)$v['file_path'])); ?></td>
                                <td><?php echo elearningFormatFileSize($v['file_size'] ?? 0); ?></td>
                                <td><code><?php echo htmlspecialchars((string)$v['checksum_sha256']); ?></code></td>
                                <td><?php echo htmlspecialchars((string)$v['created_at']); ?></td>
                                <td>
                                    <div class="elearning-actions">
                                        <?php if (!empty($v['file_path'])): ?>
                                            <a class="btn btn-sm btn-secondary" href="download.php?version_id=<?php echo (int)$v['id']; ?>" target="_blank">Download</a>
                                        <?php endif; ?>
                                        <?php if ((int)$content['current_version_id'] !== (int)$v['id']): ?>
                                            <form method="post" class="elearning-inline-form">
                                                <input type="hidden" name="version_id" value="<?php echo (int)$v['id']; ?>">
                                                <button class="btn btn-sm btn-warning" name="set_current" value="1">Set Current</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($versions) === 0): ?>
                            <tr><td colspan="6"><p class="elearning-empty">No versions found.</p></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
</div>
</div>
</body>
</html>
