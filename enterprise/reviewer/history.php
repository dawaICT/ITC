<?php
declare(strict_types=1);

$page_title = 'Review History';
$activeNav = 'rev_hist';
$epGuardMode = 'reviewer';
$epNav = 'reviewer';
require_once dirname(__DIR__) . '/includes/guard.php';

$actor = ep_current_actor();
$stmt = $db->prepare('SELECT r.*, o.title, o.public_code, o.status AS opp_status
    FROM enterprise_opportunity_reviews r
    INNER JOIN enterprise_opportunities o ON o.id = r.enterprise_opportunity_id
    WHERE r.reviewer_id = ?
    ORDER BY r.reviewed_at DESC
    LIMIT 200');
$stmt->bind_param('s', $actor);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <h2 class="h5 mb-3">Your review activity</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>When</th><th>Code</th><th>Title</th><th>Stage</th><th>Result</th><th>Comments</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-center ep-muted py-4">No reviews yet.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="small"><?= ep_h((string)$row['reviewed_at']) ?></td>
                        <td><code><?= ep_h((string)$row['public_code']) ?></code></td>
                        <td><?= ep_h((string)$row['title']) ?></td>
                        <td><?= ep_h((string)$row['review_stage']) ?></td>
                        <td><?= ep_h(ep_status_label((string)$row['resulting_status'])) ?></td>
                        <td class="small"><?= nl2br(ep_h((string)($row['comments'] ?? ''))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
