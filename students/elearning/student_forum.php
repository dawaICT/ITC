<?php
error_reporting(0);
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_access.php';

$sid = $_SESSION['Sid'] ?? null;
$courseCode = trim((string)($_GET['course_code'] ?? ($_POST['course_code'] ?? '')));
if (!$sid) {
    http_response_code(401);
    exit('Unauthorized');
}

$baseHref = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/wucportal/students/elearning/student_forum.php'))), '/') . '/';
$isCourseForum = $courseCode !== '';
$hasForumTables = elearningTableExists($db, 'el_forum_threads') && elearningTableExists($db, 'el_forum_posts');

if ($isCourseForum) {
    enforceStudentCourseAccess($db, $sid, $courseCode);
}
$courseOfferingIds = $isCourseForum ? getStudentCourseOfferingIds($db, $sid, $courseCode) : getStudentCourseOfferingIds($db, $sid);
$courseOfferingId = $courseOfferingIds[0] ?? null;

function studentForumCourseNames(mysqli $db, array $courseCodes): array
{
    if (!$courseCodes) {
        return [];
    }

    $names = [];
    foreach (['courses', 'program_courses'] as $tableName) {
        if (!elearningTableExists($db, $tableName)) {
            continue;
        }

        $courseCol = elearningDetectColumn($db, $tableName, ['course_code', 'code']);
        $nameCol = elearningDetectColumn($db, $tableName, ['course_name', 'name', 'title']);
        if ($courseCol === null || $nameCol === null) {
            continue;
        }

        $missing = array_values(array_diff($courseCodes, array_keys($names)));
        if (!$missing) {
            break;
        }

        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $types = str_repeat('s', count($missing));
        $sql = "SELECT `{$courseCol}` AS course_code, `{$nameCol}` AS course_name FROM `{$tableName}` WHERE `{$courseCol}` IN ({$placeholders})";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$missing);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $code = trim((string)($row['course_code'] ?? ''));
                if ($code !== '' && trim((string)($row['course_name'] ?? '')) !== '') {
                    $names[$code] = (string)$row['course_name'];
                }
            }
            $stmt->close();
        }
    }

    return $names;
}

function studentForumThreadCounts(mysqli $db, array $courseCodes, array $courseOfferingIds = []): array
{
    if (!$courseCodes || !elearningTableExists($db, 'el_forum_threads')) {
        return [];
    }

    $counts = [];
    $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
    $types = str_repeat('s', count($courseCodes));
    $params = $courseCodes;
    $offeringSql = elearningOfferingScopeCondition($db, 'el_forum_threads', null, $courseOfferingIds, $types, $params);
    $sql = "SELECT course_code, COUNT(*) AS total FROM el_forum_threads WHERE course_code IN ({$placeholders}) {$offeringSql} GROUP BY course_code";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $counts[(string)$row['course_code']] = (int)($row['total'] ?? 0);
        }
        $stmt->close();
    }

    return $counts;
}

function studentForumFetchThreads(mysqli $db, array $courseCodes, int $limit = 0, array $courseOfferingIds = []): array
{
    if (!$courseCodes || !elearningTableExists($db, 'el_forum_threads') || !elearningTableExists($db, 'el_forum_posts')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
    $types = str_repeat('s', count($courseCodes));
    $params = $courseCodes;
    $offeringSql = elearningOfferingScopeCondition($db, 'el_forum_threads', 't', $courseOfferingIds, $types, $params);
    $limitSql = $limit > 0 ? ' LIMIT ' . (int)$limit : '';
    $sql = "SELECT t.*, COALESCE(pc.post_count, 0) AS post_count
            FROM el_forum_threads t
            LEFT JOIN (
                SELECT thread_id, COUNT(*) AS post_count
                FROM el_forum_posts
                GROUP BY thread_id
            ) pc ON pc.thread_id = t.id
            WHERE t.course_code IN ({$placeholders})
            {$offeringSql}
            ORDER BY COALESCE(t.updated_at, t.created_at) DESC, t.id DESC{$limitSql}";

    $threads = [];
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $threads[] = $row;
        }
        $stmt->close();
    } else {
        error_log('students/elearning/student_forum.php: threads fetch prepare failed: ' . $db->error);
    }

    return $threads;
}

function studentForumFormatDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'n/a';
    }

    $time = strtotime($value);
    return $time === false ? 'n/a' : date('M j, Y', $time);
}

