<?php
$page_title = 'Executive Dashboard';
require __DIR__ . '/includes/nav.php';
error_reporting(0);

function vc_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$countTableRows = function (mysqli $db, string $table): int {
    $tableEsc = $db->real_escape_string($table);
    $exists = $db->query("SHOW TABLES LIKE '{$tableEsc}'");
    if (!$exists || $exists->num_rows === 0) {
        return 0;
    }

    $result = $db->query("SELECT COUNT(*) AS total FROM `{$tableEsc}`");
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
};

$staff = null;
$sid = (string)($_SESSION['staff_id'] ?? '');
$sqlStaff = "SELECT s.*,
                    (SELECT GROUP_CONCAT(p.PosName SEPARATOR ', ')
                     FROM staff_positions sp
                     INNER JOIN positions p ON p.PosID = sp.PosID
                     WHERE sp.staff_id = s.staff_id) AS designation
             FROM staff s
             WHERE s.staff_id = ? LIMIT 1";
if ($stmtStaff = $db->prepare($sqlStaff)) {
    $stmtStaff->bind_param('s', $sid);
    if ($stmtStaff->execute()) {
        $resStaff = $stmtStaff->get_result();
        $staff = $resStaff ? $resStaff->fetch_object() : null;
    }
    $stmtStaff->close();
}

$fullName = $staff ? trim(($staff->title ?? '') . ' ' . ($staff->Fname ?? '') . ' ' . ($staff->Lname ?? '')) : 'Staff member';
$designation = trim((string)($staff->designation ?? ''));
$stats = [
    ['label' => 'Students', 'value' => $countTableRows($db, 'students'), 'icon' => 'fa-user-graduate'],
    ['label' => 'Staff', 'value' => $countTableRows($db, 'staff'), 'icon' => 'fa-users'],
    ['label' => 'Programs', 'value' => $countTableRows($db, 'programs'), 'icon' => 'fa-list'],
    ['label' => 'Courses', 'value' => $countTableRows($db, 'courses'), 'icon' => 'fa-check-circle'],
];
?>
<main class="vc-dashboard-shell portal-dashboard">
    <section class="vc-dashboard-hero">
        <div>
            <p class="vc-eyebrow">Executive Dashboard</p>
            <h1><?php echo vc_h($fullName); ?></h1>
            <p class="vc-summary">
                <?php echo vc_h($designation !== '' ? $designation : 'Role not assigned'); ?>
            </p>
        </div>
        <a class="vc-action" href="view_staff.php?view=<?php echo urlencode($sid); ?>">
            <i class="fas fa-user"></i>
            <span>View Profile</span>
        </a>
    </section>

    <?php if ($staff): ?>
    <section class="vc-stat-grid" aria-label="Executive metrics">
        <?php foreach ($stats as $stat): ?>
            <article class="vc-stat-card">
                <span class="vc-stat-icon"><i class="fas <?php echo vc_h($stat['icon']); ?>"></i></span>
                <span>
                    <strong><?php echo number_format((int)$stat['value']); ?></strong>
                    <span><?php echo vc_h($stat['label']); ?></span>
                </span>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="vc-dashboard-grid">
        <article class="vc-panel">
            <h2>Quick Actions</h2>
            <div class="vc-action-list">
                <a href="students_by_admin.php"><i class="fas fa-user-graduate"></i><span>Students</span></a>
                <a href="staff.php"><i class="fas fa-users"></i><span>Staff</span></a>
                <a href="programs.php"><i class="fas fa-list"></i><span>Programs</span></a>
                <a href="courses.php"><i class="fas fa-check-circle"></i><span>Courses</span></a>
                <a href="exams.php"><i class="fas fa-edit"></i><span>Exams</span></a>
            </div>
        </article>

        <article class="vc-panel">
            <h2>Announcements</h2>
            <p class="vc-panel-copy">Use this workspace to track academic operations, staffing, programme setup, course activity, and exam workflows.</p>
            <div class="vc-action-list">
                <a href="reportManager.php"><i class="fas fa-chart-line"></i><span>Open Reports</span></a>
                <a href="news_events.php"><i class="fas fa-bullhorn"></i><span>Calendar Updates</span></a>
            </div>
        </article>
    </section>
    <?php else: ?>
        <div class="vc-panel">
            <h2>Profile unavailable</h2>
            <p class="mb-0 text-muted">No staff record could be found for this session.</p>
        </div>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
