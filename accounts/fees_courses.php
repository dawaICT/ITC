<?php
$page_title = 'Manage Courses';
require "includes/nav.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$message = '';
$error = '';

// Handle add or edit submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrfToken) {
        $error = 'CSRF validation failed.';
    } else {
        $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['course_name'] ?? '');
        $code = trim($_POST['course_code'] ?? '');
        $department_id = $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;
        $entry_requirements = trim($_POST['entry_requirements'] ?? '');
        $duration = trim($_POST['duration'] ?? '');
        $duration_unit = trim($_POST['duration_unit'] ?? 'months');
        $course_type = trim($_POST['course_type'] ?? 'short course');
        $status = $_POST['status'] ?? 'active';

        if ($name === '' || $code === '') {
            $error = 'Course name and course code are required.';
        } else {
            try {
                // Check if code is unique for other courses
                $check = $db->prepare("SELECT id FROM courses WHERE course_code = ? AND id != ? LIMIT 1");
                $check->bind_param('si', $code, $id);
                $check->execute();
                $dup = $check->get_result()->num_rows > 0;
                $check->close();

                if ($dup) {
                    $error = "Course Code '$code' is already in use by another course.";
                } else {
                    if ($id > 0) {
                        // Update
                        $stmt = $db->prepare("UPDATE courses 
                            SET course_name = ?, course_code = ?, department_id = ?, entry_requirements = ?, duration = ?, duration_unit = ?, course_type = ?, status = ? 
                            WHERE id = ?");
                        $stmt->bind_param('ssisssssi', $name, $code, $department_id, $entry_requirements, $duration, $duration_unit, $course_type, $status, $id);
                        if ($stmt->execute()) {
                            $message = 'Course updated successfully.';
                        } else {
                            $error = 'A database error occurred. Please try again.';
                            error_log("Database error in accounts/fees_courses.php: " . $db->error);
                        }
                        $stmt->close();
                    } else {
                        // Insert
                        $stmt = $db->prepare("INSERT INTO courses 
                            (course_name, course_code, department_id, entry_requirements, duration, duration_unit, course_type, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param('ssisssss', $name, $code, $department_id, $entry_requirements, $duration, $duration_unit, $course_type, $status);
                        if ($stmt->execute()) {
                            $message = 'Course created successfully.';
                        } else {
                            $error = 'A database error occurred. Please try again.';
                            error_log("Database error in accounts/fees_courses.php: " . $db->error);
                        }
                        $stmt->close();
                    }
                }
            } catch (Exception $e) {
                $error = 'Database operation failed: ' . $e->getMessage();
            }
        }
    }
    }
}

// Fetch active departments for select list
$activeDepts = [];
$res = $db->query("SELECT id, department_name, department_code FROM departments WHERE status = 'active' ORDER BY department_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $activeDepts[] = $row;
    }
    $res->free();
}

// Fetch all departments (active + inactive) for lookup mapping in table list
$allDepts = [];
$res = $db->query("SELECT id, department_name FROM departments");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $allDepts[$row['id']] = $row['department_name'];
    }
    $res->free();
}

// Fetch courses
$courses = [];
$res = $db->query("SELECT * FROM courses ORDER BY course_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $courses[] = $row;
    }
    $res->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header finance-section mb-4 mt-2">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-graduation-cap me-2 text-primary"></i>Manage Courses</h1>
                <p class="text-muted mb-0">Create and edit courses, link them to departments, set requirements, course types, and durations.</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus me-2"></i>Add Course
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
                            <th>Code</th>
                            <th>Course Name</th>
                            <th>Department</th>
                            <th>Duration</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($courses)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fas fa-graduation-cap fa-2x mb-2 d-block"></i> No courses configured yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($courses as $c): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark font-monospace fs-6 px-3 py-2 rounded-3"><?= htmlspecialchars($c['course_code']) ?></span></td>
                                    <td class="fw-bold"><?= htmlspecialchars($c['course_name']) ?></td>
                                    <td><?= isset($allDepts[$c['department_id']]) ? htmlspecialchars($allDepts[$c['department_id']]) : '<span class="text-muted">Unassigned</span>' ?></td>
                                    <td><?= htmlspecialchars($c['duration'] ?? 'N/A') . ' ' . htmlspecialchars($c['duration_unit'] ?? '') ?></td>
                                    <td><span class="badge bg-info text-capitalize"><?= htmlspecialchars($c['course_type'] ?? 'short course') ?></span></td>
                                    <td>
                                        <?php if ($c['status'] === 'active'): ?>
                                            <span class="badge bg-success rounded-pill px-3 py-1">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill px-3 py-1">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-outline-primary btn-sm rounded-pill px-3" 
                                                onclick='openEditModal(<?= htmlspecialchars(json_encode($c)) ?>)'>
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </button>
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