$errors = [];
$successMsg = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'new_thread') {
    if (!$isCourseForum) {
        $errors[] = 'Select a course before starting a discussion.';
    } elseif (!$hasForumTables) {
        $errors[] = 'Discussions are not available yet.';
    } else {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));

        if ($title === '') {
            $errors[] = 'Title is required';
        }
        if ($body === '') {
            $errors[] = 'Post content is required';
        }

        if (!$errors) {
            $createdByType = 'student';
            $threadId = 0;
            if (elearningTableHasCourseOffering($db, 'el_forum_threads')) {
                $stmt = $db->prepare("INSERT INTO el_forum_threads (course_offering_id, course_code, title, created_by, created_by_type, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW())");
            } else {
                $stmt = $db->prepare("INSERT INTO el_forum_threads (course_code, title, created_by, created_by_type, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())");
            }
            if ($stmt) {
                if (elearningTableHasCourseOffering($db, 'el_forum_threads')) {
                    $stmt->bind_param('issss', $courseOfferingId, $courseCode, $title, $sid, $createdByType);
                } else {
                    $stmt->bind_param('ssss', $courseCode, $title, $sid, $createdByType);
                }
                if ($stmt->execute()) {
                    $threadId = (int)$stmt->insert_id;
                } else {
                    $errors[] = 'Unable to create thread.';
                    error_log('students/elearning/student_forum.php: thread insert failed: ' . $stmt->error);
                }
                $stmt->close();
            } else {
                $errors[] = 'Unable to create thread.';
                error_log('students/elearning/student_forum.php: thread insert prepare failed: ' . $db->error);
            }

            if ($threadId > 0) {
                $stmt = $db->prepare("INSERT INTO el_forum_posts (thread_id, body, created_by, created_by_type, created_at) VALUES (?,?,?,?,NOW())");
                if ($stmt) {
                    $stmt->bind_param('isss', $threadId, $body, $sid, $createdByType);
                    if (!$stmt->execute()) {
                        $errors[] = 'Unable to save thread post.';
                        error_log('students/elearning/student_forum.php: post insert failed: ' . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'Unable to save thread post.';
                    error_log('students/elearning/student_forum.php: post insert prepare failed: ' . $db->error);
                }
            }

            if (!$errors && $threadId > 0) {
                header('Location: student_thread.php?id=' . $threadId . '&course_code=' . urlencode($courseCode));
                exit;
            }
        }
    }
}

$enrolledCourses = getStudentEnrolledCourses($db, $sid);
if (!is_array($enrolledCourses)) {
    $enrolledCourses = [];
}
$enrolledCourses = array_values(array_unique(array_filter(array_map(static fn($value) => trim((string)$value), $enrolledCourses))));
$enrolledOfferingIds = getStudentCourseOfferingIds($db, $sid);
$courseNames = studentForumCourseNames($db, $isCourseForum ? [$courseCode] : $enrolledCourses);
$threadCounts = studentForumThreadCounts($db, $enrolledCourses, $enrolledOfferingIds);
$threads = $isCourseForum ? studentForumFetchThreads($db, [$courseCode], 0, $courseOfferingIds) : studentForumFetchThreads($db, $enrolledCourses, 10, $enrolledOfferingIds);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isCourseForum ? 'Discussions - ' . htmlspecialchars($courseCode) : 'Discussions'; ?></title>
    <base href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/css/elearning-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .student-forum-header {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #1d4ed8;
            border-radius: 8px;
            padding: 22px;
            margin-bottom: 20px;
        }
        .student-forum-header h2 {
            margin: 0;
            font-size: 1.45rem;
            color: #0f172a;
        }
        .student-forum-header p {
            margin: 6px 0 0;
            color: #64748b;
        }
        .student-forum-course-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .student-forum-course {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            padding: 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            text-decoration: none;
            color: inherit;
        }
        .student-forum-course:hover {
            border-color: #bfdbfe;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07);
        }
        .student-forum-course strong {
            display: block;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .thread-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
        }
        .thread-info h5 {
            margin: 0 0 6px;
            font-size: 1rem;
        }
        .thread-info h5 a {
            color: #0f172a;
            text-decoration: none;
        }
        .thread-info h5 a:hover {
            color: #1d4ed8;
        }
        .thread-info small,
        .student-forum-muted {
            color: #64748b;
        }
        .thread-meta {
            min-width: 72px;
            text-align: right;
        }
        .thread-meta .count {
            color: #1d4ed8;
            font-size: 1.2rem;
            font-weight: 700;
        }
        .badge-locked {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-left: 8px;
            padding: 2px 8px;
            border-radius: 4px;
            background: #dc3545;
            color: #fff;
            font-size: 0.75rem;
        }
        .new-thread-form {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 22px;
        }
        @media (max-width: 640px) {
            .thread-card {
                align-items: flex-start;
                flex-direction: column;
            }
            .thread-meta {
                text-align: left;
            }
        }
    </style>

