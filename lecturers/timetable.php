<?php
$page_title = 'My Timetable';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/timetable_management.php';
require_once __DIR__ . '/includes/nav.php';

$staffId = (string)($_SESSION['staff_id'] ?? '');
$selectedYear = max(1, min(7, (int)($_GET['year_of_study'] ?? 1)));
$selectedSemester = max(1, min(4, (int)($_GET['semester'] ?? 1)));
$schedules = ttm_fetch_schedules($db, $selectedYear, $selectedSemester, null, $staffId);
$days = ttm_days();
$byDay = [];
foreach ($days as $day) {
    $byDay[$day] = [];
}
foreach ($schedules as $row) {
    $day = (string)($row['day_of_week'] ?? '');
    if (!isset($byDay[$day])) {
        $byDay[$day] = [];
    }
    $byDay[$day][] = $row;
}
?>
<style>
.lecturer-timetable-page .hero-panel,
.lecturer-timetable-page .filter-panel,
.lecturer-timetable-page .day-panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}
.lecturer-timetable-page .hero-panel { padding: 18px 20px; margin-bottom: 18px; }
.lecturer-timetable-page .filter-panel { padding: 16px; margin-bottom: 18px; }
.lecturer-timetable-page .day-panel { min-height: 160px; overflow: hidden; }
.lecturer-timetable-page .day-header {
    padding: 12px 14px;
    border-bottom: 1px solid #e5e7eb;
    font-weight: 800;
    color: #0f172a;
}
.lecturer-timetable-page .class-item {
    padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9;
}
.lecturer-timetable-page .class-item:last-child { border-bottom: 0; }
.lecturer-timetable-page .class-time { font-weight: 800; color: #1d4ed8; }
@media print {
    .lecturer-timetable-page .no-print, .sidebar, .navbar, .topbar { display: none !important; }
    .lecturer-timetable-page .hero-panel,
    .lecturer-timetable-page .filter-panel,
    .lecturer-timetable-page .day-panel { border: 0; }
}
</style>

<div class="container-fluid px-4 portal-dashboard lecturer-timetable-page">
    <div class="hero-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="page-title mb-1"><i class="fas fa-calendar-alt me-2 text-primary"></i>My Timetable</h1>
                <p class="text-muted mb-0">Scheduled classes assigned to you for the selected academic period.</p>
            </div>
            <button type="button" class="btn btn-outline-secondary no-print" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
        </div>
    </div>

    <div class="filter-panel no-print">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Year of Study</label>
                <select class="form-select" name="year_of_study">
                    <?php for ($i = 1; $i <= 7; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $selectedYear === $i ? 'selected' : ''; ?>>Year <?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Semester / Term</label>
                <select class="form-select" name="semester">
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $selectedSemester === $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100" type="submit"><i class="fas fa-search me-1"></i>Apply</button>
            </div>
        </form>
    </div>

    <div class="row g-3">
        <?php foreach ($days as $day): ?>
            <div class="col-xl-4 col-md-6">
                <div class="day-panel">
                    <div class="day-header"><?php echo ttm_h($day); ?></div>
                    <?php if (empty($byDay[$day])): ?>
                        <div class="p-3 text-muted">No classes scheduled.</div>
                    <?php else: ?>
                        <?php foreach ($byDay[$day] as $row): ?>
                            <div class="class-item">
                                <div class="class-time"><?php echo ttm_h($row['start_time'] . ' - ' . $row['end_time']); ?></div>
                                <div class="fw-semibold"><?php echo ttm_h($row['course_code']); ?> <span class="badge bg-primary"><?php echo ttm_h($row['schedule_type'] ?: 'lecture'); ?></span></div>
                                <div class="text-muted small"><?php echo ttm_h($row['course_name']); ?></div>
                                <div class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i><?php echo ttm_h($row['room'] ?: 'Venue not set'); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

