<?php
/**
 * Manage a single intake: attach courses (course_intakes) and run training
 * batches (training_batches) underneath them. §10.6 / §10.7 / §18.
 */
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/itc_course_helpers.php';

$intakeId = (int) ($_GET['id'] ?? 0);
$staffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '';

if (empty($_SESSION['itc_csrf'])) {
    $_SESSION['itc_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['itc_csrf'];
function itc_csrf_ok(?string $t): bool
{
    return is_string($t) && !empty($_SESSION['itc_csrf']) && hash_equals($_SESSION['itc_csrf'], $t);
}

if (!function_exists('itc_app_badge')) {
    function itc_app_badge(?string $s): string
    {
        $map = [
            'awaiting_payment'     => ['warning', 'Awaiting Payment'],
            'confirmed'            => ['success', 'Confirmed'],
            'rejected'             => ['danger', 'Rejected'],
            'cancelled'            => ['secondary', 'Cancelled'],
            'pending_requirements' => ['info', 'Pending Requirements'],
        ];
        [$c, $l] = $map[$s] ?? ['secondary', ucfirst((string) $s)];
        return '<span class="badge bg-' . $c . '-subtle text-' . $c . ' border">' . htmlspecialchars($l) . '</span>';
    }
}

// Load the intake.
$intake = null;
$stmt = $db->prepare("SELECT i.*, it.type_name, it.type_code FROM intakes i
                      LEFT JOIN intake_types it ON i.intake_type_id = it.id WHERE i.id = ?");
$stmt->bind_param("i", $intakeId);
$stmt->execute();
$intake = $stmt->get_result()->fetch_object();
$stmt->close();

if (!$intake) {
    $page_title = "Intake Not Found";
    require "includes/header.php";
    echo '<div class="container-fluid px-4 py-5"><div class="alert alert-warning">Intake not found. <a href="itc_intakes.php">Back to intakes</a>.</div></div>';
    require_once "includes/footer.php";
    exit;
}

$page_title = "Manage Intake — " . $intake->intake_name;
$locked = in_array($intake->status, ['Cancelled', 'Completed'], true); // BR-INTAKE-006 (no new batches)

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!itc_csrf_ok($_POST['csrf_token'] ?? null)) {
        $msg = 'Security token mismatch. Please refresh and try again.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        if ($action === 'attach_course') {
            $scId = (int) ($_POST['short_course_id'] ?? 0);
            $cap  = max(1, (int) ($_POST['capacity'] ?? 30));
            if ($locked) {
                $msg = 'This intake is ' . htmlspecialchars($intake->status) . '; courses cannot be attached.';
                $msgType = 'danger';
            } elseif ($scId <= 0) {
                $msg = 'Choose a course to attach.';
                $msgType = 'danger';
            } else {
                $ins = $db->prepare("INSERT INTO course_intakes (short_course_id, intake_id, capacity, available_slots)
                                     VALUES (?,?,?,?)");
                $ins->bind_param("iiii", $scId, $intakeId, $cap, $cap);
                try {
                    $ins->execute();
                    $msg = "Course attached to the intake.";
                    $msgType = 'success';
                } catch (Throwable $e) {
                    $msg = (str_contains($e->getMessage(), 'uq_course_intake'))
                        ? "That course is already attached to this intake."
                        : "Could not attach course: " . htmlspecialchars($e->getMessage());
                    $msgType = 'danger';
                }
            }
        } elseif ($action === 'detach_course') {
            $ciId = (int) ($_POST['course_intake_id'] ?? 0);
            $del = $db->prepare("DELETE FROM course_intakes WHERE id = ? AND intake_id = ?");
            $del->bind_param("ii", $ciId, $intakeId);
            $del->execute();
            $msg = "Course removed from the intake.";
            $msgType = 'warning';
        } elseif ($action === 'add_batch') {
            $ciId  = (int) ($_POST['course_intake_id'] ?? 0);
            $bname = trim($_POST['batch_name'] ?? '');
            $start = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $cap   = max(1, (int) ($_POST['capacity'] ?? 20));
            $trainer = ($_POST['trainer_id'] ?? '') !== '' ? trim($_POST['trainer_id']) : null;
            $location = ($_POST['location'] ?? '') !== '' ? trim($_POST['location']) : null;

            // Resolve the course duration for this offering, then derive the batch end date.
            $durVal = 0; $durUnit = 'days'; $ciCap = 0;
            $q = $db->prepare("SELECT ci.capacity, sc.duration_value, sc.duration_unit
                               FROM course_intakes ci JOIN short_courses sc ON ci.short_course_id = sc.id
                               WHERE ci.id = ? AND ci.intake_id = ?");
            $q->bind_param("ii", $ciId, $intakeId);
            $q->execute();
            $row = $q->get_result()->fetch_object();
            $q->close();

            // Already-allocated batch capacity (BR-INTAKE-007).
            $allocated = 0;
            $a = $db->prepare("SELECT COALESCE(SUM(capacity),0) s FROM training_batches WHERE course_intake_id = ?");
            $a->bind_param("i", $ciId);
            $a->execute();
            $allocated = (int) $a->get_result()->fetch_object()->s;
            $a->close();

            if ($locked) {
                $msg = 'This intake is ' . htmlspecialchars($intake->status) . '; batches cannot be created.';
                $msgType = 'danger';
            } elseif (!$row) {
                $msg = 'Course offering not found for this intake.';
                $msgType = 'danger';
            } elseif ($bname === '') {
                $msg = 'Batch name is required.';
                $msgType = 'danger';
            } elseif ($allocated + $cap > (int) $row->capacity) {
                $msg = "Batch capacity exceeds the offering's approved capacity ({$row->capacity}). Already allocated: {$allocated}.";
                $msgType = 'danger';
            } else {
                $durVal = (int) $row->duration_value;
                $durUnit = (string) $row->duration_unit;
                $end = $start ? itc_batch_end_date($start, $durVal, $durUnit) : null;
                $ins = $db->prepare("INSERT INTO training_batches
                    (course_intake_id, batch_name, start_date, end_date, trainer_id, location, capacity)
                    VALUES (?,?,?,?,?,?,?)");
                $ins->bind_param("isssssi", $ciId, $bname, $start, $end, $trainer, $location, $cap);
                $ins->execute();
                $msg = "Batch <strong>" . htmlspecialchars($bname) . "</strong> created.";
                $msgType = 'success';
            }
        } elseif ($action === 'batch_status') {
            $bId = (int) ($_POST['batch_id'] ?? 0);
            $st = in_array($_POST['status'] ?? '', ['open', 'in_progress', 'completed', 'cancelled'], true) ? $_POST['status'] : null;
            if ($st !== null) {
                $upd = $db->prepare("UPDATE training_batches SET status = ? WHERE id = ?");
                $upd->bind_param("si", $st, $bId);
                $upd->execute();
                $msg = "Batch status updated.";
                $msgType = 'success';
            }
        } elseif ($action === 'delete_batch') {
            $bId = (int) ($_POST['batch_id'] ?? 0);
            $del = $db->prepare("DELETE FROM training_batches WHERE id = ?");
            $del->bind_param("i", $bId);
            $del->execute();
            $msg = "Batch deleted.";
            $msgType = 'warning';
        } elseif ($action === 'verify_payment') {
            // §17: after payment verification, confirm the application.
            $enId = (int) ($_POST['enrollment_id'] ?? 0);
            $e = $db->prepare("SELECT e.id, e.invoice_id FROM short_course_enrollments e
                               JOIN course_intakes ci ON e.course_intake_id = ci.id
                               WHERE e.id = ? AND ci.intake_id = ?");
            $e->bind_param("ii", $enId, $intakeId);
            $e->execute();
            $en = $e->get_result()->fetch_object();
            $e->close();
            if (!$en) {
                $msg = 'Application not found for this intake.';
                $msgType = 'danger';
            } else {
                if ($en->invoice_id) {
                    $p = $db->prepare("UPDATE student_payments
                                       SET amount_paid = amount_paid + balance, balance = 0, payment_status = 'completed'
                                       WHERE payment_id = ?");
                    $p->bind_param("i", $en->invoice_id);
                    $p->execute();
                    $p->close();
                }
                $u = $db->prepare("UPDATE short_course_enrollments SET application_status = 'confirmed' WHERE id = ?");
                $u->bind_param("i", $enId);
                $u->execute();
                $u->close();
                $msg = 'Payment verified — application confirmed.';
                $msgType = 'success';
            }
        } elseif ($action === 'assign_batch') {
            // §18: place a confirmed (paid) student into a batch.
            $enId = (int) ($_POST['enrollment_id'] ?? 0);
            $bId  = (int) ($_POST['training_batch_id'] ?? 0);
            $e = $db->prepare("SELECT e.id, e.application_status, e.course_intake_id, e.training_batch_id
                               FROM short_course_enrollments e JOIN course_intakes ci ON e.course_intake_id = ci.id
                               WHERE e.id = ? AND ci.intake_id = ?");
            $e->bind_param("ii", $enId, $intakeId);
            $e->execute();
            $en = $e->get_result()->fetch_object();
            $e->close();
            $bat = null;
            if ($en) {
                $b = $db->prepare("SELECT id, capacity, current_enrolment FROM training_batches WHERE id = ? AND course_intake_id = ?");
                $b->bind_param("ii", $bId, $en->course_intake_id);
                $b->execute();
                $bat = $b->get_result()->fetch_object();
                $b->close();
            }
            if (!$en) {
                $msg = 'Application not found.';
                $msgType = 'danger';
            } elseif ($en->application_status !== 'confirmed') {
                $msg = 'Only confirmed (paid) applicants can be placed in a batch.';
                $msgType = 'danger';
            } elseif (!$bat) {
                $msg = 'Choose a batch that belongs to this course.';
                $msgType = 'danger';
            } elseif ((int) $bat->current_enrolment >= (int) $bat->capacity && (int) $en->training_batch_id !== (int) $bId) {
                $msg = 'That batch is full.';
                $msgType = 'danger';
            } else {
                try {
                    $db->begin_transaction();
                    if ($en->training_batch_id && (int) $en->training_batch_id !== (int) $bId) {
                        $db->query("UPDATE training_batches SET current_enrolment = GREATEST(current_enrolment - 1, 0) WHERE id = " . (int) $en->training_batch_id);
                    }
                    if ((int) $en->training_batch_id !== (int) $bId) {
                        $u = $db->prepare("UPDATE short_course_enrollments SET training_batch_id = ? WHERE id = ?");
                        $u->bind_param("ii", $bId, $enId);
                        $u->execute();
                        $u->close();
                        $db->query("UPDATE training_batches SET current_enrolment = current_enrolment + 1 WHERE id = " . (int) $bId);
                    }
                    $db->commit();
                    $msg = 'Student assigned to batch.';
                    $msgType = 'success';
                } catch (Throwable $ex) {
                    $db->rollback();
                    $msg = 'Could not assign batch.';
                    $msgType = 'danger';
                }
            }
        } elseif ($action === 'release_application') {
            // Free the provisional slot and cancel the application.
            $enId = (int) ($_POST['enrollment_id'] ?? 0);
            $e = $db->prepare("SELECT e.id, e.course_intake_id, e.training_batch_id, e.application_status
                               FROM short_course_enrollments e JOIN course_intakes ci ON e.course_intake_id = ci.id
                               WHERE e.id = ? AND ci.intake_id = ?");
            $e->bind_param("ii", $enId, $intakeId);
            $e->execute();
            $en = $e->get_result()->fetch_object();
            $e->close();
            if (!$en) {
                $msg = 'Application not found.';
                $msgType = 'danger';
            } elseif (in_array($en->application_status, ['cancelled', 'rejected'], true)) {
                $msg = 'That application is already released.';
                $msgType = 'warning';
            } else {
                try {
                    $db->begin_transaction();
                    $u = $db->prepare("UPDATE short_course_enrollments SET application_status = 'cancelled', training_batch_id = NULL WHERE id = ?");
                    $u->bind_param("i", $enId);
                    $u->execute();
                    $u->close();
                    $db->query("UPDATE course_intakes SET available_slots = available_slots + 1 WHERE id = " . (int) $en->course_intake_id);
                    if ($en->training_batch_id) {
                        $db->query("UPDATE training_batches SET current_enrolment = GREATEST(current_enrolment - 1, 0) WHERE id = " . (int) $en->training_batch_id);
                    }
                    $db->commit();
                    $msg = 'Application released and slot returned.';
                    $msgType = 'warning';
                } catch (Throwable $ex) {
                    $db->rollback();
                    $msg = 'Could not release application.';
                    $msgType = 'danger';
                }
            }
        }
    }
}

// Attached course offerings.
$offerings = [];
$res = $db->prepare("SELECT ci.*, sc.course_code, sc.course_name, sc.duration_value, sc.duration_unit,
                            cl.level_name
                     FROM course_intakes ci
                     JOIN short_courses sc ON ci.short_course_id = sc.id
                     LEFT JOIN course_classification_levels cl ON sc.level_id = cl.id
                     WHERE ci.intake_id = ? ORDER BY sc.course_name");
$res->bind_param("i", $intakeId);
$res->execute();
$r = $res->get_result();
while ($o = $r->fetch_object()) {
    $o->batches = [];
    $o->applicants = [];
    $offerings[(int) $o->id] = $o;
}
$res->close();

// Batches for all offerings.
if ($offerings) {
    $ids = implode(',', array_map('intval', array_keys($offerings)));
    $bq = $db->query("SELECT tb.*, CONCAT(s.Fname,' ',s.Lname) AS trainer_name
                      FROM training_batches tb
                      LEFT JOIN staff s ON tb.trainer_id = s.staff_id
                      WHERE tb.course_intake_id IN ($ids) ORDER BY tb.start_date, tb.id");
    while ($b = $bq->fetch_object()) {
        if (isset($offerings[(int) $b->course_intake_id])) {
            $offerings[(int) $b->course_intake_id]->batches[] = $b;
        }
    }

    // Applicants (intake-based applications) per offering.
    $aq = $db->query("SELECT e.id, e.student_id, e.application_status, e.applied_at, e.training_batch_id,
                             e.course_intake_id, e.invoice_id,
                             s.Fname, s.Lname, s.mobile,
                             sp.payment_status AS pay_status, sp.balance
                      FROM short_course_enrollments e
                      LEFT JOIN students s ON e.student_id COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci
                      LEFT JOIN student_payments sp ON e.invoice_id = sp.payment_id
                      WHERE e.course_intake_id IN ($ids) AND e.application_status IS NOT NULL
                      ORDER BY e.applied_at, e.id");
    while ($ap = $aq->fetch_object()) {
        if (isset($offerings[(int) $ap->course_intake_id])) {
            $offerings[(int) $ap->course_intake_id]->applicants[] = $ap;
        }
    }
}

// Courses available to attach (active + fully classified, not already attached).
$available = [];
$av = $db->query("SELECT sc.id, sc.course_code, sc.course_name
                  FROM short_courses sc
                  WHERE sc.status = 'active' AND sc.category_id IS NOT NULL AND sc.level_id IS NOT NULL
                        AND sc.intake_type_id IS NOT NULL
                        AND sc.id NOT IN (SELECT short_course_id FROM course_intakes WHERE intake_id = " . (int) $intakeId . ")
                  ORDER BY sc.course_name");
while ($row = $av->fetch_object()) {
    $available[] = $row;
}

// Active staff for the trainer dropdown.
$trainers = [];
$tq = $db->query("SELECT staff_id, CONCAT(Fname,' ',Lname) AS name FROM staff WHERE status = 'active' ORDER BY Fname, Lname");
while ($t = $tq->fetch_object()) {
    $trainers[] = $t;
}

require "includes/header.php";
?>
<style>
    .itc-page { padding-top: 1.25rem; padding-bottom: 2rem; }
    .itc-page .summary-card { border: none; border-radius: 12px; background: linear-gradient(135deg, #6f42c1 0%, #4e2a84 100%); color: #fff; }
    .itc-page .summary-card .meta { font-size: .85rem; opacity: .92; }
    .itc-page .offering-card { border: 1px solid #e5eaf2; border-radius: 12px; }
    .itc-page .offering-card .card-header { background: #f8fafc; border-bottom: 1px solid #e5eaf2; }
    .itc-page .slot-pill { background: #ecfdf5; color: #047857; font-weight: 700; font-size: .75rem; padding: .2rem .5rem; border-radius: 6px; }
    .itc-page .batch-row td { vertical-align: middle; }
    .itc-page .modal-header.admin-modal { background: linear-gradient(135deg, #6f42c1 0%, #4e2a84 100%); color: #fff; }
    .itc-page .modal-header.admin-modal .btn-close { filter: invert(1) grayscale(1) brightness(2); }
</style>

<div class="container-fluid px-4 portal-dashboard itc-page">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <a href="itc_intakes.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>All Intakes</a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show"><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card summary-card shadow-sm mb-4">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="mb-1"><?= htmlspecialchars($intake->intake_name) ?></h4>
                <div class="meta">
                    <i class="fas fa-tag me-1"></i><?= htmlspecialchars($intake->type_name ?? '—') ?>
                    &nbsp;·&nbsp; <i class="fas fa-calendar me-1"></i><?= (int) $intake->intake_year ?>
                    &nbsp;·&nbsp; <span class="badge bg-light text-dark"><?= htmlspecialchars($intake->status) ?></span>
                </div>
            </div>
            <div class="text-end meta">
                <?php if ($intake->training_start_date): ?>
                    <div><i class="fas fa-person-chalkboard me-1"></i>Training:
                        <?= date('M j, Y', strtotime($intake->training_start_date)) ?>
                        <?= $intake->training_end_date ? ' – ' . date('M j, Y', strtotime($intake->training_end_date)) : '' ?>
                    </div>
                <?php endif; ?>
                <div class="mt-1"><?= count($offerings) ?> course offering(s)</div>
            </div>
        </div>
    </div>

    <?php if ($locked): ?>
        <div class="alert alert-secondary"><i class="fas fa-lock me-1"></i>This intake is <strong><?= htmlspecialchars($intake->status) ?></strong>. Attaching courses and creating batches is disabled.</div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Attach course -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-plus-circle me-2 text-primary"></i>Attach a Course</h6></div>
                <div class="card-body">
                    <?php if (empty($available)): ?>
                        <p class="text-muted small mb-0">No more active, fully-classified courses are available to attach.
                            Add or classify courses in the <a href="short_courses.php">catalogue</a>.</p>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="attach_course">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <div class="mb-2">
                                <label class="form-label small">Course</label>
                                <select name="short_course_id" class="form-select" required <?= $locked ? 'disabled' : '' ?>>
                                    <option value="">— Select course —</option>
                                    <?php foreach ($available as $a): ?>
                                        <option value="<?= (int) $a->id ?>"><?= htmlspecialchars($a->course_code . ' — ' . $a->course_name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Capacity</label>
                                <input type="number" name="capacity" class="form-control" min="1" value="30" <?= $locked ? 'disabled' : '' ?>>
                            </div>
                            <button class="btn btn-primary w-100" <?= $locked ? 'disabled' : '' ?>><i class="fas fa-link me-1"></i>Attach to Intake</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Offerings + batches -->
        <div class="col-lg-8">
            <?php if (empty($offerings)): ?>
                <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
                    <i class="fas fa-layer-group fa-3x text-muted mb-3 d-block" style="opacity:.2"></i>
                    <h6 class="text-muted">No courses attached yet</h6>
                    <p class="text-muted small mb-0">Attach a course on the left, then create training batches for it.</p>
                </div></div>
            <?php else: ?>
                <?php foreach ($offerings as $o): ?>
                    <div class="card offering-card shadow-sm mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <strong><?= htmlspecialchars($o->course_name) ?></strong>
                                <span class="text-muted small">(<?= htmlspecialchars($o->course_code) ?>)</span>
                                <span class="text-muted small ms-2"><?= (int) $o->duration_value ?> <?= htmlspecialchars($o->duration_unit) ?>
                                    <?= $o->level_name ? '· ' . htmlspecialchars($o->level_name) : '' ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="slot-pill"><?= (int) $o->available_slots ?>/<?= (int) $o->capacity ?> slots</span>
                                <?php if (!$locked): ?>
                                    <button class="btn btn-sm btn-outline-primary add-batch-btn"
                                        data-ci="<?= (int) $o->id ?>" data-name="<?= htmlspecialchars($o->course_name) ?>"
                                        data-bs-toggle="modal" data-bs-target="#batchModal"><i class="fas fa-plus me-1"></i>Batch</button>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove this course (and its batches) from the intake?')">
                                    <input type="hidden" name="action" value="detach_course">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="course_intake_id" value="<?= (int) $o->id ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Remove course"><i class="fas fa-unlink"></i></button>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($o->batches)): ?>
                                <p class="text-muted small mb-0">No batches yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light"><tr>
                                            <th>Batch</th><th>Dates</th><th>Trainer</th><th>Location</th>
                                            <th class="text-center">Enrol/Cap</th><th>Status</th><th></th>
                                        </tr></thead>
                                        <tbody>
                                            <?php foreach ($o->batches as $b): ?>
                                                <tr class="batch-row">
                                                    <td><strong><?= htmlspecialchars($b->batch_name) ?></strong></td>
                                                    <td class="small">
                                                        <?= $b->start_date ? date('M j', strtotime($b->start_date)) : '—' ?>
                                                        <?= $b->end_date ? ' – ' . date('M j, Y', strtotime($b->end_date)) : '' ?>
                                                    </td>
                                                    <td class="small"><?= htmlspecialchars($b->trainer_name ?? '—') ?></td>
                                                    <td class="small"><?= htmlspecialchars($b->location ?? '—') ?></td>
                                                    <td class="text-center"><?= (int) $b->current_enrolment ?>/<?= (int) $b->capacity ?></td>
                                                    <td>
                                                        <form method="POST" class="m-0">
                                                            <input type="hidden" name="action" value="batch_status">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                            <input type="hidden" name="batch_id" value="<?= (int) $b->id ?>">
                                                            <select name="status" class="form-select form-select-sm" style="min-width:130px" onchange="this.form.submit()">
                                                                <?php foreach (['open', 'in_progress', 'completed', 'cancelled'] as $st): ?>
                                                                    <option value="<?= $st ?>" <?= $b->status === $st ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </form>
                                                    </td>
                                                    <td>
                                                        <form method="POST" class="m-0" onsubmit="return confirm('Delete this batch?')">
                                                            <input type="hidden" name="action" value="delete_batch">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                            <input type="hidden" name="batch_id" value="<?= (int) $b->id ?>">
                                                            <button class="btn btn-sm btn-outline-danger" title="Delete batch"><i class="fas fa-trash"></i></button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <!-- Applicants -->
                            <hr class="my-3">
                            <div class="small fw-semibold text-muted mb-2"><i class="fas fa-user-graduate me-1"></i>Applicants (<?= count($o->applicants) ?>)</div>
                            <?php if (empty($o->applicants)): ?>
                                <p class="text-muted small mb-0">No applications yet.</p>
                            <?php else: ?>
                                <?php $batchOpts = [];
                                foreach ($o->batches as $bb) { $batchOpts[(int) $bb->id] = $bb; } ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light"><tr>
                                            <th>Applicant</th><th>Applied</th><th>Payment</th><th>Status</th><th>Batch / Action</th>
                                        </tr></thead>
                                        <tbody>
                                            <?php foreach ($o->applicants as $ap): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars(trim(($ap->Fname ?? '') . ' ' . ($ap->Lname ?? '')) ?: $ap->student_id) ?></strong>
                                                        <div class="text-muted small"><?= htmlspecialchars($ap->student_id) ?><?= $ap->mobile ? ' · ' . htmlspecialchars($ap->mobile) : '' ?></div>
                                                    </td>
                                                    <td class="small"><?= $ap->applied_at ? date('M j, Y', strtotime($ap->applied_at)) : '—' ?></td>
                                                    <td class="small">
                                                        <?php if (($ap->pay_status ?? '') === 'completed'): ?>
                                                            <span class="badge bg-success-subtle text-success border">Paid</span>
                                                        <?php else: ?>
                                                            <span class="text-danger">Due <?= $ap->balance !== null ? number_format((float) $ap->balance, 2) : '' ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= itc_app_badge($ap->application_status) ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-1 flex-wrap">
                                                            <?php if ($ap->application_status === 'awaiting_payment' && !$locked): ?>
                                                                <form method="POST" class="m-0" onsubmit="return confirm('Confirm this fee has been paid?')">
                                                                    <input type="hidden" name="action" value="verify_payment">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                                    <input type="hidden" name="enrollment_id" value="<?= (int) $ap->id ?>">
                                                                    <button class="btn btn-sm btn-success" title="Verify payment & confirm"><i class="fas fa-check-circle me-1"></i>Verify</button>
                                                                </form>
                                                            <?php elseif ($ap->application_status === 'confirmed'): ?>
                                                                <?php if ($ap->training_batch_id && isset($batchOpts[(int) $ap->training_batch_id])): ?>
                                                                    <span class="badge bg-info-subtle text-info border"><i class="fas fa-users-rectangle me-1"></i><?= htmlspecialchars($batchOpts[(int) $ap->training_batch_id]->batch_name) ?></span>
                                                                <?php endif; ?>
                                                                <?php if (!empty($o->batches) && !$locked): ?>
                                                                    <form method="POST" class="m-0 d-flex gap-1">
                                                                        <input type="hidden" name="action" value="assign_batch">
                                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                                        <input type="hidden" name="enrollment_id" value="<?= (int) $ap->id ?>">
                                                                        <select name="training_batch_id" class="form-select form-select-sm" style="min-width:120px">
                                                                            <?php foreach ($o->batches as $bb): ?>
                                                                                <option value="<?= (int) $bb->id ?>" <?= (int) $ap->training_batch_id === (int) $bb->id ? 'selected' : '' ?>><?= htmlspecialchars($bb->batch_name) ?></option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                        <button class="btn btn-sm btn-outline-primary" title="Assign to batch"><i class="fas fa-arrow-right"></i></button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                            <?php if (!in_array($ap->application_status, ['cancelled', 'rejected'], true)): ?>
                                                                <form method="POST" class="m-0" onsubmit="return confirm('Release this application and free the slot?')">
                                                                    <input type="hidden" name="action" value="release_application">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                                    <input type="hidden" name="enrollment_id" value="<?= (int) $ap->id ?>">
                                                                    <button class="btn btn-sm btn-outline-danger" title="Release / cancel"><i class="fas fa-user-xmark"></i></button>
                                                                </form>
                                                            <?php endif; ?>
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
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ADD BATCH MODAL -->
<div class="modal fade itc-page" id="batchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header admin-modal"><h5 class="modal-title"><i class="fas fa-users-rectangle me-2"></i>New Batch — <span id="batchCourseName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <input type="hidden" name="action" value="add_batch">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="course_intake_id" id="batchCi">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Batch Name <span class="text-danger">*</span></label>
                        <input type="text" name="batch_name" class="form-control" required placeholder="e.g. June Batch 1">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control">
                            <div class="form-text">End date is derived from the course duration.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Capacity</label>
                            <input type="number" name="capacity" class="form-control" min="1" value="20">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Trainer</label>
                            <select name="trainer_id" class="form-select">
                                <option value="">— Unassigned —</option>
                                <?php foreach ($trainers as $t): ?>
                                    <option value="<?= htmlspecialchars($t->staff_id) ?>"><?= htmlspecialchars($t->name . ' (' . $t->staff_id . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-control" placeholder="e.g. Workshop B">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Create Batch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.add-batch-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('batchCi').value = this.dataset.ci;
            document.getElementById('batchCourseName').textContent = this.dataset.name;
        });
    });
    $(document).ready(function () {
        const banner = document.querySelector('.alert-success, .alert-danger, .alert-warning');
        if (banner) banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
</script>

<?php require_once "includes/footer.php"; ?>