<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="content-wrapper">
    <div style="margin-bottom: 20px;">
        <?php if ($isCourseForum): ?>
            <a href="elearning/course.php?course_code=<?php echo urlencode($courseCode); ?>" style="color: #666; text-decoration: none;">
                <i class="fas fa-arrow-left"></i> Back to <?php echo htmlspecialchars($courseCode); ?>
            </a>
        <?php else: ?>
            <a href="elearning/index.php" style="color: #666; text-decoration: none;">
                <i class="fas fa-arrow-left"></i> Back to Learning Hub
            </a>
        <?php endif; ?>
    </div>

    <div class="student-forum-header">
        <h2><i class="fas fa-comments"></i> <?php echo $isCourseForum ? 'Course Discussions' : 'Discussion Forum'; ?></h2>
        <p>
            <?php if ($isCourseForum): ?>
                <?php echo htmlspecialchars($courseCode); ?> - <?php echo htmlspecialchars($courseNames[$courseCode] ?? $courseCode); ?>
            <?php else: ?>
                View discussions across your enrolled courses.
            <?php endif; ?>
        </p>
    </div>

    <?php if (!$hasForumTables): ?>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Discussions are not available yet.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
    <?php endif; ?>
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>

    <?php if (!$isCourseForum): ?>
        <?php if (!$enrolledCourses): ?>
            <div class="alert alert-info"><i class="fas fa-info-circle"></i> You are not enrolled in any courses yet.</div>
        <?php else: ?>
            <div class="student-forum-course-grid">
                <?php foreach ($enrolledCourses as $code): ?>
                    <a class="student-forum-course" href="elearning/student_forum.php?course_code=<?php echo urlencode($code); ?>">
                        <span>
                            <strong><?php echo htmlspecialchars($code); ?></strong>
                            <span class="student-forum-muted"><?php echo htmlspecialchars($courseNames[$code] ?? $code); ?></span>
                        </span>
                        <span class="student-forum-muted">
                            <?php echo number_format($threadCounts[$code] ?? 0); ?> discussion<?php echo (($threadCounts[$code] ?? 0) === 1) ? '' : 's'; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h4 style="margin-bottom: 15px;"><i class="fas fa-clock"></i> Recent Discussions</h4>
    <?php else: ?>
        <div class="new-thread-form">
            <h5><i class="fas fa-plus-circle"></i> Start a New Discussion</h5>
            <form method="post">
                <input type="hidden" name="action" value="new_thread">
                <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($courseCode, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3">
                    <input type="text" class="form-control" name="title" placeholder="Discussion Title" required>
                </div>
                <div class="mb-3">
                    <textarea class="form-control" name="body" rows="4" placeholder="What would you like to discuss?" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Post</button>
            </form>
        </div>

        <h4 style="margin-bottom: 15px;"><i class="fas fa-list"></i> All Discussions</h4>
    <?php endif; ?>

    <?php if ($threads): ?>
        <?php foreach ($threads as $t): ?>
            <?php $threadCourseCode = (string)($t['course_code'] ?? $courseCode); ?>
            <div class="thread-card">
                <div class="thread-info">
                    <h5>
                        <a href="elearning/student_thread.php?id=<?php echo (int)$t['id']; ?>&course_code=<?php echo urlencode($threadCourseCode); ?>">
                            <?php echo htmlspecialchars((string)$t['title']); ?>
                        </a>
                        <?php if (!empty($t['is_locked'])): ?><span class="badge-locked"><i class="fas fa-lock"></i> Locked</span><?php endif; ?>
                    </h5>
                    <small>
                        <?php if (!$isCourseForum): ?>
                            <?php echo htmlspecialchars($threadCourseCode); ?> &middot;
                        <?php endif; ?>
                        Started by <?php echo htmlspecialchars((string)$t['created_by']); ?>
                        &middot; <?php echo htmlspecialchars(studentForumFormatDate($t['created_at'] ?? null)); ?>
                        <?php if (!empty($t['tags'])): ?>
                            &middot; Tags: <?php echo htmlspecialchars((string)$t['tags']); ?>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="thread-meta">
                    <div class="count"><?php echo (int)$t['post_count']; ?></div>
                    <small>post<?php echo ((int)$t['post_count'] === 1) ? '' : 's'; ?></small>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="alert alert-info"><i class="fas fa-info-circle"></i> No discussions yet<?php echo $isCourseForum ? '. Be the first to start one!' : ' for your enrolled courses.'; ?></div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../lecturers/dist/js/bootstrap.min.js"></script>
</body>
</html>
