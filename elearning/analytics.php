<?php
$page_title = 'Analytics';
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_ui.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = trim($_GET['course_code'] ?? '');
if (!$staffId || $courseCode === '') {
    die('Unauthorized');
}

enforceLecturerCourseAccess($db, $staffId, $courseCode);

$metrics = [];
$totalEvents = 0;
$activeStudents = 0;
$progressAverage = null;

if (elearningTableExists($db, 'el_analytics_events')) {
    $sql = "SELECT event_type, COUNT(*) AS cnt
            FROM el_analytics_events
            WHERE course_code = ?
              AND COALESCE(created_at, CURRENT_TIMESTAMP) >= DATE_SUB(NOW(), INTERVAL 14 DAY)
            GROUP BY event_type
            ORDER BY cnt DESC";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['cnt'] = (int)($row['cnt'] ?? 0);
            $totalEvents += $row['cnt'];
            $metrics[] = $row;
        }
        $stmt->close();
    }

    $sql = "SELECT COUNT(DISTINCT actor_id) AS active_students
            FROM el_analytics_events
            WHERE course_code = ?
              AND actor_type = 'student'
              AND actor_id <> ''
              AND COALESCE(created_at, CURRENT_TIMESTAMP) >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $activeStudents = (int)($row['active_students'] ?? 0);
        $stmt->close();
    }
}

$enrolledStudents = [];
if (elearningTableExists($db, 'course_registration')) {
    if ($stmt = $db->prepare("SELECT DISTINCT Sid FROM course_registration WHERE course_code = ? ORDER BY Sid")) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['Sid'])) {
                $enrolledStudents[] = (string)$row['Sid'];
            }
        }
        $stmt->close();
    }
}

if (empty($enrolledStudents) && elearningTableExists($db, 'el_course_progress')) {
    if ($stmt = $db->prepare("SELECT DISTINCT Sid FROM el_course_progress WHERE course_code = ? ORDER BY Sid")) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['Sid'])) {
                $enrolledStudents[] = (string)$row['Sid'];
            }
        }
        $stmt->close();
    }
}

$atRisk = [];
if (!empty($enrolledStudents) && elearningTableExists($db, 'el_analytics_events')) {
    $recentStudents = [];
    if ($stmt = $db->prepare("SELECT DISTINCT actor_id FROM el_analytics_events WHERE course_code = ? AND actor_type = 'student' AND actor_id <> '' AND COALESCE(created_at, CURRENT_TIMESTAMP) >= DATE_SUB(NOW(), INTERVAL 7 DAY)")) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $recentStudents[(string)$row['actor_id']] = true;
        }
        $stmt->close();
    }
    foreach ($enrolledStudents as $sid) {
        if (empty($recentStudents[$sid])) {
            $atRisk[] = $sid;
        }
    }
}

if (elearningTableExists($db, 'el_course_progress')) {
    if ($stmt = $db->prepare("SELECT AVG(progress_percent) AS avg_progress FROM el_course_progress WHERE course_code = ?")) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && $row['avg_progress'] !== null) {
            $progressAverage = round((float)$row['avg_progress'], 1);
        }
        $stmt->close();
    }
}

require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

<div class="elearning-shell">
    <div class="elearning-header">
        <div>
            <h1 class="elearning-title"><i class="fas fa-chart-line"></i> Analytics</h1>
            <p class="elearning-subtitle">Course: <?php echo htmlspecialchars($courseCode); ?></p>
        </div>
    </div>
    <?php elearningCourseTabs($courseCode, 'analytics'); ?>

    <div class="elearning-stat-grid">
        <section class="elearning-stat-card">
            <span class="elearning-stat-label">Events, 14 Days</span>
            <strong><?php echo number_format($totalEvents); ?></strong>
        </section>
        <section class="elearning-stat-card">
            <span class="elearning-stat-label">Active Students, 7 Days</span>
            <strong><?php echo number_format($activeStudents); ?></strong>
        </section>
        <section class="elearning-stat-card">
            <span class="elearning-stat-label">Enrolled Students</span>
            <strong><?php echo number_format(count($enrolledStudents)); ?></strong>
        </section>
        <section class="elearning-stat-card">
            <span class="elearning-stat-label">Average Progress</span>
            <strong><?php echo $progressAverage === null ? 'n/a' : htmlspecialchars((string)$progressAverage) . '%'; ?></strong>
        </section>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <section class="elearning-panel h-100">
                <div class="elearning-panel-header"><strong>Engagement by Event</strong><span class="elearning-muted">Last 14 days</span></div>
                <div class="elearning-panel-body">
                    <?php if (empty($metrics)): ?>
                        <p class="elearning-empty">No analytics events have been recorded yet.</p>
                    <?php else: ?>
                        <ul class="elearning-analytics-list">
                            <?php foreach ($metrics as $metric): ?>
                                <?php $percent = $totalEvents > 0 ? min(100, round(((int)$metric['cnt'] / $totalEvents) * 100)) : 0; ?>
                                <li>
                                    <div class="elearning-analytics-row">
                                        <span><?php echo htmlspecialchars((string)$metric['event_type']); ?></span>
                                        <strong><?php echo number_format((int)$metric['cnt']); ?></strong>
                                    </div>
                                    <progress class="elearning-progress" max="100" value="<?php echo (int)$percent; ?>"><?php echo (int)$percent; ?>%</progress>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-md-6">
            <section class="elearning-panel h-100">
                <div class="elearning-panel-header"><strong>At-risk Students</strong><span class="elearning-muted">No activity in 7 days</span></div>
                <div class="elearning-panel-body">
                    <?php if (empty($atRisk)): ?>
                        <p class="elearning-empty">No students are currently flagged.</p>
                    <?php else: ?>
                        <ul class="elearning-chip-list">
                            <?php foreach ($atRisk as $sid): ?>
                                <li><?php echo htmlspecialchars($sid); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
</div>
</div>
</body>
</html>
