<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/legacy_academic_guard.php';
ep_redirect_certificate_from_legacy_skills_hub($db, (string)($_SESSION['Sid'] ?? ''));
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.item.view_own_interests');

$base = '/wucportal/students/enterprise';
$profile = eh_get_profile_for_owner($db, eh_current_owner_user_id(), eh_current_student_id());
$interestTypes = eh_interest_types();
$followUps = eh_follow_up_statuses();
$interests = [];

if ($profile) {
    eh_assert_owns_profile($profile);
    $interests = eh_list_interests($db, ['profile_id' => (int)$profile['id']], 200);
}

$pageTitle = 'Interests';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main class="content-wrapper portal-dashboard eh-hub pt-3 pb-5">
<div class="container-fluid px-3 px-lg-4">
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title mb-1"><i class="fas fa-handshake me-2"></i>Buyer &amp; investor interests</h1>
                <p class="text-muted mb-0">Expressions of interest received on your published showcase items.</p>
            </div>
            <div class="col-auto d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo eh_h($base); ?>/index.php">Hub home</a>
                <a class="btn btn-outline-primary" href="<?php echo eh_h($base); ?>/submissions.php">Submissions</a>
            </div>
        </div>
    </div>

    <?php if (!$profile): ?>
        <div class="alert alert-info">Create an enterprise profile and publish an item to receive interests.
            <a href="<?php echo eh_h($base); ?>/profile_edit.php">Create profile</a>
        </div>
    <?php endif; ?>

    <section class="data-table-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>Item</th>
                        <th>Visitor</th>
                        <th>Type</th>
                        <th>Contact</th>
                        <th>Follow-up</th>
                        <th>Message</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($interests === []): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="fas fa-handshake fa-2x mb-2 d-block opacity-50"></i>
                                No interests yet. Publish an item to the public showcase to start receiving enquiries.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($interests as $row): ?>
                            <?php
                            $type = (string)$row['interest_type'];
                            $follow = (string)$row['follow_up_status'];
                            ?>
                            <tr>
                                <td class="small text-nowrap"><?php echo eh_h((string)$row['created_at']); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo eh_h((string)$row['title']); ?></div>
                                    <div class="small text-muted"><?php echo eh_h((string)$row['public_code']); ?></div>
                                </td>
                                <td>
                                    <div><?php echo eh_h((string)$row['visitor_name']); ?></div>
                                    <?php if (trim((string)($row['organization'] ?? '')) !== ''): ?>
                                        <div class="small text-muted"><?php echo eh_h((string)$row['organization']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo eh_h($interestTypes[$type] ?? $type); ?></td>
                                <td class="small">
                                    <div><?php echo eh_h((string)$row['email']); ?></div>
                                    <div><?php echo eh_h((string)$row['phone']); ?></div>
                                    <?php if (trim((string)($row['preferred_contact_method'] ?? '')) !== ''): ?>
                                        <div class="text-muted">Prefers <?php echo eh_h((string)$row['preferred_contact_method']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $follow === 'new' ? 'info' : ($follow === 'converted' ? 'success' : 'secondary'); ?>">
                                        <?php echo eh_h($followUps[$follow] ?? eh_status_label($follow)); ?>
                                    </span>
                                </td>
                                <td class="small" style="max-width: 280px;">
                                    <?php echo eh_h(mb_strimwidth((string)$row['message'], 0, 180, '…')); ?>
                                    <?php if (!empty($row['investment_range'])): ?>
                                        <div class="text-muted mt-1">Range: <?php echo eh_h((string)$row['investment_range']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($row['quantity_requested'] !== null && $row['quantity_requested'] !== ''): ?>
                                        <div class="text-muted">Qty: <?php echo (int)$row['quantity_requested']; ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <p class="small text-muted mt-3 mb-0">
        Follow-up status is managed by institutional staff. Contact your lecturer or enterprise office if you need help responding to an enquiry.
    </p>
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
