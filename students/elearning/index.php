<?php
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
require_once __DIR__ . '/../../includes/elearning_live_sessions.php';

$studentId = (string)($_SESSION['Sid'] ?? '');
if ($studentId === '' || !isset($db) || !($db instanceof mysqli)) {
    header('Location: /wucportal/elearning_login.php');
    exit();
}

function el_dash_table_exists(mysqli $db, string $table): bool
{
    $safeTable = $db->real_escape_string($table);
    if ($result = $db->query("SHOW TABLES LIKE '{$safeTable}'")) {
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
    return false;
}

function el_dash_column_exists(mysqli $db, string $table, string $column): bool
{
    $safeTable = $db->real_escape_string($table);
    $safeColumn = $db->real_escape_string($column);
    if ($result = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'")) {
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
    return false;
}

function el_dash_resolve_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return 'live_sessions.php';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if ($url[0] === '/') {
        return $url;
    }
    $url = preg_replace('#^(?:\./|\.\./)*#', '', $url);
    $url = preg_replace('#^students/elearning/#', '', $url);
    $url = preg_replace('#^elearning/#', '', $url);
    return $url !== '' ? $url : 'live_sessions.php';
}

require_once dirname(__DIR__, 2) . '/students/includes/period_mode_helper.php';

// Student profile (display only — no fees/registration on this dashboard)
$studentRec = null;
if ($stmt = $db->prepare(
    "SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.profile_image,
            p.program_name, p.period_mode, sp.program_code
     FROM students s
     LEFT JOIN student_program sp ON s.SID = sp.Sid
         AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')
     LEFT JOIN programs p ON sp.program_code = p.program_code AND COALESCE(p.is_active, 1) = 1
     WHERE s.SID = ?
     ORDER BY sp.id DESC
     LIMIT 1"
)) {
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $studentRec = $stmt->get_result()->fetch_object();
    $stmt->close();
    if ($studentRec && !empty($studentRec->program_code)) {
        $studentRec->period_mode = getProgramPeriodMode($db, (string)$studentRec->program_code);
    }
}

if (!$studentRec) {
    $error_title = 'Student Account Not Found';
    $error_message = 'Your student ID <strong>' . htmlspecialchars($studentId) . '</strong> was not found.';
    include __DIR__ . '/../includes/error_template.php';
    exit();
}

$profileImage = $studentRec->profile_image ?? '';
$safeProfileImage = preg_match('/^[A-Za-z0-9._-]+$/', $profileImage) ? $profileImage : '';
$imagePath = '../../uploads/profile/' . $safeProfileImage;
$hasImage = !empty($safeProfileImage) && $safeProfileImage !== 'default.jpg';
$initials = strtoupper(substr((string)($studentRec->Fname ?? 'S'), 0, 1) . substr((string)($studentRec->Lname ?? 'T'), 0, 1));
$studentName = trim((string)($studentRec->Fname ?? 'Student'));

// Enrolled courses
$courses = getStudentEnrolledCourses($db, $studentId);
if (!is_array($courses)) {
    $courses = [];
}
$uniqueCourses = array_values(array_unique(array_filter(array_map(
    static fn($code) => trim((string)$code),
    $courses
))));

$courseNames = [];
if ($uniqueCourses) {
    $placeholders = implode(',', array_fill(0, count($uniqueCourses), '?'));
    if ($stmt = $db->prepare("SELECT course_code, course_name FROM courses WHERE course_code IN ($placeholders)")) {
        $types = str_repeat('s', count($uniqueCourses));
        $stmt->bind_param($types, ...$uniqueCourses);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $code = trim((string)($row['course_code'] ?? ''));
            if ($code !== '') {
                $courseNames[$code] = (string)($row['course_name'] ?? 'Course');
            }
        }
        $stmt->close();
    }
}

