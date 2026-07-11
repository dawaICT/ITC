<?php
/**
 * Training Intakes — admin management of admission windows (§10.5 / §9).
 *
 * An intake is the admission period; courses are attached to it (course_intakes)
 * and training batches run underneath (training_batches) on itc_intake_manage.php.
 */
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/itc_course_helpers.php';

$page_title = "Training Intakes";
$staffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '';

if (empty($_SESSION['itc_csrf'])) {
    $_SESSION['itc_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['itc_csrf'];
function itc_csrf_ok(?string $t): bool
{
    return is_string($t) && !empty($_SESSION['itc_csrf']) && hash_equals($_SESSION['itc_csrf'], $t);
}

// §9 lifecycle statuses (must match the intakes.status ENUM).
$STATUSES = ['Draft', 'Open for Applications', 'Closed for Applications', 'Screening Applicants',
    'Payment Collection', 'Ready for Training', 'Training in Progress', 'Completed', 'Cancelled'];

$intakeTypes = itc_intake_types($db);

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!itc_csrf_ok($_POST['csrf_token'] ?? null)) {
        $msg = 'Security token mismatch. Please refresh the page and try again.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        if ($action === 'add' || $action === 'edit') {
            $name   = trim($_POST['intake_name'] ?? '');
            $year   = (int) ($_POST['intake_year'] ?? date('Y'));
            $typeId = ($_POST['intake_type_id'] ?? '') !== '' ? (int) $_POST['intake_type_id'] : null;
            $open   = !empty($_POST['application_open_date']) ? $_POST['application_open_date'] : null;
            $close  = !empty($_POST['application_close_date']) ? $_POST['application_close_date'] : null;
            $tStart = !empty($_POST['training_start_date']) ? $_POST['training_start_date'] : null;
            $tEnd   = !empty($_POST['training_end_date']) ? $_POST['training_end_date'] : null;
            $status = in_array($_POST['status'] ?? '', $STATUSES, true) ? $_POST['status'] : 'Draft';

            $errs = [];
            if ($name === '') {
                $errs[] = 'Intake name is required.';
            }
            if ($year < 2000 || $year > 2100) {
                $errs[] = 'Enter a valid intake year.';
            }
            if ($typeId === null || !isset($intakeTypes[$typeId])) {
                $errs[] = 'Choose an intake type.';
            }
            if ($open && $close && strtotime($close) < strtotime($open)) {
                $errs[] = 'Application close date cannot be before the open date.';
            }
            if ($tStart && $tEnd && strtotime($tEnd) < strtotime($tStart)) {
                $errs[] = 'Training end date cannot be before the start date.';
            }

            if ($errs) {
                $msg = implode(' ', $errs);
                $msgType = 'danger';
            } elseif ($action === 'add') {
                $ins = $db->prepare("INSERT INTO intakes
                    (intake_name, intake_year, intake_type_id, application_open_date, application_close_date,
                     training_start_date, training_end_date, status, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?)");
                $ins->bind_param("siissssss", $name, $year, $typeId, $open, $close, $tStart, $tEnd, $status, $staffId);
                if ($ins->execute()) {
                    $msg = "Intake <strong>" . htmlspecialchars($name) . "</strong> created.";
                    $msgType = 'success';
                } else {
                    $msg = "Database error: " . htmlspecialchars($db->error);
                    $msgType = 'danger';
                }
            } else {
                $id = (int) ($_POST['id'] ?? 0);
                $upd = $db->prepare("UPDATE intakes SET
                    intake_name=?, intake_year=?, intake_type_id=?, application_open_date=?, application_close_date=?,
                    training_start_date=?, training_end_date=?, status=? WHERE id=?");
                $upd->bind_param("siisssssi", $name, $year, $typeId, $open, $close, $tStart, $tEnd, $status, $id);
                $msg = $upd->execute() ? "Intake updated." : ("Update failed: " . htmlspecialchars($db->error));
                $msgType = $upd->error ? 'danger' : 'success';
            }
        } elseif ($action === 'advance_status') {
            $id = (int) ($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', $STATUSES, true) ? $_POST['status'] : null;
            if ($status === null) {
                $msg = 'Invalid status.';
                $msgType = 'danger';
            } else {
                $upd = $db->prepare("UPDATE intakes SET status=? WHERE id=?");
                $upd->bind_param("si", $status, $id);
                $upd->execute();
                $msg = "Intake status set to <strong>" . htmlspecialchars($status) . "</strong>.";
                $msgType = 'success';
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $del = $db->prepare("DELETE FROM intakes WHERE id=?"); // cascades course_intakes + batches
            $del->bind_param("i", $id);
            $msg = $del->execute() ? "Intake deleted." : ("Delete failed: " . htmlspecialchars($db->error));
            $msgType = $del->error ? 'danger' : 'warning';
        }
    }
}

// Fetch intakes with attached-course / batch counts.
$intakes = [];
$res = $db->query("
    SELECT i.*, it.type_name, it.type_code,
           (SELECT COUNT(*) FROM course_intakes ci WHERE ci.intake_id = i.id) AS course_count,
           (SELECT COUNT(*) FROM training_batches tb
                JOIN course_intakes ci ON tb.course_intake_id = ci.id
            WHERE ci.intake_id = i.id) AS batch_count
    FROM intakes i
    LEFT JOIN intake_types it ON i.intake_type_id = it.id
    ORDER BY i.intake_year DESC, i.id DESC
");
if ($res) {
    while ($r = $res->fetch_object()) {
        $intakes[] = $r;
    }
    $res->free();
}

$openCount     = count(array_filter($intakes, fn($i) => $i->status === 'Open for Applications'));
$runningCount  = count(array_filter($intakes, fn($i) => $i->status === 'Training in Progress'));
$attachedTotal = array_sum(array_map(fn($i) => (int) $i->course_count, $intakes));

require "includes/header.php";
?>
<style>
    .itc-page { padding-top: 1.25rem; padding-bottom: 2rem; }
    .itc-page .stat-card { border: none !important; box-shadow: 0 8px 24px rgba(15, 23, 42, .06) !important; border-radius: 10px; }
    .itc-page .stat-icon { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 10px; }
    .itc-page .modal-header.admin-modal { background: linear-gradient(135deg, #6f42c1 0%, #4e2a84 100%) !important; color: #fff; }
    .itc-page .modal-header.admin-modal .btn-close { filter: invert(1) grayscale(1) brightness(2); }
    .itc-page .status-select { font-size: .78rem; font-weight: 600; border-radius: 6px; min-width: 165px; }
    .itc-page .win { font-size: .8rem; color: #475569; white-space: nowrap; }
    .itc-page .win .muted { color: #94a3b8; }
    .itc-page .type-pill { background: #fff7ed; color: #c2410c; font-weight: 700; font-size: .72rem; padding: .25rem .5rem; border-radius: 6px; }
    .itc-page .count-pill { background: #eef2ff; color: #3949ab; font-weight: 700; font-size: .75rem; padding: .25rem .55rem; border-radius: 6px; }
</style>

<div class="container-fluid px-4 portal-dashboard itc-page">
    <div class="page-header mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-calendar-check me-2 text-primary"></i>Training Intakes</h5>
                <p class="page-subtitle text-muted mb-0">Admission windows for ITC courses. Attach courses and run training batches per intake.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="short_courses.php" class="btn btn-outline-secondary"><i class="fas fa-book me-1"></i>Catalogue</a>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addIntakeModal"><i class="fas fa-plus me-1"></i>New Intake</button>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show"><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6"><div class="stat-card p-3 h-100"><div class="d-flex align-items-center">
            <div class="stat-icon bg-primary text-white me-3"><i class="fas fa-calendar-check"></i></div>
            <div><h4 class="mb-0 fw-bold"><?= count($intakes) ?></h4><small class="text-muted">Total Intakes</small></div>
        </div></div></div>
        <div class="col-md-3 col-6"><div class="stat-card p-3 h-100"><div class="d-flex align-items-center">
            <div class="stat-icon bg-success text-white me-3"><i class="fas fa-door-open"></i></div>
            <div><h4 class="mb-0 fw-bold"><?= $openCount ?></h4><small class="text-muted">Open for Applications</small></div>
        </div></div></div>
        <div class="col-md-3 col-6"><div class="stat-card p-3 h-100"><div class="d-flex align-items-center">
            <div class="stat-icon bg-info text-white me-3"><i class="fas fa-person-chalkboard"></i></div>
            <div><h4 class="mb-0 fw-bold"><?= $runningCount ?></h4><small class="text-muted">Training in Progress</small></div>
        </div></div></div>
        <div class="col-md-3 col-6"><div class="stat-card p-3 h-100"><div class="d-flex align-items-center">
            <div class="stat-icon bg-warning text-white me-3"><i class="fas fa-layer-group"></i></div>
            <div><h4 class="mb-0 fw-bold"><?= $attachedTotal ?></h4><small class="text-muted">Course Offerings</small></div>
        </div></div></div>
    </div>

    <div class="data-table-card card border-0 shadow-sm">
        <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-list me-2"></i>All Intakes</h5></div>
        <div class="card-body">
            <?php if (empty($intakes)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-calendar-plus fa-3x text-muted mb-3 d-block" style="opacity:.2"></i>
                    <h5 class="text-muted">No intakes yet</h5>
                    <p class="text-muted">Create an intake, then attach courses and batches to it.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="intakesTable">
                        <thead class="table-light">
                            <tr>
                                <th>Intake</th>
                                <th>Type</th>
                                <th>Applications</th>
                                <th>Training</th>
                                <th class="text-center">Courses</th>
                                <th class="text-center">Batches</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($intakes as $i): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($i->intake_name) ?></strong>
                                        <div class="text-muted small"><?= (int) $i->intake_year ?></div>
                                    </td>
                                    <td><span class="type-pill"><?= htmlspecialchars($i->type_name ?? '—') ?></span></td>
                                    <td class="win">
                                        <?php if ($i->application_open_date): ?>
                                            <?= date('M j', strtotime($i->application_open_date)) ?>
                                            – <?= $i->application_close_date ? date('M j, Y', strtotime($i->application_close_date)) : '…' ?>
                                        <?php else: ?><span class="muted">not set</span><?php endif; ?>
                                    </td>
                                    <td class="win">
                                        <?php if ($i->training_start_date): ?>
                                            <?= date('M j', strtotime($i->training_start_date)) ?>
                                            – <?= $i->training_end_date ? date('M j, Y', strtotime($i->training_end_date)) : '…' ?>
                                        <?php else: ?><span class="muted">not set</span><?php endif; ?>
                                    </td>
                                    <td class="text-center"><span class="count-pill"><?= (int) $i->course_count ?></span></td>
                                    <td class="text-center"><span class="count-pill"><?= (int) $i->batch_count ?></span></td>
                                    <td>
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="action" value="advance_status">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $i->id ?>">
                                            <select name="status" class="form-select form-select-sm status-select" onchange="this.form.submit()">
                                                <?php foreach ($STATUSES as $s): ?>
                                                    <option value="<?= htmlspecialchars($s) ?>" <?= $i->status === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">
                                            <a class="btn btn-sm btn-success" title="Manage courses &amp; batches"
                                                href="itc_intake_manage.php?id=<?= (int) $i->id ?>"><i class="fas fa-sliders"></i></a>
                                            <button class="btn btn-sm btn-outline-primary edit-intake-btn" title="Edit"
                                                data-intake='<?= htmlspecialchars(json_encode($i), ENT_QUOTES) ?>'
                                                data-bs-toggle="modal" data-bs-target="#editIntakeModal"><i class="fas fa-edit"></i></button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this intake and all its course offerings and batches?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $i->id ?>">
                                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Shared form fields for add/edit modals.
$intakeFormFields = function (string $prefix) use ($intakeTypes, $STATUSES) {
    ob_start(); ?>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Intake Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="intake_name" id="<?= $prefix ?>Name" required maxlength="150"
                placeholder="e.g. June 2026 Short Courses Intake">
        </div>
        <div class="col-md-4">
            <label class="form-label">Year <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="intake_year" id="<?= $prefix ?>Year" min="2000" max="2100" value="<?= date('Y') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Intake Type <span class="text-danger">*</span></label>
            <select class="form-select" name="intake_type_id" id="<?= $prefix ?>Type" required>
                <option value="">— Select —</option>
                <?php foreach ($intakeTypes as $tid => $t): ?>
                    <option value="<?= (int) $tid ?>" data-code="<?= htmlspecialchars($t['type_code']) ?>"><?= htmlspecialchars($t['type_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Status</label>
            <select class="form-select" name="status" id="<?= $prefix ?>Status">
                <?php foreach ($STATUSES as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12"><hr class="my-1"><small class="text-muted"><i class="fas fa-wand-magic-sparkles me-1"></i>Set the training start, then click “Suggest application window”.</small></div>
        <div class="col-md-6">
            <label class="form-label">Training Start</label>
            <input type="date" class="form-control" name="training_start_date" id="<?= $prefix ?>TStart">
        </div>
        <div class="col-md-6">
            <label class="form-label">Training End</label>
            <input type="date" class="form-control" name="training_end_date" id="<?= $prefix ?>TEnd">
        </div>
        <div class="col-md-6">
            <label class="form-label d-flex justify-content-between align-items-center">
                <span>Applications Open</span>
                <button type="button" class="btn btn-link btn-sm p-0 suggest-dates-btn" data-prefix="<?= $prefix ?>">Suggest window</button>
            </label>
            <input type="date" class="form-control" name="application_open_date" id="<?= $prefix ?>Open">
        </div>
        <div class="col-md-6">
            <label class="form-label">Applications Close</label>
            <input type="date" class="form-control" name="application_close_date" id="<?= $prefix ?>Close">
        </div>
    </div>
    <?php
    return ob_get_clean();
};
?>

<!-- ADD MODAL -->
<div class="modal fade itc-page" id="addIntakeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header admin-modal"><h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>New Intake</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="modal-body"><?= $intakeFormFields('add') ?></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Create Intake</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="modal fade itc-page" id="editIntakeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header admin-modal"><h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Intake</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="id" id="editId">
                <div class="modal-body"><?= $intakeFormFields('edit') ?></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Populate edit modal.
    document.querySelectorAll('.edit-intake-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const i = JSON.parse(this.dataset.intake);
            document.getElementById('editId').value = i.id;
            document.getElementById('editName').value = i.intake_name;
            document.getElementById('editYear').value = i.intake_year;
            document.getElementById('editType').value = i.intake_type_id || '';
            document.getElementById('editStatus').value = i.status;
            document.getElementById('editTStart').value = i.training_start_date || '';
            document.getElementById('editTEnd').value = i.training_end_date || '';
            document.getElementById('editOpen').value = i.application_open_date || '';
            document.getElementById('editClose').value = i.application_close_date || '';
        });
    });

    // Suggest an application window from intake type + training start (mirrors itc_default_intake_dates).
    const LEAD = { ON_DEMAND: 0, ROLLING: 7, WEEKLY: 10, MONTHLY: 21, TERM_BASED: 30, SEMESTER_BASED: 45, ANNUAL: 60 };
    document.querySelectorAll('.suggest-dates-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const p = this.dataset.prefix;
            const start = document.getElementById(p + 'TStart').value;
            if (!start) { alert('Set the Training Start date first.'); return; }
            const typeSel = document.getElementById(p + 'Type');
            const code = typeSel.options[typeSel.selectedIndex] ? (typeSel.options[typeSel.selectedIndex].dataset.code || '') : '';
            const lead = (LEAD[code] ?? 14);
            const s = new Date(start + 'T00:00:00');
            const open = new Date(s); open.setDate(open.getDate() - (lead + 7));
            const close = new Date(s); close.setDate(close.getDate() - 3);
            const fmt = d => d.toISOString().slice(0, 10);
            document.getElementById(p + 'Open').value = fmt(open);
            document.getElementById(p + 'Close').value = fmt(close);
        });
    });

    $(document).ready(function () {
        if ($('#intakesTable').length && $.fn.DataTable) {
            $('#intakesTable').DataTable({ pageLength: 15, order: [], columnDefs: [{ orderable: false, targets: [6, 7] }] });
        }
        const banner = document.querySelector('.alert-success, .alert-danger, .alert-warning');
        if (banner) banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
</script>

<?php require_once "includes/footer.php"; ?>
