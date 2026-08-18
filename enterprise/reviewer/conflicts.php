<?php
declare(strict_types=1);

$page_title = 'Review Conflicts';
$activeNav = 'rev_conf';
$epGuardMode = 'reviewer';
$epNav = 'reviewer';
require_once dirname(__DIR__) . '/includes/guard.php';

$uid = ep_current_user_id();
$stmt = $db->prepare("SELECT o.id, o.public_code, o.title, o.opportunity_type, o.submitted_at,
        p.business_name, p.professional_title
    FROM enterprise_opportunities o
    JOIN enterprise_member_profiles p ON p.id = o.enterprise_profile_id
    WHERE o.status = 'submitted' AND p.owner_user_id = ?
    ORDER BY o.submitted_at DESC
    LIMIT 100");
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();
$types = ep_opportunity_types();

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <h2 class="h5">Conflict of interest</h2>
    <p class="ep-muted">These submitted opportunities are linked to your account. Another reviewer must perform technical verification.</p>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Code</th><th>Title</th><th>Type</th><th>Submitted</th></tr></thead>
            <tbody>
            <?php if ($items === []): ?>
                <tr><td colspan="4" class="text-center ep-muted py-4">No conflicts in the queue.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $row): ?>
                    <tr>
                        <td><code><?= ep_h((string)$row['public_code']) ?></code></td>
                        <td><?= ep_h((string)$row['title']) ?></td>
                        <td><?= ep_h($types[(string)$row['opportunity_type']] ?? (string)$row['opportunity_type']) ?></td>
                        <td class="small"><?= ep_h((string)($row['submitted_at'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
