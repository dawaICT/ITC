<?php
declare(strict_types=1);

$page_title = 'Lecturer eLearning';
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../../includes/elearning_access.php';
require_once __DIR__ . '/../../includes/portal_context.php';

wuc_set_portal_context('lecturer_elearning');

function el_lect_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function el_lect_stat_count(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    try {
        if (!$stmt = $db->prepare($sql)) {
            return 0;
        }
        if ($types !== '' && $params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['total'] ?? 0);
    } catch (Throwable $e) {
        error_log('lecturers/elearning/index.php stat query failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * @return array<string, array{students: int, assignments: int, pending: int, sessions: int}>
 */
function el_lect_load_course_stats(mysqli $db, array $courseCodes): array
{
    $stats = [];
    foreach ($courseCodes as $code) {
        $key = strtoupper(trim((string)$code));
        if ($key !== '') {
            $stats[$key] = ['students' => 0, 'assignments' => 0, 'pending' => 0, 'sessions' => 0];
        }
    }
    if ($stats === []) {
        return $stats;
    }

    $codes = array_keys($stats);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));

    if (elearningTableExists($db, 'course_registration')) {
        try {
            $sql = "SELECT UPPER(TRIM(course_code)) AS course_code, COUNT(DISTINCT Sid) AS total
                    FROM course_registration
                    WHERE UPPER(TRIM(course_code)) IN ($placeholders)
                    GROUP BY UPPER(TRIM(course_code))";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($types, ...$codes);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $key = (string)($row['course_code'] ?? '');
                    if ($key !== '' && isset($stats[$key])) {
                        $stats[$key]['students'] = (int)($row['total'] ?? 0);
                    }
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('lecturers/elearning/index.php student stats failed: ' . $e->getMessage());
        }
    }

    if (elearningTableExists($db, 'el_assignments')) {
        try {
            $sql = "SELECT UPPER(TRIM(course_code)) AS course_code, COUNT(*) AS total
                    FROM el_assignments
                    WHERE UPPER(TRIM(course_code)) IN ($placeholders)
                    GROUP BY UPPER(TRIM(course_code))";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($types, ...$codes);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $key = (string)($row['course_code'] ?? '');
                    if ($key !== '' && isset($stats[$key])) {
                        $stats[$key]['assignments'] = (int)($row['total'] ?? 0);
                    }
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('lecturers/elearning/index.php assignment stats failed: ' . $e->getMessage());
        }
    }

    if (elearningTableExists($db, 'el_submissions')
        && elearningTableExists($db, 'el_assignments')
        && elearningTableExists($db, 'el_grades')) {
        try {
            $sql = "SELECT UPPER(TRIM(a.course_code)) AS course_code, COUNT(*) AS total
                    FROM el_submissions s
                    INNER JOIN el_assignments a ON a.id = s.assignment_id
                    LEFT JOIN el_grades g ON g.assignment_id = s.assignment_id AND g.Sid = s.Sid
                    WHERE g.id IS NULL
                      AND UPPER(TRIM(a.course_code)) IN ($placeholders)
                    GROUP BY UPPER(TRIM(a.course_code))";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($types, ...$codes);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $key = (string)($row['course_code'] ?? '');
                    if ($key !== '' && isset($stats[$key])) {
                        $stats[$key]['pending'] = (int)($row['total'] ?? 0);
                    }
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('lecturers/elearning/index.php pending stats failed: ' . $e->getMessage());
        }
    }

    if (elearningTableExists($db, 'el_live_sessions')
        && elearningDetectColumn($db, 'el_live_sessions', ['start_time']) !== null) {
        try {
            $sql = "SELECT UPPER(TRIM(course_code)) AS course_code, COUNT(*) AS total
                    FROM el_live_sessions
                    WHERE start_time >= NOW()
                      AND start_time <= DATE_ADD(NOW(), INTERVAL 14 DAY)
                      AND UPPER(TRIM(course_code)) IN ($placeholders)
                    GROUP BY UPPER(TRIM(course_code))";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($types, ...$codes);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $key = (string)($row['course_code'] ?? '');
                    if ($key !== '' && isset($stats[$key])) {
                        $stats[$key]['sessions'] = (int)($row['total'] ?? 0);
                    }
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('lecturers/elearning/index.php session stats failed: ' . $e->getMessage());
        }
    }

    return $stats;
}

$staffId = (string)($_SESSION['staff_id'] ?? '');
if ($staffId === '') {
    $_SESSION['errorMessage'] = 'Please log in to access the eLearning Portal.';
    header('Location: /wucportal/staff_login.php', true, 302);
    exit;
}

// Lecturer portal: always scope to teaching assignments, even for staff with
// admin/eLearning permissions. Full catalogue access belongs in admin/elearning/.
$isAdmin = (function_exists('isSystemsAdmin') && isSystemsAdmin())
    || (function_exists('isAdministrator') && isAdministrator($staffId))
    || (function_exists('hasPermission') && hasPermission($staffId, 'elearn_admin_all'));

$courses = [];
try {
    $courseDetails = function_exists('getLecturerCourseDetails') ? getLecturerCourseDetails($db, $staffId) : [];
    foreach ($courseDetails as $course) {
        $code = trim((string)($course['course_code'] ?? ''));
        if ($code !== '') {
            $courses[] = [
                'course_code' => $code,
                'course_name' => (string)($course['course_name'] ?? 'Course'),
            ];
        }
    }
} catch (Throwable $e) {
    error_log('lecturers/elearning/index.php course load failed: ' . $e->getMessage());
    $courses = [];
}

$courseCount = count($courses);
$courseFilter = ($staffId !== '')
    ? elearningLecturerCourseInFilter($db, $staffId, 'course_code')
    : ['clause' => ' AND 1=0', 'types' => '', 'params' => [], 'has_access' => false];

$pendingGrades = 0;
$upcomingSessions = 0;
$openAssignments = 0;

if ($courseFilter['has_access']) {
    if (elearningTableExists($db, 'el_submissions') && elearningTableExists($db, 'el_assignments')) {
        $gradesJoin = elearningTableExists($db, 'el_grades')
            ? 'LEFT JOIN el_grades g ON g.assignment_id = s.assignment_id AND g.Sid = s.Sid'
            : '';
        $gradeWhere = elearningTableExists($db, 'el_grades') ? ' AND g.id IS NULL' : '';
        $courseClause = str_replace('course_code', 'a.course_code', $courseFilter['clause']);
        $pendingGrades = el_lect_stat_count(
            $db,
            "SELECT COUNT(*) AS total
             FROM el_submissions s
             INNER JOIN el_assignments a ON a.id = s.assignment_id
             {$gradesJoin}
             WHERE 1=1{$courseClause}{$gradeWhere}",
            $courseFilter['types'],
            $courseFilter['params']
        );
    }

    if (elearningTableExists($db, 'el_live_sessions')
        && elearningDetectColumn($db, 'el_live_sessions', ['start_time']) !== null) {
        $upcomingSessions = el_lect_stat_count(
            $db,
            "SELECT COUNT(*) AS total
             FROM el_live_sessions
             WHERE start_time >= NOW()
               AND start_time <= DATE_ADD(NOW(), INTERVAL 7 DAY){$courseFilter['clause']}",
            $courseFilter['types'],
            $courseFilter['params']
        );
    }

    if (elearningTableExists($db, 'el_assignments')) {
        $dueCol = elearningDetectColumn($db, 'el_assignments', ['due_at', 'due_date']);
        $dueSql = $dueCol !== null
            ? " AND (`{$dueCol}` IS NULL OR `{$dueCol}` >= NOW())"
            : '';
        $openAssignments = el_lect_stat_count(
            $db,
            "SELECT COUNT(*) AS total FROM el_assignments WHERE 1=1{$courseFilter['clause']}{$dueSql}",
            $courseFilter['types'],
            $courseFilter['params']
        );
    }
}

$courseCodes = array_values(array_filter(array_map(
    static fn(array $course): string => trim((string)($course['course_code'] ?? '')),
    $courses
)));
$courseStats = el_lect_load_course_stats($db, $courseCodes);

require_once __DIR__ . '/../includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard lecturer-workflow-page lecturer-elearning-dashboard">
    <div class="dashboard-header lecturer-section mb-4 wuc-in">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title">eLearning Dashboard</h1>
                <p class="text-muted mb-0">
                    Manage online courses, content, assignments, and learner progress.
                    <?php if ($isAdmin): ?>
                        <span class="badge bg-primary ms-1">Administrator</span>
                        <span class="text-muted small ms-1">· assigned courses only in this portal</span>
                    <?php elseif ($courseCount > 0): ?>
                        <span class="text-muted">· <?php echo el_lect_h((string)$courseCount); ?> course<?php echo $courseCount === 1 ? '' : 's'; ?> assigned</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="data-table-card h-100 wuc-in">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-lecturer rounded-circle p-3 me-3">
                            <i class="fas fa-book-open fa-2x text-white"></i>
                        </div>
                        <div>
                            <h3 class="stat-value mb-0"><?php echo number_format($courseCount); ?></h3>
                            <p class="stat-label mb-0 text-muted">My Courses</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="/wucportal/lecturers/assessments.php?portal=elearning" class="text-decoration-none d-block h-100">
                <div class="data-table-card h-100 wuc-in">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-warning rounded-circle p-3 me-3">
                                <i class="fas fa-clipboard-check fa-2x text-white"></i>
                            </div>
                            <div>
                                <h3 class="stat-value mb-0 text-dark"><?php echo number_format($pendingGrades); ?></h3>
                                <p class="stat-label mb-0 text-muted">Awaiting Grades</p>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="/wucportal/elearning/sessions.php" class="text-decoration-none d-block h-100">
                <div class="data-table-card h-100 wuc-in">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-info rounded-circle p-3 me-3">
                                <i class="fas fa-video fa-2x text-white"></i>
                            </div>
                            <div>
                                <h3 class="stat-value mb-0 text-dark"><?php echo number_format($upcomingSessions); ?></h3>
                                <p class="stat-label mb-0 text-muted">Live Sessions (7d)</p>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="/wucportal/lecturers/post_assign.php?portal=elearning" class="text-decoration-none d-block h-100">
                <div class="data-table-card h-100 wuc-in">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-success rounded-circle p-3 me-3">
                                <i class="fas fa-tasks fa-2x text-white"></i>
                            </div>
                            <div>
                                <h3 class="stat-value mb-0 text-dark"><?php echo number_format($openAssignments); ?></h3>
                                <p class="stat-label mb-0 text-muted">Open Assignments</p>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <nav class="assignment-command-bar mb-4" aria-label="eLearning quick links">
        <a class="command-link active" href="/wucportal/lecturers/elearning/index.php" aria-current="page">
            <i class="fas fa-chalkboard"></i><span>Dashboard</span>
        </a>
        <a class="command-link" href="/wucportal/elearning/courses.php">
            <i class="fas fa-book-open"></i><span>Online Courses</span>
        </a>
        <a class="command-link" href="/wucportal/elearning/sessions.php">
            <i class="fas fa-video"></i><span>Live Sessions</span>
        </a>
        <a class="command-link" href="/wucportal/lecturers/assessments.php?portal=elearning">
            <i class="fas fa-file-alt"></i><span>Submissions</span>
        </a>
        <a class="command-link" href="/wucportal/elearning/forum.php">
            <i class="fas fa-comments"></i><span>Discussions</span>
        </a>
        <a class="command-link" href="/wucportal/elearning/analytics.php">
            <i class="fas fa-chart-line"></i><span>Progress</span>
        </a>
    </nav>

    <section class="el-lect-courses-section wuc-in" id="el-courses">
        <div class="el-lect-courses-head">
            <div class="el-lect-courses-title-wrap">
                <h2 class="el-lect-courses-title">
                    <span class="el-lect-courses-icon" aria-hidden="true"><i class="fas fa-layer-group"></i></span>
                    Your Courses
                </h2>
                <p class="el-lect-courses-sub mb-0">
                    Pick a course to manage content, sessions, and learner activity.
                </p>
            </div>
            <div class="el-lect-courses-tools">
                <?php if ($courseCount > 0): ?>
                    <span class="badge bg-primary rounded-pill el-lect-courses-count">
                        <?php echo el_lect_h((string)$courseCount); ?> total
                    </span>
                <?php endif; ?>
                <?php if ($courseCount > 1): ?>
                <div class="el-lect-courses-search">
                    <label class="form-label visually-hidden" for="elCourseSearch">Search courses</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="search" class="form-control" id="elCourseSearch" placeholder="Filter by code or title…" autocomplete="off">
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($courseCount === 0): ?>
            <div class="data-table-card">
                <div class="card-body text-center py-5 text-muted">
                    <i class="fas fa-book-open fa-3x mb-3 opacity-50"></i>
                    <p class="mb-2">No eLearning courses have been assigned to your account yet.</p>
                    <p class="small mb-0">Please contact the Head of Section or Administrator.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="el-lect-course-grid" id="elCourseGrid">
                <?php foreach ($courses as $index => $course): ?>
                    <?php
                    $courseCode = (string)$course['course_code'];
                    $courseName = (string)$course['course_name'];
                    $searchKey = strtolower($courseCode . ' ' . $courseName);
                    $statKey = strtoupper(trim($courseCode));
                    $stats = $courseStats[$statKey] ?? ['students' => 0, 'assignments' => 0, 'pending' => 0, 'sessions' => 0];
                    $manageUrl = '/wucportal/elearning/manage.php?course_code=' . urlencode($courseCode);
                    $progressUrl = '/wucportal/elearning/analytics.php?course_code=' . urlencode($courseCode);
                    $sessionsUrl = '/wucportal/elearning/sessions.php?course_code=' . urlencode($courseCode);
                    $accentClass = 'el-lect-accent-' . (($index % 4) + 1);
                    ?>
                    <article class="el-lect-course-tile <?php echo el_lect_h($accentClass); ?> wuc-in"
                             data-search="<?php echo el_lect_h($searchKey); ?>">
                        <a class="el-lect-tile-main" href="<?php echo el_lect_h($manageUrl); ?>">
                            <div class="el-lect-tile-banner">
                                <span class="el-lect-tile-code"><?php echo el_lect_h($courseCode); ?></span>
                                <?php if ((int)$stats['pending'] > 0): ?>
                                    <span class="el-lect-tile-alert" title="Submissions awaiting grades">
                                        <i class="fas fa-circle-exclamation"></i>
                                        <?php echo el_lect_h((string)$stats['pending']); ?> to grade
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="el-lect-tile-body">
                                <h3 class="el-lect-tile-name"><?php echo el_lect_h($courseName); ?></h3>
                                <ul class="el-lect-tile-metrics" aria-label="Course activity summary">
                                    <li>
                                        <i class="fas fa-user-graduate"></i>
                                        <strong><?php echo number_format((int)$stats['students']); ?></strong>
                                        <span>Students</span>
                                    </li>
                                    <li>
                                        <i class="fas fa-file-alt"></i>
                                        <strong><?php echo number_format((int)$stats['assignments']); ?></strong>
                                        <span>Tasks</span>
                                    </li>
                                    <li>
                                        <i class="fas fa-video"></i>
                                        <strong><?php echo number_format((int)$stats['sessions']); ?></strong>
                                        <span>Live (14d)</span>
                                    </li>
                                </ul>
                            </div>
                            <span class="el-lect-tile-enter">
                                Open course studio <i class="fas fa-arrow-right"></i>
                            </span>
                        </a>
                        <div class="el-lect-tile-actions">
                            <a href="<?php echo el_lect_h($manageUrl); ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-sliders"></i> Manage
                            </a>
                            <a href="<?php echo el_lect_h($progressUrl); ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-chart-line"></i> Progress
                            </a>
                            <a href="<?php echo el_lect_h($sessionsUrl); ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-video"></i> Sessions
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="alert alert-light border mt-3 mb-0 d-none" id="elCourseSearchEmpty">
                <i class="fas fa-search me-2"></i>No courses match your filter.
            </div>
        <?php endif; ?>
    </section>
</div>

<?php if ($courseCount > 1): ?>
<script>
(function () {
    var input = document.getElementById('elCourseSearch');
    var tiles = document.querySelectorAll('#elCourseGrid .el-lect-course-tile');
    var empty = document.getElementById('elCourseSearchEmpty');
    if (!input || !tiles.length) {
        return;
    }
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var visible = 0;
        tiles.forEach(function (tile) {
            var match = q === '' || (tile.getAttribute('data-search') || '').indexOf(q) !== -1;
            tile.classList.toggle('d-none', !match);
            if (match) {
                visible++;
            }
        });
        if (empty) {
            empty.classList.toggle('d-none', visible > 0 || q === '');
        }
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
