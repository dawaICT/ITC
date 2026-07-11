<?php
error_reporting(0);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';

$sid = $_SESSION['Sid'] ?? null;
$courseCode = trim((string)($_GET['course_code'] ?? ''));
if (!$sid) {
    http_response_code(401);
    exit('Unauthorized');
}

$baseHref = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/wucportal/students/elearning/progress.php'))), '/') . '/';
$isCourseDetail = $courseCode !== '';

if ($isCourseDetail) {
    enforceStudentCourseAccess($db, $sid, $courseCode);
    $courseCodes = [$courseCode];
} else {
    $courseCodes = getStudentEnrolledCourses($db, $sid);
    if (!is_array($courseCodes)) {
        $courseCodes = [];
    }
    $courseCodes = array_values(array_unique(array_filter(array_map(
        fn($value) => trim((string)$value),
        $courseCodes
    ))));
}

function studentElearningCourseNames(mysqli $db, array $courseCodes): array
{
    if (!$courseCodes || !elearningTableExists($db, 'courses')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
    $types = str_repeat('s', count($courseCodes));
    $names = [];
    if ($stmt = $db->prepare("SELECT course_code, course_name FROM courses WHERE course_code IN ({$placeholders})")) {
        $stmt->bind_param($types, ...$courseCodes);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $code = trim((string)($row['course_code'] ?? ''));
            if ($code !== '') {
                $names[$code] = (string)($row['course_name'] ?? $code);
            }
        }
        $stmt->close();
    }
    return $names;
}

function studentElearningProgressStats(mysqli $db, string $sid, string $courseCode): array
{
    $stats = [
        'course_code' => $courseCode,
        'stored_percent' => null,
        'content_view_count' => 0,
        'total_content' => 0,
        'progress_percent' => 0,
        'quiz_attempts' => [],
        'assignment_grades' => [],
    ];

    if (elearningTableExists($db, 'el_course_progress')) {
        if ($stmt = $db->prepare("SELECT progress_percent FROM el_course_progress WHERE course_code=? AND Sid=? LIMIT 1")) {
            $stmt->bind_param('ss', $courseCode, $sid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && $row['progress_percent'] !== null) {
                $stats['stored_percent'] = (float)$row['progress_percent'];
            }
            $stmt->close();
        }
    }

    if (elearningTableExists($db, 'el_analytics_events')) {
        if ($stmt = $db->prepare("SELECT COUNT(DISTINCT content_id) AS cnt FROM el_analytics_events WHERE course_code=? AND actor_id=? AND event_type='content_view' AND content_id IS NOT NULL")) {
            $stmt->bind_param('ss', $courseCode, $sid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stats['content_view_count'] = (int)($row['cnt'] ?? 0);
            $stmt->close();
        }
    }

    if (elearningTableExists($db, 'el_contents') && elearningTableExists($db, 'el_course_modules')) {
        if ($stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM el_contents c INNER JOIN el_course_modules m ON c.module_id=m.id WHERE m.course_code=?")) {
            $stmt->bind_param('s', $courseCode);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stats['total_content'] = (int)($row['cnt'] ?? 0);
            $stmt->close();
        }
    }

    if ($stats['total_content'] > 0) {
        $stats['progress_percent'] = min(100, (int)round(($stats['content_view_count'] / $stats['total_content']) * 100));
    } elseif ($stats['stored_percent'] !== null) {
        $stats['progress_percent'] = max(0, min(100, (int)round($stats['stored_percent'])));
    }

    if (elearningTableExists($db, 'el_attempts') && elearningTableExists($db, 'el_quizzes')) {
        if ($stmt = $db->prepare("SELECT q.title, a.score, a.submitted_at FROM el_attempts a INNER JOIN el_quizzes q ON a.quiz_id=q.id WHERE a.Sid=? AND q.course_code=? ORDER BY a.submitted_at DESC")) {
            $stmt->bind_param('ss', $sid, $courseCode);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $stats['quiz_attempts'][] = $row;
            }
            $stmt->close();
        }
    }

    if (elearningTableExists($db, 'el_grades') && elearningTableExists($db, 'el_assignments')) {
        if ($stmt = $db->prepare("SELECT a.title, g.total_points, g.graded_at FROM el_grades g INNER JOIN el_assignments a ON g.assignment_id=a.id WHERE g.Sid=? AND a.course_code=? ORDER BY g.graded_at DESC")) {
            $stmt->bind_param('ss', $sid, $courseCode);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $stats['assignment_grades'][] = $row;
            }
            $stmt->close();
        }
    }

    return $stats;
}

