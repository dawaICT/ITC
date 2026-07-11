<?php
$page_title = 'Student Progress';

require_once __DIR__ . '/../lecturers/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/elearning_ui.php';

$staffId = $_SESSION['staff_id'] ?? null;
$courseCode = trim((string)($_GET['course_code'] ?? ''));
if (!$staffId) {
    header('Location: ../staff_login.php');
    exit;
}

if ($courseCode !== '') {
    enforceLecturerCourseAccess($db, $staffId, $courseCode);
}

function lecturerProgressCourseName(mysqli $db, string $courseCode): string
{
    foreach (['courses', 'program_courses'] as $tableName) {
        if (!elearningTableExists($db, $tableName)) {
            continue;
        }

        $courseCol = elearningDetectColumn($db, $tableName, ['course_code', 'code']);
        $nameCol = elearningDetectColumn($db, $tableName, ['course_name', 'name', 'title']);
        if ($courseCol === null || $nameCol === null) {
            continue;
        }

        $sql = "SELECT `{$nameCol}` AS course_name FROM `{$tableName}` WHERE UPPER(TRIM(`{$courseCol}`)) = UPPER(TRIM(?)) AND `{$nameCol}` IS NOT NULL AND TRIM(`{$nameCol}`) <> '' LIMIT 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $courseCode);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && trim((string)$row['course_name']) !== '') {
                return (string)$row['course_name'];
            }
        }
    }

    return $courseCode;
}

function lecturerProgressStudentIds(mysqli $db, string $courseCode): array
{
    $sources = ['course_registration', 'registered_courses', 'student_courses'];
    foreach ($sources as $tableName) {
        if (!elearningTableExists($db, $tableName)) {
            continue;
        }

        $sidCol = elearningDetectColumn($db, $tableName, ['Sid', 'SID', 'student_id', 'student']);
        $courseCol = elearningDetectColumn($db, $tableName, ['course_code', 'code', 'course']);
        if ($sidCol === null || $courseCol === null) {
            continue;
        }

        $statusCol = elearningDetectColumn($db, $tableName, ['status', 'state']);
        $activeSql = elearningActiveStatusSql($statusCol);
        $isActiveCol = elearningDetectColumn($db, $tableName, ['is_active', 'active']);
        if ($isActiveCol !== null) {
            $activeSql .= " AND (`{$isActiveCol}` IS NULL OR `{$isActiveCol}` = 1)";
        }

        $sql = "SELECT DISTINCT TRIM(`{$sidCol}`) AS Sid FROM `{$tableName}` WHERE UPPER(TRIM(`{$courseCol}`)) = UPPER(TRIM(?)) AND `{$sidCol}` IS NOT NULL AND TRIM(`{$sidCol}`) <> ''{$activeSql} ORDER BY Sid";
        $ids = [];
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $courseCode);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (string)$row['Sid'];
            }
            $stmt->close();
        }

        if ($ids) {
            return array_values(array_unique($ids));
        }
    }

    if (elearningTableExists($db, 'el_course_progress')) {
        $ids = [];
        if ($stmt = $db->prepare("SELECT DISTINCT TRIM(Sid) AS Sid FROM el_course_progress WHERE UPPER(TRIM(course_code)) = UPPER(TRIM(?)) AND Sid IS NOT NULL AND TRIM(Sid) <> '' ORDER BY Sid")) {
            $stmt->bind_param('s', $courseCode);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (string)$row['Sid'];
            }
            $stmt->close();
        }
        if ($ids) {
            return array_values(array_unique($ids));
        }
    }

    if (elearningTableExists($db, 'el_analytics_events')) {
        $ids = [];
        if ($stmt = $db->prepare("SELECT DISTINCT TRIM(actor_id) AS Sid FROM el_analytics_events WHERE UPPER(TRIM(course_code)) = UPPER(TRIM(?)) AND actor_type = 'student' AND actor_id IS NOT NULL AND TRIM(actor_id) <> '' ORDER BY Sid")) {
            $stmt->bind_param('s', $courseCode);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (string)$row['Sid'];
            }
            $stmt->close();
        }
        return array_values(array_unique($ids));
    }

    return [];
}

