<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/portal_alerts.php';

$user = 'EXH-NOTIFICATION-TEST';
$pass = 0;
$fail = 0;
$ok = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    $condition ? $pass++ : $fail++;
    echo ($condition ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
};

$cleanup = $db->prepare('DELETE FROM portal_alerts WHERE user_id = ?');
$cleanup->bind_param('s', $user);
$cleanup->execute();

try {
    $created = wuc_portal_alert_create($db, [
        'user_id' => $user,
        'user_role' => 'student',
        'alert_type' => 'context_test',
        'severity' => 'info',
        'title' => 'Open learning material',
        'message' => 'A course resource is ready.',
        'source_portal' => 'academic',
        'target_portal' => 'elearning',
        'action_url' => '/wucportal/students/elearning/index.php',
        'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        'dedupe_days' => 0,
    ]);
    $ok($created, 'context-aware alert is created');
    $row = $db->query("SELECT * FROM portal_alerts WHERE user_id='EXH-NOTIFICATION-TEST' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $ok(($row['source_portal'] ?? '') === 'academic', 'source portal is stored');
    $ok(($row['target_portal'] ?? '') === 'elearning', 'target portal follows the target page');
    $ok(($row['target_page'] ?? '') === '/wucportal/students/elearning/index.php', 'target page is stored');
    $ok(!empty($row['expires_at']), 'expiry is stored');

    $createdUnsafe = wuc_portal_alert_create($db, [
        'user_id' => $user,
        'user_role' => 'student',
        'alert_type' => 'unsafe_url_test',
        'title' => 'Unsafe target',
        'message' => 'External targets must be removed.',
        'action_url' => 'https://evil.example/phish',
        'dedupe_days' => 0,
    ]);
    $unsafe = $db->query("SELECT action_url FROM portal_alerts WHERE user_id='EXH-NOTIFICATION-TEST' AND alert_type='unsafe_url_test' LIMIT 1")->fetch_assoc();
    $ok($createdUnsafe && ($unsafe['action_url'] ?? null) === null, 'external notification target is stripped');

    $db->query("UPDATE portal_alerts SET expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id='EXH-NOTIFICATION-TEST' AND alert_type='context_test'");
    $visible = wuc_portal_alerts_for_user($db, $user, 20, false, 'student');
    $visibleTypes = array_column($visible, 'alert_type');
    $ok(!in_array('context_test', $visibleTypes, true), 'expired alert is excluded');
    $ok(wuc_portal_alert_get_owned($db, 'ANOTHER-USER', 'student', (int)($row['id'] ?? 0)) === null, 'another user cannot open the alert');

    // Regression: a dismissed synced alert stays dismissed (no duplication) until its
    // content changes, then it re-surfaces as unread. Exercises wuc_portal_alert_upsert_current.
    $dUser = 'EXH-NOTIF-DISMISS';
    $cleanup2 = $db->prepare('DELETE FROM portal_alerts WHERE user_id = ?');
    $cleanup2->bind_param('s', $dUser);
    $cleanup2->execute();
    try {
        $payload = [
            'user_id' => $dUser,
            'user_role' => 'student',
            'alert_type' => 'dismiss_test',
            'title' => 'Pending task',
            'message' => 'Please act on task A.',
            'severity' => 'info',
            'action_url' => '/wucportal/students/index.php',
        ];
        wuc_portal_alert_upsert_current($db, $payload);
        $rowD = $db->query("SELECT id FROM portal_alerts WHERE user_id='$dUser'")->fetch_assoc();
        $alertId = (int)($rowD['id'] ?? 0);
        $db->query("UPDATE portal_alerts SET status='dismissed' WHERE id=$alertId AND user_id='$dUser'");

        wuc_portal_alert_upsert_current($db, $payload);
        $countU = (int)$db->query("SELECT COUNT(*) c FROM portal_alerts WHERE user_id='$dUser'")->fetch_assoc()['c'];
        $statusU = $db->query("SELECT status FROM portal_alerts WHERE id=$alertId")->fetch_assoc()['status'];
        $ok($countU === 1, 'unchanged dismissed alert is not duplicated');
        $ok($statusU === 'dismissed', 'dismissed alert stays dismissed on sync');

        $changed = $payload; $changed['message'] = 'Please act on task B now.';
        wuc_portal_alert_upsert_current($db, $changed);
        $countC = (int)$db->query("SELECT COUNT(*) c FROM portal_alerts WHERE user_id='$dUser'")->fetch_assoc()['c'];
        $statusC = $db->query("SELECT status FROM portal_alerts WHERE id=$alertId")->fetch_assoc()['status'];
        $ok($countC === 1, 'content change keeps a single alert row');
        $ok($statusC === 'unread', 'content change re-surfaces the alert as unread');
    } finally {
        $cleanup2->execute();
        $cleanup2->close();
    }
} finally {
    $cleanup->execute();
    $cleanup->close();
}

echo PHP_EOL . "Result: {$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
