<?php
/**
 * Portal Permission Scoping  (Multi-Portal Redesign — Phase 2)
 *
 * Operationalizes the portal-aware permission check added in Phase 1
 * (includes/permissions.php: wuc_has_permission_unified scopes role_permissions
 * by portal_id, treating NULL as global). This page lets a systems admin set the
 * portal scope of each role/module/permission grant:
 *
 *   Global         -> role_permissions.portal_id = NULL (applies in every portal)
 *   <a portal>      -> role_permissions.portal_id = that portal id (that portal only)
 *
 * Safe by construction: changing a row to "Global" can only widen access, and the
 * page never deletes grants. Only rows whose scope actually changed are written.
 *
 * The legacy admin permission editors (user_role_mgmt.php, update_permissions.php)
 * target the pre-migration PosID/permission_name columns and no longer work against
 * the migrated role_permissions table, so this page deliberately stands alone and
 * touches only the new RBAC columns.
 */

declare(strict_types=1);

// admin.php owns session startup, the admin session guard and $db.
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/audit.php'; // canonical audit_log_current_user()

$staffId = $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? '';

// Admin gate (systems_admin or admin_all). Done before any output so a denial
// or a POST redirect can send headers cleanly.
$isAdminUser = (isset($isAdmin) && $isAdmin) || hasPermission($staffId, 'admin_all');
if (!$isAdminUser) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><div style="font:16px/1.5 system-ui;max-width:640px;margin:4rem auto;padding:1rem 1.25rem;border:1px solid #f5c2c7;background:#f8d7da;color:#842029;border-radius:.5rem">'
        . 'Access denied. Portal permission scoping is restricted to systems administrators.'
        . '</div>';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Load active portals once (used for validation and the dropdowns).
$portals = [];
$res = $db->query("SELECT id, portal_code, portal_name FROM portals WHERE status = 'active' ORDER BY FIELD(portal_code,'academic','elearning','library','applicant','alumni','employer'), portal_name");
while ($res && ($row = $res->fetch_assoc())) {
    $portals[(int)$row['id']] = $row['portal_name'] . ' (' . $row['portal_code'] . ')';
}
if ($res) { $res->free(); }

// ---- POST: apply scope changes (before any HTML output) -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $posted)) {
        $_SESSION['error_message'] = 'Security token mismatch. Please try again.';
        header('Location: portal_permission_scope.php');
        exit;
    }

    $scopes = isset($_POST['scope']) && is_array($_POST['scope']) ? $_POST['scope'] : [];
    $origs  = isset($_POST['orig'])  && is_array($_POST['orig'])  ? $_POST['orig']  : [];

    $changed = 0;
    $invalid = 0;
    $db->begin_transaction();
    try {
        foreach ($scopes as $rpId => $value) {
            $rpId  = (int)$rpId;
            if ($rpId <= 0) { continue; }

            $value = (string)$value;            // '' = Global, otherwise a portal id
            $orig  = (string)($origs[$rpId] ?? '');
            if ($value === $orig) { continue; } // unchanged — skip

            if ($value === '') {
                $portalId = null;               // Global
            } elseif (isset($portals[(int)$value])) {
                $portalId = (int)$value;        // valid, active portal
            } else {
                $invalid++;                     // ignore unknown portal ids
                continue;
            }

            if ($portalId === null) {
                $update = $db->prepare("UPDATE role_permissions SET portal_id = NULL WHERE id = ?");
                $update->bind_param('i', $rpId);
            } else {
                $update = $db->prepare("UPDATE role_permissions SET portal_id = ? WHERE id = ?");
                $update->bind_param('ii', $portalId, $rpId);
            }
            $update->execute();
            if ($db->affected_rows !== 0) {
                $changed++;
            }
            $update->close();
        }
        $db->commit();

        if ($changed > 0) {
            audit_log_current_user($db, 'permission_scope.update', [
                'status'       => 'success',
                'rescoped'     => $changed,
                'invalid'      => $invalid,
                'role_id'      => (isset($_POST['role_id']) && ctype_digit((string)$_POST['role_id'])) ? (int)$_POST['role_id'] : null,
            ]);
        }

        $msg = $changed === 1 ? '1 permission re-scoped.' : "{$changed} permissions re-scoped.";
        if ($invalid > 0) { $msg .= " {$invalid} ignored (invalid portal)."; }
        $_SESSION['success_message'] = $msg;
    } catch (Throwable $e) {
        $db->rollback();
        error_log('portal_permission_scope update failed: ' . $e->getMessage());
        $_SESSION['error_message'] = 'Could not save changes. ' .
            (ini_get('display_errors') ? $e->getMessage() : 'Please try again.');
    }

    // Preserve the role filter across the redirect.
    $back = 'portal_permission_scope.php';
    if (isset($_POST['role_id']) && ctype_digit((string)$_POST['role_id'])) {
        $back .= '?role_id=' . (int)$_POST['role_id'];
    }
    header('Location: ' . $back);
    exit;
}

