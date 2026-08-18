<?php
declare(strict_types=1);

if (!function_exists('ep_notify_user')) {
    function ep_notify_user(mysqli $db, string $userId, string $role, string $title, string $message, string $actionUrl, string $alertType = 'enterprise_event'): void
    {
        if ($userId === '' || !function_exists('wuc_notify_portal')) {
            return;
        }
        wuc_notify_portal($db, [
            'user_id' => $userId,
            'user_role' => $role,
            'title' => $title,
            'message' => $message,
            'alert_type' => $alertType,
            'module' => 'enterprise_portal',
            'action_url' => $actionUrl,
            'source_portal' => 'enterprise',
            'target_portal' => 'enterprise',
            'severity' => 'info',
        ]);
    }
}

if (!function_exists('ep_membership_notify_target')) {
    /** @return array{user_id:string,role:string}|null */
    function ep_membership_notify_target(mysqli $db, int $membershipId): ?array
    {
        $stmt = $db->prepare('SELECT user_id, student_id FROM enterprise_memberships WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $membershipId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        if (!empty($row['student_id'])) {
            return ['user_id' => (string)$row['student_id'], 'role' => 'student'];
        }
        if ((int)$row['user_id'] > 0) {
            return ['user_id' => (string)$row['user_id'], 'role' => 'staff'];
        }
        return null;
    }
}

if (!function_exists('ep_notify_membership')) {
    function ep_notify_membership(mysqli $db, int $membershipId, string $status, string $reason = ''): void
    {
        $target = ep_membership_notify_target($db, $membershipId);
        if (!$target) {
            return;
        }
        $map = [
            'pending' => ['Opt-in submitted', 'Your Skills and Enterprise Portal request is pending review.', '/wucportal/enterprise/membership_status.php'],
            'active' => ['Membership approved', 'Your Skills and Enterprise Portal membership is active.', '/wucportal/enterprise/index.php'],
            'changes_requested' => ['Membership changes requested', $reason !== '' ? $reason : 'Please update your membership information.', '/wucportal/enterprise/membership_status.php'],
            'declined' => ['Membership declined', $reason !== '' ? $reason : 'Your membership request was declined.', '/wucportal/enterprise/membership_status.php'],
            'suspended' => ['Membership suspended', $reason !== '' ? $reason : 'Your portal access has been suspended.', '/wucportal/enterprise/membership_status.php'],
            'withdrawn' => ['Participation withdrawn', 'Your Skills and Enterprise Portal participation has been withdrawn.', '/wucportal/enterprise/membership_status.php'],
        ];
        if (!isset($map[$status])) {
            return;
        }
        ep_notify_user($db, $target['user_id'], $target['role'], $map[$status][0], $map[$status][1], $map[$status][2], 'enterprise_membership');
    }
}

if (!function_exists('ep_notify_opportunity')) {
    function ep_notify_opportunity(mysqli $db, int $opportunityId, string $status, string $comments = ''): void
    {
        $opp = ep_get_opportunity($db, $opportunityId);
        if (!$opp) {
            return;
        }
        $mid = (int)$opp['membership_id'];
        $target = ep_membership_notify_target($db, $mid);
        if (!$target) {
            return;
        }
        $url = '/wucportal/enterprise/opportunities/view.php?id=' . $opportunityId;
        $titles = [
            'submitted' => 'Opportunity submitted',
            'changes_requested' => 'Changes requested',
            'reviewer_verified' => 'Opportunity verified',
            'rejected' => 'Opportunity rejected',
            'approved' => 'Opportunity approved',
            'published' => 'Opportunity published',
            'unpublished' => 'Opportunity unpublished',
            'update_required' => 'Update required',
        ];
        if (!isset($titles[$status])) {
            return;
        }
        $msg = $comments !== '' ? $comments : ('Status changed to ' . ep_status_label($status) . '.');
        ep_notify_user($db, $target['user_id'], $target['role'], $titles[$status], $msg, $url, 'enterprise_opportunity');
    }
}

if (!function_exists('ep_notify_interest')) {
    function ep_notify_interest(mysqli $db, int $interestId): void
    {
        $interest = ep_get_interest($db, $interestId);
        if (!$interest) {
            return;
        }
        $opp = ep_get_opportunity($db, (int)$interest['enterprise_opportunity_id']);
        if (!$opp) {
            return;
        }
        $target = ep_membership_notify_target($db, (int)$opp['membership_id']);
        if ($target) {
            ep_notify_user(
                $db,
                $target['user_id'],
                $target['role'],
                'New expression of interest',
                'Someone expressed interest in: ' . (string)$opp['title'],
                '/wucportal/enterprise/interests/index.php',
                'enterprise_interest'
            );
        }
    }
}

if (!function_exists('ep_notify_outcome')) {
    function ep_notify_outcome(mysqli $db, int $outcomeId): void
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_outcomes WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $outcomeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['enterprise_profile_id'])) {
            return;
        }
        $p = $db->prepare('SELECT membership_id FROM enterprise_member_profiles WHERE id = ? LIMIT 1');
        $pid = (int)$row['enterprise_profile_id'];
        $p->bind_param('i', $pid);
        $p->execute();
        $prof = $p->get_result()->fetch_assoc();
        $p->close();
        if (!$prof) {
            return;
        }
        $target = ep_membership_notify_target($db, (int)$prof['membership_id']);
        if ($target) {
            ep_notify_user(
                $db,
                $target['user_id'],
                $target['role'],
                'Outcome recorded',
                'An outcome was recorded for your enterprise participation.',
                '/wucportal/enterprise/outcomes/index.php',
                'enterprise_outcome'
            );
        }
    }
}
