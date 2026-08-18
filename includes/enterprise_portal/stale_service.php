<?php
declare(strict_types=1);

/**
 * Stale / expiry handling for published opportunities.
 */

if (!function_exists('ep_stale_settings')) {
    /** @return array{confirm_days:int,update_days:int,unpublish_days:int} */
    function ep_stale_settings(mysqli $db): array
    {
        return [
            'confirm_days' => max(7, (int)(ep_setting($db, 'stale_confirm_days', '60') ?? 60)),
            'update_days' => max(14, (int)(ep_setting($db, 'stale_update_days', '90') ?? 90)),
            'unpublish_days' => max(30, (int)(ep_setting($db, 'stale_unpublish_days', '120') ?? 120)),
        ];
    }
}

if (!function_exists('ep_list_stale_candidates')) {
    function ep_list_stale_candidates(mysqli $db, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $s = ep_stale_settings($db);
        $stmt = $db->prepare(
            "SELECT id, title, public_code, status, published_at, last_verified_at, next_review_at,
                    DATEDIFF(NOW(), COALESCE(last_verified_at, published_at, created_at)) AS days_since_confirm
             FROM enterprise_opportunities
             WHERE status = 'published'
               AND (
                    next_review_at IS NOT NULL AND next_review_at < NOW()
                 OR DATEDIFF(NOW(), COALESCE(last_verified_at, published_at, created_at)) >= ?
               )
             ORDER BY days_since_confirm DESC
             LIMIT ?"
        );
        $confirm = $s['confirm_days'];
        $stmt->bind_param('ii', $confirm, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ep_process_stale_opportunities')) {
    /**
     * Marks stale published records update_required or unpublishes after prolonged non-response.
     * @return array{marked_update:int,unpublished:int}
     */
    function ep_process_stale_opportunities(mysqli $db): array
    {
        $s = ep_stale_settings($db);
        $marked = 0;
        $unpublished = 0;
        $rows = ep_list_stale_candidates($db, 200);
        foreach ($rows as $row) {
            $days = (int)($row['days_since_confirm'] ?? 0);
            $id = (int)$row['id'];
            if ($days >= $s['unpublish_days']) {
                $r = ep_transition_opportunity($db, $id, 'unpublished', 'Automatically unpublished after prolonged non-confirmation.', 'stale');
                if ($r['ok']) {
                    $unpublished++;
                }
            } elseif ($days >= $s['update_days']) {
                $r = ep_transition_opportunity($db, $id, 'update_required', 'Availability confirmation overdue — please update and resubmit.', 'stale');
                if ($r['ok']) {
                    $marked++;
                }
            }
        }
        ep_audit($db, 'enterprise_portal.stale_processed', [
            'marked_update' => $marked,
            'unpublished' => $unpublished,
        ]);
        return ['marked_update' => $marked, 'unpublished' => $unpublished];
    }
}

if (!function_exists('ep_confirm_opportunity_availability')) {
    function ep_confirm_opportunity_availability(mysqli $db, int $opportunityId): array
    {
        $opp = ep_get_opportunity($db, $opportunityId);
        if (!$opp || (string)$opp['status'] !== 'published') {
            return ['ok' => false, 'message' => 'Only published opportunities can confirm availability.'];
        }
        $stmt = $db->prepare('UPDATE enterprise_opportunities SET last_verified_at = NOW(), next_review_at = DATE_ADD(NOW(), INTERVAL 90 DAY), updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $opportunityId);
        $stmt->execute();
        $stmt->close();
        ep_audit($db, 'enterprise_portal.availability_confirmed', ['opportunity_id' => $opportunityId]);
        return ['ok' => true, 'message' => 'Availability confirmed.'];
    }
}

if (!function_exists('ep_feature_opportunity_fairly')) {
    /** Prevent permanent featured domination by the same profile. */
    function ep_feature_opportunity_fairly(mysqli $db, int $opportunityId, bool $featured = true): array
    {
        $opp = ep_get_opportunity($db, $opportunityId);
        if (!$opp || (string)$opp['status'] !== 'published') {
            return ['ok' => false, 'message' => 'Only published opportunities can be featured.'];
        }
        if ($featured) {
            $pid = (int)$opp['enterprise_profile_id'];
            $stmt = $db->prepare("SELECT COUNT(*) c FROM enterprise_opportunities WHERE enterprise_profile_id = ? AND is_featured = 1 AND status = 'published' AND id <> ?");
            $stmt->bind_param('ii', $pid, $opportunityId);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            if ($count >= 2) {
                return ['ok' => false, 'message' => 'This participant already has the maximum featured listings (2). Rotate featured slots fairly.'];
            }
        }
        $flag = $featured ? 1 : 0;
        $upd = $db->prepare('UPDATE enterprise_opportunities SET is_featured = ?, updated_at = NOW() WHERE id = ?');
        $upd->bind_param('ii', $flag, $opportunityId);
        $upd->execute();
        $upd->close();
        ep_audit($db, 'enterprise_portal.feature_toggled', ['opportunity_id' => $opportunityId, 'featured' => $featured]);
        return ['ok' => true, 'message' => $featured ? 'Opportunity featured.' : 'Featured flag removed.'];
    }
}
