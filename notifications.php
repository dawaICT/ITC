<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/portal_config.php';
require_once __DIR__ . '/includes/session_guard.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/csrf_guard.php';
require_once __DIR__ . '/db/connect.php';
require_once __DIR__ . '/includes/portal_alerts.php';
require_once __DIR__ . '/includes/notification_integrations.php';
require_once __DIR__ . '/includes/page_meta.php';
require_once __DIR__ . '/includes/role_helpers.php';
require_once __DIR__ . '/includes/portal_access.php';

wuc_guard_start_session('notifications-center');
wuc_guard_sync_session_aliases(['staff_id', 'user_id']);
wuc_ajax_csrf_token();

$viewer = wuc_portal_alert_viewer_context($db);
if ($viewer === null) {
    wuc_safe_redirect('/wucportal/student_login.php', 302, '/wucportal/student_login.php');
}

$viewerId = $viewer['user_id'];
$viewerRole = $viewer['user_role'];
$viewerKind = $viewer['kind'];
$dashboardUrl = $viewer['dashboard_url'];
$viewerName = $viewer['display_name'];
$loginUrl = $viewerKind === 'student' ? '/wucportal/student_login.php' : '/wucportal/staff_login.php';
$portalHint = strtolower(trim((string)($_GET['portal'] ?? '')));
if ($portalHint === 'elearning' && $viewerKind !== 'student') {
    $dashboardUrl = '/wucportal/lecturers/elearning/index.php';
}

$statusFilter = (string)($_GET['status'] ?? 'active');
if (!in_array($statusFilter, ['active', 'unread', 'read', 'dismissed', 'all'], true)) {
    $statusFilter = 'active';
}
$severityFilter = (string)($_GET['severity'] ?? '');
if (!in_array($severityFilter, ['', 'info', 'warning', 'critical'], true)) {
    $severityFilter = '';
}

$selfQuery = [];
if ($portalHint === 'elearning') {
    $selfQuery['portal'] = 'elearning';
}
if ($statusFilter !== 'active') {
    $selfQuery['status'] = $statusFilter;
}
if ($severityFilter !== '') {
    $selfQuery['severity'] = $severityFilter;
}
$selfUrl = '/wucportal/notifications.php' . ($selfQuery ? '?' . http_build_query($selfQuery) : '');

