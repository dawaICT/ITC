<?php
$page_title = 'Manage Departments';
require "includes/nav.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$message = '';
$error = '';

// Handle add or edit submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'CSRF validation failed. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['department_name'] ?? '');
            $code = trim($_POST['department_code'] ?? '');
            $faculty_id = $_POST['faculty_id'] !== '' ? (int)$_POST['faculty_id'] : null;
            $status = $_POST['status'] ?? 'active';

            if ($name === '' || $code === '') {
                $error = 'Department name and code are required.';
            } else {
                if ($id > 0) {
                    // Update
                    $stmt = $db->prepare("UPDATE departments SET department_name = ?, department_code = ?, faculty_id = ?, status = ? WHERE id = ?");
                    $stmt->bind_param('ssisi', $name, $code, $faculty_id, $status, $id);
                    if ($stmt->execute()) {
                        $message = 'Department updated successfully.';
                    } else {
                        $error = 'A database error occurred. Please try again.';
                        error_log("Database error in fees_departments.php UPDATE: " . $db->error);
                    }
                    $stmt->close();
                } else {
                    // Insert
                    $stmt = $db->prepare("INSERT INTO departments (department_name, department_code, faculty_id, status) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('ssis', $name, $code, $faculty_id, $status);
                    if ($stmt->execute()) {
                        $message = 'Department created successfully.';
                    } else {
                        $error = 'A database error occurred. Please try again.';
                        error_log("Database error in fees_departments.php INSERT: " . $db->error);
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Fetch all departments
$departments = [];
$res = $db->query("SELECT * FROM departments ORDER BY department_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $departments[] = $row;
    }
    $res->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header finance-section mb-4 mt-2">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-building me-2 text-primary"></i>Manage Departments</h1>
                <p class="text-muted mb-0">Create new departments, assign department codes, and toggle active/inactive status.</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus me-2"></i>Add Department
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
                            <th>Department Name</th>
                            <th>Faculty ID</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($departments)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
                                    <i class="fas fa-building fa-2x mb-2 d-block"></i> No departments configured yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($departments as $dept): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark font-monospace fs-6 px-3 py-2 rounded-3"><?= htmlspecialchars($dept['department_code'] ?? '') ?></span></td>
                                    <td class="fw-bold"><?= htmlspecialchars($dept['department_name']) ?></td>
                                    <td><?= $dept['faculty_id'] !== null ? htmlspecialchars($dept['faculty_id']) : '<span class="text-muted">None</span>' ?></td>
                                    <td>
                                        <?php if ($dept['status'] === 'active'): ?>
                                            <span class="badge bg-success rounded-pill px-3 py-1">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill px-3 py-1">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-outline-primary btn-sm rounded-pill px-3" 
                                                onclick="openEditModal(<?= htmlspecialchars(json_encode($dept)) ?>)">
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

<!-- Add/Edit Department Modal -->
<div class="modal fade" id="deptModal" tabindex="-1" aria-labelledby="deptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-primary" id="deptModalLabel">Add Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="dept_id" value="0">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="dept_name" class="form-label fw-semibold">Department Name</label>
                        <input type="text" class="form-control rounded-3" name="department_name" id="dept_name" required placeholder="e.g., Computer Science">
                    </div>
                    <div class="mb-3">
                        <label for="dept_code" class="form-label fw-semibold">Department Code</label>
                        <input type="text" class="form-control rounded-3" name="department_code" id="dept_code" required placeholder="e.g., CS">
                    </div>
                    <div class="mb-3">
                        <label for="fac_id" class="form-label fw-semibold">Faculty ID (Optional)</label>
                        <input type="number" class="form-control rounded-3" name="faculty_id" id="fac_id" placeholder="e.g., 1">
                    </div>
                    <div class="mb-3">
                        <label for="dept_status" class="form-label fw-semibold">Status</label>
                        <select class="form-select rounded-3" name="status" id="dept_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
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
function openAddModal() {
    document.getElementById('deptModalLabel').innerText = 'Add Department';
    document.getElementById('dept_id').value = '0';
    document.getElementById('dept_name').value = '';
    document.getElementById('dept_code').value = '';
    document.getElementById('fac_id').value = '';
    document.getElementById('dept_status').value = 'active';
    var myModal = new bootstrap.Modal(document.getElementById('deptModal'));
    myModal.show();
}

function openEditModal(dept) {
    document.getElementById('deptModalLabel').innerText = 'Edit Department';
    document.getElementById('dept_id').value = dept.id;
    document.getElementById('dept_name').value = dept.department_name;
    document.getElementById('dept_code').value = dept.department_code;
    document.getElementById('fac_id').value = dept.faculty_id || '';
    document.getElementById('dept_status').value = dept.status;
    var myModal = new bootstrap.Modal(document.getElementById('deptModal'));
    myModal.show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
