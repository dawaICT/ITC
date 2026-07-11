<?php
declare(strict_types=1);

/**
 * Portal Access Management  (canonical owner of `user_portal_access`)
 * ------------------------------------------------------------------
 * DOES: control which portals (Academic, eLearning, Library, Applicant,
 *       Alumni, Employer) a single user account may enter, by granting or
 *       disabling per-user user_portal_access rows.
 * DOES NOT: assign roles, define/assign permissions, or scope permissions —
 *       use users_roles.php (roles + per-user access) and
 *       portal_permission_scope.php (role-permission portal scope) for those.
 * Access: systems admins (checkAdminAuth) or settings-capable staff.
 */

$page_title = 'Portal Access Management';
require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();

require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/audit.php';
require_once dirname(__DIR__) . '/includes/portal_access.php';

if (!function_exists('portal_access_h')) {
    function portal_access_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('portal_access_valid_date')) {
    function portal_access_valid_date(?string $value): string|false|null
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        return ($dt && $dt->format('Y-m-d') === $value) ? $value : false;
    }
}

if (!function_exists('portal_access_user_label')) {
    function portal_access_user_label(array $user): string
    {
        $name = trim((string)($user['staff_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($user['student_name'] ?? ''));
        }
        if ($name === '') {
            $name = (string)($user['username'] ?? 'User');
        }
        return $name;
    }
}

$canManagePortalAccess = (function_exists('isSystemsAdmin') && isSystemsAdmin())
    || (function_exists('canAccessSettings') && canAccessSettings());

if (!$canManagePortalAccess) {
    $_SESSION['errorMessage'] = 'You do not have permission to access this page.';
    header('Location: index.php');
    exit;
}

$csrfToken = wuc_csrf_token();

$portalRows = [];
$portalById = [];
$portalResult = $db->query("SELECT id, portal_code, portal_name, description, status FROM portals ORDER BY FIELD(portal_code, 'academic', 'elearning', 'library', 'applicant', 'alumni', 'employer'), portal_name ASC");
while ($portal = $portalResult->fetch_assoc()) {
    $portal['id'] = (int)$portal['id'];
    $portalRows[] = $portal;
    $portalById[$portal['id']] = $portal;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Defense-in-depth: re-assert the admin/settings privilege on the write path,
    // not only at page load, before mutating user_portal_access.
    $stillCanManage = (function_exists('isSystemsAdmin') && isSystemsAdmin())
        || (function_exists('canAccessSettings') && canAccessSettings());
    if (!$stillCanManage) {
        http_response_code(403);
        $_SESSION['errorMessage'] = 'You do not have permission to modify portal access.';
        header('Location: index.php');
        exit;
    }

    $userId = (int)($_POST['user_id'] ?? 0);
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['errorMessage'] = 'Your session token expired. Please try again.';
        header('Location: portal_access.php' . ($userId > 0 ? '?user_id=' . $userId : ''));
        exit;
    }

    $userExists = false;
    if ($userId > 0) {
        $stmt = $db->prepare('SELECT user_id FROM users WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $userExists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
    }

    if (!$userExists) {
        $_SESSION['errorMessage'] = 'Select a valid user before updating portal access.';
        header('Location: portal_access.php');
        exit;
    }

    $selectedPortalIds = array_map('intval', (array)($_POST['portal_ids'] ?? []));
    $selectedPortalIds = array_values(array_unique(array_filter($selectedPortalIds, static fn($id) => isset($portalById[$id]))));
    $selectedLookup = array_fill_keys($selectedPortalIds, true);
    $startDates = (array)($_POST['start_date'] ?? []);
    $endDates = (array)($_POST['end_date'] ?? []);
    $assignedBy = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'admin');
    $errors = [];

    foreach ($selectedPortalIds as $portalId) {
        $start = portal_access_valid_date($startDates[$portalId] ?? null);
        $end = portal_access_valid_date($endDates[$portalId] ?? null);
        if ($start === false || $end === false) {
            $errors[] = 'Use YYYY-MM-DD for portal start and end dates.';
            break;
        }
        if ($start !== null && $end !== null && strcmp($end, $start) < 0) {
            $errors[] = 'Portal access end date cannot be earlier than the start date.';
            break;
        }
    }

    if (empty($errors)) {
        $db->begin_transaction();
        try {
            foreach ($portalRows as $portal) {
                $portalId = (int)$portal['id'];
                if (isset($selectedLookup[$portalId])) {
                    $start = portal_access_valid_date($startDates[$portalId] ?? null);
                    $end = portal_access_valid_date($endDates[$portalId] ?? null);
                    $stmt = $db->prepare(
                        "INSERT INTO user_portal_access (user_id, portal_id, access_status, start_date, end_date, assigned_by)
                         VALUES (?, ?, 'active', ?, ?, ?)
                         ON DUPLICATE KEY UPDATE access_status = 'active', start_date = VALUES(start_date), end_date = VALUES(end_date), assigned_by = VALUES(assigned_by), updated_at = NOW()"
                    );
                    $stmt->bind_param('iisss', $userId, $portalId, $start, $end, $assignedBy);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $db->prepare("UPDATE user_portal_access SET access_status = 'disabled', updated_at = NOW() WHERE user_id = ? AND portal_id = ?");
                    $stmt->bind_param('ii', $userId, $portalId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $db->commit();
            audit_log_current_user($db, 'portal_access.update', [
                'target_user_id' => $userId,
                'active_portal_ids' => $selectedPortalIds,
            ]);
            $_SESSION['successMessage'] = 'Portal access updated successfully.';
        } catch (Throwable $e) {
            $db->rollback();
            error_log('Portal access update failed: ' . $e->getMessage());
            $_SESSION['errorMessage'] = 'Portal access could not be updated. Please try again.';
        }
    } else {
        $_SESSION['errorMessage'] = implode(' ', $errors);
    }

    header('Location: portal_access.php?user_id=' . $userId);
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$selectedUserId = (int)($_GET['user_id'] ?? 0);

$users = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $db->prepare(
        "SELECT u.user_id, u.username, u.primary_role, u.staff_id, u.student_id, u.status,
                CONCAT_WS(' ', s.Fname, s.Lname) AS staff_name,
                CONCAT_WS(' ', st.Fname, st.Lname) AS student_name
         FROM users u
         LEFT JOIN staff s ON s.staff_id = u.staff_id
         LEFT JOIN students st ON st.SID = u.student_id
         WHERE u.username LIKE ? OR u.staff_id LIKE ? OR u.student_id LIKE ? OR s.Fname LIKE ? OR s.Lname LIKE ? OR st.Fname LIKE ? OR st.Lname LIKE ?
         ORDER BY u.username ASC
         LIMIT 60"
    );
    $stmt->bind_param('sssssss', $like, $like, $like, $like, $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
} else {
    $result = $db->query(
        "SELECT u.user_id, u.username, u.primary_role, u.staff_id, u.student_id, u.status,
                CONCAT_WS(' ', s.Fname, s.Lname) AS staff_name,
                CONCAT_WS(' ', st.Fname, st.Lname) AS student_name
         FROM users u
         LEFT JOIN staff s ON s.staff_id = u.staff_id
         LEFT JOIN students st ON st.SID = u.student_id
         ORDER BY u.updated_at DESC, u.username ASC
         LIMIT 60"
    );
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

if ($selectedUserId <= 0 && !empty($users)) {
    $selectedUserId = (int)$users[0]['user_id'];
}

$selectedUser = null;
if ($selectedUserId > 0) {
    $stmt = $db->prepare(
        "SELECT u.user_id, u.username, u.primary_role, u.staff_id, u.student_id, u.status,
                CONCAT_WS(' ', s.Fname, s.Lname) AS staff_name,
                CONCAT_WS(' ', st.Fname, st.Lname) AS student_name
         FROM users u
         LEFT JOIN staff s ON s.staff_id = u.staff_id
         LEFT JOIN students st ON st.SID = u.student_id
         WHERE u.user_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $selectedUserId);
    $stmt->execute();
    $selectedUser = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$accessByPortal = [];
if ($selectedUser) {
    $stmt = $db->prepare(
        "SELECT upa.*, p.portal_code, p.portal_name
         FROM user_portal_access upa
         INNER JOIN portals p ON p.id = upa.portal_id
         WHERE upa.user_id = ?
         ORDER BY p.portal_name ASC"
    );
    $stmt->bind_param('i', $selectedUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accessByPortal[(int)$row['portal_id']] = $row;
    }
    $stmt->close();
}

$portalStats = [];
$result = $db->query(
    "SELECT p.portal_code, COUNT(upa.id) AS total_active
     FROM portals p
     LEFT JOIN user_portal_access upa ON upa.portal_id = p.id
        AND upa.access_status = 'active'
        AND (upa.start_date IS NULL OR upa.start_date <= CURDATE())
        AND (upa.end_date IS NULL OR upa.end_date >= CURDATE())
     GROUP BY p.portal_code"
);
while ($row = $result->fetch_assoc()) {
    $portalStats[$row['portal_code']] = (int)$row['total_active'];
}

require 'includes/nav.php';
?>

<style>
    .portal-access-card { border: 1px solid #e3e6f0; border-radius: 8px; }
    .portal-access-user { border-radius: 8px; border: 1px solid transparent; }
    .portal-access-user.active { border-color: #6f42c1; background: #f6f1ff; }
    .portal-access-toggle { width: 3rem; height: 1.5rem; }
</style>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-900">Portal Access Management</h1>
            <p class="text-muted mb-0">Assign Academic, eLearning, Library, Applicant, Alumni, and Employer portals to a single shared user identity.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Academic: <?php echo (int)($portalStats['academic'] ?? 0); ?></span>
            <span class="badge bg-success-subtle text-success border border-success-subtle">eLearning: <?php echo (int)($portalStats['elearning'] ?? 0); ?></span>
        </div>
    </div>

    <?php if (!empty($_SESSION['successMessage'])): ?>
        <div class="alert alert-success"><?php echo portal_access_h($_SESSION['successMessage']); unset($_SESSION['successMessage']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['errorMessage'])): ?>
        <div class="alert alert-danger"><?php echo portal_access_h($_SESSION['errorMessage']); unset($_SESSION['errorMessage']); ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <form method="get" class="d-flex gap-2">
                        <input type="search" name="q" value="<?php echo portal_access_h($search); ?>" class="form-control" placeholder="Search users, staff ID, student ID">
                        <button type="submit" class="btn btn-primary" title="Search users">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
                <div class="card-body p-2" style="max-height: 640px; overflow-y: auto;">
                    <?php if (empty($users)): ?>
                        <div class="text-center text-muted py-5">No users matched your search.</div>
                    <?php endif; ?>
                    <?php foreach ($users as $user): ?>
                        <?php
                        $isActive = (int)$user['user_id'] === $selectedUserId;
                        $params = ['user_id' => (int)$user['user_id']];
                        if ($search !== '') {
                            $params['q'] = $search;
                        }
                        ?>
                        <a class="portal-access-user d-block text-decoration-none p-3 mb-2 <?php echo $isActive ? 'active' : 'bg-white'; ?>"
                           href="portal_access.php?<?php echo http_build_query($params); ?>">
                            <div class="d-flex justify-content-between gap-2">
                                <strong class="text-dark"><?php echo portal_access_h(portal_access_user_label($user)); ?></strong>
                                <span class="badge <?php echo strtolower((string)$user['status']) === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo portal_access_h($user['status']); ?>
                                </span>
                            </div>
                            <div class="small text-muted"><?php echo portal_access_h($user['username']); ?> · <?php echo portal_access_h($user['primary_role']); ?></div>
                            <div class="small text-muted">
                                <?php if (!empty($user['staff_id'])): ?>Staff: <?php echo portal_access_h($user['staff_id']); ?><?php endif; ?>
                                <?php if (!empty($user['student_id'])): ?><?php echo !empty($user['staff_id']) ? ' · ' : ''; ?>Student: <?php echo portal_access_h($user['student_id']); ?><?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex flex-column flex-lg-row justify-content-between gap-2">
                    <div>
                        <h2 class="h5 mb-1">Portal Access</h2>
                        <?php if ($selectedUser): ?>
                            <div class="text-muted small">
                                <?php echo portal_access_h(portal_access_user_label($selectedUser)); ?>
                                · <?php echo portal_access_h($selectedUser['username']); ?>
                                · <?php echo portal_access_h($selectedUser['primary_role']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <a href="/wucportal/portal_selection.php" class="btn btn-outline-secondary btn-sm align-self-start">
                        <i class="fas fa-table-columns me-1"></i> Portal Selector
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!$selectedUser): ?>
                        <div class="text-center text-muted py-5">Select a user to manage portal access.</div>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo portal_access_h($csrfToken); ?>">
                            <input type="hidden" name="user_id" value="<?php echo (int)$selectedUser['user_id']; ?>">

                            <div class="row g-3">
                                <?php foreach ($portalRows as $portal): ?>
                                    <?php
                                    $portalId = (int)$portal['id'];
                                    $access = $accessByPortal[$portalId] ?? null;
                                    $isEnabled = $access && ($access['access_status'] ?? '') === 'active';
                                    $startDate = $access['start_date'] ?? '';
                                    $endDate = $access['end_date'] ?? '';
                                    ?>
                                    <div class="col-lg-6">
                                        <div class="portal-access-card p-3 h-100">
                                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                                <div>
                                                    <h3 class="h6 mb-1"><?php echo portal_access_h($portal['portal_name']); ?></h3>
                                                    <div class="small text-muted"><?php echo portal_access_h($portal['description'] ?? ''); ?></div>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input portal-access-toggle" type="checkbox" role="switch"
                                                           name="portal_ids[]" value="<?php echo $portalId; ?>"
                                                           id="portal_<?php echo $portalId; ?>" <?php echo $isEnabled ? 'checked' : ''; ?>>
                                                    <label class="visually-hidden" for="portal_<?php echo $portalId; ?>">
                                                        Enable <?php echo portal_access_h($portal['portal_name']); ?>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="row g-2">
                                                <div class="col-sm-6">
                                                    <label class="form-label small text-muted" for="start_<?php echo $portalId; ?>">Start date</label>
                                                    <input type="date" class="form-control form-control-sm" id="start_<?php echo $portalId; ?>"
                                                           name="start_date[<?php echo $portalId; ?>]" value="<?php echo portal_access_h($startDate); ?>">
                                                </div>
                                                <div class="col-sm-6">
                                                    <label class="form-label small text-muted" for="end_<?php echo $portalId; ?>">End date</label>
                                                    <input type="date" class="form-control form-control-sm" id="end_<?php echo $portalId; ?>"
                                                           name="end_date[<?php echo $portalId; ?>]" value="<?php echo portal_access_h($endDate); ?>">
                                                </div>
                                            </div>
                                            <div class="small text-muted mt-3">
                                                Status:
                                                <?php if ($isEnabled): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php elseif ($access): ?>
                                                    <span class="badge bg-secondary"><?php echo portal_access_h($access['access_status']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark border">Not assigned</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="d-flex justify-content-end mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Save Portal Access
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