// eLearning notifications
$notifications = [];
elearningEnsureLiveSessionLinkTable($db);
if (el_dash_table_exists($db, 'el_student_notifications')) {
    $notificationCols = [];
    if ($colRes = $db->query('SHOW COLUMNS FROM el_student_notifications')) {
        while ($col = $colRes->fetch_assoc()) {
            $notificationCols[] = strtolower((string)$col['Field']);
        }
        $colRes->free();
    }
    $selectCols = ['course_code', 'title', 'body', 'url', 'created_at'];
    if (in_array('type', $notificationCols, true)) {
        array_unshift($selectCols, 'type');
    }
    $sqlNotes = 'SELECT ' . implode(', ', $selectCols) . ' FROM el_student_notifications WHERE student_id = ? ORDER BY created_at DESC LIMIT 5';
    if ($stmt = $db->prepare($sqlNotes)) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $notifications[] = $row;
        }
        $stmt->close();
    }
}

// Upcoming deadlines (assignments + quizzes within 14 days)
$upcomingDeadlines = [];
if ($uniqueCourses) {
    $placeholders = implode(',', array_fill(0, count($uniqueCourses), '?'));
    $deadlineSources = [];
    if (el_dash_table_exists($db, 'el_assignments') && el_dash_column_exists($db, 'el_assignments', 'due_at')) {
        $deadlineSources[] = "SELECT course_code, title, due_at, 'Assignment' AS kind
                              FROM el_assignments
                              WHERE course_code IN ($placeholders)
                                AND due_at IS NOT NULL AND due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)";
    }
    if (el_dash_table_exists($db, 'el_quizzes') && el_dash_column_exists($db, 'el_quizzes', 'due_at')) {
        $quizPublishedClause = el_dash_column_exists($db, 'el_quizzes', 'is_published')
            ? 'AND is_published = 1'
            : '';
        $deadlineSources[] = "SELECT course_code, title, due_at, 'Quiz' AS kind
                              FROM el_quizzes
                              WHERE course_code IN ($placeholders)
                                {$quizPublishedClause}
                                AND due_at IS NOT NULL AND due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)";
    }
    if ($deadlineSources) {
        $sqlDue = '(' . implode(') UNION ALL (', $deadlineSources) . ') ORDER BY due_at ASC LIMIT 5';
        if ($stmtDue = $db->prepare($sqlDue)) {
            $deadlineTypes = str_repeat('s', count($uniqueCourses) * count($deadlineSources));
            $deadlineParams = [];
            for ($i = 0; $i < count($deadlineSources); $i++) {
                $deadlineParams = array_merge($deadlineParams, $uniqueCourses);
            }
            $stmtDue->bind_param($deadlineTypes, ...$deadlineParams);
            if ($stmtDue->execute()) {
                $resDue = $stmtDue->get_result();
                while ($row = $resDue->fetch_assoc()) {
                    $upcomingDeadlines[] = $row;
                }
            }
            $stmtDue->close();
        }
    }
}

// Upcoming live sessions (next 7 days)
$upcomingLiveSessions = [];
$registeredOfferingIds = getStudentCourseOfferingIds($db, $studentId);
if ($uniqueCourses && el_dash_table_exists($db, 'el_live_sessions') && el_dash_column_exists($db, 'el_live_sessions', 'start_time')) {
    $placeholders = implode(',', array_fill(0, count($uniqueCourses), '?'));
    $liveTypes = str_repeat('s', count($uniqueCourses));
    $liveParams = $uniqueCourses;
    $offeringSql = elearningOfferingScopeCondition($db, 'el_live_sessions', null, $registeredOfferingIds, $liveTypes, $liveParams);
    $topicCol = el_dash_column_exists($db, 'el_live_sessions', 'topic') ? 'topic' : (el_dash_column_exists($db, 'el_live_sessions', 'title') ? 'title' : "''");
    $sqlLive = "SELECT id, course_code, {$topicCol} AS session_title, start_time, end_time, platform
                FROM el_live_sessions
                WHERE course_code IN ($placeholders)
                  {$offeringSql}
                  AND start_time >= NOW()
                  AND start_time <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                ORDER BY start_time ASC
                LIMIT 5";
    if ($stmtLive = $db->prepare($sqlLive)) {
        $stmtLive->bind_param($liveTypes, ...$liveParams);
        if ($stmtLive->execute()) {
            $resLive = $stmtLive->get_result();
            while ($row = $resLive->fetch_assoc()) {
                $upcomingLiveSessions[] = $row;
            }
        }
        $stmtLive->close();
    }
}

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$courseCount = count($uniqueCourses);
$deadlineCount = count($upcomingDeadlines);
$notificationCount = count($notifications);
$liveSessionCount = count($upcomingLiveSessions);
$expandCourses = true;
$expandLive = $liveSessionCount > 0;
$expandDeadlines = $deadlineCount > 0;

