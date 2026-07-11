<?php
$page_title = 'Student Fee Accounts';
require "includes/nav.php";
require_once __DIR__ . '/../includes/fees_helpers.php';

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
    
    if ($action === 'create') {
        $student_id = trim($_POST['student_id'] ?? '');
        $course_id = (int)($_POST['course_id'] ?? 0);
        $training_mode_id = (int)($_POST['training_mode_id'] ?? 0);
        $academic_year = trim($_POST['academic_year'] ?? '2026');
        $intake = trim($_POST['intake'] ?? 'January');

        // Check if student exists in students table
        $checkStu = $db->prepare("SELECT Fname, Lname FROM students WHERE SID = ? LIMIT 1");
        $checkStu->bind_param('s', $student_id);
        $checkStu->execute();
        $studentExists = $checkStu->get_result()->fetch_assoc();
        $checkStu->close();

        if (!$studentExists) {
            $error = "Student ID '$student_id' does not exist in the students database.";
        } elseif ($course_id <= 0 || $training_mode_id <= 0 || $academic_year === '') {
            $error = 'All fields are required to create a fee account.';
        } else {
            $accountId = fees_generate_student_account($db, $student_id, $course_id, $training_mode_id, $academic_year, $intake);
            if ($accountId) {
                $message = "Fee account generated successfully for student '$student_id'.";
            } else {
                $error = 'Failed to create fee account. It may already exist.';
            }
        }
    } elseif ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE student_fee_accounts SET status = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param('si', $status, $id);
            if ($stmt->execute()) {
                $message = "Student fee account status updated to '$status'.";
            } else {
                $error = 'A database error occurred. Please try again.';
                error_log("Database error in accounts/fees_student_accounts.php: " . $db->error);
            }
            $stmt->close();
        }
    }
    }
}

// Set up filters
$filter_sid = trim($_GET['student_id'] ?? '');
$filter_course = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int)$_GET['course_id'] : null;
$filter_status = $_GET['status'] ?? '';
$filter_payment = $_GET['payment_status'] ?? '';

// Build Query
$where = ["sfa.status != 'deleted'"];
$params = [];
$types = '';

if ($filter_sid !== '') {
    $where[] = "sfa.student_id LIKE ?";
    $params[] = "%" . $filter_sid . "%";
    $types .= 's';
}
if ($filter_course !== null) {
    $where[] = "sfa.course_id = ?";
    $params[] = $filter_course;
    $types .= 'i';
}
if ($filter_status !== '') {
    $where[] = "sfa.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}
if ($filter_payment !== '') {
    $where[] = "sfa.payment_status = ?";
    $params[] = $filter_payment;
    $types .= 's';
}

$whereClause = implode(" AND ", $where);
$query = "SELECT sfa.*, c.course_name, c.course_code, tm.mode_name, s.Fname, s.Lname 
          FROM student_fee_accounts sfa
          INNER JOIN courses c ON sfa.course_id = c.id
          INNER JOIN training_modes tm ON sfa.training_mode_id = tm.id
          INNER JOIN students s ON sfa.student_id = s.SID
          WHERE $whereClause
          ORDER BY sfa.id DESC";

$stmt = $db->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $studentAccounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $studentAccounts = [];
}

// Fetch lists for select filters
$courses = $db->query("SELECT id, course_name FROM courses ORDER BY course_name ASC")->fetch_all(MYSQLI_ASSOC);
$trainingModes = $db->query("SELECT id, mode_name FROM training_modes WHERE status='active'")->fetch_all(MYSQLI_ASSOC);
// Students for the "New Student Account" picker (replaces free-text Student ID entry).
// We also pull the student's own enrolment details so selecting a student can
// auto-fill the rest of the form.
$studentsList = $db->query("SELECT SID, Fname, Lname, program, mode, academic_year, intake FROM students ORDER BY Fname ASC, Lname ASC")->fetch_all(MYSQLI_ASSOC);

// Build normalized lookups so a student's stored course/mode (which may differ in
// case, spacing or hyphenation, e.g. "Full-Time" vs "Full Time") can be matched to
// the canonical course_id / training_mode_id used by the form selects.
$normalize = static function (?string $v): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$v));
};
$courseLookup = [];
foreach ($courses as $c) {
    // Match a student's program string against either the course code or name.
    $courseLookup[$normalize($c['course_name'] ?? '')] = (int)$c['id'];
}
// course code lookup needs the code column, fetched separately to keep $courses light
$courseCodeRows = $db->query("SELECT id, course_code, course_name FROM courses")->fetch_all(MYSQLI_ASSOC);
foreach ($courseCodeRows as $c) {
    $courseLookup[$normalize($c['course_code'] ?? '')] = (int)$c['id'];
    $courseLookup[$normalize($c['course_name'] ?? '')] = (int)$c['id'];
}
$modeLookup = [];
foreach ($trainingModes as $m) {
    $modeLookup[$normalize($m['mode_name'] ?? '')] = (int)$m['id'];
}

