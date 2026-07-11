<?php
/**
 * Sponsorship Management — configuration hub.
 *
 * One configurable framework for all funding sources. Admins manage:
 *   1) Sponsor TYPES (Self, CDF, TEVETA, … add more with no code change)
 *   2) Programme ELIGIBILITY (which programmes accept which sponsor types,
 *      with caps + requirements)
 *
 * Backed by includes/sponsorship_helpers.php + the sponsor_types /
 * programme_sponsorship_eligibility tables.
 */
ob_start();
session_start();
if (!defined('IS_SCRIPT')) { define('IS_SCRIPT', true); }

require_once __DIR__ . '/includes/admin.php';                 // guard + $db + role helpers
require_once dirname(__DIR__) . '/includes/finance_helpers.php';   // log_audit
require_once dirname(__DIR__) . '/includes/sponsorship_helpers.php';

if (!function_exists('spm_h')) {
    function spm_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// Page-level authorisation: systems admin, finance, or admissions staff.
$canManage = (function_exists('isSystemsAdmin') && isSystemsAdmin())
    || (function_exists('canAccessFinance') && canAccessFinance())
    || (function_exists('canAccessAdmissions') && canAccessAdmissions());

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf  = $_SESSION['csrf_token'];
$actor = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'admin');

$schemaReady = sponsorship_schema_ready($db);
$activeTab = in_array(($_GET['tab'] ?? ''), ['types', 'eligibility'], true) ? $_GET['tab'] : 'types';

// ── POST (Post/Redirect/Get) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectTab = $activeTab;
    $ok = false; $msg = '';
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $msg = 'Security validation failed. Please refresh and try again.';
    } elseif (!$canManage) {
        $msg = 'You do not have permission to manage sponsorships.';
    } elseif (!$schemaReady) {
        $msg = 'Sponsorship module is not installed. Apply migrations/2026_sponsorship_management.sql.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        switch ($action) {
            case 'add_sponsor_type':
                $r = create_sponsor_type($db, [
                    'code' => $_POST['code'] ?? '', 'name' => $_POST['name'] ?? '',
                    'description' => $_POST['description'] ?? '',
                    'requires_approval' => isset($_POST['requires_approval']),
                    'is_self_funded' => isset($_POST['is_self_funded']),
                    'sort_order' => $_POST['sort_order'] ?? 0,
                ], $actor);
                $ok = $r['success']; $msg = $ok ? 'Sponsor type added.' : ($r['error'] ?? 'Failed to add type.');
                $redirectTab = 'types';
                break;
            case 'update_sponsor_type':
                $r = update_sponsor_type($db, (int)($_POST['id'] ?? 0), [
                    'name' => $_POST['name'] ?? '', 'description' => $_POST['description'] ?? '',
                    'requires_approval' => isset($_POST['requires_approval']),
                    'is_self_funded' => isset($_POST['is_self_funded']),
                    'sort_order' => $_POST['sort_order'] ?? 0,
                ], $actor);
                $ok = $r['success']; $msg = $ok ? 'Sponsor type updated.' : ($r['error'] ?? 'Failed to update.');
                $redirectTab = 'types';
                break;
            case 'toggle_sponsor_type':
                $r = set_sponsor_type_active($db, (int)($_POST['id'] ?? 0), !empty($_POST['activate']), $actor);
                $ok = $r['success']; $msg = $ok ? 'Sponsor type updated.' : ($r['error'] ?? 'Failed.');
                $redirectTab = 'types';
                break;
            case 'set_eligibility':
                $r = set_programme_eligibility($db, [
                    'program_code' => $_POST['program_code'] ?? '',
                    'sponsor_type_id' => $_POST['sponsor_type_id'] ?? 0,
                    'max_sponsored_students' => $_POST['max_sponsored_students'] ?? '',
                    'sponsorship_requirements' => $_POST['sponsorship_requirements'] ?? '',
                    'academic_requirements' => $_POST['academic_requirements'] ?? '',
                    'intake_restrictions' => $_POST['intake_restrictions'] ?? '',
                    'is_active' => 1,
                ], $actor);
                $ok = $r['success']; $msg = $ok ? 'Programme eligibility saved.' : ($r['error'] ?? 'Failed.');
                $redirectTab = 'eligibility';
                $redirectProg = (string)($_POST['program_code'] ?? '');
                break;
            case 'toggle_eligibility':
                $r = set_programme_eligibility($db, [
                    'program_code' => $_POST['program_code'] ?? '',
                    'sponsor_type_id' => $_POST['sponsor_type_id'] ?? 0,
                    'is_active' => !empty($_POST['activate']) ? 1 : 0,
                ], $actor);
                $ok = $r['success']; $msg = $ok ? 'Eligibility updated.' : ($r['error'] ?? 'Failed.');
                $redirectTab = 'eligibility';
                $redirectProg = (string)($_POST['program_code'] ?? '');
                break;
            default:
                $msg = 'Unknown action.';
        }
    }
    $_SESSION['spm_flash'] = ['ok' => $ok, 'msg' => $msg];
    $q = 'tab=' . urlencode($redirectTab);
    if (!empty($redirectProg)) { $q .= '&prog=' . urlencode($redirectProg); }
    header('Location: sponsorship_management.php?' . $q);
    exit;
}