$attentionItems = [];
if ($courseCount === 0) {
    $attentionItems[] = [
        'type' => 'warning',
        'icon' => 'fa-book-open',
        'title' => 'No eLearning courses',
        'text' => 'You are not enrolled in any courses with online materials yet.',
        'href' => '../myCourses.php',
        'label' => 'View courses',
    ];
}
foreach ($upcomingDeadlines as $due) {
    $dueTs = strtotime((string)($due['due_at'] ?? ''));
    if ($dueTs === false) {
        continue;
    }
    $daysLeft = (int)floor(($dueTs - time()) / 86400);
    if ($daysLeft > 2) {
        continue;
    }
    $attentionItems[] = [
        'type' => $daysLeft <= 0 ? 'danger' : 'warning',
        'icon' => ($due['kind'] ?? '') === 'Quiz' ? 'fa-circle-question' : 'fa-file-pen',
        'title' => (string)($due['title'] ?? 'Deadline'),
        'text' => ($due['kind'] ?? 'Task') . ' for ' . ($due['course_code'] ?? '') . ' due soon.',
        'href' => 'course.php?course_code=' . urlencode((string)($due['course_code'] ?? '')),
        'label' => 'Open course',
    ];
    if (count($attentionItems) >= 4) {
        break;
    }
}
if ($notificationCount > 0 && count($attentionItems) < 4) {
    $latest = $notifications[0];
    $attentionItems[] = [
        'type' => 'info',
        'icon' => 'fa-bell',
        'title' => (string)($latest['title'] ?? 'New notification'),
        'text' => (string)($latest['body'] ?? 'You have recent eLearning updates.'),
        'href' => el_dash_resolve_url((string)($latest['url'] ?? '')),
        'label' => 'View update',
    ];
}
if (empty($attentionItems)) {
    $attentionItems[] = [
        'type' => 'success',
        'icon' => 'fa-circle-check',
        'title' => 'eLearning is up to date',
        'text' => 'Your courses, deadlines, and live sessions are available from this dashboard.',
        'href' => '#el-courses',
        'label' => 'Browse courses',
    ];
}

$attentionCount = count(array_filter($attentionItems, static fn(array $item): bool => ($item['type'] ?? '') !== 'success'));
$notificationItems = $attentionCount > 0
    ? array_values(array_filter($attentionItems, static fn(array $item): bool => ($item['type'] ?? '') !== 'success'))
    : $attentionItems;

$expandCourses = true;
$expandLive = $liveSessionCount > 0;
$expandDeadlines = $deadlineCount > 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eLearning Dashboard - ITC</title>
<?php require_once __DIR__ . '/../../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/assets/css/main.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="../css/dashboard.css?v=20260702-profile-square-v2">
    <link rel="stylesheet" href="css/dashboard.css?v=20260702-el-collapse">
