<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/guard.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    wuc_redirect('/wucportal/enterprise/index.php');
}

ep_require_post_csrf();

$orgId = (int)($_POST['organization_id'] ?? 0);
$membershipId = (int)($_POST['membership_id'] ?? 0);
$return = trim((string)($_POST['return_url'] ?? '/wucportal/enterprise/index.php'));
if (!str_starts_with($return, '/wucportal/')) {
    $return = '/wucportal/enterprise/index.php';
}

$userId = ep_current_user_id();

if ($orgId > 0) {
    if (!ep_user_can_access_organization($db, $userId, $orgId)) {
        $_SESSION['flash_error'] = 'You cannot access that organization workspace.';
        wuc_redirect($return);
    }
    ep_set_current_organization_id($orgId);
    $_SESSION['flash_success'] = 'Organization workspace updated.';
}

if ($membershipId > 0) {
    $stmt = $db->prepare('SELECT id, user_id, student_id FROM enterprise_memberships WHERE id = ? AND status <> ? LIMIT 1');
    if ($stmt) {
        $arch = 'archived';
        $stmt->bind_param('is', $membershipId, $arch);
        $stmt->execute();
        $mem = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($mem && ($userId <= 0 || (int)$mem['user_id'] === $userId)) {
            $_SESSION['enterprise_membership_id'] = (int)$mem['id'];
            $_SESSION['flash_success'] = 'Participation workspace updated.';
        }
    }
}

wuc_redirect($return);
