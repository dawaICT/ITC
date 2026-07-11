<?php
$page_title = 'Login Activity Log';
require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();
require_once __DIR__ . '/includes/nav.php';
?>
<style>
    /* stat-card, stat-icon → assets/css/dashboard.css */
    #activityTable thead th {
        font-size: 0.8rem; text-transform: uppercase;
        letter-spacing: 0.5px; color: #6c757d;
        padding-top: 1rem; padding-bottom: 1rem;
    }
</style>
<?php

// ── Config ──────────────────────────────────────────────────────────
$perPage = 30;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Filters
$filterUserId = trim($_GET['user_id'] ?? '');
$filterType   = trim($_GET['user_type'] ?? '');
$filterFrom   = trim($_GET['date_from'] ?? '');
$filterTo     = trim($_GET['date_to'] ?? '');

// ── Build WHERE clause ─────────────────────────────────────────────
$conditions = ['1=1'];
$paramTypes = '';
$paramVals  = [];

if ($filterUserId !== '') {
    $conditions[] = "(la.user_id LIKE ? OR la.user_name LIKE ? OR COALESCE(st.Fname,'') LIKE ? OR COALESCE(st.Lname,'') LIKE ? OR COALESCE(sf.Fname,'') LIKE ? OR COALESCE(sf.Lname,'') LIKE ?)";
    $paramTypes .= 'ssssss';
    $like = "%{$filterUserId}%";
    $paramVals = array_merge($paramVals, [$like, $like, $like, $like, $like, $like]);
}
if ($filterType !== '' && in_array($filterType, ['student', 'staff'])) {
    $conditions[] = "la.user_type = ?";
    $paramTypes .= 's';
    $paramVals[] = $filterType;
}
if ($filterFrom !== '') {
    $conditions[] = "la.login_at >= ?";
    $paramTypes .= 's';
    $paramVals[] = $filterFrom . ' 00:00:00';
}
if ($filterTo !== '') {
    $conditions[] = "la.login_at <= ?";
    $paramTypes .= 's';
    $paramVals[] = $filterTo . ' 23:59:59';
}

$whereClause = implode(' AND ', $conditions);

// ── Common JOIN to resolve names from staff/students tables ────────
$joinClause = "LEFT JOIN students st ON la.user_type = 'student' AND la.user_id = st.SID
               LEFT JOIN staff sf    ON la.user_type = 'staff'   AND la.user_id = sf.staff_id";

// ── Count total ─────────────────────────────────────────────────────
$totalRows = 0;
$countSql = "SELECT COUNT(*) AS total FROM login_activity la {$joinClause} WHERE {$whereClause}";
if ($stmt = $db->prepare($countSql)) {
    if ($paramTypes) { $stmt->bind_param($paramTypes, ...$paramVals); }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $totalRows = (int)$row['total']; }
    $stmt->close();
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// ── Fetch page data (resolve name from staff/students if user_name is empty) ─
$logs = [];
$dataSql = "SELECT la.*,
                CASE
                    WHEN la.user_name IS NOT NULL AND la.user_name != ''
                        THEN la.user_name
                    WHEN la.user_type = 'student'
                        THEN CONCAT(COALESCE(st.Fname,''), ' ', COALESCE(st.Lname,''))
                    WHEN la.user_type = 'staff'
                        THEN CONCAT(COALESCE(sf.Fname,''), ' ', COALESCE(sf.Lname,''))
                    ELSE ''
                END AS resolved_name
            FROM login_activity la
            {$joinClause}
            WHERE {$whereClause}
            ORDER BY la.login_at DESC
            LIMIT {$perPage} OFFSET {$offset}";
if ($stmt = $db->prepare($dataSql)) {
    if ($paramTypes) { $stmt->bind_param($paramTypes, ...$paramVals); }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_object()) { $logs[] = $row; }
    $stmt->close();
}