// ---- GET: build the table -------------------------------------------------
$flashSuccess = $_SESSION['success_message'] ?? null; unset($_SESSION['success_message']);
$flashError   = $_SESSION['error_message']   ?? null; unset($_SESSION['error_message']);

$roleFilter = (isset($_GET['role_id']) && ctype_digit((string)$_GET['role_id'])) ? (int)$_GET['role_id'] : 0;

// Roles for the filter dropdown.
$roles = [];
$res = $db->query("SELECT role_id, role_name FROM roles WHERE status = 'active' ORDER BY role_name");
while ($res && ($row = $res->fetch_assoc())) { $roles[] = $row; }
if ($res) { $res->free(); }

// The grants themselves.
$grantsSql = "
    SELECT rp.id, rp.portal_id,
           r.role_name, m.module_key, m.module_name, p.permission_key
      FROM role_permissions rp
      JOIN roles r       ON r.role_id = rp.role_id
      JOIN modules m     ON m.module_id = rp.module_id
      JOIN permissions p ON p.permission_id = rp.permission_id
     WHERE rp.status = 'active'
       " . ($roleFilter > 0 ? "AND rp.role_id = ?" : "") . "
     ORDER BY r.role_name, m.module_name, p.permission_key";
$grants = [];
if ($stmt = $db->prepare($grantsSql)) {
    if ($roleFilter > 0) { $stmt->bind_param('i', $roleFilter); }
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) { $grants[] = $row; }
    $stmt->close();
}

$page_title = 'Portal Permission Scoping';
require_once __DIR__ . '/includes/header.php';

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="container-fluid px-4">
    <h1 class="mt-4">Portal Permission Scoping</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Portal Permission Scoping</li>
    </ol>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $h($flashSuccess); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $h($flashError); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Global</strong> permissions apply in every portal. Choosing a specific portal
        restricts the grant to that portal only — the user must be operating inside it for the
        permission to count. Only set a portal once that portal's pages establish portal context
        (eLearning already does; <em>library does not yet</em>).
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-shield-halved me-1"></i> Role / Module / Permission scope</span>
            <form class="d-flex align-items-center gap-2" method="get" action="portal_permission_scope.php">
                <label for="role_id" class="form-label mb-0 small text-muted">Filter by role</label>
                <select class="form-select form-select-sm" id="role_id" name="role_id" onchange="this.form.submit()" style="width:auto">
                    <option value="0">All roles</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo (int)$role['role_id']; ?>" <?php echo $roleFilter === (int)$role['role_id'] ? 'selected' : ''; ?>>
                            <?php echo $h($role['role_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-sm btn-outline-secondary" type="submit">Go</button></noscript>
            </form>
        </div>
        <div class="card-body">
            <?php if (empty($grants)): ?>
                <p class="text-muted mb-0">No active permission grants found<?php echo $roleFilter ? ' for this role' : ''; ?>.</p>
            <?php else: ?>
            <form method="post" action="portal_permission_scope.php">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                <input type="hidden" name="role_id" value="<?php echo (int)$roleFilter; ?>">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Role</th>
                                <th scope="col">Module</th>
                                <th scope="col">Permission</th>
                                <th scope="col" style="min-width:220px">Portal scope</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grants as $g):
                                $id = (int)$g['id'];
                                $cur = $g['portal_id'] === null ? '' : (string)(int)$g['portal_id'];
                            ?>
                            <tr>
                                <td><?php echo $h($g['role_name']); ?></td>
                                <td><?php echo $h($g['module_name']); ?></td>
                                <td><code><?php echo $h($g['permission_key']); ?></code></td>
                                <td>
                                    <input type="hidden" name="orig[<?php echo $id; ?>]" value="<?php echo $h($cur); ?>">
                                    <select class="form-select form-select-sm" name="scope[<?php echo $id; ?>]">
                                        <option value="" <?php echo $cur === '' ? 'selected' : ''; ?>>Global (all portals)</option>
                                        <?php foreach ($portals as $pid => $plabel): ?>
                                            <option value="<?php echo (int)$pid; ?>" <?php echo $cur === (string)$pid ? 'selected' : ''; ?>>
                                                <?php echo $h($plabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save scope changes
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