</head>
<body class="bg-light student-dashboard-page student-elearning-dashboard">

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main class="dash-content el-dash-content content-wrapper portal-dashboard pt-3">

    <section class="welcome-hero hero-branded" aria-label="eLearning welcome">
        <div class="welcome-hero-text">
            <span class="eyebrow">eLearning Dashboard</span>
            <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($studentName) ?></h1>
            <p>Access course materials, assignments, quizzes, live sessions, and recordings.</p>
            <div class="hero-chips" aria-label="eLearning context">
                <span class="hero-chip"><i class="fas fa-id-card"></i> <?= htmlspecialchars($studentRec->SID ?? $studentId) ?></span>
                <span class="hero-chip"><i class="fas fa-book-open"></i> <?= htmlspecialchars((string)$courseCount) ?> course<?= $courseCount === 1 ? '' : 's' ?></span>
                <?php if ($deadlineCount > 0): ?>
                    <span class="hero-chip"><i class="fas fa-hourglass-half"></i> <?= htmlspecialchars((string)$deadlineCount) ?> due soon</span>
                <?php endif; ?>
                <span class="hero-chip"><i class="far fa-clock"></i> <?= htmlspecialchars(date('D, d M Y')) ?></span>
            </div>
        </div>
        <div class="welcome-hero-meta">
            <div class="dash-notification-wrap">
                <button type="button"
                        class="dash-notification-bell"
                        id="elDashboardBell"
                        aria-label="Open eLearning notifications"
                        aria-expanded="false"
                        aria-controls="elDashboardMenu">
                    <i class="fas fa-bell"></i>
                    <?php if ($attentionCount > 0): ?>
                        <span class="dash-notification-count"><?= htmlspecialchars((string)$attentionCount) ?></span>
                    <?php endif; ?>
                </button>
                <div class="dash-notification-menu" id="elDashboardMenu" role="menu" aria-labelledby="elDashboardBell">
                    <div class="dash-notification-header">
                        <strong><?= $attentionCount > 0 ? 'Needs Attention' : 'All Clear' ?></strong>
                        <span><?= htmlspecialchars((string)count($notificationItems)) ?> item<?= count($notificationItems) === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="dash-notification-list">
                        <?php foreach ($notificationItems as $item): ?>
                            <a class="dash-notification-item <?= htmlspecialchars($item['type']) ?>" role="menuitem" href="<?= htmlspecialchars($item['href']) ?>">
                                <span class="dash-notification-icon"><i class="fas <?= htmlspecialchars($item['icon']) ?>"></i></span>
                                <span class="dash-notification-copy">
                                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                                    <small><?= htmlspecialchars($item['text']) ?></small>
                                    <em><?= htmlspecialchars($item['label']) ?> <i class="fas fa-arrow-right"></i></em>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <span class="hero-badge green">
                <i class="fas fa-graduation-cap"></i>
                <?= htmlspecialchars((string)$courseCount) ?> active course<?= $courseCount === 1 ? '' : 's' ?>
            </span>
        </div>
    </section>

    <div class="dash-quicknav-wrap">
        <nav class="dash-quicknav" aria-label="eLearning quick navigation">
            <a href="#el-courses" class="quicknav-item"><i class="fas fa-book"></i><span>My Courses</span></a>
            <a href="live_sessions.php" class="quicknav-item"><i class="fas fa-video"></i><span>Live Sessions</span></a>
            <a href="recordings.php" class="quicknav-item"><i class="fas fa-circle-play"></i><span>Recordings</span></a>
            <a href="progress.php" class="quicknav-item"><i class="fas fa-chart-line"></i><span>Progress</span></a>
            <a href="student_forum.php" class="quicknav-item"><i class="fas fa-comments"></i><span>Forum</span></a>
            <a href="../index.php" class="quicknav-item"><i class="fas fa-home"></i><span>Academic Dashboard</span></a>
        </nav>
    </div>

    <section class="stats-grid" aria-label="eLearning statistics">
        <a href="#el-courses" class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-book-open"></i></div>
            <div>
                <div class="stat-label">My Courses</div>
                <div class="stat-value"><?= htmlspecialchars((string)$courseCount) ?></div>
                <div class="stat-sub">with eLearning access</div>
            </div>
        </a>
        <a href="#el-deadlines" class="stat-card">
            <div class="stat-icon <?= $deadlineCount > 0 ? 'amber' : 'green' ?>"><i class="fas fa-hourglass-half"></i></div>
            <div>
                <div class="stat-label">Due Soon</div>
                <div class="stat-value"><?= htmlspecialchars((string)$deadlineCount) ?></div>
                <div class="stat-sub">next 14 days</div>
            </div>
        </a>
        <a href="live_sessions.php" class="stat-card">
            <div class="stat-icon <?= $liveSessionCount > 0 ? 'purple' : 'neutral' ?>"><i class="fas fa-video"></i></div>
            <div>
                <div class="stat-label">Live Sessions</div>
                <div class="stat-value"><?= htmlspecialchars((string)$liveSessionCount) ?></div>
                <div class="stat-sub">next 7 days</div>
            </div>
        </a>
        <a href="#el-notifications" class="stat-card">
            <div class="stat-icon <?= $notificationCount > 0 ? 'amber' : 'neutral' ?>"><i class="fas fa-bell"></i></div>
            <div>
                <div class="stat-label">Notifications</div>
                <div class="stat-value"><?= htmlspecialchars((string)$notificationCount) ?></div>
                <div class="stat-sub">recent updates</div>
            </div>
        </a>
    </section>

    <section class="dash-grid" aria-label="eLearning dashboard layout">

        <section aria-label="Student profile">
            <article class="card card-collapsible">
                <div class="profile-header">
                    <div class="profile-avatar-wrap <?= $hasImage ? '' : 'no-image' ?>">
                        <?php if ($hasImage): ?>
                            <img class="profile-avatar"
                                 src="<?= htmlspecialchars($imagePath) ?>"
                                 alt="Profile photo"
                                 onerror="this.closest('.profile-avatar-wrap').classList.add('no-image');">
                        <?php endif; ?>
                        <div class="profile-avatar-fallback"><?= htmlspecialchars($initials) ?></div>
                    </div>
                    <div class="profile-identity">
                        <div class="profile-name"><?= htmlspecialchars(trim(($studentRec->Fname ?? '') . ' ' . ($studentRec->Lname ?? ''))) ?></div>
                        <div class="profile-id">
                            <?= htmlspecialchars($studentRec->SID ?? $studentId) ?>
                            <span class="badge-period purple">eLearning</span>
                        </div>
                    </div>
                    <button class="card-toggle collapsed ms-auto align-self-start"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#elProfileDetails"
                            aria-expanded="false"
                            aria-controls="elProfileDetails">
                        <span class="card-toggle-label">Expand</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <div id="elProfileDetails" class="collapse">
                    <div class="profile-details">
                        <div class="profile-row">
                            <span class="profile-row-label">Program</span>
                            <span class="profile-row-value"><?= htmlspecialchars($studentRec->program_name ?? '—') ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-row-label">Online courses</span>
                            <span class="profile-row-value"><?= htmlspecialchars((string)$courseCount) ?> enrolled</span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-row-label">Due soon</span>
                            <span class="profile-row-value"><?= htmlspecialchars((string)$deadlineCount) ?> in 14 days</span>
                        </div>
                    </div>
                    <div class="profile-actions profile-actions--split">
                        <a href="../editProfile.php?update=<?= urlencode((string)($studentRec->SID ?? $studentId)) ?>" class="btn-profile btn-profile-outline">
                            <i class="fas fa-camera"></i> Photo
                        </a>
                        <a href="../index.php" class="btn-profile btn-profile-fill">
                            <i class="fas fa-home"></i> Academic Hub
                        </a>
                    </div>
                </div>
            </article>
        </section>

        <section aria-label="eLearning updates">

            <article class="card el-section-anchor card-collapsible" id="el-courses">
                <div class="card-hdr">
                    <h3><i class="fas fa-book-open"></i> My Courses</h3>
                    <div class="card-hdr-actions">
                        <span class="badge bg-primary"><?= htmlspecialchars((string)$courseCount) ?></span>
                        <button class="card-toggle<?= $expandCourses ? '' : ' collapsed' ?>"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#elCoursesBody"
                                aria-expanded="<?= $expandCourses ? 'true' : 'false' ?>"
                                aria-controls="elCoursesBody">
                            <span class="card-toggle-label"><?= $expandCourses ? 'Collapse' : 'Expand' ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div id="elCoursesBody" class="collapse<?= $expandCourses ? ' show' : '' ?>">
                <div class="card-body card-body-scroll">
                    <?php if ($courseCount > 0): ?>
                        <div class="el-course-grid">
                            <?php foreach ($uniqueCourses as $courseCode): ?>
                                <a class="el-course-card" href="course.php?course_code=<?= urlencode($courseCode) ?>">
                                    <div class="el-course-card-hdr">
                                        <strong><i class="fas fa-book"></i> <?= htmlspecialchars($courseCode) ?></strong>
                                        <small><?= htmlspecialchars($courseNames[$courseCode] ?? 'Course') ?></small>
                                    </div>
                                    <div class="el-course-card-body">
                                        <p>Modules, materials, quizzes, and assignments.</p>
                                    </div>
                                    <span class="el-course-card-foot">Open course <i class="fas fa-arrow-right"></i></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-book-open"></i>
                            <p>No eLearning courses enrolled yet.</p>
                            <a href="../myCourses.php" class="btn-profile btn-profile-fill d-inline-flex mt-2">View registered courses</a>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
            </article>

            <article class="card card-collapsible" id="el-deadlines">
                <div class="card-hdr">
                    <h3><i class="fas fa-hourglass-half"></i> Assignment & Quiz Deadlines</h3>
                    <div class="card-hdr-actions">
                        <?php if ($deadlineCount > 0): ?>
                            <span class="badge bg-primary"><?= htmlspecialchars((string)$deadlineCount) ?> due</span>
                        <?php endif; ?>
                        <button class="card-toggle<?= $expandDeadlines ? '' : ' collapsed' ?>"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#elDeadlinesBody"
                                aria-expanded="<?= $expandDeadlines ? 'true' : 'false' ?>"
                                aria-controls="elDeadlinesBody">
                            <span class="card-toggle-label"><?= $expandDeadlines ? 'Collapse' : 'Expand' ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div id="elDeadlinesBody" class="collapse<?= $expandDeadlines ? ' show' : '' ?>">
                <div class="card-body card-body-scroll">
                    <?php if (!empty($upcomingDeadlines)): ?>
                        <ul class="deadline-list">
                            <?php foreach ($upcomingDeadlines as $due):
                                $dueTs = strtotime((string)($due['due_at'] ?? ''));
                                $daysLeft = $dueTs ? (int)floor(($dueTs - time()) / 86400) : 99;
                                $urgency = $daysLeft <= 2 ? 'red' : ($daysLeft <= 6 ? 'amber' : 'green');
                            ?>
                                <li class="deadline-item">
                                    <span class="deadline-icon <?= htmlspecialchars($urgency) ?>">
                                        <i class="fas <?= ($due['kind'] ?? '') === 'Quiz' ? 'fa-circle-question' : 'fa-file-pen' ?>"></i>
                                    </span>
                                    <span class="deadline-copy">
                                        <strong><?= htmlspecialchars((string)($due['title'] ?? 'Deadline')) ?></strong>
                                        <small><?= htmlspecialchars((string)($due['kind'] ?? '')) ?> · <?= htmlspecialchars((string)($due['course_code'] ?? '')) ?></small>
                                    </span>
                                    <span class="deadline-when <?= htmlspecialchars($urgency) ?>">
                                        <strong><?= $daysLeft <= 0 ? 'Today' : ($daysLeft . 'd') ?></strong>
                                        <small><?= $dueTs ? htmlspecialchars(date('M d, H:i', $dueTs)) : '—' ?></small>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-circle-check"></i>
                            <p>Nothing due in the next two weeks.</p>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
            </article>

            <article class="card card-collapsible" id="el-live">
                <div class="card-hdr">
                    <h3><i class="fas fa-video"></i> Upcoming Live Sessions</h3>
                    <div class="card-hdr-actions">
                        <a href="live_sessions.php" class="badge bg-primary text-decoration-none">All</a>
                        <button class="card-toggle<?= $expandLive ? '' : ' collapsed' ?>"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#elLiveBody"
                                aria-expanded="<?= $expandLive ? 'true' : 'false' ?>"
                                aria-controls="elLiveBody">
                            <span class="card-toggle-label"><?= $expandLive ? 'Collapse' : 'Expand' ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div id="elLiveBody" class="collapse<?= $expandLive ? ' show' : '' ?>">
                <div class="card-body card-body-scroll">
                    <?php if (!empty($upcomingLiveSessions)): ?>
                        <ul class="el-live-list">
                            <?php foreach ($upcomingLiveSessions as $session):
                                $startTs = strtotime((string)($session['start_time'] ?? ''));
                            ?>
                                <li class="el-live-item">
                                    <span class="el-live-time">
                                        <strong><?= $startTs ? htmlspecialchars(date('M d', $startTs)) : '—' ?></strong>
                                        <small><?= $startTs ? htmlspecialchars(date('H:i', $startTs)) : '' ?></small>
                                    </span>
                                    <span class="el-live-copy">
                                        <strong><?= htmlspecialchars((string)($session['session_title'] ?? 'Live session')) ?></strong>
                                        <small><?= htmlspecialchars((string)($session['course_code'] ?? '')) ?> · <?= htmlspecialchars(ucfirst((string)($session['platform'] ?? 'online'))) ?></small>
                                    </span>
                                    <a href="live_sessions.php" class="el-live-link">Join <i class="fas fa-arrow-right"></i></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-video-slash"></i>
                            <p>No live sessions scheduled in the next week.</p>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
            </article>

            <article class="card card-collapsible" id="el-notifications">
                <div class="card-hdr">
                    <h3><i class="fas fa-bell"></i> Recent Notifications</h3>
                    <div class="card-hdr-actions">
                        <?php if ($notificationCount > 0): ?>
                            <span class="badge bg-primary"><?= htmlspecialchars((string)$notificationCount) ?></span>
                        <?php endif; ?>
                        <button class="card-toggle collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#elNotificationsBody"
                                aria-expanded="false"
                                aria-controls="elNotificationsBody">
                            <span class="card-toggle-label">Expand</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div id="elNotificationsBody" class="collapse">
                <div class="card-body card-body-scroll">
                    <?php if (!empty($notifications)): ?>
                        <ul class="el-notification-list">
                            <?php foreach ($notifications as $note):
                                $createdAt = strtotime((string)($note['created_at'] ?? ''));
                                $createdLabel = $createdAt ? date('M d, h:i A', $createdAt) : 'Recent';
                                $noteCourseCode = trim((string)($note['course_code'] ?? ''));
                                $noteCourseLabel = ($noteCourseCode === '' || strtoupper($noteCourseCode) === 'GENERAL')
                                    ? 'General'
                                    : $noteCourseCode;
                                $targetUrl = el_dash_resolve_url((string)($note['url'] ?? ''));
                            ?>
                                <li>
                                    <a class="el-notification-item" href="<?= htmlspecialchars($targetUrl) ?>">
                                        <span class="el-notification-item-icon"><i class="fas fa-bell"></i></span>
                                        <span class="el-notification-copy">
                                            <strong><?= htmlspecialchars((string)($note['title'] ?? 'Notification')) ?></strong>
                                            <small><?= htmlspecialchars($noteCourseLabel) ?> · <?= htmlspecialchars($createdLabel) ?></small>
                                        </span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <p>No new eLearning notifications.</p>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
            </article>

        </section>

    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dashboard-ui.js?v=20260702"></script>
</body>
</html>
