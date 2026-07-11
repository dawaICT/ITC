<?php
declare(strict_types=1);

$page_title = 'Department & Academic Leadership Assignments';
require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();

require_once dirname(__DIR__) . '/includes/audit.php';
require_once dirname(__DIR__) . '/includes/internal_staff_helpers.php';
require_once dirname(__DIR__) . '/includes/department_assignment_helpers.php';

function dept_assign_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
        $_SESSION['errorMessage'] = 'CSRF token validation failed.';
        header('Location: department_assignments.php');
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    if ($action !== 'assign_department') {
        audit_log_current_user($db, 'department.assign', [
            'status' => 'blocked',
            'reason' => 'Unknown POST action on department_assignments.php',
            'requested_action' => $action,
        ]);
        $_SESSION['errorMessage'] = 'Unknown action rejected.';
        header('Location: department_assignments.php');
        exit;
    }

    $result = wuc_assign_department_to_staff($db, [
        'target_staff_number' => trim((string)($_POST['target_staff_number'] ?? '')),
        'department_id' => (int)($_POST['department_id'] ?? 0),
        'assignment_type' => trim((string)($_POST['assignment_type'] ?? 'member')),
        'confirm_self_leadership' => !empty($_POST['confirm_self_leadership']),
        'assigned_by' => trim((string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'admin')),
        'actor_staff_number' => trim((string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '')),
        'actor_user_id' => (int)($_SESSION['user_id_db'] ?? 0),
    ]);

    audit_log_current_user($db, 'department.assign', $result['audit']);

    if ($result['ok']) {
        $_SESSION['successMessage'] = $result['message'];
    } else {
        $_SESSION['errorMessage'] = $result['message'];
    }

    header('Location: department_assignments.php');
    exit;
}

require 'includes/nav.php';

$deptAssignments = wuc_fetch_department_assignments($db);

$departmentsList = [];
$res = $db->query('SELECT id, department_name AS deptName FROM departments WHERE status = "active" ORDER BY department_name ASC');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $departmentsList[] = $row;
    }
    $res->free();
}

$staffList = [];
$res = $db->query(
    "SELECT s.staff_id, s.Fname, s.Lname
       FROM staff s
       INNER JOIN users u ON u.staff_id = s.staff_id
      WHERE (u.primary_role IS NULL OR u.primary_role NOT IN ('student','applicant','alumni','employer'))
        AND (u.student_id IS NULL OR TRIM(u.student_id) = '')
      ORDER BY s.Fname ASC"
);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $staffList[] = $row;
    }
    $res->free();
}

$assignmentTypes = wuc_department_assignment_types();
$loggedInStaffNumber = trim((string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? ''));
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1 text-gray-800"><i class="fas fa-sitemap me-2"></i>Department &amp; Academic Leadership Assignments</h1>
        <p class="text-muted mb-0">
            Assign staff to departments and academic leadership positions (member, HOD, dean).
            RBAC portal roles are managed separately on
            <a href="users_roles.php">Staff and Lecturer Roles Management</a>.
        </p>
    </div>

    <?php if (isset($_SESSION['successMessage'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo dept_assign_h($_SESSION['successMessage']); unset($_SESSION['successMessage']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['errorMessage'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo dept_assign_h($_SESSION['errorMessage']); unset($_SESSION['errorMessage']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Current Department Assignments</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Staff Number</th>
                                    <th>Staff Member</th>
                                    <th>Department</th>
                                    <th>Assignment Type</th>
                                    <th>Assigned Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$deptAssignments): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No department assignments recorded yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deptAssignments as $da): ?>
                                        <tr>
                                            <td><strong><?php echo dept_assign_h($da['staff_id']); ?></strong></td>
                                            <td><?php echo dept_assign_h(trim($da['Fname'] . ' ' . $da['Lname'])); ?></td>
                                            <td><?php echo dept_assign_h($da['deptName']); ?></td>
                                            <td>
                                                <span class="badge <?php echo in_array($da['assignment_type'], ['hod', 'dean'], true) ? 'bg-danger' : 'bg-info text-dark'; ?>">
                                                    <?php echo dept_assign_h($assignmentTypes[$da['assignment_type']] ?? $da['assignment_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo dept_assign_h($da['assigned_at'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Add Department Assignment</h6>
                </div>
                <div class="card-body">
                    <form method="post" action="department_assignments.php" id="deptAssignForm">
                        <input type="hidden" name="csrf_token" value="<?php echo dept_assign_h($_SESSION['csrf_token'] ?? ''); ?>">
                        <input type="hidden" name="action" value="assign_department">

                        <div class="mb-3">
                            <label class="form-label" for="target_staff_number">Staff Member</label>
                            <select class="form-select" name="target_staff_number" id="target_staff_number" required>
                                <option value="">-- Select Staff --</option>
                                <?php foreach ($staffList as $s): ?>
                                    <option value="<?php echo dept_assign_h($s['staff_id']); ?>">
                                        <?php echo dept_assign_h($s['staff_id'] . ' — ' . trim($s['Fname'] . ' ' . $s['Lname'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="department_id">Department</label>
                            <select class="form-select" name="department_id" id="department_id" required>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departmentsList as $d): ?>
                                    <option value="<?php echo (int)$d['id']; ?>"><?php echo dept_assign_h($d['deptName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="assignment_type">Assignment Type</label>
                            <select class="form-select" name="assignment_type" id="assignment_type" required>
                                <?php foreach ($assignmentTypes as $typeKey => $typeLabel): ?>
                                    <option value="<?php echo dept_assign_h($typeKey); ?>"><?php echo dept_assign_h($typeLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Leadership types (HOD, Dean) are department assignments — not RBAC portal roles.</div>
                        </div>

                        <div class="mb-3 form-check" id="selfLeadershipConfirm" style="display:none;">
                            <input class="form-check-input" type="checkbox" name="confirm_self_leadership" value="1" id="confirm_self_leadership">
                            <label class="form-check-label" for="confirm_self_leadership">
                                I confirm assigning myself (<strong><?php echo dept_assign_h($loggedInStaffNumber); ?></strong>) to this leadership role.
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-save me-2"></i>Save Assignment
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const loggedInStaff = <?php echo json_encode($loggedInStaffNumber, JSON_UNESCAPED_UNICODE); ?>;
    const staffSelect = document.getElementById('target_staff_number');
    const typeSelect = document.getElementById('assignment_type');
    const confirmWrap = document.getElementById('selfLeadershipConfirm');
    const confirmBox = document.getElementById('confirm_self_leadership');

    function refreshSelfConfirm() {
        const isLeadership = typeSelect.value === 'hod' || typeSelect.value === 'dean';
        const isSelf = staffSelect.value !== '' && staffSelect.value === loggedInStaff;
        const show = isLeadership && isSelf;
        confirmWrap.style.display = show ? 'block' : 'none';
        if (!show) {
            confirmBox.checked = false;
        }
    }

    staffSelect.addEventListener('change', refreshSelfConfirm);
    typeSelect.addEventListener('change', refreshSelfConfirm);
    refreshSelfConfirm();
});
</script>

<?php require 'includes/footer.php'; ?>
