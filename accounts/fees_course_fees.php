<?php
$page_title = 'Manage Base Course Fees';
require "includes/nav.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrfToken) {
        $error = 'CSRF validation failed.';
    } else {
        $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $training_mode_id = (int)($_POST['training_mode_id'] ?? 0);
        $academic_year = trim($_POST['academic_year'] ?? '');
        $base_fee = isset($_POST['base_fee']) && is_numeric($_POST['base_fee']) ? (float)$_POST['base_fee'] : 0.00;
        $currency = trim($_POST['currency'] ?? 'ZMW');
        $effective_start_date = trim($_POST['effective_start_date'] ?? date('Y-m-d'));
        $status = $_POST['status'] ?? 'active';

        if ($course_id <= 0 || $training_mode_id <= 0 || $academic_year === '') {
            $error = 'Course, training mode, and academic year are required.';
        } elseif ($base_fee < 0) {
            $error = 'Base fee cannot be negative.';
        } else {
            $db->begin_transaction();
            try {
                // If setting this one to active, mark all previous active ones as expired/inactive
                if ($status === 'active') {
                    $expire = $db->prepare("UPDATE course_fees 
                                            SET status = 'expired', effective_end_date = CURRENT_DATE() 
                                            WHERE course_id = ? AND training_mode_id = ? AND academic_year = ? AND status = 'active'");
                    $expire->bind_param('iis', $course_id, $training_mode_id, $academic_year);
                    $expire->execute();
                    $expire->close();
                }

                // Insert the new fee record (do not overwrite)
                $stmt = $db->prepare("INSERT INTO course_fees 
                    (course_id, training_mode_id, academic_year, currency, base_fee, effective_start_date, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('iissdss', $course_id, $training_mode_id, $academic_year, $currency, $base_fee, $effective_start_date, $status);
                
                if ($stmt->execute()) {
                    $db->commit();
                    $message = 'Base course fee recorded successfully.';
                } else {
                    $db->rollback();
                    $error = 'A database error occurred. Please try again.';
                    error_log("Database error in accounts/fees_course_fees.php: " . $db->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $db->rollback();
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'expire') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE course_fees SET status = 'expired', effective_end_date = CURRENT_DATE() WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $message = 'Course fee marked as expired.';
            } else {
                $error = 'A database error occurred. Please try again.';
                error_log("Database error in accounts/fees_course_fees.php: " . $db->error);
            }
            $stmt->close();
        }
    }
    }
}

// Fetch active courses
$activeCourses = [];
$res = $db->query("SELECT id, course_name, course_code FROM courses WHERE status = 'active' ORDER BY course_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $activeCourses[] = $row;
    }
    $res->free();
}

// Fetch active training modes
$activeModes = [];
$res = $db->query("SELECT id, mode_name FROM training_modes WHERE status = 'active' ORDER BY mode_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $activeModes[] = $row;
    }
    $res->free();
}

// Fetch all course fees with course and mode names
$courseFees = [];
$query = "SELECT cf.*, c.course_name, c.course_code, tm.mode_name 
          FROM course_fees cf
          INNER JOIN courses c ON cf.course_id = c.id
          INNER JOIN training_modes tm ON cf.training_mode_id = tm.id
          ORDER BY c.course_name ASC, cf.academic_year DESC, cf.status ASC";
$res = $db->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $courseFees[] = $row;
    }
    $res->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header finance-section mb-4 mt-2">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-money-check-alt me-2 text-primary"></i>Base Course Fees</h1>
                <p class="text-muted mb-0">Record and review base tuition rates by course, training mode, and academic year. Overwriting is prevented; historical rates remain archived.</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus me-2"></i>Add Course Fee
                </button>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Course</th>
                            <th>Training Mode</th>
                            <th>Academic Year</th>
                            <th>Base Fee</th>
                            <th>Effective Dates</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($courseFees)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fas fa-money-check-alt fa-2x mb-2 d-block"></i> No base fees configured yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($courseFees as $cf): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($cf['course_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($cf['course_code']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($cf['mode_name']) ?></td>
                                    <td><span class="badge bg-light text-dark font-monospace fs-6 px-3 py-1"><?= htmlspecialchars($cf['academic_year']) ?></span></td>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($cf['currency']) ?> <?= number_format($cf['base_fee'], 2) ?></td>
                                    <td>
                                        <div class="small">Start: <?= htmlspecialchars($cf['effective_start_date']) ?></div>
                                        <?php if ($cf['effective_end_date']): ?>
                                            <div class="small text-muted">End: <?= htmlspecialchars($cf['effective_end_date']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($cf['status'] === 'active'): ?>
                                            <span class="badge bg-success rounded-pill px-3 py-1">Active</span>
                                        <?php elseif ($cf['status'] === 'expired'): ?>
                                            <span class="badge bg-warning text-dark rounded-pill px-3 py-1">Expired</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill px-3 py-1">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($cf['status'] === 'active'): ?>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="action" value="expire">
                                                <input type="hidden" name="id" value="<?= htmlspecialchars($cf['id']) ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="return confirm('Are you sure you want to expire this fee?');">
                                                    <i class="fas fa-calendar-times me-1"></i> Expire
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small">Archived</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="feeModal" tabindex="-1" aria-labelledby="feeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary" id="feeModalLabel">Add Course Fee</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="f_course" class="form-label fw-semibold">Course</label>
                        <select class="form-select rounded-3" name="course_id" id="f_course" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach ($activeCourses as $c): ?>
                                <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['course_name']) ?> (<?= htmlspecialchars($c['course_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="f_mode" class="form-label fw-semibold">Training Mode</label>
                        <select class="form-select rounded-3" name="training_mode_id" id="f_mode" required>
                            <option value="">-- Select Mode --</option>
                            <?php foreach ($activeModes as $m): ?>
                                <option value="<?= htmlspecialchars($m['id']) ?>"><?= htmlspecialchars($m['mode_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="f_year" class="form-label fw-semibold">Academic Year</label>
                        <input type="text" class="form-control rounded-3" name="academic_year" id="f_year" required placeholder="e.g., 2026" value="2026">
                    </div>
                    <div class="row mb-3">
                        <div class="col-8">
                            <label for="f_fee" class="form-label fw-semibold">Base Course Fee</label>
                            <input type="number" step="0.01" class="form-control rounded-3" name="base_fee" id="f_fee" required placeholder="0.00">
                        </div>
                        <div class="col-4">
                            <label for="f_curr" class="form-label fw-semibold">Currency</label>
                            <input type="text" class="form-control rounded-3" name="currency" id="f_curr" required value="ZMW">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="f_start" class="form-label fw-semibold">Effective Start Date</label>
                        <input type="date" class="form-control rounded-3" name="effective_start_date" id="f_start" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="f_status" class="form-label fw-semibold">Status</label>
                        <select class="form-select rounded-3" name="status" id="f_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Save Fee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('f_course').value = '';
    document.getElementById('f_mode').value = '';
    document.getElementById('f_fee').value = '';
    document.getElementById('f_status').value = 'active';
    var myModal = new bootstrap.Modal(document.getElementById('feeModal'));
    myModal.show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
