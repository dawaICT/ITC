<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — Interest detail + follow-up update.
 */

$page_title = 'Interest Detail — Skills-to-Trade';
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.interests.manage');

$base = '/wucportal/admin/enterprise';
$interestId = (int)($_GET['id'] ?? $_POST['interest_id'] ?? 0);

if ($interestId <= 0) {
    wuc_set_flash('error', 'Invalid interest.');
    header('Location: ' . $base . '/interests.php');
    exit;
}

if (empty($_SESSION['eh_interest_form_token']) || !is_string($_SESSION['eh_interest_form_token'])) {
    $_SESSION['eh_interest_form_token'] = bin2hex(random_bytes(16));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        eh_require_post_csrf();
    } catch (Throwable $e) {
        wuc_set_flash('error', $e->getMessage());
        header('Location: ' . $base . '/interest_view.php?id=' . $interestId);
        exit;
    }

    $postedFormToken = (string)($_POST['form_token'] ?? '');
    $sessionFormToken = (string)($_SESSION['eh_interest_form_token'] ?? '');
    if ($sessionFormToken === '' || !hash_equals($sessionFormToken, $postedFormToken)) {
        wuc_set_flash('error', 'Form expired or already submitted.');
        header('Location: ' . $base . '/interest_view.php?id=' . $interestId);
        exit;
    }
    unset($_SESSION['eh_interest_form_token']);

    $result = eh_update_interest_followup($db, $interestId, [
        'follow_up_status' => (string)($_POST['follow_up_status'] ?? ''),
        'assigned_to' => (string)($_POST['assigned_to'] ?? ''),
        'internal_notes' => (string)($_POST['internal_notes'] ?? ''),
    ]);

    $_SESSION['eh_interest_form_token'] = bin2hex(random_bytes(16));
    wuc_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . $base . '/interest_view.php?id=' . $interestId);
    exit;
}

$interest = eh_get_interest($db, $interestId);
if (!$interest) {
    wuc_set_flash('error', 'Interest not found.');
    header('Location: ' . $base . '/interests.php');
    exit;
}

$followUps = eh_follow_up_statuses();
$interestTypes = eh_interest_types();
$formToken = (string)$_SESSION['eh_interest_form_token'];
$csrf = wuc_csrf_token();
$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;

require_once __DIR__ . '/../includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title">Interest #<?php echo (int)$interestId; ?></h1>
                <p class="text-muted mb-0">
                    <?php echo eh_h((string)$interest['title']); ?>
                    · <code><?php echo eh_h((string)$interest['public_code']); ?></code>
                </p>
            </div>
            <div class="col-auto">
                <a href="<?php echo eh_h($base); ?>/interests.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>All interests</a>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <?php $alertType = ($flash['type'] ?? '') === 'error' ? 'danger' : (string)$flash['type']; ?>
        <div class="alert alert-<?php echo eh_h($alertType); ?> alert-dismissible fade show" role="alert">
            <?php echo eh_h((string)($flash['message'] ?? '')); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="data-table-card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-user me-2"></i>Visitor</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Name</div>
                            <div class="fw-semibold"><?php echo eh_h((string)$interest['visitor_name']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Organisation</div>
                            <div><?php echo eh_h((string)($interest['organization'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Email</div>
                            <div><a href="mailto:<?php echo eh_h((string)$interest['email']); ?>"><?php echo eh_h((string)$interest['email']); ?></a></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Phone</div>
                            <div><?php echo eh_h((string)$interest['phone']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Interest type</div>
                            <div><?php echo eh_h($interestTypes[(string)$interest['interest_type']] ?? (string)$interest['interest_type']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Preferred contact</div>
                            <div><?php echo eh_h((string)($interest['preferred_contact_method'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Investment range</div>
                            <div><?php echo eh_h((string)($interest['investment_range'] ?? '—')); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Quantity</div>
                            <div><?php echo isset($interest['quantity_requested']) && $interest['quantity_requested'] !== null ? (int)$interest['quantity_requested'] : '—'; ?></div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Message</div>
                            <p class="mb-0"><?php echo nl2br(eh_h((string)$interest['message'])); ?></p>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Submitted</div>
                            <div class="small"><?php echo eh_h((string)$interest['created_at']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Enterprise</div>
                            <div><?php echo eh_h((string)($interest['business_name'] ?? '')); ?></div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="data-table-card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-tasks me-2"></i>Follow-up</h5></div>
                <div class="card-body">
                    <form method="post" action="<?php echo eh_h($base); ?>/interest_view.php?id=<?php echo (int)$interestId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo eh_h($csrf); ?>">
                        <input type="hidden" name="form_token" value="<?php echo eh_h($formToken); ?>">
                        <input type="hidden" name="interest_id" value="<?php echo (int)$interestId; ?>">

                        <div class="mb-3">
                            <label class="form-label" for="follow_up_status">Status</label>
                            <select class="form-select" name="follow_up_status" id="follow_up_status" required>
                                <?php foreach ($followUps as $key => $label): ?>
                                    <option value="<?php echo eh_h($key); ?>" <?php echo (string)$interest['follow_up_status'] === $key ? 'selected' : ''; ?>>
                                        <?php echo eh_h($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="assigned_to">Assigned to (staff ID)</label>
                            <input class="form-control" type="text" name="assigned_to" id="assigned_to" maxlength="50"
                                   value="<?php echo eh_h((string)($interest['assigned_to'] ?? '')); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="internal_notes">Internal notes</label>
                            <textarea class="form-control" name="internal_notes" id="internal_notes" rows="6" maxlength="5000"><?php echo eh_h((string)($interest['internal_notes'] ?? '')); ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-save me-2"></i>Update follow-up
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
