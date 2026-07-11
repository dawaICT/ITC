<?php
$page_title = 'Student Login Activity';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/../includes/report_print.php';

if (!function_exists('lecturer_login_log_h')) {
    function lecturer_login_log_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('lecturer_login_log_parse_ua')) {
    function lecturer_login_log_parse_ua(?string $ua): array
    {
        $ua = (string)$ua;
        $device = 'desktop';
        $deviceIcon = 'fa-laptop';
        $browser = 'Browser';
        $browserIcon = 'fa-globe';

        if (preg_match('/mobile|android|iphone|ipad|phone/i', $ua)) {
            if (preg_match('/ipad|tablet/i', $ua)) {
                $device = 'tablet';
                $deviceIcon = 'fa-tablet-alt';
            } else {
                $device = 'mobile';
                $deviceIcon = 'fa-mobile-alt';
            }
        }

        if (preg_match('/firefox/i', $ua)) {
            $browser = 'Firefox';
            $browserIcon = 'fa-firefox-browser';
        } elseif (preg_match('/chrome|crios/i', $ua)) {
            $browser = 'Chrome';
            $browserIcon = 'fa-chrome';
        } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome|crios/i', $ua)) {
            $browser = 'Safari';
            $browserIcon = 'fa-safari';
        } elseif (preg_match('/edge|edg/i', $ua)) {
            $browser = 'Edge';
            $browserIcon = 'fa-edge';
        } elseif (preg_match('/msie|trident/i', $ua)) {
            $browser = 'IE';
            $browserIcon = 'fa-internet-explorer';
        }

        return [
            'device' => $device,
            'device_icon' => $deviceIcon,
            'browser' => $browser,
            'browser_icon' => $browserIcon,
        ];
    }
}

if (!function_exists('lecturer_login_log_date')) {
    function lecturer_login_log_date($value): string
    {
        $value = trim((string)$value);
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return ($date && $date->format('Y-m-d') === $value) ? $value : '';
    }
}

if (!function_exists('lecturer_login_log_time_ago')) {
    function lecturer_login_log_time_ago(?string $datetime): string
    {
        if (!$datetime || strtotime($datetime) === false) {
            return '-';
        }
        $diff = time() - strtotime($datetime);
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }
        if ($diff < 604800) {
            return floor($diff / 86400) . 'd ago';
        }
        return date('M j', strtotime($datetime));
    }
}

if (!wuc_table_exists($db, 'login_activity')) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS login_activity (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) NOT NULL,
            user_type ENUM('student','staff') NOT NULL DEFAULT 'student',
            user_name VARCHAR(150) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user_id (user_id),
            KEY idx_user_type (user_type),
            KEY idx_login_at (login_at),
            KEY idx_user_type_login (user_type, login_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('Failed to create login_activity table: ' . $e->getMessage());
    }
}

$staffId = (string)($_SESSION['staff_id'] ?? '');

// Handle Clear Term Logs and Restore Logs actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wuc_verify_csrf();
    $jsonPath = __DIR__ . '/../logs/lecturer_cleared_logs.json';
    
    if (isset($_POST['clear_logs'])) {
        $clearedData = [];
        if (!is_dir(dirname($jsonPath))) {
            mkdir(dirname($jsonPath), 0777, true);
        }
        if (file_exists($jsonPath)) {
            $content = file_get_contents($jsonPath);
            $clearedData = json_decode($content, true) ?: [];
        }
        $clearedData[$staffId] = date('Y-m-d H:i:s');
        file_put_contents($jsonPath, json_encode($clearedData, JSON_PRETTY_PRINT));
        header('Location: student_login_log.php?cleared=1');
        exit();
    }
    
    if (isset($_POST['restore_logs'])) {
        if (file_exists($jsonPath)) {
            $content = file_get_contents($jsonPath);
            $clearedData = json_decode($content, true) ?: [];
            if (isset($clearedData[$staffId])) {
                unset($clearedData[$staffId]);
                file_put_contents($jsonPath, json_encode($clearedData, JSON_PRETTY_PRINT));
            }
        }
        header('Location: student_login_log.php?restored=1');
        exit();
    }
}

