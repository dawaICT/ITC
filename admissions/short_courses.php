<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
require_once __DIR__ . '/includes/short_course_helpers.php';
require_once dirname(__DIR__) . '/includes/short_course_student.php';

if (!checkSessionTimeout(30) || !isAdminAuthenticated()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }
    setFlashMessage('error', 'Session expired or unauthorized access');
    header('Location: /wucportal/staff_login.php');
    exit;
}

$page_title = "Short Courses";
$staffId = $_SESSION['staff_id'] ?? $_SESSION['username'] ?? '';
$csrfToken = sc_ensure_csrf_token();

// ─── Handle form submissions BEFORE any output ───────────────────────────
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!sc_valid_csrf($_POST['csrf_token'] ?? null)) {
        $msg = 'Security token mismatch. Please refresh the page and try again.';
        $msgType = 'danger';
    } elseif ($_POST['action'] === 'add') {

        $code    = strtoupper(trim($_POST['course_code'] ?? ''));
        $name    = trim($_POST['course_name'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $durVal  = max(1, (int)($_POST['duration_value'] ?? 12));
        $durUnit = in_array($_POST['duration_unit'] ?? '', ['days', 'weeks', 'months']) ? $_POST['duration_unit'] : 'days';
        $fee     = max(0, (float)($_POST['fee'] ?? 0));
        $cap     = max(1, (int)($_POST['max_capacity'] ?? 30));
        $prereqs = trim($_POST['prerequisites'] ?? '');
        $mode    = in_array($_POST['delivery_mode'] ?? '', ['full-time', 'part-time', 'online', 'blended']) ? $_POST['delivery_mode'] : 'full-time';
        $status  = in_array($_POST['status'] ?? '', ['active', 'inactive', 'upcoming']) ? $_POST['status'] : 'active';
        $start   = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end     = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        // Auto-fill the end date from start + duration when not supplied.
        $end     = sc_derive_end_date($start, $durVal, $durUnit, $end);

        if ($code === '' || $name === '') {
            $msg = 'Course code and name are required.';
            $msgType = 'danger';
        } elseif (!sc_is_short_course_duration($durVal, $durUnit)) {
            $msg = 'Short courses cannot be longer than six months. Create this as a programme instead.';
            $msgType = 'danger';
        } else {
            $chk = $db->prepare("SELECT id FROM short_courses WHERE course_code = ?");
            $chk->bind_param("s", $code);
            $chk->execute();
            $courseExists = $chk->get_result()->num_rows > 0;
            $chk->close();

            if ($courseExists) {
                $msg = "Course code <strong>" . htmlspecialchars($code) . "</strong> already exists.";
                $msgType = 'danger';
            } else {
                $ins = $db->prepare("INSERT INTO short_courses
                    (course_code, course_name, description, duration_value, duration_unit, fee, max_capacity, prerequisites, delivery_mode, status, start_date, end_date, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $ins->bind_param("sssisdissssss", $code, $name, $desc, $durVal, $durUnit, $fee, $cap, $prereqs, $mode, $status, $start, $end, $staffId);
                if ($ins->execute()) {
                    $msg = "Short course <strong>" . htmlspecialchars($name) . "</strong> created successfully.";
                    $msgType = 'success';
                } else {
                    $msg = "Database error: " . htmlspecialchars($db->error);
                    $msgType = 'danger';
                }
            }
        }
    }

    if (sc_valid_csrf($_POST['csrf_token'] ?? null) && $_POST['action'] === 'edit' && !empty($_POST['id'])) {
        $id      = (int)$_POST['id'];
        $name    = trim($_POST['course_name'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $durVal  = max(1, (int)($_POST['duration_value'] ?? 12));
        $durUnit = in_array($_POST['duration_unit'] ?? '', ['days', 'weeks', 'months']) ? $_POST['duration_unit'] : 'days';
        $fee     = max(0, (float)($_POST['fee'] ?? 0));
        $cap     = max(1, (int)($_POST['max_capacity'] ?? 30));
        $prereqs = trim($_POST['prerequisites'] ?? '');
        $mode    = in_array($_POST['delivery_mode'] ?? '', ['full-time', 'part-time', 'online', 'blended']) ? $_POST['delivery_mode'] : 'full-time';
        $status  = in_array($_POST['status'] ?? '', ['active', 'inactive', 'upcoming']) ? $_POST['status'] : 'active';
        $start   = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end     = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        // Auto-fill the end date from start + duration when not supplied.
        $end     = sc_derive_end_date($start, $durVal, $durUnit, $end);

        if (!sc_is_short_course_duration($durVal, $durUnit)) {
            $msg = 'Short courses cannot be longer than six months. Create this as a programme instead.';
            $msgType = 'danger';
        } else {
            $upd = $db->prepare("UPDATE short_courses SET
                course_name=?, description=?, duration_value=?, duration_unit=?, fee=?, max_capacity=?, prerequisites=?, delivery_mode=?, status=?, start_date=?, end_date=?
                WHERE id=?");
            $upd->bind_param("ssisdisssssi", $name, $desc, $durVal, $durUnit, $fee, $cap, $prereqs, $mode, $status, $start, $end, $id);
            if ($upd->execute()) {
                $msg = "Course updated successfully.";
                $msgType = 'success';
            } else {
                $msg = "Update failed: " . htmlspecialchars($db->error);
                $msgType = 'danger';
            }
        }
    }

    if (sc_valid_csrf($_POST['csrf_token'] ?? null) && $_POST['action'] === 'delete' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        try {
            $db->begin_transaction();
            $delEnrollments = $db->prepare("DELETE FROM short_course_enrollments WHERE short_course_id = ?");
            $delEnrollments->bind_param("i", $id);
            $delEnrollments->execute();
            $delEnrollments->close();
            $del = $db->prepare("DELETE FROM short_courses WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            $db->commit();
            $msg = "Course deleted.";
            $msgType = 'warning';
        } catch (Throwable $e) {
            $db->rollback();
            $msg = "Delete failed: " . htmlspecialchars($e->getMessage());
            $msgType = 'danger';
        }
    }
}

// ─── Fetch all short courses with enrollment counts ──────────────────────
$courses = [];
$res = $db->query("
    SELECT sc.*,
           (SELECT COUNT(*) FROM short_course_enrollments e WHERE e.short_course_id = sc.id AND e.status IN ('enrolled','active')) AS enrolled_count,
           (SELECT COUNT(*) FROM short_course_enrollments e WHERE e.short_course_id = sc.id) AS total_enrollments
    FROM short_courses sc
    ORDER BY sc.status ASC, sc.course_code ASC
");
if ($res) {
    while ($r = $res->fetch_object()) {
        $courses[] = $r;
    }
    $res->free();
}

$totalActive   = count(array_filter($courses, fn($c) => $c->status === 'active'));
$totalUpcoming = count(array_filter($courses, fn($c) => $c->status === 'upcoming'));
$totalInactive = count(array_filter($courses, fn($c) => $c->status === 'inactive'));
$totalEnrolled = array_sum(array_map(fn($c) => (int)$c->enrolled_count, $courses));

require_once "includes/nav.php";
?>

<style>
    /* Short-course enrolment — visual unification with regNewStud.php aesthetic. Scoped sc-form-* */
    .sc-form-card { border: 1px solid #e9ecef; border-radius: .75rem; overflow: hidden; }
    .sc-form-card .sc-form-head {
        display: flex; align-items: center; gap: .65rem;
        padding: .75rem 1rem; background: #f8f9fa;
        border-bottom: 1px solid #e9ecef; font-weight: 600;
    }
    .sc-form-card .sc-form-icon {
        width: 2.4rem; height: 2.4rem; border-radius: .6rem; flex: 0 0 auto;
        display: inline-flex; align-items: center; justify-content: center;
        background: var(--brand-primary, #2E3190); color: #fff; font-size: 1.05rem;
    }
    .sc-form-card .sc-form-body { padding: 1.1rem; }
    .sc-form-card .sc-form-foot {
        display: flex; justify-content: flex-end; gap: .5rem;
        padding: .85rem 1rem; border-top: 1px solid #e9ecef; background: #fcfcfd;
    }
    .credential-card {
        border: 1px solid #cfe9d9; background: #e7f5ec;
        border-radius: .6rem; padding: .85rem 1rem; color: #14693a;
    }
    .credential-card code { background: rgba(0,0,0,.06); padding: .05rem .4rem; border-radius: .3rem; }
</style>

<div class="container-fluid px-4 py-4 portal-dashboard">
    <!-- Page Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1 class="h3 mb-1 text-gray-800"><i class="fas fa-certificate me-2 text-primary"></i>Short Courses</h1>
                <p class="page-subtitle mb-0">Create and manage short courses · Enroll students with flexible durations</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="fas fa-plus me-1"></i>New Short Course
            </button>
        </div>
    </div>

    <!-- Alert -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?= $msgType === 'success' ? 'check-circle' : ($msgType === 'danger' ? 'exclamation-triangle' : 'info-circle') ?> me-2"></i>
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3"><i class="fas fa-certificate text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= count($courses) ?></h3>
                        <p class="text-muted mb-0">Total Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3"><i class="fas fa-check-circle text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= $totalActive ?></h3>
                        <p class="text-muted mb-0">Active</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon me-3" style="background:linear-gradient(135deg,#6f42c1,#9333ea);"><i class="fas fa-user-graduate text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= $totalEnrolled ?></h3>
                        <p class="text-muted mb-0">Enrolled Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3"><i class="fas fa-clock text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= $totalUpcoming ?></h3>
                        <p class="text-muted mb-0">Upcoming</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Courses Table -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Short Courses</h5>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($courses)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-certificate fa-3x text-muted mb-3 d-block" style="opacity:0.2;"></i>
                    <h5 class="text-muted">No short courses yet</h5>
                    <p class="text-muted">Click "New Short Course" to create your first one.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="shortCoursesTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Code</th>
                                <th>Course Name</th>
                                <th class="text-center">Duration</th>
                                <th>Mode</th>
                                <th class="text-end">Fee (ZMW)</th>
                                <th class="text-center">Enrolled / Cap</th>
                                <th>Status</th>
                                <th>Dates</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $n = 1;
                            foreach ($courses as $c): ?>
                                <tr>
                                    <td class="text-muted"><?= $n++ ?></td>
                                    <td><span class="badge bg-light text-dark border fw-bold"><?= htmlspecialchars($c->course_code) ?></span></td>
                                    <td>
                                        <strong><?= htmlspecialchars($c->course_name) ?></strong>
                                        <?php if ($c->description): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars(sc_trim_width($c->description, 60)) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="dur-badge"><?= (int)$c->duration_value ?></span>
                                        <span class="duration-unit"><?= $c->duration_unit ?></span>
                                    </td>
                                    <td><span class="mode-tag"><?= $c->delivery_mode ?></span></td>
                                    <td class="text-end fee-amount"><?= number_format((float)$c->fee, 2) ?></td>
                                    <td class="text-center">
                                        <span class="enroll-badge"><?= (int)$c->enrolled_count ?> / <?= (int)$c->max_capacity ?></span>
                                    </td>
                                    <td>
                                        <span class="status-dot <?= $c->status ?>"></span>
                                        <?= ucfirst($c->status) ?>
                                    </td>
                                    <td>
                                        <?php if ($c->start_date): ?>
                                            <small><?= date('M j, Y', strtotime($c->start_date)) ?></small>
                                            <?php if ($c->end_date): ?>
                                                <br><small class="text-muted">→ <?= date('M j, Y', strtotime($c->end_date)) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <small class="text-muted">—</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">
                                            <button class="btn btn-sm btn-primary manage-btn" title="Manage Students"
                                                data-course-id="<?= $c->id ?>"
                                                data-course-name="<?= htmlspecialchars($c->course_name) ?>"
                                                data-course-code="<?= htmlspecialchars($c->course_code) ?>"
                                                data-bs-toggle="modal" data-bs-target="#manageModal">
                                                <i class="fas fa-users"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary edit-btn"
                                                data-course='<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>'
                                                data-bs-toggle="modal" data-bs-target="#editModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="d-inline"
                                                onsubmit="return confirm('Delete this short course and all enrollments?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="id" value="<?= $c->id ?>">
                                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
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

<!-- ═══ ADD MODAL ═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Create Short Course</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Course Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="course_code" required
                                placeholder="e.g. SC-WLD01" maxlength="30" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Course Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="course_name" required
                                placeholder="e.g. Basic Welding Techniques">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"
                                placeholder="Brief course overview..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold"><i class="fas fa-clock me-1 text-primary"></i>Duration</label>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <div class="quick-preset border rounded px-3 py-2 text-center" onclick="setDuration(5,'days',this)">
                                    <div class="duration-display">5</div><div class="duration-unit">Days</div>
                                </div>
                                <div class="quick-preset border rounded px-3 py-2 text-center" onclick="setDuration(14,'days',this)">
                                    <div class="duration-display">14</div><div class="duration-unit">Days</div>
                                </div>
                                <div class="quick-preset border rounded px-3 py-2 text-center" onclick="setDuration(21,'days',this)">
                                    <div class="duration-display">21</div><div class="duration-unit">Days</div>
                                </div>
                                <div class="quick-preset border rounded px-3 py-2 text-center" onclick="setDuration(3,'months',this)">
                                    <div class="duration-display">3</div><div class="duration-unit">Months</div>
                                </div>
                                <div class="quick-preset border rounded-pill px-3 py-2 text-center bg-light" onclick="focusCustom(this)">
                                    <div style="font-size:1.2rem;font-weight:700;color:#6f42c1;">✎</div>
                                    <div class="duration-unit">Custom</div>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="number" class="form-control" name="duration_value" id="addDurationVal" min="1" value="5" required>
                                </div>
                                <div class="col-6">
                                    <select class="form-select" name="duration_unit" id="addDurationUnit">
                                        <option value="days" selected>Days</option>
                                        <option value="weeks">Weeks</option>
                                        <option value="months">Months</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Fee (ZMW)</label>
                            <input type="number" class="form-control" name="fee" step="0.01" min="0" value="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Max Capacity</label>
                            <input type="number" class="form-control" name="max_capacity" min="1" value="30">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Delivery Mode</label>
                            <select class="form-select" name="delivery_mode">
                                <option value="full-time">Full-time</option>
                                <option value="part-time">Part-time</option>
                                <option value="online">Online</option>
                                <option value="blended">Blended</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="active">Active</option>
                                <option value="upcoming">Upcoming</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Entry Requirements</label>
                            <textarea class="form-control" name="prerequisites" rows="2"
                                placeholder="e.g. Grade 9 certificate, Minimum age 16"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Create Course</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ EDIT MODAL ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Short Course</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="id" id="editId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Course Code</label>
                            <input type="text" class="form-control" id="editCode" disabled>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Course Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="course_name" id="editName" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="editDesc" rows="2"></textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Duration</label>
                            <input type="number" class="form-control" name="duration_value" id="editDurVal" min="1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit</label>
                            <select class="form-select" name="duration_unit" id="editDurUnit">
                                <option value="days">Days</option>
                                <option value="weeks">Weeks</option>
                                <option value="months">Months</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fee (ZMW)</label>
                            <input type="number" class="form-control" name="fee" id="editFee" step="0.01" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Max Capacity</label>
                            <input type="number" class="form-control" name="max_capacity" id="editCap" min="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Delivery Mode</label>
                            <select class="form-select" name="delivery_mode" id="editMode">
                                <option value="full-time">Full-time</option>
                                <option value="part-time">Part-time</option>
                                <option value="online">Online</option>
                                <option value="blended">Blended</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" id="editStart">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" id="editEnd">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="editStatus">
                                <option value="active">Active</option>
                                <option value="upcoming">Upcoming</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Entry Requirements</label>
                            <textarea class="form-control" name="prerequisites" id="editPrereqs" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ MANAGE STUDENTS MODAL ═══════════════════════════════════════════ -->
<div class="modal fade" id="manageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i>Manage Students — <span id="manageCourseName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <ul class="nav nav-tabs px-3 pt-3" id="manageTabs">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#tabEnrolled"><i class="fas fa-list me-1"></i>Enrolled Students</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tabEnrollExisting"><i class="fas fa-user-plus me-1"></i>Enroll Existing</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tabCreateNew"><i class="fas fa-user-edit me-1"></i>Create &amp; Enroll New</a>
                    </li>
                </ul>
                <div class="tab-content p-4">
                    <div class="tab-pane fade show active" id="tabEnrolled">
                        <div id="enrolledLoading" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i></div>
                        <div id="enrolledContent" style="display:none;">
                            <div id="enrolledEmpty" class="text-center py-4" style="display:none;">
                                <i class="fas fa-inbox fa-3x text-muted d-block mb-2" style="opacity:0.15;"></i>
                                <p class="text-muted">No students enrolled yet. Use the tabs above to add students.</p>
                            </div>
                            <div class="table-responsive" id="enrolledTableWrap" style="display:none;">
                                <table class="table table-hover align-middle" id="enrolledTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th><th>Student ID</th><th>Name</th><th>Contact</th>
                                            <th>Enrolled</th><th>Status</th><th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="enrolledBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tabEnrollExisting">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Search Student</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="searchStudentInput"
                                    placeholder="Type student ID, name, or NRC..." autocomplete="off">
                                <div id="searchResults" class="search-dropdown" style="display:none;"></div>
                            </div>
                        </div>
                        <div id="selectedStudentCard" class="alert alert-info d-flex align-items-center" style="display:none !important;">
                            <div class="flex-grow-1">
                                <strong id="selectedStudentName"></strong><br>
                                <small>ID: <code id="selectedStudentId"></code></small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearSelectedStudent()"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes (optional)</label>
                            <input type="text" class="form-control" id="enrollNotes" placeholder="e.g. Sponsored by NAPSA">
                        </div>
                        <button type="button" class="btn btn-primary" id="enrollExistingBtn" disabled onclick="enrollExistingStudent()">
                            <i class="fas fa-user-check me-1"></i>Enroll Student
                        </button>
                        <div id="enrollExistingAlert" class="mt-3" style="display:none;"></div>
                    </div>
                    <div class="tab-pane fade" id="tabCreateNew">
                        <div class="sc-form-card">
                            <div class="sc-form-head">
                                <span class="sc-form-icon"><i class="fas fa-user-edit"></i></span>
                                <div>
                                    <div>New Student Details</div>
                                    <div class="small text-muted fw-normal">Creates a student record and login account, then enrols them in this course.</div>
                                </div>
                            </div>
                            <div class="sc-form-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="newFname" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="newLname" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Gender</label>
                                        <select class="form-select" id="newSex">
                                            <option value="M">Male</option>
                                            <option value="F">Female</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">NRC / Passport</label>
                                        <input type="text" class="form-control" id="newNrc" placeholder="e.g. 123456/78/1">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Date of Birth</label>
                                        <input type="date" class="form-control" id="newDob">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Mobile</label>
                                        <input type="text" class="form-control" id="newMobile" placeholder="e.g. 0971234567">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" id="newEmail" placeholder="Optional">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Notes (optional)</label>
                                        <input type="text" class="form-control" id="newNotes" placeholder="e.g. Walk-in registration">
                                    </div>
                                </div>
                                <div class="alert alert-info small d-flex align-items-center mt-3 mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    A default password (the student's NRC/Passport number) is generated automatically. The student must change it on first login.
                                </div>
                            </div>
                            <div class="sc-form-foot">
                                <button type="button" class="btn btn-success" onclick="createAndEnroll(this)">
                                    <i class="fas fa-user-plus me-1"></i>Create Student &amp; Enroll
                                </button>
                            </div>
                        </div>
                        <div id="createAlert" class="mt-3" style="display:none;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const shortCourseCsrf = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';

    function setDuration(val, unit, el) {
        document.getElementById('addDurationVal').value = val;
        document.getElementById('addDurationUnit').value = unit;
        document.querySelectorAll('#addModal .quick-preset').forEach(p => p.classList.remove('selected'));
        if (el) el.classList.add('selected');
    }
    function focusCustom(el) {
        document.querySelectorAll('#addModal .quick-preset').forEach(p => p.classList.remove('selected'));
        if (el) el.classList.add('selected');
        document.getElementById('addDurationVal').focus();
        document.getElementById('addDurationVal').select();
    }

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const c = JSON.parse(this.dataset.course);
            document.getElementById('editId').value        = c.id;
            document.getElementById('editCode').value      = c.course_code;
            document.getElementById('editName').value      = c.course_name;
            document.getElementById('editDesc').value      = c.description || '';
            document.getElementById('editDurVal').value    = c.duration_value;
            document.getElementById('editDurUnit').value   = c.duration_unit;
            document.getElementById('editFee').value       = c.fee;
            document.getElementById('editCap').value       = c.max_capacity;
            document.getElementById('editMode').value      = c.delivery_mode;
            document.getElementById('editStart').value     = c.start_date || '';
            document.getElementById('editEnd').value       = c.end_date || '';
            document.getElementById('editStatus').value    = c.status;
            document.getElementById('editPrereqs').value   = c.prerequisites || '';
        });
    });

    let currentCourseId   = null;
    let selectedStudentSID = null;
    let searchTimeout      = null;

    document.querySelectorAll('.manage-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            currentCourseId = this.dataset.courseId;
            document.getElementById('manageCourseName').textContent =
                this.dataset.courseCode + ' — ' + this.dataset.courseName;
            loadEnrollments();
            clearSelectedStudent();
            document.getElementById('searchStudentInput').value = '';
            document.getElementById('searchResults').style.display = 'none';
            document.getElementById('enrollExistingAlert').style.display = 'none';
            document.getElementById('createAlert').style.display = 'none';
            ['newFname','newLname','newNrc','newMobile','newEmail','newDob','newNotes'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
        });
    });

    function loadEnrollments() {
        document.getElementById('enrolledLoading').style.display = '';
        document.getElementById('enrolledContent').style.display = 'none';

        fetch('ajax/short_course_ajax.php?action=get_enrollments&course_id=' + currentCourseId)
            .then(r => r.json())
            .then(data => {
                document.getElementById('enrolledLoading').style.display = 'none';
                document.getElementById('enrolledContent').style.display = '';
                const list = data.enrollments || [];
                if (list.length === 0) {
                    document.getElementById('enrolledEmpty').style.display = '';
                    document.getElementById('enrolledTableWrap').style.display = 'none';
                } else {
                    document.getElementById('enrolledEmpty').style.display = 'none';
                    document.getElementById('enrolledTableWrap').style.display = '';
                    const body = document.getElementById('enrolledBody');
                    body.innerHTML = '';
                    list.forEach((e, i) => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td class="text-muted">${i + 1}</td>
                            <td><code>${e.student_id}</code></td>
                            <td><strong>${esc(e.Fname)} ${esc(e.Lname)}</strong></td>
                            <td><small>${esc(e.mobile || '')}${e.email ? '<br>' + esc(e.email) : ''}</small></td>
                            <td><small>${new Date(e.enrollment_date).toLocaleDateString()}</small></td>
                            <td>
                                <select class="form-select form-select-sm" style="width:120px;"
                                        onchange="updateEnrollmentStatus(${e.id}, this.value)">
                                    <option value="enrolled"  ${e.status==='enrolled'  ? 'selected':''}>Enrolled</option>
                                    <option value="active"    ${e.status==='active'    ? 'selected':''}>Active</option>
                                    <option value="completed" ${e.status==='completed' ? 'selected':''}>Completed</option>
                                    <option value="withdrawn" ${e.status==='withdrawn' ? 'selected':''}>Withdrawn</option>
                                    <option value="expired"   ${e.status==='expired'   ? 'selected':''}>Expired</option>
                                </select>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-danger" onclick="removeEnrollment(${e.id})">
                                    <i class="fas fa-user-minus"></i>
                                </button>
                            </td>`;
                        body.appendChild(row);
                    });
                }
            })
            .catch(() => {
                document.getElementById('enrolledLoading').style.display = 'none';
                document.getElementById('enrolledContent').style.display = '';
                document.getElementById('enrolledEmpty').style.display = '';
                document.getElementById('enrolledEmpty').innerHTML = '<p class="text-danger">Error loading enrollments.</p>';
            });
    }

    document.getElementById('searchStudentInput').addEventListener('input', function () {
        clearTimeout(searchTimeout);
        const q = this.value.trim();
        if (q.length < 2) { document.getElementById('searchResults').style.display = 'none'; return; }
        searchTimeout = setTimeout(() => {
            fetch('ajax/short_course_ajax.php?action=search_students&q=' + encodeURIComponent(q) + '&course_id=' + currentCourseId)
                .then(r => r.json())
                .then(data => {
                    const box = document.getElementById('searchResults');
                    box.innerHTML = (!data.results || data.results.length === 0)
                        ? '<div class="item text-muted"><i class="fas fa-search me-2"></i>No students found</div>'
                        : data.results.map(s =>
                            `<div class="item search-result-item" data-sid="${esc(s.SID)}" data-name="${esc(s.Fname)} ${esc(s.Lname)}">
                                <strong>${esc(s.Fname)} ${esc(s.Lname)}</strong>
                                <span class="badge bg-light text-dark ms-2">${s.SID}</span>
                                ${s.mobile ? '<br><small class="text-muted">' + esc(s.mobile) + '</small>' : ''}
                             </div>`).join('');
                    box.querySelectorAll('.search-result-item').forEach(item => {
                        item.addEventListener('click', () => selectStudent(item.dataset.sid, item.dataset.name));
                    });
                    box.style.display = '';
                })
                .catch(() => {
                    const box = document.getElementById('searchResults');
                    box.innerHTML = '<div class="item text-danger"><i class="fas fa-exclamation-circle me-2"></i>Search failed</div>';
                    box.style.display = '';
                });
        }, 300);
    });

    function selectStudent(sid, name) {
        selectedStudentSID = sid;
        document.getElementById('selectedStudentName').textContent = name;
        document.getElementById('selectedStudentId').textContent = sid;
        document.getElementById('selectedStudentCard').style.cssText = 'display:flex !important';
        document.getElementById('searchResults').style.display = 'none';
        document.getElementById('searchStudentInput').value = '';
        document.getElementById('enrollExistingBtn').disabled = false;
    }
    function clearSelectedStudent() {
        selectedStudentSID = null;
        document.getElementById('selectedStudentCard').style.cssText = 'display:none !important';
        document.getElementById('enrollExistingBtn').disabled = true;
    }

    function enrollExistingStudent() {
        if (!selectedStudentSID || !currentCourseId) return;
        const btn = document.getElementById('enrollExistingBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Enrolling...';
        const fd = new FormData();
        fd.append('action', 'enroll_student');
        fd.append('csrf_token', shortCourseCsrf);
        fd.append('course_id', currentCourseId);
        fd.append('student_id', selectedStudentSID);
        fd.append('notes', document.getElementById('enrollNotes').value);
        fetch('ajax/short_course_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                showAlert('enrollExistingAlert', data.message, data.success ? 'success' : 'danger');
                btn.innerHTML = '<i class="fas fa-user-check me-1"></i>Enroll Student';
                if (data.success) { clearSelectedStudent(); document.getElementById('enrollNotes').value = ''; loadEnrollments(); }
                else btn.disabled = false;
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-check me-1"></i>Enroll Student';
                showAlert('enrollExistingAlert', 'Network error. Please try again.', 'danger');
            });
    }

    function createAndEnroll(btn) {
        const fname = document.getElementById('newFname').value.trim();
        const lname = document.getElementById('newLname').value.trim();
        if (!fname || !lname) { showAlert('createAlert', 'First name and last name are required.', 'danger'); return; }
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating...';
        const fd = new FormData();
        fd.append('action',   'create_enroll');
        fd.append('csrf_token', shortCourseCsrf);
        fd.append('course_id', currentCourseId);
        fd.append('fname',    fname);
        fd.append('lname',    lname);
        fd.append('sex',      document.getElementById('newSex').value);
        fd.append('nrc_pass', document.getElementById('newNrc').value);
        fd.append('mobile',   document.getElementById('newMobile').value);
        fd.append('email',    document.getElementById('newEmail').value);
        fd.append('dob',      document.getElementById('newDob').value);
        fd.append('notes',    document.getElementById('newNotes').value);
        fetch('ajax/short_course_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-plus me-1"></i>Create Student & Enroll';
                if (data.success) {
                    document.getElementById('createAlert').innerHTML =
                        `<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>${esc(data.message)}</div>
                         <div class="credential-card mb-2">
                             <p class="mb-1 fw-bold"><i class="fas fa-id-badge me-1"></i>Student Credentials</p>
                             <p class="mb-1">Student ID: <code>${data.student_id}</code></p>
                             <p class="mb-0">Default Password: <code>${data.default_password}</code></p>
                         </div>`;
                    document.getElementById('createAlert').style.display = '';
                    ['newFname','newLname','newNrc','newMobile','newEmail','newDob','newNotes'].forEach(id => {
                        const el = document.getElementById(id); if (el) el.value = '';
                    });
                    loadEnrollments();
                } else {
                    showAlert('createAlert', data.message, 'danger');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-plus me-1"></i>Create Student & Enroll';
                showAlert('createAlert', 'Network error. Please try again.', 'danger');
            });
    }

    function updateEnrollmentStatus(enrollId, status) {
        const fd = new FormData();
        fd.append('action', 'update_enrollment');
        fd.append('csrf_token', shortCourseCsrf);
        fd.append('enrollment_id', enrollId);
        fd.append('status', status);
        fetch('ajax/short_course_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => { if (!data.success) alert('Error: ' + data.message); })
            .catch(() => alert('Network error while updating enrollment.'));
    }

    function removeEnrollment(enrollId) {
        if (!confirm('Remove this student from the course?')) return;
        const fd = new FormData();
        fd.append('action', 'remove_enrollment');
        fd.append('csrf_token', shortCourseCsrf);
        fd.append('enrollment_id', enrollId);
        fetch('ajax/short_course_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => { if (data.success) loadEnrollments(); else alert('Error: ' + data.message); })
            .catch(() => alert('Network error while removing enrollment.'));
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function showAlert(id, msg, type) {
        const el = document.getElementById(id);
        el.innerHTML = `<div class="alert alert-${type}">${esc(msg)}</div>`;
        el.style.display = '';
        setTimeout(() => { el.style.display = 'none'; }, 6000);
    }

    $(document).ready(function () {
        if ($('#shortCoursesTable').length) {
            $('#shortCoursesTable').DataTable({
                pageLength: 15,
                responsive: true,
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
                language: { search: '', searchPlaceholder: 'Search short courses...' }
            });
        }
    });
</script>

<?php require_once "includes/footer.php"; ?>
