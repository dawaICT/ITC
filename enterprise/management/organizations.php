<?php
declare(strict_types=1);

$page_title = 'Organizations';
$activeNav = 'mgmt_orgs';
$epGuardMode = 'management';
$epNav = 'management';
require_once dirname(__DIR__) . '/includes/guard.php';

if (!ep_can_manage_organizations($db)) {
    $_SESSION['flash_error'] = 'Permission denied.';
    wuc_redirect('/wucportal/enterprise/management/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ep_require_post_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        $res = ep_create_organization($db, [
            'org_name' => trim((string)($_POST['org_name'] ?? '')),
            'org_type' => trim((string)($_POST['org_type'] ?? '')),
            'org_code' => trim((string)($_POST['org_code'] ?? '')),
            'province' => trim((string)($_POST['province'] ?? '')),
            'district' => trim((string)($_POST['district'] ?? '')),
            'contact_email' => trim((string)($_POST['contact_email'] ?? '')),
            'contact_phone' => trim((string)($_POST['contact_phone'] ?? '')),
        ]);
        if (!empty($res['ok'])) {
            $_SESSION['flash_success'] = 'Organization created: ' . ($res['org_code'] ?? '');
        } else {
            $_SESSION['flash_error'] = $res['message'] ?? 'Create failed.';
        }
    }
    if ($action === 'link_user') {
        $res = ep_link_user_to_organization(
            $db,
            (int)($_POST['organization_id'] ?? 0),
            (int)($_POST['user_id'] ?? 0),
            trim((string)($_POST['role_in_org'] ?? 'officer'))
        );
        $_SESSION[$res['ok'] ? 'flash_success' : 'flash_error'] = $res['ok']
            ? 'User linked to organization.'
            : ($res['message'] ?? 'Link failed.');
    }
    wuc_redirect('/wucportal/enterprise/management/organizations.php');
}

$organizations = [];
if (ep_organizations_table_ready($db)) {
    $res = $db->query("SELECT o.*, (SELECT COUNT(*) FROM enterprise_organization_users u WHERE u.organization_id = o.id AND u.status='active') AS officer_count
        FROM enterprise_organizations o ORDER BY o.org_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $organizations[] = $row;
        }
    }
}
$types = ep_organization_types();

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="alert alert-info small">
    Multi-organization workspaces for <?= ep_h(ep_platform_product_name()) ?>.
    Each cooperative, institution, employer or buyer can operate in an isolated workspace while sharing the same platform.
</div>

<div class="ep-card mb-3">
    <h2 class="h6">Register organization</h2>
    <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="org_name" required></div>
        <div class="col-md-3"><label class="form-label">Type</label>
            <select class="form-select" name="org_type" required>
                <?php foreach ($types as $t): ?><option value="<?= ep_h($t) ?>"><?= ep_h(str_replace('_', ' ', $t)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label">Code (optional)</label><input class="form-control" name="org_code" placeholder="auto"></div>
        <div class="col-md-2"><label class="form-label">Province</label><input class="form-control" name="province"></div>
        <div class="col-md-2"><label class="form-label">District</label><input class="form-control" name="district"></div>
        <div class="col-md-3"><label class="form-label">Contact email</label><input class="form-control" type="email" name="contact_email"></div>
        <div class="col-md-3"><label class="form-label">Contact phone</label><input class="form-control" name="contact_phone"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Create organization</button></div>
    </form>
</div>

<div class="ep-card mb-3">
    <h2 class="h6">Link officer to organization</h2>
    <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
        <input type="hidden" name="action" value="link_user">
        <div class="col-md-4"><label class="form-label">Organization</label>
            <select class="form-select" name="organization_id" required>
                <?php foreach ($organizations as $o): ?>
                    <option value="<?= (int)$o['id'] ?>"><?= ep_h((string)$o['org_name']) ?> (<?= ep_h((string)$o['org_code']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label">Portal user ID</label><input class="form-control" type="number" min="1" name="user_id" required></div>
        <div class="col-md-3"><label class="form-label">Role in org</label>
            <select class="form-select" name="role_in_org">
                <option value="officer">Officer</option>
                <option value="admin">Admin</option>
                <option value="agent">Field agent</option>
                <option value="cooperative_admin">Cooperative admin</option>
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-primary btn-sm" type="submit">Link user</button></div>
    </form>
</div>

<div class="ep-card">
    <h2 class="h6">Organizations</h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Location</th><th>Officers</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($organizations as $o): ?>
                <tr>
                    <td><?= ep_h((string)$o['org_code']) ?></td>
                    <td><?= ep_h((string)$o['org_name']) ?></td>
                    <td><?= ep_h(str_replace('_', ' ', (string)$o['org_type'])) ?></td>
                    <td><?= ep_h(trim(($o['district'] ?? '') . ', ' . ($o['province'] ?? ''), ', ')) ?></td>
                    <td><?= (int)($o['officer_count'] ?? 0) ?></td>
                    <td>
                        <form method="post" action="/wucportal/enterprise/switch_workspace.php" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= ep_h($csrfToken) ?>">
                            <input type="hidden" name="organization_id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="return_url" value="/wucportal/enterprise/management/organizations.php">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Open workspace</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