function lecturerProgressStudentProfiles(mysqli $db, array $studentIds): array
{
    $profiles = [];
    foreach ($studentIds as $sid) {
        $profiles[$sid] = [
            'Sid' => $sid,
            'name' => $sid,
            'email' => '',
        ];
    }

    if (!$studentIds || !elearningTableExists($db, 'students')) {
        return $profiles;
    }

    $sidCol = elearningDetectColumn($db, 'students', ['SID', 'Sid', 'student_id']);
    if ($sidCol === null) {
        return $profiles;
    }

    $firstNameCol = elearningDetectColumn($db, 'students', ['Fname', 'first_name', 'firstname', 'given_name']);
    $lastNameCol = elearningDetectColumn($db, 'students', ['Lname', 'last_name', 'lastname', 'surname']);
    $emailCol = elearningDetectColumn($db, 'students', ['email', 'student_email']);
    $nameExpr = "TRIM(`{$sidCol}`)";
    if ($firstNameCol !== null && $lastNameCol !== null) {
        $nameExpr = "TRIM(CONCAT(COALESCE(`{$firstNameCol}`, ''), ' ', COALESCE(`{$lastNameCol}`, '')))";
    } elseif ($firstNameCol !== null) {
        $nameExpr = "TRIM(COALESCE(`{$firstNameCol}`, ''))";
    }
    $emailExpr = $emailCol !== null ? "`{$emailCol}`" : "''";

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $types = str_repeat('s', count($studentIds));
    $sql = "SELECT `{$sidCol}` AS Sid, {$nameExpr} AS student_name, {$emailExpr} AS email FROM students WHERE `{$sidCol}` IN ({$placeholders})";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$studentIds);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid = (string)$row['Sid'];
            $name = trim((string)($row['student_name'] ?? ''));
            $profiles[$sid] = [
                'Sid' => $sid,
                'name' => $name !== '' ? $name : $sid,
                'email' => (string)($row['email'] ?? ''),
            ];
        }
        $stmt->close();
    }

    return $profiles;
}

function lecturerProgressContentTotal(mysqli $db, string $courseCode): int
{
    if (!elearningTableExists($db, 'el_contents') || !elearningTableExists($db, 'el_course_modules')) {
        return 0;
    }

    if ($stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM el_contents c INNER JOIN el_course_modules m ON c.module_id = m.id WHERE UPPER(TRIM(m.course_code)) = UPPER(TRIM(?))")) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['cnt'] ?? 0);
    }

    return 0;
}

function lecturerProgressStoredPercents(mysqli $db, string $courseCode): array
{
    if (!elearningTableExists($db, 'el_course_progress')) {
        return [];
    }

    $items = [];
    if ($stmt = $db->prepare("SELECT Sid, progress_percent, updated_at FROM el_course_progress WHERE UPPER(TRIM(course_code)) = UPPER(TRIM(?))")) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid = (string)$row['Sid'];
            $items[$sid] = [
                'percent' => (float)($row['progress_percent'] ?? 0),
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }
        $stmt->close();
    }

    return $items;
}

function lecturerProgressContentViews(mysqli $db, string $courseCode): array
{
    if (!elearningTableExists($db, 'el_analytics_events')) {
        return [];
    }

    $items = [];
    $sql = "SELECT actor_id AS Sid, COUNT(DISTINCT content_id) AS viewed_count, MAX(created_at) AS last_activity
            FROM el_analytics_events
            WHERE UPPER(TRIM(course_code)) = UPPER(TRIM(?))
              AND actor_type = 'student'
              AND actor_id <> ''
              AND content_id IS NOT NULL
              AND (event_type = 'content_view' OR event_type = 'content_open' OR event_type = 'view')
            GROUP BY actor_id";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[(string)$row['Sid']] = [
                'viewed_count' => (int)($row['viewed_count'] ?? 0),
                'last_activity' => $row['last_activity'] ?? null,
            ];
        }
        $stmt->close();
    }

    return $items;
}

function lecturerProgressQuizStats(mysqli $db, string $courseCode): array
{
    if (!elearningTableExists($db, 'el_attempts') || !elearningTableExists($db, 'el_quizzes')) {
        return [];
    }

    $items = [];
    $sql = "SELECT a.Sid, COUNT(*) AS attempts, AVG(a.score) AS average_score, MAX(COALESCE(a.submitted_at, a.started_at)) AS last_quiz
            FROM el_attempts a
            INNER JOIN el_quizzes q ON a.quiz_id = q.id
            WHERE UPPER(TRIM(q.course_code)) = UPPER(TRIM(?))
            GROUP BY a.Sid";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[(string)$row['Sid']] = [
                'attempts' => (int)($row['attempts'] ?? 0),
                'average_score' => $row['average_score'] === null ? null : round((float)$row['average_score'], 1),
                'last_quiz' => $row['last_quiz'] ?? null,
            ];
        }
        $stmt->close();
    }

    return $items;
}

