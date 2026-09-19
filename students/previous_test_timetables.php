<?php
/**
 * Student — Previous / historical Test Timetables
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
$periods = $schemaOk ? tt_student_historical_periods($db, $studentId) : [];
$active = $schemaOk ? tt_current_student_visible_period($db) : null;

$page_title = 'Previous Test Timetables';
require_once __DIR__ . '/includes/navbar.php';
?>

<style>
.tt-prev-page { padding: 1.25rem 0 2rem; }
.tt-prev-page .hero, .tt-prev-page .list-card {
    background: #fff; border: 1px solid #e5eaf2; border-radius: 12px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}
.tt-prev-page .hero { padding: 1.25rem 1.4rem; margin-bottom: 1rem; }
.tt-prev-page .list-card a {
    display: block; padding: 1rem 1.2rem; border-bottom: 1px solid #f1f5f9;
    text-decoration: none; color: inherit;
}
.tt-prev-page .list-card a:last-child { border-bottom: 0; }
.tt-prev-page .list-card a:hover { background: #f8fafc; }
</style>

<main class="content-wrapper">
<div class="container-fluid px-3 px-md-4 tt-prev-page">
    <div class="hero">
        <h1 class="h4 fw-bold mb-1"><i class="fas fa-history me-2 text-primary"></i>Previous Test Timetables</h1>
        <p class="text-muted mb-0">Closed and archived assessment periods for your programme.</p>
    </div>

    <?php if ($active): ?>
        <div class="alert alert-success">
            A current test timetable is available.
            <a href="test_timetable.php" class="alert-link">View current timetable</a>
        </div>
    <?php endif; ?>

    <?php if (!$schemaOk): ?>
        <div class="alert alert-warning">Test timetable is not available.</div>
    <?php elseif ($periods === []): ?>
        <div class="alert alert-info">No previous test timetables found.</div>
    <?php else: ?>
        <div class="list-card">
            <?php foreach ($periods as $p): ?>
                <a href="test_timetable.php?period_id=<?= (int)$p['id'] ?>">
                    <strong><?= htmlspecialchars((string)$p['term_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <span class="text-muted">· <?= htmlspecialchars((string)$p['academic_year'], ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="small text-muted mt-1">
                        <?= htmlspecialchars((string)$p['test_start_date'], ENT_QUOTES, 'UTF-8') ?>
                        → <?= htmlspecialchars((string)$p['test_end_date'], ENT_QUOTES, 'UTF-8') ?>
                        · <?= htmlspecialchars((string)$p['status'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
