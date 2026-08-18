<?php
/**
 * Shared Test Timetable management UI (Registrar + Admin).
 * Caller must already authenticate via canAccessRegistrar(), load deps, and render nav.
 * Optional: $ttSelfUrl (default test_timetable.php) for redirects/links.
 */
declare(strict_types=1);

if (!isset($db) || !($db instanceof mysqli)) {
    throw new RuntimeException('test_timetable_manage requires $db');
}

$ttSelf = isset($ttSelfUrl) ? (string)$ttSelfUrl : 'test_timetable.php';
$csrfToken = wuc_csrf_token();
$actor = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'registrar');
$schemaOk = tt_ensure_schema($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['errorMsg'] = 'Invalid security token. Please try again.';
        header('Location: ' . $ttSelf);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $periodIdPost = (int)($_POST['period_id'] ?? 0);

    if (!$schemaOk) {
        $_SESSION['errorMsg'] = 'Test timetable tables are missing. Run migrations/20260804_test_timetable.php.';
        header('Location: ' . $ttSelf);
        exit;
    }

    if ($action === 'create_period') {
        $result = tt_create_period($db, [
            'academic_year' => $_POST['academic_year'] ?? '',
            'term_label' => $_POST['term_label'] ?? '',
            'academic_period_id' => $_POST['academic_period_id'] ?? '',
            'scheduling_start_date' => $_POST['scheduling_start_date'] ?? '',
            'publication_date' => $_POST['publication_date'] ?? '',
            'test_start_date' => $_POST['test_start_date'] ?? '',
            'test_end_date' => $_POST['test_end_date'] ?? '',
        ], $actor);
        $_SESSION[$result['ok'] ? 'successMsg' : 'errorMsg'] = $result['ok']
            ? 'Assessment period created.'
            : ($result['error'] ?? 'Could not create period.');
        $redir = $result['ok'] ? $ttSelf . '?period_id=' . (int)$result['id'] : $ttSelf;
        header('Location: ' . $redir);
        exit;
    }

    if ($action === 'save_entry') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $result = tt_save_entry($db, [
            'assessment_period_id' => $periodIdPost,
            'test_date' => $_POST['test_date'] ?? '',
            'start_time' => $_POST['start_time'] ?? '',
            'end_time' => $_POST['end_time'] ?? '',
            'program_code' => $_POST['program_code'] ?? '',
            'year_of_study' => $_POST['year_of_study'] ?? 1,
            'course_code' => $_POST['course_code'] ?? '',
            'section_label' => $_POST['section_label'] ?? '',
            'classroom_id' => $_POST['classroom_id'] ?? '',
            'lecturer_staff_id' => $_POST['lecturer_staff_id'] ?? '',
        ], $actor, $entryId > 0 ? $entryId : null);
        $_SESSION[$result['ok'] ? 'successMsg' : 'errorMsg'] = $result['ok']
            ? 'Test slot saved.'
            : ($result['error'] ?? 'Could not save entry.');
        header('Location: ' . $ttSelf . '?period_id=' . $periodIdPost);
        exit;
    }

    if ($action === 'delete_entry') {
        $result = tt_delete_entry($db, (int)($_POST['entry_id'] ?? 0), $periodIdPost);
        $_SESSION[$result['ok'] ? 'successMsg' : 'errorMsg'] = $result['ok']
            ? 'Test entry removed.'
            : ($result['error'] ?? 'Could not delete entry.');
        header('Location: ' . $ttSelf . '?period_id=' . $periodIdPost);
        exit;
    }

    if (in_array($action, ['publish', 'close', 'archive', 'set_scheduling'], true)) {
        $map = [
            'publish' => 'published',
            'close' => 'closed',
            'archive' => 'archived',
            'set_scheduling' => 'scheduling',
        ];
        $result = tt_set_period_status($db, $periodIdPost, $map[$action]);
        $_SESSION[$result['ok'] ? 'successMsg' : 'errorMsg'] = $result['ok']
            ? 'Period status updated to ' . $map[$action] . '.'
            : ($result['error'] ?? 'Status update failed.');
        header('Location: ' . $ttSelf . '?period_id=' . $periodIdPost);
        exit;
    }

    header('Location: ' . $ttSelf);
    exit;
}

