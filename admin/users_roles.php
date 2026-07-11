<?php
declare(strict_types=1);

/**
 * Staff and Lecturer Roles Management  (canonical owner of internal role/access)
 * ------------------------------------------------------------------
 * DOES: manage INTERNAL staff & lecturer accounts only — assign/revoke roles
 *       (user_roles), grant/revoke per-user direct access (user_module_access),
 *       and map role → permission grants incl. portal scope (role_permissions).
 *       Every action is CSRF-checked, prepared, audit-logged, and gated to
 *       internal-staff users/roles (wuc_assert_internal_staff_*).
 * DOES NOT: manage students/applicants/alumni/employers (excluded from the list
 *       and blocked in every handler), assign departments/HOD/HOS leadership
 *       (delegated to department_assignments.php), or control portal entry
 *       (use portal_access.php).
 * Access: systems admins only (checkAdminAuth).
 */

$page_title = 'Staff and Lecturer Roles Management';
require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();

require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/audit.php';
require_once dirname(__DIR__) . '/includes/internal_staff_helpers.php';

function users_roles_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
        $_SESSION['errorMessage'] = 'CSRF token validation failed.';
        header('Location: users_roles.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'assign_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);
        if ($userId > 0 && $roleId > 0) {
            $blockMsg = wuc_assert_internal_staff_user_for_roles_mgmt($db, $userId);
            if ($blockMsg !== null) {
                $_SESSION['errorMessage'] = $blockMsg;
            } elseif (($roleBlock = wuc_assert_internal_staff_role_for_roles_mgmt($db, $roleId)) !== null) {
                $_SESSION['errorMessage'] = $roleBlock;
            } else {
                $stmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = 'active'");
                if ($stmt) {
                    $stmt->bind_param('ii', $userId, $roleId);
                    if ($stmt->execute()) {
                        audit_log_current_user($db, 'role.assign', [
                            'status' => 'success',
                            'target_user_id' => $userId,
                            'role_id' => $roleId,
                        ]);
                        $_SESSION['successMessage'] = 'Staff role assigned successfully.';
                    } else {
                        $_SESSION['errorMessage'] = 'Error executing role assignment.';
                    }
                    $stmt->close();
                }
            }
        }
    }
    elseif ($action === 'revoke_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);
        if ($userId > 0 && $roleId > 0) {
            $blockMsg = wuc_assert_internal_staff_user_for_roles_mgmt($db, $userId);
            if ($blockMsg !== null) {
                $_SESSION['errorMessage'] = $blockMsg;
            } else {
                // Prevent revoking self Systems Admin
                $rStmt = $db->prepare("SELECT role_name FROM roles WHERE role_id = ? LIMIT 1");
                $rStmt->bind_param('i', $roleId);
                $rStmt->execute();
                $rRow = $rStmt->get_result()->fetch_assoc();
                $rStmt->close();
                $roleName = $rRow['role_name'] ?? '';

                if ($roleName === 'systems_admin' && (int)$_SESSION['user_id_db'] === $userId) {
                    $_SESSION['errorMessage'] = 'You cannot revoke the Systems Admin role from yourself.';
                } elseif (!wuc_is_internal_staff_role($roleName)) {
                    $_SESSION['errorMessage'] = 'Only internal staff roles can be managed from this page.';
                } else {
                    $stmt = $db->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
                    $stmt->bind_param('ii', $userId, $roleId);
                    if ($stmt->execute()) {
                        audit_log_current_user($db, 'role.revoke', [
                            'status' => 'success',
                            'target_user_id' => $userId,
                            'role_id' => $roleId,
                        ]);
                        $_SESSION['successMessage'] = 'Staff role revoked successfully.';
                    } else {
                        $_SESSION['errorMessage'] = 'Error revoking role.';
                    }
                    $stmt->close();
                }
            }
        }
    }
    elseif ($action === 'grant_direct_access') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $permId = (int)($_POST['permission_id'] ?? 0);
        $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

        if ($userId > 0 && $permId > 0) {
            $blockMsg = wuc_assert_internal_staff_user_for_roles_mgmt($db, $userId);
            if ($blockMsg !== null) {
                $_SESSION['errorMessage'] = $blockMsg;
                header('Location: users_roles.php');
                exit;
            }
            // Find module_id for this permission
            $pStmt = $db->prepare("SELECT permission_key FROM permissions WHERE permission_id = ? LIMIT 1");
            $pStmt->bind_param('i', $permId);
            $pStmt->execute();
            $pRow = $pStmt->get_result()->fetch_assoc();
            $pStmt->close();
            $permKey = $pRow['permission_key'] ?? '';
            $modKey = explode('.', $permKey)[0] ?? '';

            $mStmt = $db->prepare("SELECT module_id FROM modules WHERE module_key = ? LIMIT 1");
            $mStmt->bind_param('s', $modKey);
            $mStmt->execute();
            $mRow = $mStmt->get_result()->fetch_assoc();
            $mStmt->close();
            $moduleId = $mRow ? (int)$mRow['module_id'] : 0;

            if ($moduleId > 0) {
                $stmt = $db->prepare("INSERT INTO user_module_access (user_id, module_id, permission_id, start_date, end_date, assigned_by) VALUES (?, ?, ?, ?, ?, ?)");
                $assignedBy = $_SESSION['user_id'] ?? 'admin';
                $stmt->bind_param('iiisss', $userId, $moduleId, $permId, $startDate, $endDate, $assignedBy);
                if ($stmt->execute()) {
                    audit_log_current_user($db, 'direct_access.grant', [
                        'target_user_id' => $userId, 'module_id' => $moduleId,
                        'permission_id' => $permId, 'start' => $startDate, 'end' => $endDate
                    ]);
                    $_SESSION['successMessage'] = 'Direct module access granted successfully.';
                } else {
                    $_SESSION['errorMessage'] = 'Error granting direct access.';
                }
                $stmt->close();
            }
        }
    } 
    elseif ($action === 'revoke_direct_access') {
        $accessId = (int)($_POST['access_id'] ?? 0);
        if ($accessId > 0) {
            // Scope guard: only revoke direct access that belongs to an internal
            // staff/lecturer account, mirroring the grant_direct_access contract so
            // this page cannot mutate access rows for student/external accounts.
            $ownerStmt = $db->prepare("SELECT user_id FROM user_module_access WHERE id = ? LIMIT 1");
            $ownerStmt->bind_param('i', $accessId);
            $ownerStmt->execute();
            $ownerRow = $ownerStmt->get_result()->fetch_assoc();
            $ownerStmt->close();
            $ownerUserId = (int)($ownerRow['user_id'] ?? 0);

            if ($ownerUserId <= 0) {
                $_SESSION['errorMessage'] = 'That direct-access grant no longer exists.';
            } elseif (($blockMsg = wuc_assert_internal_staff_user_for_roles_mgmt($db, $ownerUserId)) !== null) {
                audit_log_current_user($db, 'direct_access.revoke', [
                    'status' => 'blocked', 'access_id' => $accessId, 'target_user_id' => $ownerUserId,
                    'reason' => 'target is not an internal staff/lecturer account',
                ]);
                $_SESSION['errorMessage'] = $blockMsg;
            } else {
                $stmt = $db->prepare("DELETE FROM user_module_access WHERE id = ?");
                $stmt->bind_param('i', $accessId);
                if ($stmt->execute()) {
                    audit_log_current_user($db, 'direct_access.revoke', [
                        'status' => 'success', 'access_id' => $accessId, 'target_user_id' => $ownerUserId,
                    ]);
                    $_SESSION['successMessage'] = 'Direct access revoked successfully.';
                } else {
                    $_SESSION['errorMessage'] = 'Error revoking direct access.';
                }
                $stmt->close();
            }
        }
    }
    elseif ($action === 'save_role_permissions') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        if ($roleId > 0) {
            if (($roleBlock = wuc_assert_internal_staff_role_for_roles_mgmt($db, $roleId)) !== null) {
                $_SESSION['errorMessage'] = $roleBlock;
                header('Location: users_roles.php');
                exit;
            }

            $portalScopeRaw = trim((string)($_POST['permission_portal_id'] ?? ''));
            $portalId = null;
            $portalLabel = 'Global';
            $scopeValid = true;

            if ($portalScopeRaw !== '') {
                if (!ctype_digit($portalScopeRaw)) {
                    $scopeValid = false;
                } else {
                    $portalId = (int)$portalScopeRaw;
                    $pStmt = $db->prepare("SELECT portal_name FROM portals WHERE id = ? AND status = 'active' LIMIT 1");
                    $pStmt->bind_param('i', $portalId);
                    $pStmt->execute();
                    $portalRow = $pStmt->get_result()->fetch_assoc();
                    $pStmt->close();
                    if (!$portalRow) {
                        $scopeValid = false;
                    } else {
                        $portalLabel = (string)$portalRow['portal_name'];
                    }
                }
            }

            if (!$scopeValid) {
                $_SESSION['errorMessage'] = 'Select a valid portal scope before saving role permissions.';
            } else {
                $perms = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['perms'] ?? [])))));
                $db->begin_transaction();
                try {
                    if ($portalId === null) {
                        $delete = $db->prepare("DELETE FROM role_permissions WHERE role_id = ? AND portal_id IS NULL");
                        $delete->bind_param('i', $roleId);
                    } else {
                        $delete = $db->prepare("DELETE FROM role_permissions WHERE role_id = ? AND portal_id = ?");
                        $delete->bind_param('ii', $roleId, $portalId);
                    }
                    $delete->execute();
                    $delete->close();

                    foreach ($perms as $permId) {
                        if ($permId <= 0) {
                            continue;
                        }

                        $pStmt = $db->prepare("SELECT permission_key FROM permissions WHERE permission_id = ? AND status = 'active' LIMIT 1");
                        $pStmt->bind_param('i', $permId);
                        $pStmt->execute();
                        $pRow = $pStmt->get_result()->fetch_assoc();
                        $pStmt->close();
                        $permKey = $pRow['permission_key'] ?? '';
                        $modKey = explode('.', $permKey)[0] ?? '';

                        $mStmt = $db->prepare("SELECT module_id FROM modules WHERE module_key = ? AND status = 'active' LIMIT 1");
                        $mStmt->bind_param('s', $modKey);
                        $mStmt->execute();
                        $mRow = $mStmt->get_result()->fetch_assoc();
                        $mStmt->close();
                        $moduleId = $mRow ? (int)$mRow['module_id'] : 0;

                        if ($moduleId > 0) {
                            if ($portalId === null) {
                                $insert = $db->prepare("INSERT INTO role_permissions (role_id, module_id, permission_id, portal_id) VALUES (?, ?, ?, NULL)");
                                $insert->bind_param('iii', $roleId, $moduleId, $permId);
                            } else {
                                $insert = $db->prepare("INSERT INTO role_permissions (role_id, module_id, permission_id, portal_id) VALUES (?, ?, ?, ?)");
                                $insert->bind_param('iiii', $roleId, $moduleId, $permId, $portalId);
                            }
                            $insert->execute();
                            $insert->close();
                        }
                    }

                    $db->commit();
                    audit_log_current_user($db, 'role_permissions.update', [
                        'role_id' => $roleId,
                        'portal_id' => $portalId,
                        'portal_scope' => $portalLabel,
                        'permissions_count' => count($perms)
                    ]);
                    $_SESSION['successMessage'] = 'Role permissions updated for ' . $portalLabel . ' scope.';
                } catch (Throwable $e) {
                    $db->rollback();
                    error_log('Role permissions update failed: ' . $e->getMessage());
                    $_SESSION['errorMessage'] = 'Role permissions could not be updated. Please try again.';
                }
            }
        }
    } 
    elseif ($action === 'assign_department') {
        audit_log_current_user($db, 'department.assign', [
            'status' => 'blocked',
            'reason' => 'Department assignment is not allowed from users_roles.php',
            'target_staff_number' => trim((string)($_POST['staff_id'] ?? '')),
            'new_department_id' => (int)($_POST['department_id'] ?? 0),
            'assignment_type' => trim((string)($_POST['type'] ?? '')),
        ]);
        $_SESSION['errorMessage'] = 'Department and academic leadership assignments are managed from Department Assignments, not this page.';
    }
    elseif ($action !== '') {
        audit_log_current_user($db, 'security.post_rejected', [
            'status' => 'blocked',
            'reason' => 'Unknown POST action on users_roles.php',
            'requested_action' => $action,
        ]);
        $_SESSION['errorMessage'] = 'Unknown action rejected.';
    }

    header('Location: users_roles.php');
    exit;
}