$flash = $_SESSION['spm_flash'] ?? null;
unset($_SESSION['spm_flash']);

// ── Data for rendering ──────────────────────────────────────────────────────
$sponsorTypes = $schemaReady ? get_sponsor_types($db, false) : [];
$editType = null;
if ($schemaReady && !empty($_GET['edit_type'])) {
    $editType = get_sponsor_type($db, (int)$_GET['edit_type']);
}

$programs = [];
if ($res = @$db->query("SELECT program_code, program_name FROM programs WHERE COALESCE(is_active,1)=1 ORDER BY program_code")) {
    while ($r = $res->fetch_assoc()) { $programs[] = $r; }
    $res->free();
}
$selectedProg = isset($_GET['prog']) ? trim((string)$_GET['prog']) : '';
$progOptions = ($schemaReady && $selectedProg !== '') ? get_programme_sponsor_options($db, $selectedProg, false) : [];
$progEligibleTypeIds = array_map(static fn($o) => (int)$o['sponsor_type_id'], $progOptions);

require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 fw-bold text-primary mb-1"><i class="fas fa-hand-holding-dollar me-2"></i>Sponsorship Management</h1>
            <p class="text-muted mb-0">Configure sponsor types and per-programme funding eligibility — no code changes required.</p>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['ok'] ? 'success' : 'danger'; ?> alert-dismissible fade show">
            <i class="fas fa-<?php echo $flash['ok'] ? 'circle-check' : 'triangle-exclamation'; ?> me-2"></i>
            <?php echo spm_h($flash['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$schemaReady): ?>
        <div class="alert alert-warning">
            <strong>Sponsorship module not installed.</strong> Apply the migration as a DDL-capable user:
            <pre class="mb-0 mt-2 small">mysql -h 127.0.0.1 --protocol=TCP -u root wucportal &lt; migrations/2026_sponsorship_management.sql</pre>
        </div>
    <?php elseif (!$canManage): ?>
        <div class="alert alert-danger">You do not have permission to manage sponsorships.</div>
    <?php else: ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'types' ? 'active' : ''; ?>" href="?tab=types">
                <i class="fas fa-tags me-1"></i>Sponsor Types
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'eligibility' ? 'active' : ''; ?>" href="?tab=eligibility">
                <i class="fas fa-graduation-cap me-1"></i>Programme Eligibility
            </a>
        </li>
    </ul>

    <?php if ($activeTab === 'types'): ?>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><?php echo $editType ? 'Edit' : 'Add'; ?> Sponsor Type</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo spm_h($csrf); ?>">
                        <input type="hidden" name="action" value="<?php echo $editType ? 'update_sponsor_type' : 'add_sponsor_type'; ?>">
                        <?php if ($editType): ?><input type="hidden" name="id" value="<?php echo (int)$editType['id']; ?>"><?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Code <span class="text-muted small">(lowercase, no spaces)</span></label>
                            <input class="form-control" name="code" value="<?php echo spm_h($editType['code'] ?? ''); ?>"
                                   <?php echo $editType ? 'disabled' : 'required'; ?> placeholder="e.g. rotary_club">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Name</label>
                            <input class="form-control" name="name" value="<?php echo spm_h($editType['name'] ?? ''); ?>" required placeholder="e.g. Rotary Club Bursary">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea class="form-control" name="description" rows="2"><?php echo spm_h($editType['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="requires_approval" id="ra" <?php echo (!$editType || (int)$editType['requires_approval'] === 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="ra">Requires approval</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_self_funded" id="sf" <?php echo ($editType && (int)$editType['is_self_funded'] === 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="sf">Self-funded (student pays 100%)</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Sort order</label>
                            <input class="form-control" type="number" name="sort_order" value="<?php echo (int)($editType['sort_order'] ?? 0); ?>">
                        </div>
                        <button class="btn btn-primary w-100"><i class="fas fa-save me-1"></i><?php echo $editType ? 'Update' : 'Add'; ?> Type</button>
                        <?php if ($editType): ?><a href="?tab=types" class="btn btn-link w-100 mt-1">Cancel</a><?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Configured Sponsor Types <span class="badge bg-secondary"><?php echo count($sponsorTypes); ?></span></h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light"><tr><th>Name</th><th>Code</th><th>Flags</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($sponsorTypes as $t): ?>
                                <tr>
                                    <td><strong><?php echo spm_h($t['name']); ?></strong><?php if (!empty($t['description'])): ?><br><small class="text-muted"><?php echo spm_h($t['description']); ?></small><?php endif; ?></td>
                                    <td><code><?php echo spm_h($t['code']); ?></code></td>
                                    <td>
                                        <?php if ((int)$t['is_self_funded'] === 1): ?><span class="badge bg-info-subtle text-info border">Self-funded</span><?php endif; ?>
                                        <?php if ((int)$t['requires_approval'] === 1): ?><span class="badge bg-warning-subtle text-warning-emphasis border">Approval</span><?php endif; ?>
                                        <?php if ((int)$t['is_system'] === 1): ?><span class="badge bg-light text-secondary border">Built-in</span><?php endif; ?>
                                    </td>
                                    <td><?php echo (int)$t['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                    <td class="text-end">
                                        <a href="?tab=types&edit_type=<?php echo (int)$t['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-pen"></i></a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo spm_h($csrf); ?>">
                                            <input type="hidden" name="action" value="toggle_sponsor_type">
                                            <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                            <input type="hidden" name="activate" value="<?php echo (int)$t['is_active'] === 1 ? '0' : '1'; ?>">
                                            <button class="btn btn-sm btn-outline-<?php echo (int)$t['is_active'] === 1 ? 'secondary' : 'success'; ?>">
                                                <i class="fas fa-<?php echo (int)$t['is_active'] === 1 ? 'ban' : 'check'; ?>"></i>
                                            </button>
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
    </div>

    <?php else: /* eligibility tab */ ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="eligibility">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Select Programme</label>
                    <select class="form-select" name="prog" onchange="this.form.submit()">
                        <option value="">— choose a programme —</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?php echo spm_h($p['program_code']); ?>" <?php echo $selectedProg === $p['program_code'] ? 'selected' : ''; ?>>
                                <?php echo spm_h($p['program_code'] . ' — ' . $p['program_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedProg !== ''): ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Eligible Funding for <code><?php echo spm_h($selectedProg); ?></code></h5>
                    <?php if (!$progOptions): ?>
                        <p class="text-muted mb-0">No sponsor types configured yet for this programme. Add one on the right.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light"><tr><th>Sponsor Type</th><th>Max Students</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($progOptions as $o): ?>
                                <tr>
                                    <td><strong><?php echo spm_h($o['sponsor_type_name']); ?></strong><?php if (!empty($o['sponsorship_requirements'])): ?><br><small class="text-muted"><?php echo spm_h($o['sponsorship_requirements']); ?></small><?php endif; ?></td>
                                    <td><?php echo $o['max_sponsored_students'] !== null ? (int)$o['max_sponsored_students'] : '<span class="text-muted">Unlimited</span>'; ?></td>
                                    <td><?php echo (int)$o['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo spm_h($csrf); ?>">
                                            <input type="hidden" name="action" value="toggle_eligibility">
                                            <input type="hidden" name="program_code" value="<?php echo spm_h($selectedProg); ?>">
                                            <input type="hidden" name="sponsor_type_id" value="<?php echo (int)$o['sponsor_type_id']; ?>">
                                            <input type="hidden" name="activate" value="<?php echo (int)$o['is_active'] === 1 ? '0' : '1'; ?>">
                                            <button class="btn btn-sm btn-outline-<?php echo (int)$o['is_active'] === 1 ? 'secondary' : 'success'; ?>">
                                                <?php echo (int)$o['is_active'] === 1 ? 'Disable' : 'Enable'; ?>
                                            </button>
                                        </form>
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
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Add / Update Eligibility</h5>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo spm_h($csrf); ?>">
                        <input type="hidden" name="action" value="set_eligibility">
                        <input type="hidden" name="program_code" value="<?php echo spm_h($selectedProg); ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Sponsor Type</label>
                            <select class="form-select" name="sponsor_type_id" required>
                                <option value="">— select —</option>
                                <?php foreach ($sponsorTypes as $t): if ((int)$t['is_active'] !== 1) continue; ?>
                                    <option value="<?php echo (int)$t['id']; ?>"><?php echo spm_h($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Max Sponsored Students <span class="text-muted small">(blank = unlimited)</span></label>
                            <input class="form-control" type="number" min="0" name="max_sponsored_students">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Sponsorship Requirements</label>
                            <textarea class="form-control" name="sponsorship_requirements" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Academic Requirements</label>
                            <textarea class="form-control" name="academic_requirements" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Intake Restrictions</label>
                            <input class="form-control" name="intake_restrictions" placeholder="e.g. January intake only">
                        </div>
                        <button class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>Save Eligibility</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; /* tab */ ?>

    <?php endif; /* schema/perm */ ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
