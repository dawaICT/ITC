<?php
declare(strict_types=1);

$page_title = 'Notifications';
$activeNav = 'settings';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/portal_alerts.php';
require_once __DIR__ . '/includes/participant_helpers.php';

$recipient = ep_notification_recipient();
$alerts = [];
if ($recipient['user_id'] !== '' && function_exists('wuc_portal_alerts_ready') && wuc_portal_alerts_ready($db)) {
    $uid = $recipient['user_id'];
    $stmt = $db->prepare("SELECT title, message, action_url, created_at, status FROM portal_alerts
        WHERE user_id = ? AND (source_portal = 'enterprise' OR alert_type LIKE 'enterprise%')
        ORDER BY created_at DESC LIMIT 50");
    if ($stmt) {
        $stmt->bind_param('s', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $alerts[] = $r;
        }
        $stmt->close();
    }
}

require_once __DIR__ . '/includes/layout.php';
?>
<div class="ep-card">
    <?php if ($alerts === []): ?>
        <p class="ep-muted mb-0">No enterprise notifications yet.</p>
    <?php else: ?>
        <?php foreach ($alerts as $a): ?>
            <div class="border-bottom py-2">
                <strong><?= ep_h((string)$a['title']) ?></strong>
                <div class="small ep-muted"><?= ep_h((string)$a['created_at']) ?> · <?= ep_h((string)$a['status']) ?></div>
                <div><?= ep_h((string)$a['message']) ?></div>
                <?php if (!empty($a['action_url'])): ?><a href="<?= ep_h((string)$a['action_url']) ?>">Open</a><?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
