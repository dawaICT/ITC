<?php
declare(strict_types=1);

$page_title = 'Received Interest';
$epGuardMode = 'member';
$activeNav = 'interests';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);
$rows = ep_list_interests_for_profile($db, $profileId);
require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <p class="small ep-muted">Expressions of interest are leads — they are not outcomes until converted with evidence.</p>
    <?php if ($rows === []): ?>
        <p class="ep-muted mb-0">No interest received yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>When</th><th>Opportunity</th><th>Type</th><th>Visitor</th><th>Lead status</th><th>Age</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= ep_h((string)$r['created_at']) ?></td>
                        <td><?= ep_h((string)$r['title']) ?></td>
                        <td><?= ep_h(ep_status_label((string)$r['interest_type'])) ?></td>
                        <td><?= ep_h((string)$r['visitor_name']) ?><?php if (!empty($r['organization'])): ?> · <?= ep_h((string)$r['organization']) ?><?php endif; ?></td>
                        <td><?= ep_h(ep_status_label((string)$r['lead_status'])) ?></td>
                        <td><?= ep_h(ep_lead_age_label($db, (string)$r['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