function lecturerProgressAssignmentStats(mysqli $db, string $courseCode): array
{
    if (!elearningTableExists($db, 'el_grades') || !elearningTableExists($db, 'el_assignments')) {
        return [];
    }

    $items = [];
    $sql = "SELECT g.Sid, COUNT(*) AS graded_count, AVG(g.total_points) AS average_points, MAX(g.graded_at) AS last_grade
            FROM el_grades g
            INNER JOIN el_assignments a ON g.assignment_id = a.id
            WHERE UPPER(TRIM(a.course_code)) = UPPER(TRIM(?))
            GROUP BY g.Sid";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $courseCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[(string)$row['Sid']] = [
                'graded_count' => (int)($row['graded_count'] ?? 0),
                'average_points' => $row['average_points'] === null ? null : round((float)$row['average_points'], 1),
                'last_grade' => $row['last_grade'] ?? null,
            ];
        }
        $stmt->close();
    }

    return $items;
}

function lecturerProgressLatestDate(array $values): ?string
{
    $latest = null;
    foreach ($values as $value) {
        if ($value === null || trim((string)$value) === '') {
            continue;
        }
        $time = strtotime((string)$value);
        if ($time !== false && ($latest === null || $time > $latest)) {
            $latest = $time;
        }
    }

    return $latest === null ? null : date('Y-m-d H:i:s', $latest);
}

function lecturerProgressFormatDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'n/a';
    }

    $time = strtotime($value);
    return $time === false ? 'n/a' : date('M j, Y g:i A', $time);
}

function lecturerProgressStatus(int $percent, ?string $lastActivity): array
{
    if ($lastActivity === null) {
        return ['label' => 'No activity', 'class' => 'badge-danger'];
    }
    if ($percent >= 75) {
        return ['label' => 'On track', 'class' => 'badge-success'];
    }
    if ($percent >= 35) {
        return ['label' => 'In progress', 'class' => 'badge-warning'];
    }

    return ['label' => 'Needs attention', 'class' => 'badge-danger'];
}

$courseName = $courseCode !== '' ? lecturerProgressCourseName($db, $courseCode) : '';
$studentRows = [];
$totalContent = 0;
$averageProgress = 0;
$activeThisWeek = 0;
$needsAttention = 0;

if ($courseCode !== '') {
    $studentIds = lecturerProgressStudentIds($db, $courseCode);
    $profiles = lecturerProgressStudentProfiles($db, $studentIds);
    $totalContent = lecturerProgressContentTotal($db, $courseCode);
    $storedPercents = lecturerProgressStoredPercents($db, $courseCode);
    $contentViews = lecturerProgressContentViews($db, $courseCode);
    $quizStats = lecturerProgressQuizStats($db, $courseCode);
    $assignmentStats = lecturerProgressAssignmentStats($db, $courseCode);

    foreach ($studentIds as $sid) {
        $viewed = $contentViews[$sid]['viewed_count'] ?? 0;
        $storedPercent = $storedPercents[$sid]['percent'] ?? null;
        if ($totalContent > 0) {
            $percent = min(100, (int)round(($viewed / $totalContent) * 100));
        } elseif ($storedPercent !== null) {
            $percent = max(0, min(100, (int)round((float)$storedPercent)));
        } else {
            $percent = 0;
        }

        $lastActivity = lecturerProgressLatestDate([
            $contentViews[$sid]['last_activity'] ?? null,
            $storedPercents[$sid]['updated_at'] ?? null,
            $quizStats[$sid]['last_quiz'] ?? null,
            $assignmentStats[$sid]['last_grade'] ?? null,
        ]);
        $status = lecturerProgressStatus($percent, $lastActivity);
        if ($lastActivity !== null && strtotime($lastActivity) >= strtotime('-7 days')) {
            $activeThisWeek++;
        }
        if ($percent < 35 || $lastActivity === null) {
            $needsAttention++;
        }

        $studentRows[] = [
            'Sid' => $sid,
            'name' => $profiles[$sid]['name'] ?? $sid,
            'email' => $profiles[$sid]['email'] ?? '',
            'percent' => $percent,
            'viewed' => $viewed,
            'quiz_attempts' => $quizStats[$sid]['attempts'] ?? 0,
            'quiz_average' => $quizStats[$sid]['average_score'] ?? null,
            'graded_assignments' => $assignmentStats[$sid]['graded_count'] ?? 0,
            'assignment_average' => $assignmentStats[$sid]['average_points'] ?? null,
            'last_activity' => $lastActivity,
            'status_label' => $status['label'],
            'status_class' => $status['class'],
        ];
    }

    if ($studentRows) {
        $averageProgress = (int)round(array_sum(array_column($studentRows, 'percent')) / count($studentRows));
    }
}