// Load navigation UI
require "includes/nav.php";

$listFilters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'role' => trim((string)($_GET['role'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'department_id' => (int)($_GET['department_id'] ?? 0),
    'page' => (int)($_GET['page'] ?? 1),
    'per_page' => 25,
];
$userResult = wuc_fetch_internal_staff_users($db, $listFilters);
$users = $userResult['rows'];
$userTotal = (int)($userResult['total'] ?? 0);
$userPage = (int)($userResult['page'] ?? 1);
$userPerPage = (int)($userResult['per_page'] ?? 25);
$userTotalPages = max(1, (int)ceil($userTotal / max(1, $userPerPage)));

$staffUsersForSelect = wuc_fetch_internal_staff_users($db, ['per_page' => 500, 'page' => 1])['rows'];

$rolesList = wuc_fetch_internal_staff_roles($db);
$dataIntegrityIssues = wuc_detect_staff_role_inconsistencies($db);

$modulesList = [];
$res = $db->query("SELECT * FROM modules ORDER BY display_order ASC");
while ($row = $res->fetch_assoc()) {
    $modulesList[] = $row;
}

$permissionsList = [];
$res = $db->query("SELECT * FROM permissions ORDER BY permission_key ASC");
while ($row = $res->fetch_assoc()) {
    $permissionsList[] = $row;
}

$portalsList = [];
$res = $db->query("SELECT id, portal_code, portal_name FROM portals WHERE status = 'active' ORDER BY FIELD(portal_code, 'academic', 'elearning', 'library', 'applicant', 'alumni', 'employer'), portal_name ASC");
while ($row = $res->fetch_assoc()) {
    $portalsList[] = $row;
}

$directAccesses = [];
$directSql = "SELECT uma.*, u.username, p.permission_label
                FROM user_module_access uma
                JOIN users u ON u.user_id = uma.user_id
                JOIN permissions p ON p.permission_id = uma.permission_id
                INNER JOIN staff s ON s.staff_id = u.staff_id
               WHERE u.staff_id IS NOT NULL
                 AND TRIM(u.staff_id) <> ''
                 AND (u.primary_role IS NULL OR u.primary_role NOT IN ('student','applicant','alumni','employer'))
                 AND (u.student_id IS NULL OR TRIM(u.student_id) = '')
            ORDER BY uma.id DESC";
$res = $db->query($directSql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $directAccesses[] = $row;
    }
    $res->free();
}

$departmentsList = [];
$res = $db->query("SELECT id, department_name AS deptName FROM departments WHERE status = 'active' ORDER BY department_name ASC");
while ($row = $res->fetch_assoc()) {
    $departmentsList[] = $row;
}

$auditLogs = [];
$res = $db->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 50");
while ($row = $res->fetch_assoc()) {
    $auditLogs[] = $row;
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <h1 class="h3 mb-1 text-gray-800"><i class="fas fa-users-cog me-2"></i>Staff and Lecturer Roles Management</h1>
            <p class="text-muted mb-0">Manage internal staff and lecturer roles, permissions, and access levels. Student accounts are managed separately in the Student Management module. Department and leadership assignments are managed on <a href="department_assignments.php">Department Assignments</a>.</p>
        </div>
    </div>

    <?php if (isset($_SESSION['successMessage'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo users_roles_h($_SESSION['successMessage']); unset($_SESSION['successMessage']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['errorMessage'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo users_roles_h($_SESSION['errorMessage']); unset($_SESSION['errorMessage']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($dataIntegrityIssues)): ?>
        <div class="alert alert-warning" role="alert">
            <h6 class="alert-heading mb-2"><i class="fas fa-triangle-exclamation me-1"></i>Account data inconsistencies detected</h6>
            <p class="mb-2 small">These accounts are excluded from staff/lecturer role management until corrected in the appropriate module.</p>
            <ul class="mb-0 small">
                <?php foreach (array_slice($dataIntegrityIssues, 0, 8) as $issue): ?>
                    <li><?php echo users_roles_h($issue['message'] ?? ''); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($dataIntegrityIssues) > 8): ?>
                <p class="mb-0 mt-2 small text-muted"><?php echo count($dataIntegrityIssues) - 8; ?> additional issue(s) not shown.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4" id="accessTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users-pane" type="button" role="tab">Staff &amp; Lecturer Roles</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="direct-tab" data-bs-toggle="tab" data-bs-target="#direct-pane" type="button" role="tab">Direct module Access</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="perms-tab" data-bs-toggle="tab" data-bs-target="#perms-pane" type="button" role="tab">Role Permissions</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="audits-tab" data-bs-toggle="tab" data-bs-target="#audits-pane" type="button" role="tab">Audit Logs</button>
        </li>
    </ul>

    <div class="tab-content" id="accessTabsContent">
        <!-- Tab 1: Staff & Lecturer Roles -->
        <div class="tab-pane fade show active" id="users-pane" role="tabpanel">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Filter Internal Staff &amp; Lecturers</h6>
                </div>
                <div class="card-body">
                    <form method="get" action="users_roles.php" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label" for="search">Search</label>
                            <input type="text" class="form-control" id="search" name="search"
                                   value="<?php echo users_roles_h($listFilters['search']); ?>"
                                   placeholder="Staff number, name, email, or username">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="role">User Type / Role</label>
                            <select class="form-select" id="role" name="role">
                                <option value="">All internal roles</option>
                                <?php foreach (wuc_internal_staff_role_names() as $roleKey): ?>
                                    <option value="<?php echo users_roles_h($roleKey); ?>" <?php echo $listFilters['role'] === $roleKey ? 'selected' : ''; ?>>
                                        <?php echo users_roles_h(wuc_internal_staff_user_type_label($roleKey)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="department_id">Department</label>
                            <select class="form-select" id="department_id" name="department_id">
                                <option value="">All departments</option>
                                <?php foreach ($departmentsList as $d): ?>
                                    <option value="<?php echo (int)$d['id']; ?>" <?php echo (int)$listFilters['department_id'] === (int)$d['id'] ? 'selected' : ''; ?>>
                                        <?php echo users_roles_h($d['deptName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="status">Account Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All statuses</option>
                                <?php foreach (['active', 'inactive', 'suspended'] as $statusOpt): ?>
                                    <option value="<?php echo users_roles_h($statusOpt); ?>" <?php echo $listFilters['status'] === $statusOpt ? 'selected' : ''; ?>>
                                        <?php echo users_roles_h(ucfirst($statusOpt)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary me-2"><i class="fas fa-filter me-1"></i>Apply Filters</button>
                            <a href="users_roles.php" class="btn btn-outline-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row">
                <div class="col-md-7">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Internal Staff &amp; Lecturers</h6>
                            <span class="badge bg-secondary"><?php echo (int)$userTotal; ?> account(s)</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Staff No.</th>
                                            <th>Full Name</th>
                                            <th>User Type</th>
                                            <th>Role</th>
                                            <th>Department / Section</th>
                                            <th>Designation</th>
                                            <th>Status</th>
                                            <th>Last Login</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!$users): ?>
                                            <tr>
                                                <td colspan="9" class="text-center text-muted py-4">
                                                    No internal staff or lecturer accounts match your filters.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                        <?php foreach ($users as $u):
                                            $uRoles = getUserRoles($u['user_id']);
                                            $name = trim((string)($u['Fname'] ?? '') . ' ' . (string)($u['Lname'] ?? ''));
                                            $deptSection = trim((string)($u['department_name'] ?? ''));
                                            if (!empty($u['section_name'])) {
                                                $deptSection = $deptSection !== ''
                                                    ? $deptSection . ' / ' . $u['section_name']
                                                    : (string)$u['section_name'];
                                            }
                                        ?>
                                            <tr>
                                                <td><strong><?php echo users_roles_h($u['staff_id']); ?></strong></td>
                                                <td><?php echo users_roles_h($name); ?></td>
                                                <td><?php echo users_roles_h(wuc_internal_staff_user_type_label($u['primary_role'] ?? '')); ?></td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo users_roles_h($u['primary_role']); ?></span>
                                                    <?php foreach ($uRoles as $ur): ?>
                                                        <span class="badge bg-info text-dark mb-1"><?php echo users_roles_h($ur['role_label']); ?></span>
                                                    <?php endforeach; ?>
                                                </td>
                                                <td><?php echo users_roles_h($deptSection !== '' ? $deptSection : '—'); ?></td>
                                                <td><?php echo users_roles_h($u['designation'] ?? '—'); ?></td>
                                                <td>
                                                    <span class="badge <?php echo ($u['account_status'] ?? '') === 'active' ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                        <?php echo users_roles_h($u['account_status'] ?? 'unknown'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo users_roles_h($u['last_login'] ?: '—'); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-primary assign-role-btn"
                                                            data-userid="<?php echo (int)$u['user_id']; ?>"
                                                            data-username="<?php echo users_roles_h($u['username']); ?>"
                                                            data-bs-toggle="modal" data-bs-target="#assignRoleModal">
                                                        <i class="fas fa-plus-circle me-1"></i>Assign Role
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($userTotalPages > 1): ?>
                            <nav aria-label="Staff users pagination" class="mt-3">
                                <ul class="pagination pagination-sm mb-0">
                                    <?php for ($p = 1; $p <= $userTotalPages; $p++):
                                        $query = $_GET;
                                        $query['page'] = $p;
                                        $pageUrl = 'users_roles.php?' . http_build_query($query);
                                    ?>
                                        <li class="page-item <?php echo $p === $userPage ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo users_roles_h($pageUrl); ?>"><?php echo $p; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Revoke Staff / Lecturer Roles</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo users_roles_h($_SESSION['csrf_token'] ?? ''); ?>">
                                <input type="hidden" name="action" value="revoke_role">
                                <div class="mb-3">
                                    <label class="form-label">Select Staff User</label>
                                    <select class="form-select" name="user_id" id="revoke_user_select" required>
                                        <option value="">-- Choose Staff User --</option>
                                        <?php foreach ($staffUsersForSelect as $u): ?>
                                            <option value="<?php echo (int)$u['user_id']; ?>"><?php echo users_roles_h($u['staff_id'] . ' — ' . trim($u['Fname'] . ' ' . $u['Lname'])); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Select Role to Revoke</label>
                                    <select class="form-select" name="role_id" id="revoke_role_select" required>
                                        <option value="">-- Select Role --</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-danger w-100"><i class="fas fa-minus-circle me-2"></i>Revoke Role</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 2: Direct Module Access -->
        <div class="tab-pane fade" id="direct-pane" role="tabpanel">
            <div class="row">
                <div class="col-md-8">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Active Direct Access Rules</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Username</th>
                                            <th>Permission</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($directAccesses as $da): 
                                            $isExpired = false;
                                            if ($da['end_date'] && strtotime($da['end_date']) < time()) {
                                                $isExpired = true;
                                            }
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($da['username']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($da['permission_label']); ?></td>
                                                <td><?php echo $da['start_date'] ?: 'N/A'; ?></td>
                                                <td><?php echo $da['end_date'] ?: 'N/A'; ?></td>
                                                <td>
                                                    <?php if ($isExpired): ?>
                                                        <span class="badge bg-danger">Expired</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to revoke this direct access rule?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="action" value="revoke_direct_access">
                                                        <input type="hidden" name="access_id" value="<?php echo $da['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash-alt me-1"></i>Revoke</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Grant Direct Override</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="grant_direct_access">
                                <div class="mb-3">
                                    <label class="form-label">Staff User</label>
                                    <select class="form-select" name="user_id" required>
                                        <option value="">-- Select Staff User --</option>
                                        <?php foreach ($staffUsersForSelect as $u): ?>
                                            <option value="<?php echo (int)$u['user_id']; ?>"><?php echo users_roles_h($u['staff_id'] . ' — ' . trim($u['Fname'] . ' ' . $u['Lname'])); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Permission</label>
                                    <select class="form-select" name="permission_id" required>
                                        <option value="">-- Choose Permission --</option>
                                        <?php foreach ($permissionsList as $p): ?>
                                            <option value="<?php echo $p['permission_id']; ?>"><?php echo htmlspecialchars($p['permission_label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" class="form-control" name="start_date">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">End Date (Expiry)</label>
                                    <input type="date" class="form-control" name="end_date">
                                </div>
                                <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus-circle me-2"></i>Grant Access</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 3: Role Permissions -->
        <div class="tab-pane fade" id="perms-pane" role="tabpanel">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Role Module Permissions Matrix</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-end mb-4">
                        <div class="col-lg-5">
                            <label class="form-label">Select Role to Manage</label>
                            <select class="form-select" id="role_permission_select">
                                <option value="">-- Choose Role --</option>
                                <?php foreach ($rolesList as $r): ?>
                                    <option value="<?php echo $r['role_id']; ?>"><?php echo htmlspecialchars($r['role_label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label">Portal Scope</label>
                            <select class="form-select" id="role_permission_portal_select">
                                <option value="">Global (all portals)</option>
                                <?php foreach ($portalsList as $portal): ?>
                                    <option value="<?php echo (int)$portal['id']; ?>">
                                        <?php echo htmlspecialchars($portal['portal_name'] . ' (' . $portal['portal_code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <a href="portal_permission_scope.php" class="btn btn-outline-primary w-100">
                                <i class="fas fa-table-columns me-1"></i> Scope Overview
                            </a>
                        </div>
                    </div>

                    <form method="POST" action="" id="role_permissions_form" style="display:none;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="save_role_permissions">
                        <input type="hidden" name="role_id" id="form_role_id">
                        <input type="hidden" name="permission_portal_id" id="form_permission_portal_id">
                        <div class="alert alert-info py-2">
                            <i class="fas fa-circle-info me-1"></i>
                            Saving this matrix updates only the selected portal scope. Other portal scopes for this role are preserved.
                        </div>

                        <div class="row">
                            <?php foreach ($modulesList as $m): ?>
                                <div class="col-md-4 mb-4">
                                    <div class="card border-left-primary h-100 py-2">
                                        <div class="card-body">
                                            <h6 class="font-weight-bold text-primary mb-3">
                                                <i class="fas <?php echo $m['module_icon'] ?: 'fa-folder'; ?> me-2"></i><?php echo htmlspecialchars($m['module_name']); ?>
                                            </h6>
                                            <div class="permissions-checklist">
                                                <?php 
                                                $modKey = $m['module_key'];
                                                foreach ($permissionsList as $p) {
                                                    if (explode('.', $p['permission_key'])[0] === $modKey) {
                                                        $action = explode('.', $p['permission_key'])[1];
                                                        echo '<div class="form-check">';
                                                        echo '<input class="form-check-input perm-checkbox" type="checkbox" name="perms[]" value="'.$p['permission_id'].'" id="perm_'.$p['permission_id'].'">';
                                                        echo '<label class="form-check-label" for="perm_'.$p['permission_id'].'">'.htmlspecialchars($action).'</label>';
                                                        echo '</div>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-save me-2"></i>Save Permissions Matrix</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tab 4: Audit Logs -->
        <div class="tab-pane fade" id="audits-pane" role="tabpanel">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">System Access & Audit Log</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>User ID</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditLogs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($log['user_id']); ?></strong></td>
                                        <td><span class="badge bg-warning text-dark"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                        <td><small><?php echo htmlspecialchars($log['details']); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Assign Role -->
<div class="modal fade text-dark" id="assignRoleModal" tabindex="-1" aria-labelledby="assignRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo users_roles_h($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="action" value="assign_role">
            <input type="hidden" name="user_id" id="modal_user_id">
            <div class="modal-header">
                <h5 class="modal-title" id="assignRoleModalLabel">Assign Internal Staff Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Assigning internal role for staff user: <strong id="modal_username"></strong></p>
                <div class="mb-3">
                    <label class="form-label">Select Internal Role</label>
                    <select class="form-select" name="role_id" required>
                        <option value="">-- Select Role --</option>
                        <?php foreach ($rolesList as $r): ?>
                            <option value="<?php echo (int)$r['role_id']; ?>"><?php echo users_roles_h($r['role_label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Save Role Assignment</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Populate Assign Role Modal
    const assignRoleBtns = document.querySelectorAll('.assign-role-btn');
    assignRoleBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('modal_user_id').value = this.dataset.userid;
            document.getElementById('modal_username').textContent = this.dataset.username;
        });
    });

    // Populate Revoke Roles based on selected User
    const revokeUserSelect = document.getElementById('revoke_user_select');
    const revokeRoleSelect = document.getElementById('revoke_role_select');
    
    revokeUserSelect.addEventListener('change', function () {
        const userId = this.value;
        revokeRoleSelect.innerHTML = '<option value="">-- Select Role --</option>';
        if (userId === '') return;

        fetch('ajax_get_user_roles.php?user_id=' + encodeURIComponent(userId))
            .then(res => {
                if (!res.ok) {
                    throw new Error('Access denied for this account type.');
                }
                return res.json();
            })
            .then(roles => {
                if (roles.error) {
                    throw new Error(roles.error);
                }
                roles.forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.role_id;
                    opt.textContent = r.role_label;
                    revokeRoleSelect.appendChild(opt);
                });
            })
            .catch(() => {
                revokeRoleSelect.innerHTML = '<option value="">-- Not an internal staff account --</option>';
            });
    });

    // Role Permissions check matrix fetch
    const rolePermissionSelect = document.getElementById('role_permission_select');
    const rolePermissionPortalSelect = document.getElementById('role_permission_portal_select');
    const rolePermissionsForm = document.getElementById('role_permissions_form');
    const formRoleId = document.getElementById('form_role_id');
    const formPermissionPortalId = document.getElementById('form_permission_portal_id');
    const checkboxes = document.querySelectorAll('.perm-checkbox');

    function loadRolePermissionMatrix() {
        const roleId = rolePermissionSelect.value;
        const portalId = rolePermissionPortalSelect.value;
        if (roleId === '') {
            rolePermissionsForm.style.display = 'none';
            return;
        }

        formRoleId.value = roleId;
        formPermissionPortalId.value = portalId;
        checkboxes.forEach(cb => cb.checked = false);

        fetch('ajax_get_role_permissions.php?role_id=' + encodeURIComponent(roleId) + '&portal_id=' + encodeURIComponent(portalId))
            .then(res => res.json())
            .then(permIds => {
                permIds.forEach(id => {
                    const cb = document.getElementById('perm_' + id);
                    if (cb) cb.checked = true;
                });
                rolePermissionsForm.style.display = 'block';
            });
    }

    rolePermissionSelect.addEventListener('change', loadRolePermissionMatrix);
    rolePermissionPortalSelect.addEventListener('change', loadRolePermissionMatrix);
});
</script>

<?php require "includes/footer.php"; ?>
