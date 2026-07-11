<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once dirname(__DIR__) . '/db/connect.php';
    require_once dirname(__DIR__) . '/includes/portal_alerts.php';
    require_once dirname(__DIR__) . '/includes/notification_integrations.php';

    $testStudent = 'TEST-NOTIF-STUDENT';
    $testStaff = 'TEST-NOTIF-STAFF';

    wuc_notify_portal($db, [
        'user_id' => $testStudent,
        'user_role' => 'student',
        'module' => 'registration',
        'alert_type' => 'registration_pending',
        'title' => 'Test registration pending',
        'message' => 'CLI smoke test.',
        'action_url' => '/wucportal/students/registration.php',
        'dedupe_days' => 0,
    ]);

    wuc_portal_alerts_sync_sources($db, $testStudent, 'student');
    $studentAlerts = wuc_portal_alerts_for_center($db, $testStudent, ['status' => 'active'], 20, 'student');
    echo 'Student alerts: ' . count($studentAlerts) . PHP_EOL;

    if ($stmt = $db->prepare('DELETE FROM portal_alerts WHERE user_id IN (?, ?)')) {
        $stmt->bind_param('ss', $testStudent, $testStaff);
        $stmt->execute();
        $stmt->close();
    }
    echo "OK\n";
} catch (Throwable $e) {
    echo 'ERR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}