$periods = $schemaOk ? tt_list_periods($db, true) : [];
$periodId = (int)($_GET['period_id'] ?? 0);
if ($periodId < 1 && $periods !== []) {
    $periodId = (int)$periods[0]['id'];
}
$period = $periodId > 0 ? tt_get_period($db, $periodId) : null;
$canSchedule = $period ? tt_registrar_can_schedule($period) : false;
$showConflicts = isset($_GET['conflicts']) || (string)($_GET['action'] ?? '') === 'check_conflicts';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$entries = [];
$summary = ['total' => 0, 'scheduled_courses' => 0, 'unscheduled' => 0, 'conflicts' => 0, 'rooms_used' => 0];
$conflicts = [];
$unscheduled = [];
$editEntry = null;

if ($period) {
    $entries = tt_list_entries($db, $periodId, [
        'limit' => $perPage,
        'offset' => ($page - 1) * $perPage,
    ]);
    $summary = tt_period_summary($db, $periodId);
    if ($showConflicts || strtolower((string)$period['status']) === 'scheduling') {
        $conflicts = tt_detect_conflicts($db, $periodId);
    }
    $unscheduled = array_slice(tt_unscheduled_courses($db, $periodId), 0, 40);
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) {
        foreach ($entries as $e) {
            if ((int)$e['id'] === $editId) {
                $editEntry = $e;
                break;
            }
        }
        if (!$editEntry) {
            $all = tt_list_entries($db, $periodId, ['limit' => 500]);
            foreach ($all as $e) {
                if ((int)$e['id'] === $editId) {
                    $editEntry = $e;
                    break;
                }
            }
        }
    }
}

$currentAp = tt_lookup_current_academic_period($db);
$programs = tt_lookup_programs($db);
$classrooms = tt_lookup_classrooms($db);
$lecturers = tt_lookup_lecturers($db);
$preloadProgram = trim((string)($_GET['preload_program'] ?? ''));
$courseProgram = $preloadProgram !== ''
    ? $preloadProgram
    : (string)($editEntry['program_code'] ?? ($programs[0]['program_code'] ?? ''));
$coursesByProgram = $courseProgram !== '' ? tt_lookup_courses_for_program($db, $courseProgram) : [];

$status = $period ? strtolower((string)$period['status']) : '';
$defaultYear = $currentAp['academic_year'] ?? (date('Y') . '/' . (date('Y') + 1));
$defaultTerm = $currentAp['period_name'] ?? ($currentAp['semester_term'] ?? 'Term 1');
?>