// Retrieve clear timestamp
$clearedAt = null;
$jsonPath = __DIR__ . '/../logs/lecturer_cleared_logs.json';
if (file_exists($jsonPath)) {
    $clearedData = json_decode(file_get_contents($jsonPath), true) ?: [];
    if (!empty($clearedData[$staffId])) {
        $clearedAt = $clearedData[$staffId];
    }
}

$students = [];
$assignedStudentsListSql = "
    SELECT DISTINCT assigned.Sid AS student_id,
           COALESCE(NULLIF(TRIM(CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, ''))), ''), assigned.Sid) AS student_name
    FROM (
        SELECT DISTINCT cr.Sid
        FROM course_registration cr
        INNER JOIN course_lecturer cl
            ON UPPER(TRIM(cr.course_code)) = UPPER(TRIM(cl.course_code))
        WHERE cl.staff_id = ?
          AND (cl.status IS NULL OR TRIM(cl.status) = '' OR LOWER(TRIM(cl.status)) IN ('active', 'assigned', 'current'))
          AND (cr.is_active IS NULL OR cr.is_active = 1)
    ) assigned
    LEFT JOIN students s ON assigned.Sid = s.SID
    ORDER BY student_name ASC
";
if ($stmtStud = $db->prepare($assignedStudentsListSql)) {
    $stmtStud->bind_param('s', $staffId);
    $stmtStud->execute();
    $resStud = $stmtStud->get_result();
    while ($row = $resStud->fetch_assoc()) {
        $students[] = $row;
    }
    $stmtStud->close();
}

$perPageOptions = [25, 50, 100];
$requestedPerPage = (int)($_GET['per_page'] ?? 25);
$perPage = in_array($requestedPerPage, $perPageOptions, true) ? $requestedPerPage : 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$filterSid = trim((string)($_GET['sid'] ?? ''));
$defaultWeekStart = date('Y-m-d', strtotime('monday this week'));
$defaultWeekEnd = date('Y-m-d', strtotime('sunday this week'));
$filterFrom = array_key_exists('date_from', $_GET) ? lecturer_login_log_date($_GET['date_from'] ?? '') : $defaultWeekStart;
$filterTo = array_key_exists('date_to', $_GET) ? lecturer_login_log_date($_GET['date_to'] ?? '') : $defaultWeekEnd;
$registerFromSql = ($filterFrom !== '' ? $filterFrom : $defaultWeekStart) . ' 00:00:00';
$registerToSql = ($filterTo !== '' ? $filterTo : $defaultWeekEnd) . ' 23:59:59';

$assignedStudentsSql = "SELECT DISTINCT cr.Sid
    FROM course_registration cr
    INNER JOIN course_lecturer cl
        ON UPPER(TRIM(cr.course_code)) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(cl.course_code)) COLLATE utf8mb4_unicode_ci
    WHERE cl.staff_id COLLATE utf8mb4_unicode_ci = ?
      AND (cl.status IS NULL OR TRIM(cl.status) = '' OR LOWER(TRIM(cl.status)) IN ('active', 'assigned', 'current'))
      AND (cr.is_active IS NULL OR cr.is_active = 1)";

$programJoin = "LEFT JOIN (
        SELECT Sid, MAX(program_code) AS program_code
        FROM student_program
        GROUP BY Sid
    ) sp ON la.user_id COLLATE utf8mb4_unicode_ci = sp.Sid COLLATE utf8mb4_unicode_ci";

$joinClause = "LEFT JOIN students s
        ON la.user_id COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci
    {$programJoin}";

$where = [
    "la.user_type = 'student'",
    "la.user_id IN ({$assignedStudentsSql})",
];
$paramTypes = 's';
$paramVals = [$staffId];

