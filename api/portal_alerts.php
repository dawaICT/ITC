<?php
/**
 * Portal alert hub endpoint (unified alerts for staff and students).
 *
 * GET  ?action=list          → unread alerts + count for the logged-in user
 * POST action=mark_read      → mark one alert read      (alert_id)
 * POST action=dismiss        → dismiss one alert        (alert_id)
 * POST action=mark_all_read  → mark every unread alert read
 *
 * All mutations are CSRF-protected and owner-scoped: a user can only touch
 * rows whose portal_alerts.user_id matches their own session identity.
 */

require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/csrf_guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/portal_alerts.php';

wuc_api_start_session('portal-alerts');
wuc_guard_sync_session_aliases(['staff_id', 'user_id']);

// Staff sessions carry staff_id/user_id; student sessions carry Sid.
$alertUserId = trim((string)($_SESSION['staff_id'] ?? ''));
if ($alertUserId === '') {
    $alertUserId = trim((string)($_SESSION['Sid'] ?? ''));
}
$alertUserRole = 'student';
if (!empty($_SESSION['staff_id']) || !empty($_SESSION['user_id'])) {
    $alertUserRole = wuc_portal_alert_normalize_role((string)($_SESSION['role'] ?? 'staff'));
}
if ($alertUserId === '' || !preg_match('/^[A-Za-z0-9\/\-_]+$/', $alertUserId)) {
    wuc_json_response([
        'success' => false,
        'message' => 'Session expired or invalid.',
        'session_expired' => true,
    ], 401);
}

wuc_ajax_require_csrf();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');

try {
    switch ($action) {
        case 'mark_read':
        case 'dismiss':
            $alertId = (int)($_POST['alert_id'] ?? 0);
            if ($alertId <= 0) {
                wuc_json_error('Invalid alert ID.', 422);
            }
            $status = $action === 'dismiss' ? 'dismissed' : 'read';
            $ok = wuc_portal_alert_set_status($db, $alertUserId, $alertId, $status, $alertUserRole);
            wuc_json_response(['success' => $ok, 'unread' => wuc_portal_alerts_unread_count($db, $alertUserId, $alertUserRole)]);
            break;

        case 'mark_all_read':
            $count = wuc_portal_alerts_mark_all_read($db, $alertUserId, $alertUserRole);
            wuc_json_response(['success' => true, 'marked' => $count, 'unread' => 0]);
            break;

        case 'list':
        default:
            require_once __DIR__ . '/notification_integrations.php';
            wuc_portal_alerts_sync_sources($db, $alertUserId, $alertUserRole);
            $alerts = wuc_portal_alerts_for_user($db, $alertUserId, 10, true, $alertUserRole);
            wuc_json_response([
                'success' => true,
                'unread' => count($alerts),
                'alerts' => array_map(static function (array $a): array {
                    return [
                        'id' => (int)$a['id'],
                        'type' => (string)$a['alert_type'],
                        'severity' => (string)$a['severity'],
                        'title' => (string)$a['title'],
                        'message' => (string)$a['message'],
                        'action_url' => $a['action_url'] !== null ? (string)$a['action_url'] : null,
                        'created_at' => (string)$a['created_at'],
                    ];
                }, $alerts),
            ]);
            break;
    }
} catch (Throwable $e) {
    error_log('api/portal_alerts.php failed: ' . $e->getMessage());
    wuc_json_error('Could not process the alert request.', 500);
}