<style>
.tt-page { padding-top: 1.25rem; padding-bottom: 2rem; }
.tt-page .page-header,
.tt-page .tt-card {
    background: #fff;
    border: 1px solid #e5eaf2;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}
.tt-page .page-header { padding: 1.1rem 1.25rem; margin-bottom: 1rem; }
.tt-page .page-title { color: #14213d; font-size: 1.05rem; font-weight: 700; margin: 0; }
.tt-page .page-subtitle { color: #64748b; font-size: 0.88rem; margin: 0.2rem 0 0; }
.tt-stat {
    background: #fff;
    border: 1px solid #e5eaf2;
    border-radius: 10px;
    padding: 1rem 1.1rem;
    height: 100%;
}
.tt-stat .label { color: #64748b; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.tt-stat .value { color: #14213d; font-size: 1.45rem; font-weight: 800; }
.tt-badge-draft { background: #e2e8f0; color: #334155; }
.tt-badge-scheduling { background: #fef3c7; color: #92400e; }
.tt-badge-published, .tt-badge-active { background: #d1fae5; color: #065f46; }
.tt-badge-closed, .tt-badge-archived { background: #fee2e2; color: #991b1b; }
</style>

<div class="container-fluid px-4 tt-page">
    <div class="page-header d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <h1 class="page-title"><i class="fas fa-calendar-check me-2 text-primary"></i>Test Timetable</h1>
            <p class="page-subtitle">Prepare, conflict-check, publish and close assessment test schedules.</p>
        </div>
        <?php if ($period): ?>
            <span class="badge tt-badge-<?= tt_h($status) ?> px-3 py-2 text-uppercase"><?= tt_h($status) ?></span>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['successMsg'])): ?>
        <div class="alert alert-success"><?= tt_h($_SESSION['successMsg']) ?></div>
        <?php unset($_SESSION['successMsg']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['errorMsg'])): ?>
        <div class="alert alert-danger"><?= tt_h($_SESSION['errorMsg']) ?></div>
        <?php unset($_SESSION['errorMsg']); ?>
    <?php endif; ?>

    <?php if (!$schemaOk): ?>
        <div class="alert alert-warning">Schema missing. Run <code>php migrations/20260804_test_timetable.php</code>.</div>
    <?php else: ?>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="tt-card p-3 h-100">
                <h5 class="mb-3" style="font-size:.95rem;font-weight:700;color:#164e86;">Assessment Period</h5>
                <form method="get" class="mb-3">
                    <label class="form-label fw-bold small">Select period</label>
                    <select name="period_id" class="form-select" onchange="this.form.submit()">
                        <?php if ($periods === []): ?>
                            <option value="">No periods yet</option>
                        <?php endif; ?>
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $periodId ? 'selected' : '' ?>>
                                <?= tt_h($p['academic_year'] . ' — ' . $p['term_label'] . ' (' . $p['status'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($period): ?>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Academic year</dt><dd class="col-7"><?= tt_h($period['academic_year']) ?></dd>
                        <dt class="col-5 text-muted">Term</dt><dd class="col-7"><?= tt_h($period['term_label']) ?></dd>
                        <dt class="col-5 text-muted">Scheduling from</dt><dd class="col-7"><?= tt_h($period['scheduling_start_date']) ?></dd>
                        <dt class="col-5 text-muted">Publication</dt><dd class="col-7"><?= tt_h($period['publication_date']) ?></dd>
                        <dt class="col-5 text-muted">Test window</dt>
                        <dd class="col-7"><?= tt_h($period['test_start_date']) ?> → <?= tt_h($period['test_end_date']) ?></dd>
                    </dl>
                    <?php if (!$canSchedule): ?>
                        <div class="alert alert-info small mt-3 mb-0">Preparation controls unlock on <?= tt_h($period['scheduling_start_date']) ?>.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="tt-card p-3 h-100">
                <h5 class="mb-3" style="font-size:.95rem;font-weight:700;color:#164e86;">Create Assessment Period</h5>
                <form method="post" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?= tt_h($csrfToken) ?>">
                    <input type="hidden" name="action" value="create_period">
                    <?php if ($currentAp): ?>
                        <input type="hidden" name="academic_period_id" value="<?= (int)$currentAp['id'] ?>">
                    <?php endif; ?>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Academic year</label>
                        <input type="text" name="academic_year" class="form-control" required value="<?= tt_h((string)$defaultYear) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Term label</label>
                        <input type="text" name="term_label" class="form-control" required value="<?= tt_h((string)$defaultTerm) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Scheduling start</label>
                        <input type="date" name="scheduling_start_date" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Publication date</label>
                        <input type="date" name="publication_date" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Test start</label>
                        <input type="date" name="test_start_date" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Test end</label>
                        <input type="date" name="test_end_date" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Create period</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($period): ?>
    <div class="row g-3 mb-3">
        <div class="col"><div class="tt-stat"><div class="label">Total Tests</div><div class="value"><?= (int)$summary['total'] ?></div></div></div>
        <div class="col"><div class="tt-stat"><div class="label">Scheduled Courses</div><div class="value"><?= (int)$summary['scheduled_courses'] ?></div></div></div>
        <div class="col"><div class="tt-stat"><div class="label">Unscheduled</div><div class="value"><?= (int)$summary['unscheduled'] ?></div></div></div>
        <div class="col"><div class="tt-stat"><div class="label">Conflicts</div><div class="value text-danger"><?= (int)$summary['conflicts'] ?></div></div></div>
        <div class="col"><div class="tt-stat"><div class="label">Rooms Used</div><div class="value"><?= (int)$summary['rooms_used'] ?></div></div></div>
    </div>

    <div class="tt-card p-3 mb-3 d-flex flex-wrap gap-2 align-items-center">
        <a class="btn btn-outline-secondary btn-sm" href="<?= tt_h($ttSelf) ?>?period_id=<?= $periodId ?>&conflicts=1">
            <i class="fas fa-exclamation-triangle me-1"></i>Check Conflicts
        </a>
        <?php if ($canSchedule && !in_array($status, ['published', 'active', 'closed', 'archived'], true)): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Publish only if there are no critical conflicts.');">
                <input type="hidden" name="csrf_token" value="<?= tt_h($csrfToken) ?>">
                <input type="hidden" name="action" value="publish">
                <input type="hidden" name="period_id" value="<?= $periodId ?>">
                <button class="btn btn-success btn-sm" type="submit"><i class="fas fa-bullhorn me-1"></i>Publish</button>
            </form>
        <?php endif; ?>
        <?php if (in_array($status, ['published', 'active'], true)): ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= tt_h($csrfToken) ?>">
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="period_id" value="<?= $periodId ?>">
                <button class="btn btn-warning btn-sm" type="submit"><i class="fas fa-lock me-1"></i>Close</button>
            </form>
        <?php endif; ?>
        <?php if ($status === 'closed'): ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= tt_h($csrfToken) ?>">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="period_id" value="<?= $periodId ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fas fa-archive me-1"></i>Archive</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($showConflicts || $conflicts !== []): ?>
        <div class="tt-card p-3 mb-3">
            <h5 class="mb-2" style="font-size:.95rem;font-weight:700;">Conflict report</h5>
            <?php if ($conflicts === []): ?>
                <div class="alert alert-success mb-0">No conflicts detected.</div>
            <?php else: ?>
                <ul class="mb-0">
                    <?php foreach ($conflicts as $c): ?>
                        <li class="text-danger small mb-1"><?= tt_h($c['message']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($canSchedule && !in_array($status, ['closed', 'archived'], true)): ?>
    <div class="tt-card p-3 mb-3">
        <h5 class="mb-3" style="font-size:.95rem;font-weight:700;color:#164e86;">
            <?= $editEntry ? 'Edit Test' : 'Add Test' ?>
        </h5>
        <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= tt_h($csrfToken) ?>">
            <input type="hidden" name="action" value="save_entry">
            <input type="hidden" name="period_id" value="<?= $periodId ?>">
            <input type="hidden" name="entry_id" value="<?= (int)($editEntry['id'] ?? 0) ?>">
            <div class="col-md-3">
                <label class="form-label small fw-bold">Date</label>
                <input type="date" name="test_date" class="form-control" required
                       min="<?= tt_h($period['test_start_date']) ?>" max="<?= tt_h($period['test_end_date']) ?>"
                       value="<?= tt_h((string)($editEntry['test_date'] ?? $period['test_start_date'])) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Start</label>
                <input type="time" name="start_time" class="form-control" required
                       value="<?= tt_h(substr((string)($editEntry['start_time'] ?? '09:00:00'), 0, 5)) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">End</label>
                <input type="time" name="end_time" class="form-control" required
                       value="<?= tt_h(substr((string)($editEntry['end_time'] ?? '11:00:00'), 0, 5)) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Year / level</label>
                <input type="number" name="year_of_study" class="form-control" min="1" max="10" required
                       value="<?= (int)($editEntry['year_of_study'] ?? 1) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Section / class</label>
                <input type="text" name="section_label" class="form-control" maxlength="80"
                       value="<?= tt_h((string)($editEntry['section_label'] ?? '')) ?>" placeholder="Optional">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Programme</label>
                <select name="program_code" id="tt_program" class="form-select" required>
                    <?php foreach ($programs as $pr): ?>
                        <option value="<?= tt_h($pr['program_code']) ?>"
                            <?= $courseProgram === (string)$pr['program_code'] ? 'selected' : '' ?>>
                            <?= tt_h($pr['program_name'] . ' (' . $pr['program_code'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Course</label>
                <select name="course_code" id="tt_course" class="form-select" required>
                    <?php foreach ($coursesByProgram as $c): ?>
                        <option value="<?= tt_h($c['course_code']) ?>"
                            <?= (string)($editEntry['course_code'] ?? '') === (string)$c['course_code'] ? 'selected' : '' ?>>
                            <?= tt_h($c['course_code'] . ' — ' . $c['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Room</label>
                <select name="classroom_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($classrooms as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"
                            <?= (int)($editEntry['classroom_id'] ?? 0) === (int)$r['id'] ? 'selected' : '' ?>>
                            <?= tt_h($r['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">Lecturer / invigilator</label>
                <select name="lecturer_staff_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($lecturers as $l): ?>
                        <option value="<?= tt_h($l['staff_id']) ?>"
                            <?= (string)($editEntry['lecturer_staff_id'] ?? '') === (string)$l['staff_id'] ? 'selected' : '' ?>>
                            <?= tt_h($l['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-save me-1"></i><?= $editEntry ? 'Update' : 'Add Test' ?>
                </button>
                <?php if ($editEntry): ?>
                    <a href="<?= tt_h($ttSelf) ?>?period_id=<?= $periodId ?>" class="btn btn-outline-secondary btn-sm">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
        <p class="small text-muted mt-2 mb-0">Course list refreshes when you change programme (requires a short reload).</p>
    </div>
    <?php endif; ?>

    <div class="tt-card mb-3">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0" style="font-size:.95rem;font-weight:700;color:#164e86;">Scheduled tests</h5>
            <span class="small text-muted">Page <?= $page ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th><th>Time</th><th>Programme</th><th>Yr</th><th>Course</th>
                        <th>Section</th><th>Room</th><th>Lecturer</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($entries === []): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No tests scheduled yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $e): ?>
                        <tr>
                            <td><?= tt_h($e['test_date']) ?></td>
                            <td><?= tt_h(substr((string)$e['start_time'], 0, 5)) ?>–<?= tt_h(substr((string)$e['end_time'], 0, 5)) ?></td>
                            <td><?= tt_h($e['program_name'] ?: $e['program_code']) ?></td>
                            <td><?= (int)$e['year_of_study'] ?></td>
                            <td><?= tt_h(($e['course_code'] ?? '') . ' ' . ($e['course_name'] ?? '')) ?></td>
                            <td><?= tt_h((string)($e['section_label'] ?? '—')) ?></td>
                            <td><?= tt_h((string)($e['room_code'] ?? '—')) ?></td>
                            <td><?= tt_h(trim((string)($e['lecturer_name'] ?? '')) ?: '—') ?></td>
                            <td class="text-nowrap">
                                <?php if ($canSchedule && !in_array($status, ['closed', 'archived'], true)): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= tt_h($ttSelf) ?>?period_id=<?= $periodId ?>&edit=<?= (int)$e['id'] ?>">Edit</a>
                                <?php endif; ?>
                                <?php if (in_array($status, ['draft', 'scheduling'], true)): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this test entry?');">
                                        <input type="hidden" name="csrf_token" value="<?= tt_h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_entry">
                                        <input type="hidden" name="period_id" value="<?= $periodId ?>">
                                        <input type="hidden" name="entry_id" value="<?= (int)$e['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($entries) >= $perPage || $page > 1): ?>
            <div class="p-3 d-flex gap-2">
                <?php if ($page > 1): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= tt_h($ttSelf) ?>?period_id=<?= $periodId ?>&page=<?= $page - 1 ?>">Previous</a>
                <?php endif; ?>
                <?php if (count($entries) >= $perPage): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= tt_h($ttSelf) ?>?period_id=<?= $periodId ?>&page=<?= $page + 1 ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($unscheduled !== []): ?>
    <div class="tt-card p-3 mb-3">
        <h5 class="mb-2" style="font-size:.95rem;font-weight:700;">Unscheduled courses (sample)</h5>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Programme</th><th>Year</th><th>Course</th></tr></thead>
                <tbody>
                <?php foreach ($unscheduled as $u): ?>
                    <tr>
                        <td><?= tt_h($u['program_name'] ?: $u['program_code']) ?></td>
                        <td><?= (int)$u['year_of_study'] ?></td>
                        <td><?= tt_h($u['course_code'] . ' — ' . ($u['course_name'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; /* period */ ?>
    <?php endif; /* schema */ ?>
</div>

<script>
(function () {
    var sel = document.getElementById('tt_program');
    if (!sel) return;
    sel.addEventListener('change', function () {
        var url = new URL(window.location.href);
        url.searchParams.set('period_id', '<?= (int)$periodId ?>');
        url.searchParams.set('preload_program', sel.value);
        url.searchParams.delete('edit');
        window.location.href = url.toString();
    });
})();
</script>