if ($clearedAt !== null) {
    $where[] = "la.login_at > ?";
    $paramTypes .= 's';
    $paramVals[] = $clearedAt;
}

if ($filterSid !== '') {
    $where[] = "(la.user_id LIKE ? OR s.Fname LIKE ? OR s.Lname LIKE ? OR la.user_name LIKE ?)";
    $like = '%' . $filterSid . '%';
    $paramTypes .= 'ssss';
    array_push($paramVals, $like, $like, $like, $like);
}
if ($filterFrom !== '') {
    $where[] = 'la.login_at >= ?';
    $paramTypes .= 's';
    $paramVals[] = $filterFrom . ' 00:00:00';
}
if ($filterTo !== '') {
    $where[] = 'la.login_at <= ?';
    $paramTypes .= 's';
    $paramVals[] = $filterTo . ' 23:59:59';
}

$baseWhere = implode(' AND ', $where);
$totalRows = 0;
$queryError = '';

$countSql = "SELECT COUNT(DISTINCT la.user_id, DATE(la.login_at)) AS total FROM login_activity la {$joinClause} WHERE {$baseWhere}";
if ($stmt = $db->prepare($countSql)) {
    $stmt->bind_param($paramTypes, ...$paramVals);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalRows = (int)($row['total'] ?? 0);
    $stmt->close();
} else {
    $queryError = 'Unable to prepare login activity count.';
    error_log('student_login_log count prepare failed: ' . $db->error);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$logs = [];
$dataSql = "SELECT MIN(la.id) AS id, la.user_id, la.user_name, MAX(la.login_at) AS login_at, COUNT(la.id) AS login_count,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, ''))), ''), la.user_name, '') AS resolved_name,
        sp.program_code
    FROM login_activity la
    {$joinClause}
    WHERE {$baseWhere}
    GROUP BY la.user_id, DATE(la.login_at), la.user_name, sp.program_code
    ORDER BY login_at DESC, id DESC
    LIMIT ? OFFSET ?";
$dataTypes = $paramTypes . 'ii';
$dataVals = array_merge($paramVals, [$perPage, $offset]);
if ($stmt = $db->prepare($dataSql)) {
    $stmt->bind_param($dataTypes, ...$dataVals);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_object()) {
        $logs[] = $row;
    }
    $stmt->close();
} else {
    $queryError = 'Unable to load login activity records.';
    error_log('student_login_log data prepare failed: ' . $db->error);
}

$stats = [
    'today' => 0,
    'week' => 0,
    'unique' => 0,
    'assigned' => 0,
];

$statsTypes = 'sss';
$statsVals = [$registerFromSql, $registerToSql, $staffId];
$statsClearedWhere = "";
if ($clearedAt !== null) {
    $statsClearedWhere = " AND la.login_at > ?";
    $statsTypes .= 's';
    $statsVals[] = $clearedAt;
}

