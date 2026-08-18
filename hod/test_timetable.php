<?php
/**
 * HOS — section-scoped Test Timetable review
 */
declare(strict_types=1);

$page_title = 'Test Timetable';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/test_timetable.php';

$hodStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$department = hod_resolve_department($db, $hodStaffId);
$programCodes = hod_section_program_codes($db, $department);
$courseCodes = hod_section_course_codes($db, $department, $hodStaffId);
$schemaOk = tt_ensure_schema($db);

$periods = $schemaOk ? tt_list_periods($db, false) : [];
$periodId = (int)($_GET['period_id'] ?? 0);
if ($periodId < 1 && $periods !== []) {
    $periodId = (int)$periods[0]['id'];
}
$period = $periodId > 0 ? tt_get_period($db, $periodId) : null;

$entries = [];
$summary = ['total' => 0, 'scheduled_courses' => 0, 'unscheduled' => 0, 'conflicts' => 0, 'rooms_used' => 0];
$conflicts = [];
$unscheduled = [];
$testDates = [];

if ($period && $schemaOk) {
    $filters = ['limit' => 300];
    if ($programCodes !== []) {
        $filters['program_codes'] = $programCodes;
    } elseif ($courseCodes !== []) {
        $filters['course_codes'] = $courseCodes;
    } else {
        $filters['program_codes'] = ['__none__'];
    }
    $entries = tt_list_entries($db, $periodId, $filters);
    $summary = tt_period_summary($db, $periodId, $programCodes);
    $allConflicts = tt_detect_conflicts($db, $periodId);
    foreach ($allConflicts as $c) {
        $msg = (string)$c['message'];
        $keep = $programCodes === [];
        foreach ($programCodes as $pc) {
            if (stripos($msg, $pc) !== false) {
                $keep = true;
                break;
            }
        }
        if (!$keep) {
            foreach ($entries as $e) {
                $pname = (string)($e['program_name'] ?? '');
                if ($pname !== '' && stripos($msg, $pname) !== false) {
                    $keep = true;
                    break;
                }
                if (($c['type'] ?? '') === 'room' || ($c['type'] ?? '') === 'lecturer') {
                    $keep = true;
                    break;
                }
            }
        }
        if ($keep) {
            $conflicts[] = $c;
        }
    }
    $unscheduled = array_slice(tt_unscheduled_courses($db, $periodId, $programCodes), 0, 50);
    foreach ($entries as $e) {
        $testDates[(string)$e['test_date']] = true;
    }
    ksort($testDates);
}

$sectionLabel = !empty($department['name']) ? (string)$department['name'] : 'Your section';
?>

<style>
.tt-hos-page { padding-top: 1.25rem; padding-bottom: 2rem; }
.tt-hos-page .tt-card {
    background: #fff; border: 1px solid #e5eaf2; border-radius: 10px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}
.tt-hos-page .tt-stat {
    background: #fff; border: 1px solid #e5eaf2; border-radius: 10px;
    padding: 1rem; height: 100%;
}
.tt-hos-page .tt-stat .label { color: #64748b; font-size: .75rem; font-weight: 700; text-transform: uppercase; }
.tt-hos-page .tt-stat .value { font-size: 1.4rem; font-weight: 800; color: #14213d; }
</style>

<div class="container-fluid px-4 tt-hos-page">
    <div class="tt-card p-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
            <div>
                <h1 class="h5 mb-1 fw-bold"><i class="fas fa-calendar-check me-2 text-primary"></i>Section Test Timetable</h1>
                <p class="text-muted small mb-0">Review schedule for <?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>
            <?php if ($periods !== []): ?>
            <form method="get" class="d-flex gap-2 align-items-center">
                <select name="period_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($periods as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $periodId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['academic_year'] . ' — ' . $p['term_label'] . ' (' . $p['status'] . ')', ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$schemaOk): ?>
        <div class="alert alert-warning">Test timetable is not installed yet.</div>
    <?php elseif ($programCodes === [] && $courseCodes === []): ?>
        <div class="alert alert-info">No programmes or courses mapped to your section. Contact the registrar if this is unexpected.</div>
    <?php elseif (!$period): ?>
        <div class="alert alert-info">No assessment periods available.</div>
    <?php else: ?>

    <div class="row g-3 mb-3">
        <div class="col"><div class="tt-stat"><div class="label">Scheduled tests</div><div class="value"><?= (int)$summary['total'] ?></div></div></div>
        <div class="col"><div class="tt-stat"><div class="label">Unscheduled courses</div><div class="value"><?= (int)$summary['unscheduled'] ?></div></div></div>
        <div class="col"><div class="tt-stat"><div class="label">Conflicts</div><div class="value text-danger"><?= count($conflicts) ?></div></div></div>
        <div class="col"><div class="tt-stat"><div class="label">Rooms used</div><div class="value"><?= (int)$summary['rooms_used'] ?></div></div></div>
        <div class="col"><div class="tt-stat"><div class="label">Test dates</div><div class="value"><?= count($testDates) ?></div></div></div>
    </div>

    <?php if ($testDates !== []): ?>
    <div class="tt-card p-3 mb-3">
        <div class="small text-muted fw-bold mb-1">TEST DATES</div>
        <div><?= htmlspecialchars(implode(' · ', array_keys($testDates)), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php endif; ?>

    <?php if ($conflicts !== []): ?>
    <div class="tt-card p-3 mb-3">
        <h2 class="h6 fw-bold text-danger">Timetable conflicts</h2>
        <ul class="mb-0 small">
            <?php foreach ($conflicts as $c): ?>
                <li class="mb-1"><?= htmlspecialchars((string)$c['message'], ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="tt-card mb-3">
        <div class="p-3 border-bottom"><h2 class="h6 mb-0 fw-bold">Scheduled tests</h2></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th><th>Time</th><th>Programme</th><th>Yr</th>
                        <th>Course</th><th>Room</th><th>Lecturer</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($entries === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No tests for your section in this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $e): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$e['test_date'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(substr((string)$e['start_time'], 0, 5) . '–' . substr((string)$e['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($e['program_name'] ?: $e['program_code']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)$e['year_of_study'] ?></td>
                            <td><?= htmlspecialchars(trim(($e['course_code'] ?? '') . ' ' . ($e['course_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($e['room_code'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(trim((string)($e['lecturer_name'] ?? '')) ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($unscheduled !== []): ?>
    <div class="tt-card p-3 mb-3">
        <h2 class="h6 fw-bold">Unscheduled courses</h2>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Programme</th><th>Year</th><th>Course</th></tr></thead>
                <tbody>
                <?php foreach ($unscheduled as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($u['program_name'] ?: $u['program_code']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)$u['year_of_study'] ?></td>
                        <td><?= htmlspecialchars((string)$u['course_code'] . ' — ' . (string)($u['course_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
