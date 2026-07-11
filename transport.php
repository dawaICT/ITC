<?php
declare(strict_types=1);

require_once __DIR__ . '/transport/includes/transport.php';

$page_title = 'Transport Management';

function transport_hub_scalar(mysqli $db, string $table, string $sql): float
{
    static $allowedTables = [
        'transport_cohorts', 'transport_enrollments', 'transport_sessions',
        'transport_vehicles', 'transport_payments', 'transport_incident_reports',
    ];
    if (!in_array($table, $allowedTables, true)) {
        return 0;
    }
    $check = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $check->bind_param('s', $table);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    if (!$exists) {
        return 0;
    }
    $result = $db->query($sql);
    $row = $result->fetch_row();
    $result->free();
    return isset($row[0]) ? (float)$row[0] : 0;
}

$hubStats = [
    'trainees' => (int)transport_hub_scalar($db, 'transport_enrollments', "SELECT COUNT(*) FROM transport_enrollments WHERE booking_status='booked' AND status IN ('enrolled','active')"),
    'cohorts' => (int)transport_hub_scalar($db, 'transport_cohorts', "SELECT COUNT(*) FROM transport_cohorts WHERE status IN ('open','in_progress')"),
    'vehicles' => (int)transport_hub_scalar($db, 'transport_vehicles', "SELECT COUNT(*) FROM transport_vehicles WHERE status='available'"),
    'today' => (int)transport_hub_scalar($db, 'transport_sessions', "SELECT COUNT(*) FROM transport_sessions WHERE session_date=CURDATE() AND status='scheduled'"),
];

$hubAlerts = [
    [
        'label' => 'Payments awaiting review',
        'value' => (int)transport_hub_scalar($db, 'transport_payments', "SELECT COUNT(*) FROM transport_payments WHERE status='submitted'"),
        'icon' => 'fas fa-money-check-dollar',
        'href' => '/wucportal/transport/payments.php',
        'tone' => 'warning',
    ],
    [
        'label' => 'Fleet compliance alerts',
        'value' => (int)transport_hub_scalar($db, 'transport_vehicles', "SELECT COUNT(*) FROM transport_vehicles WHERE status IN ('maintenance','unavailable') OR fitness_expiry < CURDATE() OR insurance_expiry < CURDATE() OR next_service_due < CURDATE()"),
        'icon' => 'fas fa-triangle-exclamation',
        'href' => '/wucportal/transport/fleet_dashboard.php',
        'tone' => 'danger',
    ],
    [
        'label' => 'Open incident reports',
        'value' => (int)transport_hub_scalar($db, 'transport_incident_reports', "SELECT COUNT(*) FROM transport_incident_reports WHERE status IN ('open','in_review')"),
        'icon' => 'fas fa-shield-halved',
        'href' => '/wucportal/transport/compliance.php',
        'tone' => 'danger',
    ],
];

$workspaces = [
    [
        'title' => 'Run Training',
        'description' => 'Move trainees from enrolment to completed sessions and attendance.',
        'icon' => 'fas fa-chalkboard-user',
        'links' => [
            ['Trainees', 'trainees.php', 'fas fa-user-plus'],
            ['Cohorts', 'cohorts.php', 'fas fa-layer-group'],
            ['Sessions', 'sessions.php', 'fas fa-calendar-days'],
            ['Attendance', 'attendance.php', 'fas fa-user-check'],
        ],
    ],
    [
        'title' => 'Learning & Quality',
        'description' => 'Plan delivery, assess competence, and maintain instructor standards.',
        'icon' => 'fas fa-graduation-cap',
        'links' => [
            ['Curriculum', 'curriculum.php', 'fas fa-book-open'],
            ['Assessments', 'trainee_assessments.php', 'fas fa-clipboard-check'],
            ['Assessment Bank', 'ai_assessment_bank.php', 'fas fa-wand-magic-sparkles'],
            ['Instructors', 'instructors.php', 'fas fa-id-card'],
        ],
    ],
    [
        'title' => 'Fleet & Safety',
        'description' => 'Keep vehicles roadworthy, compliant, inspected, and ready for training.',
        'icon' => 'fas fa-truck',
        'links' => [
            ['Fleet Dashboard', 'fleet_dashboard.php', 'fas fa-chart-line'],
            ['Fleet Assets', 'fleet.php', 'fas fa-truck-moving'],
            ['Pre-use Checks', 'preuse_checks.php', 'fas fa-list-check'],
        ],
    ],
    [
        'title' => 'Finance & Partners',
        'description' => 'Manage approved fees, verified payments, bookings, and client accounts.',
        'icon' => 'fas fa-handshake',
        'links' => [
            ['Payments & Booking', 'payments.php', 'fas fa-money-check-dollar'],
            ['Course Fees', 'fees.php', 'fas fa-coins'],
            ['Corporate Clients', 'clients.php', 'fas fa-building'],
        ],
    ],
    [
        'title' => 'Reports & Insights',
        'description' => 'Review delivery performance and management information.',
        'icon' => 'fas fa-chart-column',
        'links' => [
            ['Reports & Summaries', 'reports.php', 'fas fa-chart-bar'],
            ['Training Reports', 'training_reports.php', 'fas fa-filter'],
        ],
    ],
    [
        'title' => 'Governance & Policy',
        'description' => 'Maintain compliance evidence, operating rules, and audit readiness.',
        'icon' => 'fas fa-scale-balanced',
        'links' => [
            ['Compliance', 'compliance.php', 'fas fa-shield-halved'],
            ['Policies & Audit', 'policies.php', 'fas fa-file-contract'],
        ],
    ],
];