<!-- Add/Edit Course Modal -->
<div class="modal fade" id="courseModal" tabindex="-1" aria-labelledby="courseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary" id="courseModalLabel">Add Course</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="course_id" value="0">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="c_name" class="form-label fw-semibold">Course Name</label>
                            <input type="text" class="form-control rounded-3" name="course_name" id="c_name" required placeholder="e.g., Craft Certificate in Automotive Mechanics">
                        </div>
                        <div class="col-md-6">
                            <label for="c_code" class="form-label fw-semibold">Course Code</label>
                            <input type="text" class="form-control rounded-3" name="course_code" id="c_code" required placeholder="e.g., ACM-01">
                        </div>
                        <div class="col-md-6">
                            <label for="c_dept" class="form-label fw-semibold">Department</label>
                            <select class="form-select rounded-3" name="department_id" id="c_dept">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($activeDepts as $dept): ?>
                                    <option value="<?= htmlspecialchars($dept['id']) ?>"><?= htmlspecialchars($dept['department_name']) ?> (<?= htmlspecialchars($dept['department_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="c_type" class="form-label fw-semibold">Course Type</label>
                            <select class="form-select rounded-3" name="course_type" id="c_type">
                                <option value="short course">Short Course</option>
                                <option value="certificate">Certificate</option>
                                <option value="trade test">Trade Test</option>
                                <option value="diploma">Diploma</option>
                                <option value="service">Service</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="c_dur" class="form-label fw-semibold">Duration Value</label>
                            <input type="text" class="form-control rounded-3" name="duration" id="c_dur" placeholder="e.g., 3, 1, 6">
                        </div>
                        <div class="col-md-6">
                            <label for="c_dur_u" class="form-label fw-semibold">Duration Unit</label>
                            <select class="form-select rounded-3" name="duration_unit" id="c_dur_u">
                                <option value="days">Days</option>
                                <option value="weeks">Weeks</option>
                                <option value="months">Months</option>
                                <option value="years">Years</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="c_req" class="form-label fw-semibold">Entry Requirements</label>
                            <input type="text" class="form-control rounded-3" name="entry_requirements" id="c_req" placeholder="e.g., Grade 12 Certificate with pass in English and Mathematics">
                        </div>
                        <div class="col-md-6">
                            <label for="c_status" class="form-label fw-semibold">Status</label>
                            <select class="form-select rounded-3" name="status" id="c_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// The unified layout gives `.main-content` `position: relative; z-index: 1`
// (so the body watermark shows through), which creates a stacking context that
// traps any modal inside it BELOW Bootstrap's body-level backdrop — the modal
// then renders grayed out and unclickable. Reparent the modal to <body> so it
// and the backdrop share the root stacking context and the modal sits on top.
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('courseModal');
    if (modalEl && modalEl.parentNode !== document.body) {
        document.body.appendChild(modalEl);
    }
});

function openAddModal() {
    document.getElementById('courseModalLabel').innerText = 'Add Course';
    document.getElementById('course_id').value = '0';
    document.getElementById('c_name').value = '';
    document.getElementById('c_code').value = '';
    document.getElementById('c_dept').value = '';
    document.getElementById('c_type').value = 'short course';
    document.getElementById('c_dur').value = '';
    document.getElementById('c_dur_u').value = 'months';
    document.getElementById('c_req').value = '';
    document.getElementById('c_status').value = 'active';
    var myModal = new bootstrap.Modal(document.getElementById('courseModal'));
    myModal.show();
}

function openEditModal(c) {
    document.getElementById('courseModalLabel').innerText = 'Edit Course';
    document.getElementById('course_id').value = c.id;
    document.getElementById('c_name').value = c.course_name;
    document.getElementById('c_code').value = c.course_code;
    document.getElementById('c_dept').value = c.department_id || '';
    document.getElementById('c_type').value = c.course_type || 'short course';
    document.getElementById('c_dur').value = c.duration || '';
    document.getElementById('c_dur_u').value = c.duration_unit || 'months';
    document.getElementById('c_req').value = c.entry_requirements || '';
    document.getElementById('c_status').value = c.status;
    var myModal = new bootstrap.Modal(document.getElementById('courseModal'));
    myModal.show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