$statsSql = "SELECT
        SUM(CASE WHEN DATE(la.login_at) = CURDATE() THEN 1 ELSE 0 END) AS today_count,
        SUM(CASE WHEN la.login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week_count,
        COUNT(DISTINCT la.user_id) AS unique_students
    FROM login_activity la
    WHERE la.user_type = 'student'
      AND la.login_at >= ?
      AND la.login_at <= ?
      AND la.user_id IN ({$assignedStudentsSql})
      {$statsClearedWhere}";
if ($stmt = $db->prepare($statsSql)) {
    $stmt->bind_param($statsTypes, ...$statsVals);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stats['today'] = (int)($row['today_count'] ?? 0);
    $stats['week'] = (int)($row['week_count'] ?? 0);
    $stats['unique'] = (int)($row['unique_students'] ?? 0);
    $stmt->close();
}

$assignedCountSql = "SELECT COUNT(DISTINCT scoped.Sid) AS assigned_count FROM ({$assignedStudentsSql}) scoped";
if ($stmt = $db->prepare($assignedCountSql)) {
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stats['assigned'] = (int)($row['assigned_count'] ?? 0);
    $stmt->close();
}

$registerRows = [];
$registerWhere = [];
$registerTypes = 'sss';
$registerVals = [$staffId, $registerFromSql, $registerToSql];

$clearedJoinCond = "";
if ($clearedAt !== null) {
    $clearedJoinCond = " AND la.login_at > ?";
    $registerTypes .= 's';
    $registerVals[] = $clearedAt;
}

if ($filterSid !== '') {
    $registerWhere[] = "(assigned.Sid LIKE ? OR s.Fname LIKE ? OR s.Lname LIKE ?)";
    $like = '%' . $filterSid . '%';
    $registerTypes .= 'sss';
    array_push($registerVals, $like, $like, $like);
}
$registerWhereSql = $registerWhere ? 'WHERE ' . implode(' AND ', $registerWhere) : '';
$registerSql = "SELECT assigned.Sid,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, ''))), ''), assigned.Sid) AS student_name,
        sp.program_code,
        COUNT(la.id) AS login_count,
        MIN(la.login_at) AS first_login,
        MAX(la.login_at) AS last_login
    FROM ({$assignedStudentsSql}) assigned
    LEFT JOIN students s ON assigned.Sid COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci
    LEFT JOIN (
        SELECT Sid, MAX(program_code) AS program_code
        FROM student_program
        GROUP BY Sid
    ) sp ON assigned.Sid COLLATE utf8mb4_unicode_ci = sp.Sid COLLATE utf8mb4_unicode_ci
    LEFT JOIN login_activity la
        ON la.user_id COLLATE utf8mb4_unicode_ci = assigned.Sid COLLATE utf8mb4_unicode_ci
       AND la.user_type = 'student'
       AND la.login_at >= ?
       AND la.login_at <= ?
       {$clearedJoinCond}
    {$registerWhereSql}
    GROUP BY assigned.Sid, student_name, sp.program_code
    ORDER BY student_name ASC, assigned.Sid ASC";
if ($stmt = $db->prepare($registerSql)) {
    $stmt->bind_param($registerTypes, ...$registerVals);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_object()) {
        $registerRows[] = $row;
    }
    $stmt->close();
} else {
    $queryError = 'Unable to load the online register.';
    error_log('student_login_log register prepare failed: ' . $db->error);
}

$presentCount = 0;
foreach ($registerRows as $registerRow) {
    if ((int)($registerRow->login_count ?? 0) > 0) {
        $presentCount++;
    }
}
$stats['assigned'] = count($registerRows) ?: $stats['assigned'];
$stats['unique'] = $presentCount;

if (!function_exists('lecturer_login_log_qs')) {
    function lecturer_login_log_qs(int $page): string
    {
        $params = $_GET;
        $params['page'] = $page;
        return '?' . http_build_query($params);
    }
}

$fromNum = $totalRows ? $offset + 1 : 0;
$toNum = $offset + count($logs);
?>

<?php
render_report_print_styles();
render_report_print_script();
render_report_print_header(
    'Weekly Online Register',
    'Student login register for assigned courses',
    [
        'Lecturer' => $staffId,
        'Week From' => $filterFrom,
        'Week To' => $filterTo,
        'Assigned Students' => number_format($stats['assigned']),
        'Logged In' => number_format($presentCount),
    ]
);
?>

