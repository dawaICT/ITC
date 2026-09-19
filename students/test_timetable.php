<?php
/**
 * Student — active personalized Test Timetable (published window only)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/test_timetable.php';

if (!isset($_SESSION['Sid'])) {
    header('Location: ../student_login.php');
    exit;
}

$studentId = (string)$_SESSION['Sid'];
$schemaOk = tt_ensure_schema($db);
$period = $schemaOk ? tt_current_student_visible_period($db) : null;
$periodId = (int)($_GET['period_id'] ?? 0);

// Historical view when explicitly requested and period is closed/archived for this student
$historical = false;
if ($periodId > 0 && $schemaOk) {
    $requested = tt_get_period($db, $periodId);
    if ($requested && in_array(strtolower((string)$requested['status']), ['closed', 'archived'], true)) {
        $period = $requested;
        $historical = true;
    }
}

$tests = [];
if ($period && $schemaOk) {
    if ($historical) {
        $tests = tt_student_tests($db, $studentId, (int)$period['id'], true);
    } elseif (tt_student_can_view_period($period)) {
        $tests = tt_student_tests($db, $studentId, (int)$period['id']);
    }
}

$page_title = $historical ? 'Previous Test Timetable' : 'Test Timetable';
require_once __DIR__ . '/includes/navbar.php';
?>

<style>
.tt-stu-page { padding: 1.25rem 0 2rem; }
.tt-stu-page .hero {
    background: #fff; border: 1px solid #e5eaf2; border-radius: 12px;
    padding: 1.25rem 1.4rem; margin-bottom: 1rem;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}
.tt-stu-page .tt-table-card {
    background: #fff; border: 1px solid #e5eaf2; border-radius: 12px; overflow: hidden;
}
</style>

<main class="content-wrapper">
<div class="container-fluid px-3 px-md-4 tt-stu-page">
    <div class="hero">
        <h1 class="h4 fw-bold mb-1"><i class="fas fa-calendar-check me-2 text-primary"></i><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($period): ?>
            <p class="text-muted mb-0">
                <?= htmlspecialchars((string)$period['term_label'], ENT_QUOTES, 'UTF-8') ?>
                · <?= htmlspecialchars((string)$period['academic_year'], ENT_QUOTES, 'UTF-8') ?>
                · Tests <?= htmlspecialchars((string)$period['test_start_date'], ENT_QUOTES, 'UTF-8') ?>
                to <?= htmlspecialchars((string)$period['test_end_date'], ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php else: ?>
            <p class="text-muted mb-0">No published test timetable is available for you right now.</p>
        <?php endif; ?>
    </div>

    <?php if (!$schemaOk): ?>
        <div class="alert alert-warning">Test timetable is not available.</div>
    <?php elseif (!$period): ?>
        <div class="alert alert-info">
            The test timetable appears here only after the registrar publishes it and during the test period.
            <a href="previous_test_timetables.php" class="alert-link">View previous timetables</a>
        </div>
    <?php elseif ($tests === []): ?>
        <div class="alert alert-info">No tests match your programme and registered courses for this period.</div>
    <?php else: ?>
        <div class="tt-table-card table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Course</th>
                        <th>Venue</th>
                        <th>Section</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tests as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$t['test_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(substr((string)$t['start_time'], 0, 5) . '–' . substr((string)$t['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(trim(($t['course_code'] ?? '') . ' — ' . ($t['course_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($t['room_code'] ?: ($t['room_name'] ?? 'TBA')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($t['section_label'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="mt-3">
        <a href="previous_test_timetables.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-history me-1"></i>Previous Timetables
        </a>
    </div>
</div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
