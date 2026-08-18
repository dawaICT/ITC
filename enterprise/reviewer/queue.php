<?php
declare(strict_types=1);

$page_title = 'Review Queue';
$activeNav = 'rev_queue';
$epGuardMode = 'reviewer';
$epNav = 'reviewer';
require_once dirname(__DIR__) . '/includes/guard.php';

$uid = ep_current_user_id();
$stmt = $db->prepare("SELECT o.id, o.public_code, o.title, o.opportunity_type, o.submitted_at,
        p.business_name, p.professional_title, c.category_name
    FROM enterprise_opportunities o
    JOIN enterprise_member_profiles p ON p.id = o.enterprise_profile_id
    LEFT JOIN enterprise_categories c ON c.id = o.category_id
    WHERE o.status = 'submitted' AND (p.owner_user_id IS NULL OR p.owner_user_id <> ?)
    ORDER BY o.submitted_at ASC, o.id ASC
    LIMIT 200");
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
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Submitted for review (<?= count($items) ?>)</h2>
        <a href="/wucportal/enterprise/reviewer/conflicts.php" class="btn btn-sm btn-outline-warning">Conflicts</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr>
                <th>Code</th><th>Title</th><th>Type</th><th>Category</th><th>Enterprise</th><th>Submitted</th><th></th>
            </tr></thead>
            <tbody>
            <?php if ($items === []): ?>
                <tr><td colspan="7" class="text-center ep-muted py-4">No items in the queue.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $row): ?>
                    <tr>
                        <td><code><?= ep_h((string)$row['public_code']) ?></code></td>
                        <td><?= ep_h((string)$row['title']) ?></td>
                        <td><?= ep_h($types[(string)$row['opportunity_type']] ?? (string)$row['opportunity_type']) ?></td>
                        <td><?= ep_h((string)($row['category_name'] ?? '—')) ?></td>
                        <td><?= ep_h((string)($row['business_name'] ?? $row['professional_title'] ?? '')) ?></td>
                        <td class="small"><?= ep_h((string)($row['submitted_at'] ?? '')) ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-primary" href="/wucportal/enterprise/reviewer/review.php?id=<?= (int)$row['id'] ?>">Review</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