// A student's `program` is a program_code in the `programs` table, while fee
// accounts reference a `courses.id`. Bridge them: program_code -> program_name
// -> matching course_name -> course_id. This lets the course auto-resolve for
// students whose program is a real programme (not just a literal course code).
$programToCourse = [];
if ($progRows = $db->query("SELECT program_code, program_name FROM programs")) {
    while ($p = $progRows->fetch_assoc()) {
        $courseId = $courseLookup[$normalize($p['program_name'] ?? '')] ?? '';
        if ($courseId !== '') {
            $programToCourse[$normalize($p['program_code'] ?? '')] = $courseId;
        }
    }
    $progRows->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header finance-section mb-4 mt-2">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-user-circle me-2 text-primary"></i>Student Fee Accounts</h1>
                <p class="text-muted mb-0">Monitor student account balances, filter by status, manually initialize fee records, or withdraw/cancel accounts.</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                    <i class="fas fa-user-plus me-2"></i>New Student Account
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

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Student ID</label>
                    <input type="text" class="form-control rounded-3" name="student_id" value="<?= htmlspecialchars($filter_sid) ?>" placeholder="Search ID...">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Course</label>
                    <select class="form-select rounded-3" name="course_id">
                        <option value="">All Courses</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= htmlspecialchars($c['id']) ?>" <?= $filter_course === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['course_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Account Status</label>
                    <select class="form-select rounded-3" name="status">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="withdrawn" <?= $filter_status === 'withdrawn' ? 'selected' : '' ?>>Withdrawn</option>
                        <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Payment Status</label>
                    <select class="form-select rounded-3" name="payment_status">
                        <option value="">All Payments</option>
                        <option value="Unpaid" <?= $filter_payment === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="Partially Paid" <?= $filter_payment === 'Partially Paid' ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="Paid" <?= $filter_payment === 'Paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="Overpaid" <?= $filter_payment === 'Overpaid' ? 'selected' : '' ?>>Overpaid</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill"><i class="fas fa-search me-2"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student ID / Name</th>
                            <th>Course</th>
                            <th>Mode / Intake</th>
                            <th>Payable</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($studentAccounts)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fas fa-user-slash fa-2x mb-2 d-block"></i> No student accounts found matching filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($studentAccounts as $ac): ?>
                                <?php
                                $badge = 'bg-secondary';
                                if ($ac['payment_status'] === 'Paid') $badge = 'bg-success';
                                elseif ($ac['payment_status'] === 'Partially Paid') $badge = 'bg-warning text-dark';
                                elseif ($ac['payment_status'] === 'Overpaid') $badge = 'bg-info';
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($ac['student_id']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($ac['Fname'] . ' ' . $ac['Lname']) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold small"><?= htmlspecialchars($ac['course_name']) ?></div>
                                        <span class="badge bg-light text-dark font-monospace" style="font-size:0.75rem;"><?= htmlspecialchars($ac['course_code']) ?></span>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold"><?= htmlspecialchars($ac['mode_name']) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($ac['intake']) ?> (<?= htmlspecialchars($ac['academic_year']) ?>)</div>
                                    </td>
                                    <td class="fw-bold text-dark">ZMW <?= number_format($ac['total_payable'], 2) ?></td>
                                    <td class="fw-bold text-success">ZMW <?= number_format($ac['amount_paid'], 2) ?></td>
                                    <td class="fw-bold text-danger">ZMW <?= number_format($ac['balance'], 2) ?></td>
                                    <td>
                                        <span class="badge <?= $badge ?> rounded-pill mb-1 d-block text-center"><?= htmlspecialchars($ac['payment_status']) ?></span>
                                        <?php if ($ac['status'] === 'active'): ?>
                                            <span class="badge bg-soft-success text-success rounded-pill d-block text-center" style="font-size:0.75rem;">Active</span>
                                        <?php elseif ($ac['status'] === 'withdrawn'): ?>
                                            <span class="badge bg-soft-warning text-warning rounded-pill d-block text-center" style="font-size:0.75rem;">Withdrawn</span>
                                        <?php else: ?>
                                            <span class="badge bg-soft-danger text-danger rounded-pill d-block text-center" style="font-size:0.75rem;">Cancelled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <a href="fees_statement.php?student_id=<?= urlencode($ac['student_id']) ?>&course_id=<?= $ac['course_id'] ?>" class="btn btn-outline-primary btn-sm rounded-start-pill px-3" title="View Statement">
                                                <i class="fas fa-file-invoice"></i> Statement
                                            </a>
                                            <button class="btn btn-outline-secondary btn-sm rounded-end-pill px-3" onclick="openStatusModal(<?= htmlspecialchars(json_encode($ac)) ?>)">
                                                <i class="fas fa-edit"></i> Status
                                            </button>
                                        </div>
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

<!-- Create Account Modal -->
<div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary" id="createModalLabel">Create Student Fee Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="c_sid" class="form-label fw-semibold">Student</label>
                        <select class="form-select rounded-3" name="student_id" id="c_sid" required>
                            <option value="">-- Select Student --</option>
                            <?php foreach ($studentsList as $stu): ?>
                                <?php
                                    $stuProgKey = $normalize($stu['program'] ?? '');
                                    // Prefer the program_code -> course bridge; fall back to a direct
                                    // course code/name match if the program isn't a registered programme.
                                    $resolvedCourse = $programToCourse[$stuProgKey] ?? ($courseLookup[$stuProgKey] ?? '');
                                    $resolvedMode = $modeLookup[$normalize($stu['mode'] ?? '')] ?? '';
                                ?>
                                <option value="<?= htmlspecialchars($stu['SID']) ?>"
                                        data-course="<?= htmlspecialchars((string)$resolvedCourse) ?>"
                                        data-mode="<?= htmlspecialchars((string)$resolvedMode) ?>"
                                        data-year="<?= htmlspecialchars($stu['academic_year'] ?? '') ?>"
                                        data-intake="<?= htmlspecialchars($stu['intake'] ?? '') ?>"><?= htmlspecialchars($stu['Fname'] . ' ' . $stu['Lname']) ?> (<?= htmlspecialchars($stu['SID']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="c_course" class="form-label fw-semibold">Course</label>
                        <select class="form-select rounded-3" name="course_id" id="c_course" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['course_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="c_mode" class="form-label fw-semibold">Training Mode</label>
                        <select class="form-select rounded-3" name="training_mode_id" id="c_mode" required>
                            <option value="">-- Select Mode --</option>
                            <?php foreach ($trainingModes as $m): ?>
                                <option value="<?= htmlspecialchars($m['id']) ?>"><?= htmlspecialchars($m['mode_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <label for="c_year" class="form-label fw-semibold">Academic Year</label>
                            <input type="text" class="form-control rounded-3" name="academic_year" id="c_year" required value="2026">
                        </div>
                        <div class="col-6">
                            <label for="c_intake" class="form-label fw-semibold">Intake</label>
                            <input type="text" class="form-control rounded-3" name="intake" id="c_intake" required value="January">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Generate Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary" id="statusModalLabel">Update Account Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="id" id="status_id" value="0">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <p class="text-muted small">Update status for student <strong id="status_student_name"></strong> (<span id="status_student_id"></span>)</p>
                    </div>
                    <div class="mb-3">
                        <label for="sfa_status" class="form-label fw-semibold">Account Status</label>
                        <select class="form-select rounded-3" name="status" id="sfa_status">
                            <option value="active">Active</option>
                            <option value="withdrawn">Withdrawn (Preserves payment history)</option>
                            <option value="cancelled">Cancelled (Closes / invalidates invoice)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// The unified layout gives `.main-content` `position: relative; z-index: 1`
// (so the body watermark shows through), which creates a stacking context that
// traps any modal inside it BELOW Bootstrap's body-level backdrop — the modal
// then renders grayed out and unclickable. Reparent the modals to <body> so
// each and the backdrop share the root stacking context and the modal sits on top.
document.addEventListener('DOMContentLoaded', function () {
    ['createModal', 'statusModal'].forEach(function (id) {
        var modalEl = document.getElementById(id);
        if (modalEl && modalEl.parentNode !== document.body) {
            document.body.appendChild(modalEl);
        }
    });
});

// When a student is selected, auto-fill the rest of the form from that student's
// own enrolment record (course, training mode, academic year, intake). Course/mode
// are best-effort matches resolved server-side; year/intake are filled verbatim.
document.addEventListener('DOMContentLoaded', function () {
    var sidSelect = document.getElementById('c_sid');
    if (!sidSelect) { return; }
    sidSelect.addEventListener('change', function () {
        var opt = sidSelect.options[sidSelect.selectedIndex];
        if (!opt || !opt.value) { return; }
        var course = opt.getAttribute('data-course') || '';
        var mode = opt.getAttribute('data-mode') || '';
        var year = opt.getAttribute('data-year') || '';
        var intake = opt.getAttribute('data-intake') || '';
        if (course) { document.getElementById('c_course').value = course; }
        if (mode) { document.getElementById('c_mode').value = mode; }
        if (year) { document.getElementById('c_year').value = year; }
        if (intake) { document.getElementById('c_intake').value = intake; }
    });
});

function openCreateModal() {
    document.getElementById('c_sid').value = '';
    document.getElementById('c_course').value = '';
    document.getElementById('c_mode').value = '';
    var myModal = new bootstrap.Modal(document.getElementById('createModal'));
    myModal.show();
}

function openStatusModal(ac) {
    document.getElementById('status_id').value = ac.id;
    document.getElementById('status_student_id').innerText = ac.student_id;
    document.getElementById('status_student_name').innerText = ac.Fname + ' ' + ac.Lname;
    document.getElementById('sfa_status').value = ac.status;
    var myModal = new bootstrap.Modal(document.getElementById('statusModal'));
    myModal.show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
