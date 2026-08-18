<?php
declare(strict_types=1);

$page_title = 'Opportunities';
$epGuardMode = 'member';
$activeNav = 'opportunities';
require_once __DIR__ . '/../includes/guard.php';
require_once __DIR__ . '/../includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);

$opportunities = ep_list_opportunities_for_profile($db, $profileId);
$types = ep_opportunity_types();

require_once __DIR__ . '/../includes/layout.php';
?>
<div class="ep-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="ep-muted small mb-0">Manage drafts, submissions, and published listings.</p>
        <a class="btn btn-primary btn-sm" href="/wucportal/enterprise/opportunities/create.php">Create opportunity</a>
    </div>
    <?php if ($opportunities === []): ?>
        <p class="mb-0">No opportunities yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Code</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($opportunities as $o): ?>
                    <tr>
                        <td><?= ep_h((string)$o['title']) ?></td>
                        <td class="small"><?= ep_h($types[(string)$o['opportunity_type']] ?? (string)$o['opportunity_type']) ?></td>
                        <td class="small"><code><?= ep_h((string)$o['public_code']) ?></code></td>
                        <td><span class="badge bg-<?= ep_h(ep_status_badge_class((string)$o['status'])) ?>"><?= ep_h(ep_status_label((string)$o['status'])) ?></span></td>
                        <td class="text-end">
                            <a href="/wucportal/enterprise/opportunities/view.php?id=<?= (int)$o['id'] ?>">View</a>
                            <?php if (in_array((string)$o['status'], ['draft', 'changes_requested', 'update_required', 'unpublished', 'rejected'], true)): ?>
                                · <a href="/wucportal/enterprise/opportunities/edit.php?id=<?= (int)$o['id'] ?>">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