require_once __DIR__ . '/transport/includes/nav.php';
?>
<main class="transport-hub container-fluid px-3 px-lg-4 py-4">
    <header class="transport-hub-hero mb-4">
        <div>
            <span class="transport-hub-eyebrow"><i class="fas fa-route"></i> Transport Management</span>
            <h1>One workspace for training, fleet, safety, and reporting</h1>
            <p>Choose the job you need to complete. Related tools are grouped together so you can move through the workflow without hunting through menus.</p>
        </div>
        <a class="btn btn-light transport-hub-primary-action" href="/wucportal/transport/sessions.php">
            <i class="fas fa-calendar-plus me-2"></i>Schedule a session
        </a>
    </header>

    <section class="transport-hub-stats mb-4" aria-label="Transport summary">
        <article class="transport-hub-stat"><span class="icon purple"><i class="fas fa-users"></i></span><div><strong><?php echo number_format($hubStats['trainees']); ?></strong><span>Booked active trainees</span></div></article>
        <article class="transport-hub-stat"><span class="icon green"><i class="fas fa-layer-group"></i></span><div><strong><?php echo number_format($hubStats['cohorts']); ?></strong><span>Open or active cohorts</span></div></article>
        <article class="transport-hub-stat"><span class="icon blue"><i class="fas fa-truck"></i></span><div><strong><?php echo number_format($hubStats['vehicles']); ?></strong><span>Available vehicles</span></div></article>
        <article class="transport-hub-stat"><span class="icon amber"><i class="fas fa-calendar-day"></i></span><div><strong><?php echo number_format($hubStats['today']); ?></strong><span>Sessions scheduled today</span></div></article>
    </section>

    <div class="row g-4">
        <section class="col-12 col-xxl-9 order-2 order-xxl-1" aria-labelledby="transport-workspaces-title">
            <div class="d-flex align-items-end justify-content-between gap-3 mb-3">
                <div><h2 id="transport-workspaces-title" class="transport-section-title">Workspaces</h2><p class="text-muted mb-0">Start with the outcome you want to achieve.</p></div>
            </div>
            <div class="transport-workspace-grid">
                <?php foreach ($workspaces as $workspace): ?>
                    <article class="transport-workspace-card">
                        <div class="transport-workspace-heading">
                            <span class="transport-workspace-icon"><i class="<?php echo htmlspecialchars($workspace['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i></span>
                            <div><h3><?php echo htmlspecialchars($workspace['title'], ENT_QUOTES, 'UTF-8'); ?></h3><p><?php echo htmlspecialchars($workspace['description'], ENT_QUOTES, 'UTF-8'); ?></p></div>
                        </div>
                        <nav class="transport-workspace-links" aria-label="<?php echo htmlspecialchars($workspace['title'], ENT_QUOTES, 'UTF-8'); ?> tools">
                            <?php foreach ($workspace['links'] as [$label, $route, $icon]): ?>
                                <a href="/wucportal/transport/<?php echo rawurlencode($route); ?>"><i class="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>"></i><span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span><i class="fas fa-chevron-right arrow"></i></a>
                            <?php endforeach; ?>
                        </nav>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <aside class="col-12 col-xxl-3 order-1 order-xxl-2" aria-labelledby="transport-priorities-title">
            <div class="transport-priority-panel">
                <div class="transport-priority-header"><div><span>Today</span><h2 id="transport-priorities-title">Needs attention</h2></div><i class="fas fa-bell"></i></div>
                <div class="transport-priority-items">
                    <?php foreach ($hubAlerts as $alert): ?>
                        <a href="<?php echo htmlspecialchars($alert['href'], ENT_QUOTES, 'UTF-8'); ?>" class="transport-priority-item <?php echo htmlspecialchars($alert['tone'], ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="priority-icon"><i class="<?php echo htmlspecialchars($alert['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i></span>
                            <span><strong><?php echo number_format($alert['value']); ?></strong><?php echo htmlspecialchars($alert['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="transport-priority-footer"><i class="fas fa-circle-info me-2"></i>Counts update from live Transport records.</div>
            </div>
        </aside>
    </div>
</main>
<?php require_once __DIR__ . '/transport/includes/footer.php'; ?>
