<?php
$page_title = 'Online Class Reports';
require 'includes/nav.php';
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/elearning_live_reports.php';

$hodStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$deptContext = hod_resolve_department($db, $hodStaffId);

// A section spans several departments; collect course codes across ALL of
// them (plus the staff's own lectured courses), mirroring hod/index.php.
$deptCourseCodes = [];
$courseScopeDeptIds = (array)($deptContext['candidates'] ?? []);
if (empty($courseScopeDeptIds)) {
    $courseScopeDeptIds = [(string)$deptContext['id']];
}
foreach ($courseScopeDeptIds as $courseScopeDeptId) {
    $deptCourseCodes = array_merge(
        $deptCourseCodes,
        hod_department_course_codes($db, (string)$courseScopeDeptId, $hodStaffId)
    );
}
$deptCourseCodes = array_values(array_unique(array_filter($deptCourseCodes)));

$dateFrom = elearning_live_report_date($_GET['date_from'] ?? '', date('Y-m-01'));
$dateTo = elearning_live_report_date($_GET['date_to'] ?? '', date('Y-m-d'));
$sessions = empty($deptCourseCodes) ? [] : elearning_live_report_fetch_sessions($db, $dateFrom, $dateTo, $deptCourseCodes);
$attendeesBySession = elearning_live_report_fetch_attendees($db, array_column($sessions, 'id'));

$totalAttended = 0;
foreach ($sessions as $session) {
    $totalAttended += (int)($session['attended_count'] ?? 0);
}
?>
<style>
.online-report-page .report-stat {
    background: #fff;
    border: 1px solid #e6eaf2;
    border-radius: 8px;
    padding: 1rem;
    box-shadow: 0 8px 22px rgba(15, 23, 42, .06);
}
.online-report-page .report-stat strong {
    display: block;
    font-size: 1.5rem;
    color: #172033;
}
.online-report-page .session-card {
    border: 1px solid #e6eaf2;
    border-radius: 8px;
    box-shadow: 0 8px 22px rgba(15, 23, 42, .06);
    overflow: hidden;
}
.online-report-page .session-card + .session-card {
    margin-top: 1rem;
}
.online-report-page .session-head {
    background: #fff;
    border-bottom: 1px solid #e6eaf2;
    padding: 1rem;
}
.online-report-page .session-meta {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
}
.online-report-page .session-meta span {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: .25rem .6rem;
    font-size: .85rem;
}
@media print {
    .d-print-none, .sidebar, .sidebar-backdrop { display: none !important; }
    .main-wrapper { margin-left: 0 !important; }
}
</style>

<div class="container-fluid px-4 portal-dashboard hod-page online-report-page">
    <div class="page-header mb-3 mt-2 d-print-none">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-video me-2 text-primary"></i>Online Class Reports</h5>
                <p class="page-subtitle mb-0">
                    <?php echo elearning_live_report_h((string)($deptContext['name'] ?: 'Your section')); ?> live classes and attended students.
                </p>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if (empty($deptCourseCodes)): ?>
        <div class="alert alert-warning">
            Your HOS account is not linked to department courses, so no online class report can be scoped.
        </div>
    <?php endif; ?>

    <form method="get" class="data-table-card mb-4 d-print-none">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">From</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo elearning_live_report_h($dateFrom); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo elearning_live_report_h($dateTo); ?>">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-filter me-1"></i>Apply</button>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="report-stat">
                <span class="text-muted">Online classes</span>
                <strong><?php echo count($sessions); ?></strong>
            </div>
        </div>
        <div class="col-md-4">
            <div class="report-stat">
                <span class="text-muted">Student attendances</span>
                <strong><?php echo (int)$totalAttended; ?></strong>
            </div>
        </div>
        <div class="col-md-4">
            <div class="report-stat">
                <span class="text-muted">Department courses</span>
                <strong><?php echo count($deptCourseCodes); ?></strong>
            </div>
        </div>
    </div>

    <?php if (empty($sessions)): ?>
        <div class="alert alert-info">No online classes were found for the selected period.</div>
    <?php endif; ?>

    <?php foreach ($sessions as $session): ?>
        <?php $attendees = $attendeesBySession[(int)$session['id']] ?? []; ?>
        <article class="session-card bg-white">
            <div class="session-head">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <h5 class="mb-1"><?php echo elearning_live_report_h($session['topic']); ?></h5>
                        <div class="session-meta">
                            <span><i class="fas fa-book me-1"></i><?php echo elearning_live_report_h($session['course_code'] . ' - ' . $session['course_name']); ?></span>
                            <span><i class="fas fa-clock me-1"></i><?php echo elearning_live_report_h(date('M d, Y h:i A', strtotime((string)$session['start_time']))); ?></span>
                            <span><i class="fas fa-user-tie me-1"></i><?php echo elearning_live_report_h($session['created_by_name'] ?: $session['created_by']); ?></span>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-primary"><?php echo elearning_live_report_h(elearning_live_report_status($session)); ?></span>
                        <div class="small text-muted mt-2"><?php echo count($attendees); ?> attended</div>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Joined</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attendees)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No attended students have been logged for this class.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($attendees as $attendee): ?>
                            <tr>
                                <td class="font-monospace"><?php echo elearning_live_report_h($attendee['student_id']); ?></td>
                                <td><?php echo elearning_live_report_h(trim(($attendee['Fname'] ?? '') . ' ' . ($attendee['Lname'] ?? '')) ?: '-'); ?></td>
                                <td><?php echo elearning_live_report_h(date('M d, Y h:i A', strtotime((string)$attendee['first_join_time']))); ?></td>
                                <td><?php echo elearning_live_report_h(elearning_live_report_duration($attendee)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