<style>
.lecturer-login-log-page .page-hero {
    background: #fff;
    border: 1px solid #e6eaf2;
    border-radius: 8px;
    padding: 1rem 1.1rem;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
}
.lecturer-login-log-page .page-title {
    font-size: 1.35rem;
    font-weight: 700;
    margin: 0;
    color: #172033;
}
.lecturer-login-log-page .stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(140px, 1fr));
    gap: .75rem;
}
.lecturer-login-log-page .stat-card {
    background: #fff;
    border: 1px solid #e6eaf2;
    border-radius: 8px;
    padding: .85rem;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .05);
}
.lecturer-login-log-page .stat-card span {
    display: block;
    color: #64748b;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.lecturer-login-log-page .stat-card strong {
    display: block;
    margin-top: .25rem;
    font-size: 1.45rem;
    color: #172033;
}
.lecturer-login-log-page .filter-card,
.lecturer-login-log-page .records-card {
    background: #fff;
    border: 1px solid #e6eaf2;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
}
.lecturer-login-log-page .filter-card {
    padding: 1rem;
}
.lecturer-login-log-page .records-card .card-header {
    background: #fff;
    border-bottom: 1px solid #e6eaf2;
    padding: .9rem 1rem;
}
.lecturer-login-log-page .table th {
    white-space: nowrap;
    font-size: .78rem;
    text-transform: uppercase;
    color: #64748b;
}
.lecturer-login-log-page .student-chip {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    font-weight: 700;
    color: #17335f;
}
.lecturer-login-log-page .student-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #e8f0ff;
    color: #1f4f99;
    font-size: .8rem;
    font-weight: 800;
}
.lecturer-login-log-page .ua-cell {
    max-width: 320px;
}
.lecturer-login-log-page .register-status {
    display: inline-flex;
    min-width: 78px;
    justify-content: center;
}
.lecturer-login-log-page .bg-purple-light {
    background-color: #f1e6ff;
}
.lecturer-login-log-page .text-purple {
    color: #6f42c1;
    font-weight: 600;
}
.lecturer-login-log-page [data-bs-toggle="collapse"] i {
    transition: transform 0.2s;
}
.lecturer-login-log-page [data-bs-toggle="collapse"]:not(.collapsed) i {
    transform: rotate(180deg);
}
@media print {
    body.print-register-only .lecturer-login-log-page .page-hero,
    body.print-register-only .lecturer-login-log-page .stat-grid,
    body.print-register-only .lecturer-login-log-page .filter-card,
    body.print-register-only .lecturer-login-log-page .activity-section {
        display: none !important;
    }
    body.print-register-only .online-register-section {
        display: block !important;
    }
}
@media (max-width: 900px) {
    .lecturer-login-log-page .stat-grid {
        grid-template-columns: repeat(2, minmax(140px, 1fr));
    }
}
@media (max-width: 560px) {
    .lecturer-login-log-page .stat-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container-fluid px-4 portal-dashboard lecturer-login-log-page">
    <?php if (isset($_GET['cleared'])): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3 d-print-none" role="alert">
            <i class="fas fa-check-circle me-2"></i>Your student login logs view for the term has been cleared.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['restored'])): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3 d-print-none" role="alert">
            <i class="fas fa-check-circle me-2"></i>All student login logs history has been restored.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="page-hero mb-3 mt-2 d-print-none">
        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
            <div>
                <h1 class="page-title"><i class="fas fa-history me-2 text-primary"></i>Student Login Activity</h1>
                <p class="text-muted mb-0">Weekly register resets every Monday and includes students in your assigned courses.</p>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <?php if ($clearedAt !== null): ?>
                    <span class="badge bg-warning text-dark border py-2 px-3" title="Logs prior to this date are hidden on your portal.">
                        Filtered since <?php echo date('M j, Y g:i A', strtotime($clearedAt)); ?>
                    </span>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <button type="submit" name="restore_logs" class="btn btn-outline-secondary btn-sm" title="Show all log history">
                            <i class="fas fa-undo me-1"></i>Show All
                        </button>
                    </form>
                <?php else: ?>
                    <form method="post" onsubmit="return confirm('Are you sure you want to clear your view of the student login logs for this term? This action only hides the records on your side and cannot be undone.');" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                        <button type="submit" name="clear_logs" class="btn btn-danger btn-sm">
                            <i class="fas fa-trash-alt me-1"></i>Clear Term Logs
                        </button>
                    </form>
                <?php endif; ?>
                <div class="text-muted small">
                    Showing <?php echo number_format($fromNum); ?>-<?php echo number_format($toNum); ?> of <?php echo number_format($totalRows); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="stat-grid mb-3 d-print-none">
        <div class="stat-card"><span>Today</span><strong><?php echo number_format($stats['today']); ?></strong></div>
        <div class="stat-card"><span>This Week</span><strong><?php echo number_format($stats['week']); ?></strong></div>
        <div class="stat-card"><span>Register Present</span><strong><?php echo number_format($stats['unique']); ?></strong></div>
        <div class="stat-card"><span>Assigned Students</span><strong><?php echo number_format($stats['assigned']); ?></strong></div>
    </div>

    <?php if ($queryError !== ''): ?>
        <div class="alert alert-danger"><?php echo lecturer_login_log_h($queryError); ?></div>
    <?php endif; ?>

    <form method="get" class="filter-card mb-3 d-print-none">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="sid">Student</label>
                <select class="form-select" id="sid" name="sid">
                    <option value="">All assigned students</option>
                    <?php foreach ($students as $student): ?>
                        <?php $sidVal = (string)$student['student_id']; ?>
                        <option value="<?php echo lecturer_login_log_h($sidVal); ?>" <?php echo strcasecmp($filterSid, $sidVal) === 0 ? 'selected' : ''; ?>>
                            <?php echo lecturer_login_log_h($sidVal . ' - ' . $student['student_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="date_from">From</label>
                <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo lecturer_login_log_h($filterFrom); ?>">
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="date_to">To</label>
                <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo lecturer_login_log_h($filterTo); ?>">
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="per_page">Rows</label>
                <select class="form-select" id="per_page" name="per_page">
                    <?php foreach ($perPageOptions as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-search me-1"></i>Filter</button>
                <a href="student_login_log.php" class="btn btn-outline-secondary" title="Clear filters"><i class="fas fa-undo"></i></a>
            </div>
        </div>
    </form>

    <div class="records-card mb-4 online-register-section">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong><i class="fas fa-clipboard-check me-2 text-primary"></i>Weekly online register</strong>
                <span class="badge bg-light text-dark border ms-2"><?php echo lecturer_login_log_h($filterFrom); ?> to <?php echo lecturer_login_log_h($filterTo); ?></span>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm d-print-none" onclick="printOnlineRegister()">
                <i class="fas fa-print me-1"></i>Print Register
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Program</th>
                        <th>Status</th>
                        <th>First Login</th>
                        <th>Last Login</th>
                        <th>Logins</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($registerRows)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No assigned students were found for this register.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($registerRows as $index => $row): ?>
                        <?php
                        $loginCount = (int)($row->login_count ?? 0);
                        $isPresent = $loginCount > 0;
                        ?>
                        <tr>
                            <td><?php echo number_format($index + 1); ?></td>
                            <td class="fw-semibold"><?php echo lecturer_login_log_h($row->Sid); ?></td>
                            <td><?php echo lecturer_login_log_h($row->student_name); ?></td>
                            <td><?php echo !empty($row->program_code) ? lecturer_login_log_h($row->program_code) : '<span class="text-muted">-</span>'; ?></td>
                            <td>
                                <span class="badge register-status <?php echo $isPresent ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $isPresent ? 'Present' : 'No login'; ?>
                                </span>
                            </td>
                            <td><?php echo $row->first_login ? lecturer_login_log_h(date('M j, Y g:i A', strtotime((string)$row->first_login))) : '<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo $row->last_login ? lecturer_login_log_h(date('M j, Y g:i A', strtotime((string)$row->last_login))) : '<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo number_format($loginCount); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="records-card mb-4 activity-section">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong><i class="fas fa-list me-2 text-primary"></i>Login records</strong>
                <span class="badge bg-light text-dark border ms-2"><?php echo number_format($totalRows); ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php
                $isFilteredOrPaginated = isset($_GET['page']) || isset($_GET['sid']) || isset($_GET['date_from']) || isset($_GET['date_to']);
                $collapseClass = $isFilteredOrPaginated ? 'show' : '';
                $btnCollapsedClass = $isFilteredOrPaginated ? '' : 'collapsed';
                $ariaExpanded = $isFilteredOrPaginated ? 'true' : 'false';
                ?>
                <button class="btn btn-sm btn-outline-primary <?php echo $btnCollapsedClass; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#loginRecordsCollapse" aria-expanded="<?php echo $ariaExpanded; ?>" aria-controls="loginRecordsCollapse">
                    <i class="fas fa-chevron-down me-1"></i> <span class="collapse-text"><?php echo $isFilteredOrPaginated ? 'Hide Records' : 'Show Records'; ?></span>
                </button>
                <small class="text-muted ms-2">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></small>
            </div>
        </div>
        <div class="collapse <?php echo $collapseClass; ?>" id="loginRecordsCollapse">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Name</th>
                            <th>Program</th>
                            <th>Date</th>
                            <th>Login Count</th>
                            <th>Last Login</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No login activity found for the selected criteria.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($logs as $index => $log): ?>
                            <?php
                            $name = trim((string)($log->resolved_name ?? ''));
                            $initial = strtoupper(substr($name !== '' ? $name : (string)$log->user_id, 0, 1));
                            $loginAt = strtotime((string)$log->login_at);
                            $loginCount = (int)($log->login_count ?? 1);
                            ?>
                            <tr>
                                <td class="text-muted"><?php echo number_format($offset + $index + 1); ?></td>
                                <td>
                                    <span class="student-chip">
                                        <span class="student-avatar"><?php echo lecturer_login_log_h($initial); ?></span>
                                        <?php echo lecturer_login_log_h($log->user_id); ?>
                                    </span>
                                </td>
                                <td><?php echo $name !== '' ? lecturer_login_log_h($name) : '<span class="text-muted">-</span>'; ?></td>
                                <td>
                                    <?php if (!empty($log->program_code)): ?>
                                        <span class="badge bg-light text-dark border"><?php echo lecturer_login_log_h($log->program_code); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($loginAt): ?>
                                        <?php echo lecturer_login_log_h(date('M j, Y', $loginAt)); ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-purple-light text-purple border">
                                        <?php echo number_format($loginCount); ?> logins
                                    </span>
                                </td>
                                <td>
                                    <?php if ($loginAt): ?>
                                        <div><?php echo lecturer_login_log_h(date('g:i A', $loginAt)); ?></div>
                                        <span class="badge <?php echo (time() - $loginAt < 3600) ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo lecturer_login_log_h(lecturer_login_log_time_ago($log->login_at)); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white">
                    <nav aria-label="Login activity pagination">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo lecturer_login_log_h(lecturer_login_log_qs(1)); ?>">First</a>
                            </li>
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo lecturer_login_log_h(lecturer_login_log_qs(max(1, $page - 1))); ?>">Prev</a>
                            </li>
                            <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo lecturer_login_log_h(lecturer_login_log_qs(min($totalPages, $page + 1))); ?>">Next</a>
                            </li>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo lecturer_login_log_h(lecturer_login_log_qs($totalPages)); ?>">Last</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function printOnlineRegister() {
    document.body.classList.add('print-register-only');
    printReport('Weekly Online Register');
    setTimeout(function () {
        document.body.classList.remove('print-register-only');
    }, 1500);
}

document.addEventListener('DOMContentLoaded', function() {
    var collapseEl = document.getElementById('loginRecordsCollapse');
    if (collapseEl) {
        collapseEl.addEventListener('show.bs.collapse', function () {
            var btn = document.querySelector('[data-bs-target="#loginRecordsCollapse"]');
            if (btn) {
                var txt = btn.querySelector('.collapse-text');
                if (txt) txt.textContent = 'Hide Records';
            }
        });
        collapseEl.addEventListener('hide.bs.collapse', function () {
            var btn = document.querySelector('[data-bs-target="#loginRecordsCollapse"]');
            if (btn) {
                var txt = btn.querySelector('.collapse-text');
                if (txt) txt.textContent = 'Show Records';
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
