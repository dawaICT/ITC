<?php
/**
 * Lecturer — My Test Schedule
 */
declare(strict_types=1);

$page_title = 'My Test Schedule';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/test_timetable.php';
require_once __DIR__ . '/includes/nav.php';

$staffId = (string)($_SESSION['staff_id'] ?? '');
$schemaOk = tt_ensure_schema($db);
$tests = ($schemaOk && $staffId !== '') ? tt_lecturer_tests($db, $staffId) : [];
?>

<style>
.tt-lec-page .hero-panel, .tt-lec-page .day-panel {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
}
.tt-lec-page .hero-panel { padding: 18px 20px; margin-bottom: 18px; }
</style>

<div class="container-fluid px-4 portal-dashboard tt-lec-page">
    <div class="hero-panel">
        <h1 class="page-title mb-1"><i class="fas fa-clipboard-list me-2 text-primary"></i>My Test Schedule</h1>
        <p class="text-muted mb-0">Tests where you are assigned as lecturer/invigilator or teach the course.</p>
    </div>

    <?php if (!$schemaOk): ?>
        <div class="alert alert-warning">Test timetable is not available yet.</div>
    <?php elseif ($tests === []): ?>
        <div class="alert alert-info">No test assignments found for your courses in current assessment periods.</div>
    <?php else: ?>
        <div class="day-panel table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Course</th>
                        <th>Programme / class</th>
                        <th>Venue</th>
                        <th>Students</th>
                        <th>Period</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tests as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$t['test_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(substr((string)$t['start_time'], 0, 5) . '–' . substr((string)$t['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(trim(($t['course_code'] ?? '') . ' ' . ($t['course_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?= htmlspecialchars((string)($t['program_name'] ?: $t['program_code']), ENT_QUOTES, 'UTF-8') ?>
                            Yr <?= (int)$t['year_of_study'] ?>
                            <?php if (!empty($t['section_label'])): ?>
                                <span class="text-muted">· <?= htmlspecialchars((string)$t['section_label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)($t['room_code'] ?: ($t['room_name'] ?? 'TBA')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= isset($t['student_count']) && $t['student_count'] !== null ? (int)$t['student_count'] : '—' ?></td>
                        <td class="small text-muted"><?= htmlspecialchars((string)($t['_period']['term_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
