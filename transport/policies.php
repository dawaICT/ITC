<?php
declare(strict_types=1);
/**
 * Compliance & Policy register — document fleet/training policies, track review
 * dates, and keep an audit trail (transport_policies, transport_audit_log).
 */
require_once __DIR__ . '/includes/transport.php';
require_once __DIR__ . '/includes/teveta_helpers.php';

$page_title = 'Policies & Audit';
$CATS = ['vehicle_use','maintenance','safety','driver','fuel','disposal','compliance','other'];
$actor = (string)($_SESSION['user_name'] ?? $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'staff');

function pol_audit(mysqli $db, string $action, ?int $id, string $actor, string $notes): void
{
    $stmt = $db->prepare("INSERT INTO transport_audit_log (entity, entity_id, action, actor, notes) VALUES ('policy', ?, ?, ?, ?)");
    $stmt->bind_param('isss', $id, $action, $actor, $notes);
    $stmt->execute();
    $stmt->close();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!tev_verify_csrf()) {
        tev_flash_set('danger', 'Security token mismatch. Please refresh and try again.');
        tev_redirect_self();
    }
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_policy') {
            $title = trim((string)($_POST['title'] ?? ''));
            $category = (string)($_POST['category'] ?? 'other');
            $owner = trim((string)($_POST['owner'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $effective = trim((string)($_POST['effective_date'] ?? ''));
            $review = trim((string)($_POST['review_date'] ?? ''));
            if ($title === '') { throw new RuntimeException('Policy title is required.'); }
            if (!in_array($category, $CATS, true)) { $category = 'other'; }
            $eff = ($effective !== '' && DateTimeImmutable::createFromFormat('Y-m-d', $effective)) ? $effective : null;
            $rev = ($review !== '' && DateTimeImmutable::createFromFormat('Y-m-d', $review)) ? $review : null;

            $stmt = $db->prepare("INSERT INTO transport_policies (title, category, owner, description, effective_date, review_date, status) VALUES (?,?,?,?,?,?,'active')");
            $stmt->bind_param('ssssss', $title, $category, $owner, $description, $eff, $rev);
            $stmt->execute();
            $newId = (int)$db->insert_id;
            $stmt->close();
            pol_audit($db, 'created', $newId, $actor, 'Policy "' . $title . '" added');
            tev_flash_set('success', 'Policy added.');
        } elseif ($action === 'set_status') {
            $id = (int)($_POST['policy_id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'active');
            if (!in_array($status, ['active','draft','archived'], true)) { $status = 'active'; }
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE transport_policies SET status=? WHERE id=?");
                $stmt->bind_param('si', $status, $id);
                $stmt->execute();
                $stmt->close();
                pol_audit($db, 'status_' . $status, $id, $actor, 'Status set to ' . $status);
                tev_flash_set('success', 'Policy status updated.');
            }
        }
    } catch (Throwable $e) {
        error_log('policies.php: ' . $e->getMessage());
        tev_flash_set('danger', $e->getMessage());
    }
    tev_redirect_self();
}

$policies = [];
$r = $db->query("SELECT * FROM transport_policies ORDER BY (status='archived'), review_date IS NULL, review_date");
while ($x = $r->fetch_assoc()) { $policies[] = $x; }

$dueReview = 0;
foreach ($policies as $p) {
    if ($p['status'] === 'active' && $p['review_date'] && strtotime($p['review_date']) <= strtotime('+30 days')) { $dueReview++; }
}

$audit = $db->query("SELECT * FROM transport_audit_log WHERE entity='policy' ORDER BY id DESC LIMIT 20");

require_once __DIR__ . '/includes/nav.php';
?>
<div class="container-fluid py-3">
    <h3 class="mb-1"><i class="fas fa-file-contract me-2"></i>Policies &amp; Audit</h3>
    <p class="text-muted">Document fleet/training policies, track review dates, and keep an audit trail.</p>
    <?php echo tev_flash_render(); ?>

    <?php if ($dueReview > 0): ?>
        <div class="alert alert-warning"><i class="fas fa-bell me-1"></i><?php echo (int)$dueReview; ?> active policy(ies) due for review within 30 days.</div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header"><strong>Policy register (<?php echo count($policies); ?>)</strong></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle">
                    <thead><tr><th>Policy</th><th>Category</th><th>Owner</th><th>Review due</th><th>Status</th><th></th></tr></thead><tbody>
                        <?php if (!$policies): ?><tr><td colspan="6" class="text-muted text-center py-4">No policies yet.</td></tr>
                        <?php else: foreach ($policies as $p):
                            $reviewDue = $p['review_date'] && strtotime($p['review_date']) <= strtotime('+30 days');
                            $sb = ['active'=>'success','draft'=>'secondary','archived'=>'light text-dark'][$p['status']] ?? 'secondary'; ?>
                            <tr>
                                <td><?php echo tev_h($p['title']); ?><?php echo $p['description']?'<div class="text-muted small">'.tev_h(mb_strimwidth($p['description'],0,80,'…')).'</div>':''; ?></td>
                                <td><span class="badge bg-info text-dark text-capitalize"><?php echo tev_h(str_replace('_',' ',$p['category'])); ?></span></td>
                                <td class="small"><?php echo tev_h($p['owner'] ?: '—'); ?></td>
                                <td><?php echo $p['review_date'] ? ($reviewDue?'<span class="badge bg-warning text-dark">'.tev_h($p['review_date']).'</span>':tev_h($p['review_date'])) : '<span class="text-muted">—</span>'; ?></td>
                                <td><span class="badge bg-<?php echo $sb; ?> text-capitalize"><?php echo tev_h($p['status']); ?></span></td>
                                <td>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo tev_h(tev_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="policy_id" value="<?php echo (int)$p['id']; ?>">
                                        <?php if ($p['status'] !== 'archived'): ?>
                                            <input type="hidden" name="status" value="archived">
                                            <button class="btn btn-sm btn-outline-secondary" title="Archive"><i class="fas fa-box-archive"></i></button>
                                        <?php else: ?>
                                            <input type="hidden" name="status" value="active">
                                            <button class="btn btn-sm btn-outline-success" title="Reactivate"><i class="fas fa-rotate-left"></i></button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody></table></div></div>
            </div>

            <div class="card">
                <div class="card-header"><strong><i class="fas fa-clock-rotate-left me-2"></i>Audit trail</strong></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>When</th><th>Action</th><th>By</th><th>Notes</th></tr></thead><tbody>
                        <?php if (!$audit || !$audit->num_rows): ?><tr><td colspan="4" class="text-muted text-center py-3">No audit entries yet.</td></tr>
                        <?php else: while ($a = $audit->fetch_assoc()): ?>
                            <tr><td class="small text-muted"><?php echo tev_h($a['created_at']); ?></td><td class="text-capitalize"><?php echo tev_h(str_replace('_',' ',$a['action'])); ?></td><td class="small"><?php echo tev_h($a['actor']); ?></td><td class="small"><?php echo tev_h($a['notes']); ?></td></tr>
                        <?php endwhile; endif; ?>
                    </tbody></table></div></div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><strong><i class="fas fa-plus me-2"></i>Add policy</strong></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo tev_h(tev_csrf_token()); ?>">
                        <input type="hidden" name="action" value="add_policy">
                        <div class="mb-2"><label class="form-label">Title</label><input class="form-control" name="title" required maxlength="200"></div>
                        <div class="mb-2"><label class="form-label">Category</label>
                            <select name="category" class="form-select"><?php foreach($CATS as $c): ?><option value="<?php echo $c; ?>"><?php echo tev_h(ucwords(str_replace('_',' ',$c))); ?></option><?php endforeach; ?></select>
                        </div>
                        <div class="mb-2"><label class="form-label">Owner</label><input class="form-control" name="owner" maxlength="120" placeholder="e.g. Head of Section"></div>
                        <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"></textarea></div>
                        <div class="row g-2">
                            <div class="col-6 mb-2"><label class="form-label">Effective</label><input type="date" class="form-control" name="effective_date" value="<?php echo date('Y-m-d'); ?>"></div>
                            <div class="col-6 mb-2"><label class="form-label">Review</label><input type="date" class="form-control" name="review_date" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>"></div>
                        </div>
                        <button class="btn btn-primary w-100 mt-2"><i class="fas fa-save me-1"></i>Add policy</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
