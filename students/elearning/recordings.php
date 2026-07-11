<?php
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
require_once __DIR__ . '/../../includes/elearning_recordings.php';

$studentId = (string)($_SESSION['Sid'] ?? '');
if ($studentId === '') {
    die('Unauthorized');
}

$requestedCourse = trim((string)($_GET['course_code'] ?? ''));
$registeredCourses = getStudentEnrolledCourses($db, $studentId);
$registeredCourses = array_values(array_unique(array_filter(array_map(static fn($code) => trim((string)$code), $registeredCourses))));
$registeredOfferingIds = getStudentCourseOfferingIds($db, $studentId);

if ($requestedCourse !== '') {
    enforceStudentCourseAccess($db, $studentId, $requestedCourse);
    $recordings = elearningGetPublishedRecordingsForCourse($db, $requestedCourse, getStudentCourseOfferingIds($db, $studentId, $requestedCourse));
} else {
    $recordings = elearningGetPublishedRecordingsForCourses($db, $registeredCourses, $registeredOfferingIds);
}

$courseNames = [];
if ($registeredCourses) {
    $placeholders = implode(',', array_fill(0, count($registeredCourses), '?'));
    $types = str_repeat('s', count($registeredCourses));
    if ($stmt = $db->prepare("SELECT course_code, course_name FROM courses WHERE course_code IN ($placeholders)")) {
        $stmt->bind_param($types, ...$registeredCourses);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $courseNames[(string)$row['course_code']] = (string)$row['course_name'];
        }
        $stmt->close();
    }
}

$baseHref = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/wucportal/students/elearning/recordings.php'))), '/') . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recorded Videos</title>
    <base href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/css/elearning-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .recording-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
        .recording-card { background: #fff; border: 1px solid #e5eaf2; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06); }
        .recording-frame { width: 100%; aspect-ratio: 16 / 9; border: 0; background: #0f172a; display: block; }
        .recording-body { padding: 16px; }
        .recording-title { margin: 0 0 6px; font-size: 1rem; color: #0f172a; }
        .recording-meta { color: #64748b; font-size: 0.78rem; display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
        .recording-description { color: #475569; font-size: 0.84rem; margin-bottom: 12px; }
        .recording-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .format-badge { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 5px 9px; background: #eef2ff; color: #3730a3; font-size: 0.74rem; font-weight: 700; }
    </style>

<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="content-wrapper">
    <section class="elearning-shell">
        <div class="elearning-header">
            <div>
                <p class="elearning-kicker">Student eLearning</p>
                <h2><i class="fas fa-record-vinyl me-2"></i>Recorded Videos</h2>
                <p><?php echo $requestedCourse !== '' ? 'Course: ' . htmlspecialchars($requestedCourse, ENT_QUOTES, 'UTF-8') : 'Recorded classes from your registered courses.'; ?></p>
            </div>
            <div class="elearning-actions">
                <a class="btn btn-outline-secondary" href="elearning/index.php">
                    <i class="fas fa-arrow-left me-1"></i>My Courses
                </a>
            </div>
        </div>

        <?php if (!$recordings): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No recorded videos are available for <?php echo $requestedCourse !== '' ? 'this course' : 'your registered courses'; ?>.
            </div>
        <?php else: ?>
            <div class="recording-grid">
                <?php foreach ($recordings as $recording): ?>
                    <?php
                    $courseCode = (string)$recording['course_code'];
                    $previewUrl = elearningGoogleDrivePreviewUrl($recording['drive_file_id'] ?? null, (string)$recording['drive_url']);
                    $recordedAt = !empty($recording['recorded_at']) ? strtotime((string)$recording['recorded_at']) : strtotime((string)$recording['created_at']);
                    ?>
                    <article class="recording-card">
                        <iframe class="recording-frame"
                                src="<?php echo htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                allow="autoplay"
                                allowfullscreen
                                loading="lazy"
                                title="<?php echo htmlspecialchars((string)$recording['title'], ENT_QUOTES, 'UTF-8'); ?>"></iframe>
                        <div class="recording-body">
                            <h3 class="recording-title"><?php echo htmlspecialchars((string)$recording['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                            <div class="recording-meta">
                                <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span><i class="fas fa-calendar"></i> <?php echo htmlspecialchars(date('M d, Y', $recordedAt), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if (!empty($recording['duration_minutes'])): ?>
                                    <span><i class="fas fa-clock"></i> <?php echo (int)$recording['duration_minutes']; ?> min</span>
                                <?php endif; ?>
                                <span><i class="fas fa-film"></i> <?php echo htmlspecialchars(elearningVideoFormatLabel($recording['video_format'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <?php if (!empty($courseNames[$courseCode])): ?>
                                <p class="recording-description"><?php echo htmlspecialchars($courseNames[$courseCode], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($recording['description'])): ?>
                                <p class="recording-description"><?php echo htmlspecialchars((string)$recording['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <div class="recording-actions">
                                <span class="format-badge"><i class="fas fa-play-circle"></i> <?php echo htmlspecialchars(elearningVideoFormatLabel($recording['video_format'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                <a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars((string)$recording['drive_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="fab fa-google-drive"></i> Open in Drive
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
</body>
</html>