// Open notification: mark read and redirect to the linked module page.
$openId = (int)($_GET['open'] ?? 0);
if ($openId > 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $owned = wuc_portal_alert_get_owned($db, $viewerId, $viewerRole, $openId);
    if ($owned) {
        wuc_portal_alert_set_status($db, $viewerId, $openId, 'read', $viewerRole);
        $target = wuc_notifications_safe_url($owned['action_url'] ?? null);
        if ($target !== '') {
            $targetPortal = strtolower(trim((string)($owned['target_portal'] ?? '')));
            $portalUserId = wuc_resolve_session_user_id($db);
            if ($targetPortal === '' || ($portalUserId > 0 && wuc_user_has_portal_access($db, $portalUserId, $targetPortal))) {
                $_SESSION['current_portal'] = $targetPortal !== '' ? $targetPortal : ($_SESSION['current_portal'] ?? '');
                wuc_safe_redirect($target, 302, $dashboardUrl);
            }
            $_SESSION['notif_center_flash'] = [
                'type' => 'warning',
                'text' => 'This notification belongs to a portal you cannot currently access.',
            ];
        }
    }
    wuc_safe_redirect($selfUrl, 302, '/wucportal/notifications.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wuc_ajax_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $alertId = (int)($_POST['alert_id'] ?? 0);
    $flashType = 'warning';
    $flashText = '';

    if ($action === 'mark_read' && $alertId > 0) {
        $ok = wuc_portal_alert_set_status($db, $viewerId, $alertId, 'read', $viewerRole);
        $flashType = $ok ? 'success' : 'warning';
        $flashText = $ok ? 'Notification marked as read.' : 'Notification could not be updated.';
    } elseif ($action === 'dismiss' && $alertId > 0) {
        $ok = wuc_portal_alert_set_status($db, $viewerId, $alertId, 'dismissed', $viewerRole);
        $flashType = $ok ? 'success' : 'warning';
        $flashText = $ok ? 'Notification dismissed.' : 'Notification could not be dismissed.';
    } elseif ($action === 'mark_all_read') {
        $marked = wuc_portal_alerts_mark_all_read($db, $viewerId, $viewerRole);
        $flashType = $marked > 0 ? 'success' : 'info';
        $flashText = $marked > 0 ? "{$marked} notification" . ($marked === 1 ? '' : 's') . ' marked as read.' : 'No unread notifications to mark.';
    }

    if ($flashText !== '') {
        $_SESSION['notif_center_flash'] = ['type' => $flashType, 'text' => $flashText];
    }
    wuc_safe_redirect($selfUrl, 303, '/wucportal/notifications.php');
}

$flash = '';
$flashType = 'info';
if (isset($_SESSION['notif_center_flash']) && is_array($_SESSION['notif_center_flash'])) {
    $flash = (string)($_SESSION['notif_center_flash']['text'] ?? '');
    $flashType = (string)($_SESSION['notif_center_flash']['type'] ?? 'info');
    if (!in_array($flashType, ['success', 'info', 'warning'], true)) {
        $flashType = 'info';
    }
    unset($_SESSION['notif_center_flash']);
}

wuc_portal_alerts_sync_sources($db, $viewerId, $viewerRole);
if ($viewerKind === 'student') {
    wuc_portal_alerts_sync_student_documents($db, $viewerId);
}

$alertsReady = wuc_portal_alerts_ready($db);
$counts = wuc_portal_alerts_counts($db, $viewerId, $viewerRole);
$alerts = wuc_portal_alerts_for_center($db, $viewerId, [
    'status' => $statusFilter,
    'severity' => $severityFilter,
], 100, $viewerRole);

function wuc_notifications_badge_class(string $severity): string
{
    if ($severity === 'critical') {
        return 'danger';
    }
    if ($severity === 'warning') {
        return 'warning text-dark';
    }
    return 'info text-dark';
}

function wuc_notifications_icon(string $severity): string
{
    if ($severity === 'critical') {
        return 'fa-triangle-exclamation';
    }
    if ($severity === 'warning') {
        return 'fa-circle-exclamation';
    }
    return 'fa-circle-info';
}

function wuc_notifications_module_label(string $type): string
{
    $type = strtolower(trim($type));
    $map = [
        'payment_confirmed' => 'Finance',
        'registration_approved' => 'Registration',
        'registration_rejected' => 'Registration',
        'registration_pending' => 'Registration',
        'course_assigned' => 'Academics',
        'course_material' => 'eLearning',
        'academic_risk' => 'Academic',
        'announcement' => 'Announcements',
        'student_doc_test_docket' => 'Exams',
        'student_doc_exam_slip' => 'Exams',
    ];
    if (isset($map[$type])) {
        return $map[$type];
    }
    if (str_starts_with($type, 'el_')) {
        return 'eLearning';
    }
    if (str_starts_with($type, 'lecturer_')) {
        return 'Lecturer';
    }
    if (str_starts_with($type, 'enterprise_')) {
        return 'System';
    }
    return ucwords(str_replace(['_', '-'], ' ', preg_replace('/^(student_dashboard_)/', '', $type)));
}

function wuc_notifications_type_label(string $type): string
{
    return wuc_notifications_module_label($type);
}

function wuc_notifications_safe_url(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (preg_match('/^\s*javascript:/i', $url)) {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }
    if ($url[0] === '/') {
        return $url;
    }
    return '/wucportal/' . ltrim($url, '/');
}

$csrf = (string)($_SESSION['csrf_token'] ?? '');
$title = 'Notifications';
$isStudentViewer = $viewerKind === 'student';
$roleDisplay = getRoleDisplayNames()[$viewerRole] ?? ucwords(str_replace('_', ' ', $viewerRole));
$navUnreadCount = (int)($counts['unread'] ?? 0);

if ($isStudentViewer) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(wuc_portal_title($title), ENT_QUOTES, 'UTF-8') ?></title>
    <?php wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/assets/css/main.css">
    <link rel="stylesheet" href="/wucportal/students/css/dashboard.css">
    <?php require __DIR__ . '/includes/notifications_page_styles.php'; ?>
</head>
<body class="bg-light student-dashboard-page student-portal has-unified-sidebar">
    <?php require_once __DIR__ . '/students/includes/navbar.php'; ?>
    <?php
} else {
    $page_title = $title;
    $GLOBALS['wuc_suppress_portal_alerts_stack'] = true;
    require __DIR__ . '/includes/notifications_staff_nav.php';
    require __DIR__ . '/includes/notifications_page_styles.php';
}
?>
<main class="<?= $isStudentViewer ? 'dash-content content-wrapper portal-dashboard pt-3' : 'notification-shell portal-dashboard' ?>">
    <section class="notification-hero mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1><i class="fas fa-bell me-2 text-primary"></i><?= htmlspecialchars($title) ?></h1>
                <p>Campus updates and workflow alerts for <?= htmlspecialchars($roleDisplay) ?> — <?= htmlspecialchars($viewerName) ?>.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Dashboard
                </a>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-primary" <?= $navUnreadCount === 0 ? 'disabled' : '' ?>>
                        <i class="fas fa-check-double me-1"></i>Mark all read
                    </button>
                </form>
            </div>
        </div>
    </section>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($flashType) ?> d-flex align-items-center justify-content-between gap-2" role="status">
            <span><i class="fas <?= $flashType === 'success' ? 'fa-circle-check' : 'fa-circle-info' ?> me-2"></i><?= htmlspecialchars($flash) ?></span>
            <button type="button" class="btn-close" aria-label="Close" onclick="this.closest('.alert').remove()"></button>
        </div>
    <?php endif; ?>

    <section class="row g-3 mb-3" aria-label="Notification summary">
        <div class="col-6 col-lg-3"><div class="notification-stat"><span>Unread</span><strong><?= htmlspecialchars((string)$counts['unread']) ?></strong></div></div>
        <div class="col-6 col-lg-3"><div class="notification-stat"><span>Critical</span><strong><?= htmlspecialchars((string)$counts['critical']) ?></strong></div></div>
        <div class="col-6 col-lg-3"><div class="notification-stat"><span>Warnings</span><strong><?= htmlspecialchars((string)$counts['warning']) ?></strong></div></div>
        <div class="col-6 col-lg-3"><div class="notification-stat"><span>Active</span><strong><?= htmlspecialchars((string)$counts['active']) ?></strong></div></div>
    </section>

    <section class="notification-card data-table-card">
        <div class="p-3 border-bottom">
            <form method="get" class="row g-2 align-items-end">
                <?php if ($portalHint === 'elearning'): ?>
                    <input type="hidden" name="portal" value="elearning">
                <?php endif; ?>
                <div class="col-md-4">
                    <label for="status" class="form-label fw-semibold">Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach (['active' => 'Active', 'unread' => 'Unread', 'read' => 'Read', 'dismissed' => 'Dismissed', 'all' => 'All'] as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="severity" class="form-label fw-semibold">Severity</label>
                    <select class="form-select" id="severity" name="severity">
                        <option value="">All severities</option>
                        <?php foreach (['critical' => 'Critical', 'warning' => 'Warning', 'info' => 'Info'] as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= $severityFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Apply Filters</button>
                </div>
            </form>
        </div>

        <?php if (!$alertsReady): ?>
            <div class="empty-state">
                <i class="fas fa-database"></i>
                <p>The notifications table is not installed yet. Run migration <code>migrations/20260702_zero_cost_ai_foundations.sql</code>.</p>
            </div>
        <?php elseif (!$alerts): ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <p>No notifications available at the moment.</p>
            </div>
        <?php else: ?>
            <?php foreach ($alerts as $alert): ?>
                <?php
                    $severity = (string)$alert['severity'];
                    $safeUrl = wuc_notifications_safe_url($alert['action_url'] ?? null);
                    $openUrl = '/wucportal/notifications.php?open=' . (int)$alert['id'];
                    if ($selfQuery) {
                        $openUrl .= '&' . http_build_query($selfQuery);
                    }
                    $isUnread = ($alert['status'] ?? '') === 'unread';
                    $moduleLabel = wuc_notifications_module_label((string)$alert['alert_type']);
                ?>
                <article class="notification-item<?= $isUnread ? ' unread' : '' ?>">
                    <span class="notification-icon <?= htmlspecialchars($severity) ?>">
                        <i class="fas <?= htmlspecialchars(wuc_notifications_icon($severity)) ?>"></i>
                    </span>
                    <div>
                        <div class="notification-title"><?= htmlspecialchars((string)$alert['title']) ?></div>
                        <div class="notification-message"><?= htmlspecialchars((string)$alert['message']) ?></div>
                        <div class="notification-meta">
                            <span class="badge bg-<?= htmlspecialchars(wuc_notifications_badge_class($severity)) ?>"><?= htmlspecialchars(ucfirst($severity)) ?></span>
                            <span class="badge bg-light text-dark"><?= htmlspecialchars(ucfirst((string)$alert['status'])) ?></span>
                            <span class="badge bg-primary"><?= htmlspecialchars($moduleLabel) ?></span>
                            <span class="text-muted small"><?= htmlspecialchars(date('M d, Y H:i', strtotime((string)$alert['created_at']))) ?></span>
                        </div>
                    </div>
                    <div class="notification-actions">
                        <?php if ($safeUrl !== ''): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($openUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="fas fa-arrow-up-right-from-square me-1"></i>Open
                            </a>
                        <?php endif; ?>
                        <?php if (($alert['status'] ?? '') === 'unread'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="alert_id" value="<?= (int)$alert['id'] ?>">
                                <button class="btn btn-sm btn-outline-success" type="submit"><i class="fas fa-check me-1"></i>Read</button>
                            </form>
                        <?php endif; ?>
                        <?php if (($alert['status'] ?? '') !== 'dismissed'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="dismiss">
                                <input type="hidden" name="alert_id" value="<?= (int)$alert['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fas fa-xmark me-1"></i>Dismiss</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    ['status', 'severity'].forEach(function (id) {
        var select = document.getElementById(id);
        if (select && select.form) {
            select.addEventListener('change', function () { select.form.submit(); });
        }
    });
});
</script>
<?php if ($isStudentViewer): ?>
</body>
</html>
<?php else: ?>
    <?php require $wuc_notifications_staff_footer; ?>
<?php endif; ?>
