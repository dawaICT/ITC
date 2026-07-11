<?php
$page_title = 'eLearning - My Courses';
require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';

// Verify database connection
if (!isset($db) || $db->connect_error) {
    die('<div style="padding: 40px; text-align: center; font-family: Arial;">
        <h3 style="color: #dc3545;">Database Error</h3>
        <p>Unable to connect to database. Please try again later.</p>
        <a href="../lecturers/index.php" style="color: #2196F3;">← Back to Dashboard</a>
    </div>');
}

$staffId = $_SESSION['staff_id'] ?? null;
if (!$staffId) {
    header('Location: ../staff_login.php');
    exit;
}
$courses = [];
$title = "My Courses";

// ALL lecturers (including admins) only see their assigned courses on this page.
// Admins who need full course access use admin/elearning/ instead.
// Use centralized helper that resolves names from courses + program_courses tables
$courseDetails = getLecturerCourseDetails($db, $staffId);
if (!is_array($courseDetails)) {
    $courseDetails = [];
}

if (!empty($courseDetails)) {
    // Also try to fetch descriptions from the courses table when the installed schema has one.
    $codes = array_column($courseDetails, 'course_code');
    $descriptionMap = [];
    if (!empty($codes)) {
        $courseCodeCol = elearningDetectColumn($db, 'courses', ['course_code', 'code']);
        $descriptionCol = elearningDetectColumn($db, 'courses', ['syllabus', 'description', 'course_description', 'summary']);

        if ($courseCodeCol !== null && $descriptionCol !== null) {
            $placeholders = implode(',', array_fill(0, count($codes), '?'));
            $types = str_repeat('s', count($codes));
            $stmt = $db->prepare("SELECT `{$courseCodeCol}` AS course_code, `{$descriptionCol}` AS description FROM `courses` WHERE `{$courseCodeCol}` IN ($placeholders)");
            if ($stmt) {
                $stmt->bind_param($types, ...$codes);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $descriptionMap[$row['course_code']] = $row['description'] ?? '';
                }
                $stmt->close();
            } else {
                error_log('Course description prepare failed: ' . $db->error);
            }
        }
    }

    foreach ($courseDetails as $cd) {
        $courses[] = [
            'course_code' => $cd['course_code'],
            'course_title' => $cd['course_name'],
            'description' => $descriptionMap[$cd['course_code']] ?? ''
        ];
    }
}
require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

    <div class="elearning-shell elearning-course-index">
        <div class="elearning-header">
            <div>
                <h1 class="elearning-title"><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($title); ?></h1>
                <p class="elearning-subtitle">Manage course modules, shared files, live sessions, assessments, and discussions.</p>
            </div>
        </div>
    
    <?php if (empty($courses)): ?>
        <div class="alert alert-info">
            <strong>No courses found.</strong>
            <p>You haven't been assigned any courses yet. Contact your department head or administrator.</p>
        </div>
    <?php else: ?>
        <div class="elearning-grid">
            <?php foreach ($courses as $course): ?>
                <article class="elearning-card">
                    <div class="elearning-card-main">
                        <div class="elearning-course-icon" aria-hidden="true">
                            <i class="fas fa-book"></i>
                        </div>
                        <div>
                            <h2 class="elearning-course-title"><?php echo htmlspecialchars($course['course_code']); ?></h2>
                            <p class="elearning-course-name"><?php echo htmlspecialchars($course['course_title'] ?? 'Untitled'); ?></p>
                        </div>
                    </div>
                    <p class="elearning-course-description">
                        <?php echo htmlspecialchars(!empty($course['description']) ? $course['description'] : 'No course description has been added yet.'); ?>
                    </p>
                    <div class="elearning-actions">
                        <a href="manage.php?course_code=<?php echo urlencode($course['course_code']); ?>" class="btn btn-primary">
                            <i class="fas fa-cog"></i> Manage Course
                        </a>
                        <a href="sessions.php?course_code=<?php echo urlencode($course['course_code']); ?>" class="btn btn-secondary">
                            <i class="fas fa-video"></i> Live Sessions
                        </a>
                        <a href="recordings.php?course_code=<?php echo urlencode($course['course_code']); ?>" class="btn btn-secondary">
                            <i class="fas fa-record-vinyl"></i> Recordings
                        </a>
                        <a href="assessments.php?course_code=<?php echo urlencode($course['course_code']); ?>" class="btn btn-secondary">
                            <i class="fas fa-clipboard-check"></i> Assessments
                        </a>
                        <a href="student_progress.php?course_code=<?php echo urlencode($course['course_code']); ?>" class="btn btn-secondary">
                            <i class="fas fa-user-check"></i> Student Progress
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <div class="elearning-footer-actions">
        <a href="../lecturers/index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
    </div>
</div>
</div>
</body>
</html>