$courseNames = studentElearningCourseNames($db, $courseCodes);
$courseProgress = [];
foreach ($courseCodes as $code) {
    $courseProgress[$code] = studentElearningProgressStats($db, $sid, $code);
}

$detailStats = $isCourseDetail ? ($courseProgress[$courseCode] ?? null) : null;
$detailCourseName = $isCourseDetail ? ($courseNames[$courseCode] ?? $courseCode) : 'Learning Progress';
$overallPercent = 0;
if (!$isCourseDetail && $courseProgress) {
    $overallPercent = (int)round(array_sum(array_map(
        fn($item) => (int)($item['progress_percent'] ?? 0),
        $courseProgress
    )) / count($courseProgress));
}

function studentElearningFormatDate(?string $value): string
{
    $time = $value ? strtotime($value) : false;
    return $time ? date('M j, Y', $time) : 'Not dated';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isCourseDetail ? 'My Progress - ' . htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8') : 'Learning Progress'; ?></title>
    <base href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/css/elearning-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .progress-summary {
            background: #ffffff;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
        }
        .progress-summary h2 {
            margin: 0;
            color: #0f172a;
            font-size: 1.35rem;
        }
        .progress-meter {
            height: 10px;
            border-radius: 999px;
            overflow: hidden;
            background: #e5eaf2;
        }
        .progress-meter span {
            display: block;
            height: 100%;
            background: #1b2a4a;
        }
        .progress-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .progress-stat-card,
        .progress-course-card {
            background: #ffffff;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.05);
        }
        .progress-stat-card strong {
            display: block;
            color: #0f172a;
            font-size: 1.45rem;
            line-height: 1.2;
        }
        .progress-stat-card span,
        .progress-course-card small {
            color: #64748b;
        }
        .grade-item {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.85rem 0;
            border-bottom: 1px solid #edf2f7;
        }
        .grade-item:last-child {
            border-bottom: 0;
        }
        .score-badge {
            align-self: flex-start;
            background: #e8f7ee;
            color: #177245;
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            font-weight: 700;
            white-space: nowrap;
        }
        @media (max-width: 640px) {
            .grade-item {
                flex-direction: column;
            }
        }
    </style>

<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main class="content-wrapper">
    <section class="elearning-shell">
        <div class="elearning-header">
            <div>
                <p class="elearning-kicker">Student eLearning</p>
                <h2><i class="fas fa-chart-line me-2"></i><?php echo $isCourseDetail ? 'My Progress' : 'Learning Progress'; ?></h2>
                <p><?php echo htmlspecialchars($isCourseDetail ? $detailCourseName . ' (' . $courseCode . ')' : 'Progress across your enrolled eLearning courses.', ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="elearning-actions">
                <a class="btn btn-outline-secondary" href="<?php echo $isCourseDetail ? 'elearning/course.php?course_code=' . urlencode($courseCode) : 'elearning/index.php'; ?>">
                    <i class="fas fa-arrow-left me-1"></i><?php echo $isCourseDetail ? 'Back to Course' : 'My Courses'; ?>
                </a>
            </div>
        </div>

        <?php if (!$courseProgress): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No eLearning course progress is available yet.
            </div>
        <?php elseif (!$isCourseDetail): ?>
            <section class="progress-summary">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2>Overall Progress</h2>
                        <p class="text-muted mb-0"><?php echo count($courseProgress); ?> enrolled course<?php echo count($courseProgress) === 1 ? '' : 's'; ?></p>
                    </div>
                    <strong><?php echo $overallPercent; ?>%</strong>
                </div>
                <div class="progress-meter"><span style="width: <?php echo $overallPercent; ?>%"></span></div>
            </section>

            <div class="progress-card-grid">
                <?php foreach ($courseProgress as $code => $stats): ?>
                    <?php $percent = (int)($stats['progress_percent'] ?? 0); ?>
                    <article class="progress-course-card">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <strong><?php echo htmlspecialchars($courseNames[$code] ?? $code, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small class="d-block"><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                            <span class="score-badge"><?php echo $percent; ?>%</span>
                        </div>
                        <div class="progress-meter mb-3"><span style="width: <?php echo $percent; ?>%"></span></div>
                        <div class="d-flex justify-content-between text-muted small mb-3">
                            <span><?php echo (int)$stats['content_view_count']; ?>/<?php echo (int)$stats['total_content']; ?> viewed</span>
                            <span><?php echo count($stats['quiz_attempts']); ?> quizzes</span>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="elearning/progress.php?course_code=<?php echo urlencode($code); ?>">
                            View Details
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php
                $progressPercent = (int)($detailStats['progress_percent'] ?? 0);
                $quizAttempts = $detailStats['quiz_attempts'] ?? [];
                $assignmentGrades = $detailStats['assignment_grades'] ?? [];
            ?>
            <section class="progress-summary">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2><?php echo htmlspecialchars($detailCourseName, ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <strong><?php echo $progressPercent; ?>%</strong>
                </div>
                <div class="progress-meter"><span style="width: <?php echo $progressPercent; ?>%"></span></div>
            </section>

            <div class="progress-card-grid">
                <div class="progress-stat-card">
                    <strong><?php echo (int)$detailStats['content_view_count']; ?>/<?php echo (int)$detailStats['total_content']; ?></strong>
                    <span>Content Viewed</span>
                </div>
                <div class="progress-stat-card">
                    <strong><?php echo count($quizAttempts); ?></strong>
                    <span>Quiz Attempts</span>
                </div>
                <div class="progress-stat-card">
                    <strong><?php echo count($assignmentGrades); ?></strong>
                    <span>Assignments Graded</span>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <section class="elearning-card">
                        <div class="elearning-panel-header">
                            <h5 class="mb-0"><i class="fas fa-pen me-2"></i>Quiz Scores</h5>
                        </div>
                        <div class="elearning-panel-body">
                            <?php if ($quizAttempts): ?>
                                <?php foreach ($quizAttempts as $attempt): ?>
                                    <div class="grade-item">
                                        <div>
                                            <strong><?php echo htmlspecialchars((string)$attempt['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <small class="d-block text-muted"><?php echo studentElearningFormatDate($attempt['submitted_at'] ?? null); ?></small>
                                        </div>
                                        <span class="score-badge"><?php echo round((float)$attempt['score'], 1); ?>%</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted mb-0">No quiz attempts yet.</p>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
                <div class="col-lg-6">
                    <section class="elearning-card">
                        <div class="elearning-panel-header">
                            <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Assignment Grades</h5>
                        </div>
                        <div class="elearning-panel-body">
                            <?php if ($assignmentGrades): ?>
                                <?php foreach ($assignmentGrades as $grade): ?>
                                    <div class="grade-item">
                                        <div>
                                            <strong><?php echo htmlspecialchars((string)$grade['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <small class="d-block text-muted"><?php echo studentElearningFormatDate($grade['graded_at'] ?? null); ?></small>
                                        </div>
                                        <span class="score-badge"><?php echo round((float)$grade['total_points'], 1); ?> pts</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted mb-0">No assignments graded yet.</p>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>
        <?php endif; ?>
    </section>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
</body>
</html>
