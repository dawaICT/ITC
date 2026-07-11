<?php
$page_title = 'Module';
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_files.php';
require_once __DIR__ . '/../includes/elearning_ui.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = $_GET['course_code'] ?? '';
$moduleId = isset($_GET['module_id']) ? (int)$_GET['module_id'] : 0;
if (!$staffId || !$courseCode || !$moduleId) { die('Unauthorized'); }

// Verify lecturer has access to this course
enforceLecturerCourseAccess($db, $staffId, $courseCode);
$courseOfferingId = getLecturerCourseOfferingId($db, $staffId, $courseCode);

// Fetch module
if (elearningTableHasCourseOffering($db, 'el_course_modules') && $courseOfferingId !== null) {
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
if (!$module) { die('Module not found'); }

// Fetch contents
$contents = [];
if ($stmt = $db->prepare("SELECT c.*, v.version_no, v.file_path, v.file_size FROM el_contents c LEFT JOIN el_content_versions v ON v.id = c.current_version_id WHERE c.module_id=? ORDER BY c.id DESC")) {
    $stmt->bind_param('i', $moduleId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) { $contents[] = $row; }
    $stmt->close();
}

require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

    <div class="elearning-shell">
        <div class="elearning-header">
            <div>
                <h1 class="elearning-title"><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($module['title']); ?></h1>
                <p class="elearning-subtitle">Course: <?php echo htmlspecialchars($courseCode); ?></p>
            </div>
            <div class="elearning-actions">
                <a class="btn btn-secondary" href="manage.php?course_code=<?php echo urlencode($courseCode); ?>">
                    <i class="fas fa-arrow-left"></i> Course
                </a>
                <a class="btn btn-primary" href="upload_content.php?module_id=<?php echo (int)$moduleId; ?>&course_code=<?php echo urlencode($courseCode); ?>">
                    <i class="fas fa-upload"></i> Upload Content
                </a>
            </div>
        </div>
        <?php elearningCourseTabs($courseCode, 'manage'); ?>

    <section class="elearning-panel">
        <div class="elearning-panel-header"><strong>Shared Files and Links</strong></div>
        <div class="elearning-panel-body">
            <div class="table-responsive">
            <table class="table table-hover align-middle elearning-table">
                <thead class="table-light">
                    <tr>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Version</th>
                        <th>Size</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contents as $c): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($c['content_type']); ?></td>
                            <td><?php echo htmlspecialchars($c['title']); ?></td>
                            <td><?php echo (int)($c['version_no'] ?? 1); ?></td>
                            <td><?php echo elearningFormatFileSize($c['file_size'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars((string)($c['updated_at'] ?? '')); ?></td>
                            <td>
                                <div class="elearning-actions">
                                    <?php if (!empty($c['file_path'])): ?>
                                        <a class="btn btn-sm btn-primary" href="download.php?content_id=<?php echo (int)$c['id']; ?>" target="_blank"><i class="fas fa-download"></i> Open</a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-secondary" href="versions.php?content_id=<?php echo (int)$c['id']; ?>&course_code=<?php echo urlencode($courseCode); ?>">Versions</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($contents) === 0): ?>
                        <tr><td colspan="6"><p class="elearning-empty">No shared files or links yet.</p></td></tr>
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