require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

<div class="elearning-shell">
    <div class="elearning-header">
        <div>
            <h1 class="elearning-title"><i class="fas fa-user-check"></i> Student Progress</h1>
            <p class="elearning-subtitle">
                <?php if ($courseCode !== ''): ?>
                    <?php echo htmlspecialchars($courseCode); ?> - <?php echo htmlspecialchars($courseName); ?>
                <?php else: ?>
                    Select a course to review student progress.
                <?php endif; ?>
            </p>
        </div>
        <div class="elearning-actions">
            <a href="courses.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Courses</a>
        </div>
    </div>

    <?php if ($courseCode === ''): ?>
        <div class="alert alert-info">
            <strong>Select a course.</strong>
            <p>Open Student Progress from one of your eLearning course cards.</p>
        </div>
    <?php else: ?>
        <?php elearningCourseTabs($courseCode, 'student_progress'); ?>

        <div class="elearning-stat-grid">
            <section class="elearning-stat-card">
                <span class="elearning-stat-label">Students</span>
                <strong><?php echo number_format(count($studentRows)); ?></strong>
            </section>
            <section class="elearning-stat-card">
                <span class="elearning-stat-label">Average Progress</span>
                <strong><?php echo number_format($averageProgress); ?>%</strong>
            </section>
            <section class="elearning-stat-card">
                <span class="elearning-stat-label">Active, 7 Days</span>
                <strong><?php echo number_format($activeThisWeek); ?></strong>
            </section>
            <section class="elearning-stat-card">
                <span class="elearning-stat-label">Needs Attention</span>
                <strong><?php echo number_format($needsAttention); ?></strong>
            </section>
        </div>

        <section class="elearning-panel">
            <div class="elearning-panel-header">
                <strong>Student Progress Register</strong>
                <span class="elearning-muted"><?php echo number_format($totalContent); ?> course item<?php echo $totalContent === 1 ? '' : 's'; ?></span>
            </div>
            <div class="elearning-panel-body">
                <?php if (empty($studentRows)): ?>
                    <p class="elearning-empty">No enrolled students or progress records were found for this course.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle elearning-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Progress</th>
                                    <th>Content</th>
                                    <th>Quizzes</th>
                                    <th>Assignments</th>
                                    <th>Last Activity</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($studentRows as $row): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['name']); ?></strong><br>
                                            <span class="elearning-muted"><?php echo htmlspecialchars($row['Sid']); ?></span>
                                            <?php if ($row['email'] !== ''): ?>
                                                <br><span class="elearning-muted"><?php echo htmlspecialchars($row['email']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="min-width: 150px;">
                                            <strong><?php echo number_format((int)$row['percent']); ?>%</strong>
                                            <progress class="elearning-progress" max="100" value="<?php echo (int)$row['percent']; ?>"><?php echo (int)$row['percent']; ?>%</progress>
                                        </td>
                                        <td><?php echo number_format((int)$row['viewed']); ?> / <?php echo number_format($totalContent); ?></td>
                                        <td>
                                            <?php echo number_format((int)$row['quiz_attempts']); ?>
                                            <?php if ($row['quiz_average'] !== null): ?>
                                                <br><span class="elearning-muted">Avg <?php echo htmlspecialchars((string)$row['quiz_average']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo number_format((int)$row['graded_assignments']); ?>
                                            <?php if ($row['assignment_average'] !== null): ?>
                                                <br><span class="elearning-muted">Avg <?php echo htmlspecialchars((string)$row['assignment_average']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars(lecturerProgressFormatDate($row['last_activity'])); ?></td>
                                        <td><span class="badge <?php echo htmlspecialchars($row['status_class']); ?>"><?php echo htmlspecialchars($row['status_label']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
</div>
</div>
</body>
</html>
