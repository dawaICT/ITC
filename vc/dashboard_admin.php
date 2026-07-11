<?php
require "includes/admin.php";

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

$stats = [
    ['label' => 'Students', 'value' => $countTableRows($db, 'students'), 'icon' => 'glyphicon-user'],
    ['label' => 'Staff', 'value' => $countTableRows($db, 'staff'), 'icon' => 'glyphicon-briefcase'],
    ['label' => 'Programs', 'value' => $countTableRows($db, 'programs'), 'icon' => 'glyphicon-th-list'],
    ['label' => 'Courses', 'value' => $countTableRows($db, 'courses'), 'icon' => 'glyphicon-check'],
];
?>

<main class="vc-dashboard-shell">
    <section class="vc-dashboard-hero">
        <div>
            <p class="vc-eyebrow">Executive Dashboard</p>
            <h1>Vice Chancellor Overview</h1>
            <p class="vc-summary">Monitor academic operations, staffing, programs, and learner records from one responsive view.</p>
        </div>
        <a class="vc-action" href="reportManager.php">
            <span class="glyphicon glyphicon-stats" aria-hidden="true"></span>
            Reports
        </a>
    </section>

    <section class="vc-stat-grid" aria-label="Executive metrics">
        <?php foreach ($stats as $stat): ?>
            <article class="vc-stat-card">
                <span class="vc-stat-icon"><span class="glyphicon <?php echo htmlspecialchars($stat['icon']); ?>"></span></span>
                <span>
                    <strong><?php echo number_format((int)$stat['value']); ?></strong>
                    <span><?php echo htmlspecialchars($stat['label']); ?></span>
                </span>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="vc-dashboard-grid">
        <article class="vc-panel">
            <h2>Quick Actions</h2>
            <div class="vc-action-list">
                <a href="students_by_admin.php"><span class="glyphicon glyphicon-user"></span> Students</a>
                <a href="staff.php"><span class="glyphicon glyphicon-briefcase"></span> Staff</a>
                <a href="programs.php"><span class="glyphicon glyphicon-th-list"></span> Programs</a>
                <a href="courses.php"><span class="glyphicon glyphicon-check"></span> Courses</a>
                <a href="assessments.php"><span class="glyphicon glyphicon-tasks"></span> Assessments</a>
                <a href="exams.php"><span class="glyphicon glyphicon-edit"></span> Exams</a>
                <a href="semester.php"><span class="glyphicon glyphicon-list-alt"></span> Semester Courses</a>
                <a href="news_events.php"><span class="glyphicon glyphicon-calendar"></span> Calendar</a>
            </div>
        </article>

        <article class="vc-panel">
            <h2>Announcements</h2>
            <p class="vc-panel-copy">Use reports and quick actions to monitor student records, staffing, programmes, courses, assessments, and academic calendar updates.</p>
            <div class="vc-action-list">
                <a href="reportManager.php"><span class="glyphicon glyphicon-stats"></span> Open Reports</a>
                <a href="news_events.php"><span class="glyphicon glyphicon-bullhorn"></span> Manage Calendar Updates</a>
            </div>
        </article>
    </section>
</main>
</body>
</html>