// ── Dashboard stats ─────────────────────────────────────────────────
$stats = ['today_total' => 0, 'today_students' => 0, 'today_staff' => 0, 'week_total' => 0];
$statSql = "SELECT
    SUM(CASE WHEN DATE(login_at) = CURDATE() THEN 1 ELSE 0 END) AS today_total,
    SUM(CASE WHEN DATE(login_at) = CURDATE() AND user_type = 'student' THEN 1 ELSE 0 END) AS today_students,
    SUM(CASE WHEN DATE(login_at) = CURDATE() AND user_type = 'staff' THEN 1 ELSE 0 END) AS today_staff,
    SUM(CASE WHEN login_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week_total
    FROM login_activity";
if ($r = $db->query($statSql)) {
    if ($row = $r->fetch_assoc()) {
        foreach ($stats as $k => &$v) { $v = (int)($row[$k] ?? 0); }
        unset($v);
    }
    $r->free();
}

// Helpers
function admin_log_qs(int $p): string {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}
function admin_time_ago(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($dt));
}
function friendly_ip(string $ip): string {
    if ($ip === '::1' || $ip === '127.0.0.1') return '127.0.0.1 (local)';
    return $ip;
}
?>

    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-history me-2 text-primary"></i>Login Activity Log</h5>
                <p class="page-subtitle mb-0">Monitor all system access events and user authentications</p>
            </div>
            <div class="header-actions">
                <a href="index.php" class="btn btn-outline-primary shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-calendar-day"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats['today_total']) ?></h4>
                        <p class="text-muted small mb-0">Logins Today</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-user-graduate"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats['today_students']) ?></h4>
                        <p class="text-muted small mb-0">Total Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-user-tie"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats['today_staff']) ?></h4>
                        <p class="text-muted small mb-0">Total Staff</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3 text-white"><i class="fas fa-calendar-week"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats['week_total']) ?></h4>
                        <p class="text-muted small mb-0">This Week</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Inline Filter Bar -->
    <form method="GET" class="stat-card mb-4 p-3 border-0 bg-white">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Search User</label>
                <input type="text" class="form-control form-control-sm border-0 bg-light" name="user_id"
                       value="<?= htmlspecialchars($filterUserId) ?>" placeholder="ID or name…">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Type</label>
                <select class="form-select form-select-sm border-0 bg-light" name="user_type">
                    <option value="">All Types</option>
                    <option value="student" <?= $filterType === 'student' ? 'selected' : '' ?>>Students</option>
                    <option value="staff" <?= $filterType === 'staff' ? 'selected' : '' ?>>Staff</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">From</label>
                <input type="date" class="form-control form-control-sm border-0 bg-light" name="date_from"
                       value="<?= htmlspecialchars($filterFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">To</label>
                <input type="date" class="form-control form-control-sm border-0 bg-light" name="date_to"
                       value="<?= htmlspecialchars($filterTo) ?>">
            </div>
            <div class="col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-fill fw-bold">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="login_activity.php" class="btn btn-light btn-sm px-3" title="Reset">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </div>
    </form>

    <!-- Results Table -->
    <div class="stat-card p-0 border-0 bg-white overflow-hidden mb-5">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 px-4 border-bottom">
            <span class="fw-bold text-dark">
                <i class="fas fa-list-ul me-2 text-primary"></i>Activity Log
                <span class="badge bg-primary-subtle text-primary ms-2"><?= number_format($totalRows) ?> Total</span>
            </span>
            <span class="text-muted small">Showing page <?= $page ?> of <?= $totalPages ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="activityTable">
                    <thead class="table-light">
                        <tr>
                            <th width="40">#</th>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Login Time</th>
                            <th>IP Address</th>
                            <th>Browser</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                No login activity found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($logs as $i => $log): ?>
                        <tr>
                            <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                            <td>
                                <span class="fw-semibold <?= $log->user_type === 'staff' ? 'text-success' : 'text-primary' ?>">
                                    <?= htmlspecialchars($log->user_id) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars(trim($log->resolved_name) ?: '—') ?></td>
                            <td>
                                <?php if ($log->user_type === 'staff'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Staff</span>
                                <?php else: ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Student</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span title="<?= date('M j, Y g:i:s A', strtotime($log->login_at)) ?>">
                                    <?= date('M j, g:i A', strtotime($log->login_at)) ?>
                                </span>
                                <span class="badge <?= (time() - strtotime($log->login_at) < 3600) ? 'bg-success' : 'bg-secondary' ?> bg-opacity-75 ms-1">
                                    <?= admin_time_ago($log->login_at) ?>
                                </span>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars(friendly_ip($log->ip_address ?? '')) ?></td>
                            <td class="text-muted small" title="<?= htmlspecialchars($log->user_agent ?? '') ?>">
                                <?php
                                $ua = $log->user_agent ?? '';
                                if (strpos($ua, 'Firefox') !== false) { echo '<i class="fab fa-firefox-browser me-1"></i>Firefox'; }
                                elseif (strpos($ua, 'Edg') !== false)  { echo '<i class="fab fa-edge me-1"></i>Edge'; }
                                elseif (strpos($ua, 'Chrome') !== false) { echo '<i class="fab fa-chrome me-1"></i>Chrome'; }
                                elseif (strpos($ua, 'Safari') !== false) { echo '<i class="fab fa-safari me-1"></i>Safari'; }
                                elseif ($ua) { echo '<i class="fas fa-globe me-1"></i>Other'; }
                                else { echo '—'; }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white py-2">
            <nav aria-label="Activity log pagination">
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= admin_log_qs($page - 1) ?>"><i class="fas fa-chevron-left"></i></a>
                    </li>
                    <?php
                    $start = max(1, $page - 3);
                    $end   = min($totalPages, $page + 3);
                    if ($start > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?= admin_log_qs(1) ?>">1</a></li>
                        <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <?php endif;
                    for ($p = $start; $p <= $end; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= admin_log_qs($p) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor;
                    if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= admin_log_qs($totalPages) ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= admin_log_qs($page + 1) ?>"><i class="fas fa-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
