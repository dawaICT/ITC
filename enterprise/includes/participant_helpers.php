<?php
declare(strict_types=1);

if (!function_exists('ep_member_profile_id')) {
    function ep_member_profile_id(?array $epProfile): int
    {
        return $epProfile ? (int)$epProfile['id'] : 0;
    }
}

if (!function_exists('ep_require_member_profile')) {
    function ep_require_member_profile(int $profileId): void
    {
        if ($profileId <= 0) {
            $_SESSION['flash_error'] = 'Complete your professional profile first.';
            wuc_redirect('/wucportal/enterprise/profile/edit.php');
        }
    }
}

if (!function_exists('ep_assert_own_opportunity')) {
    function ep_assert_own_opportunity(?array $opp, int $profileId): void
    {
        if (!$opp || (int)($opp['enterprise_profile_id'] ?? 0) !== $profileId) {
            $_SESSION['flash_error'] = 'Opportunity not found.';
            wuc_redirect('/wucportal/enterprise/opportunities/index.php');
        }
    }
}

if (!function_exists('ep_list_interests_for_profile')) {
    function ep_list_interests_for_profile(mysqli $db, int $profileId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $db->prepare(
            'SELECT i.*, o.title, o.public_code
             FROM enterprise_opportunity_interests i
             JOIN enterprise_opportunities o ON o.id = i.enterprise_opportunity_id
             WHERE o.enterprise_profile_id = ?
             ORDER BY i.created_at DESC
             LIMIT ?'
        );
        $stmt->bind_param('ii', $profileId, $limit);
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

if (!function_exists('ep_count_interests_for_profile')) {
    function ep_count_interests_for_profile(mysqli $db, int $profileId): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS c
             FROM enterprise_opportunity_interests i
             JOIN enterprise_opportunities o ON o.id = i.enterprise_opportunity_id
             WHERE o.enterprise_profile_id = ?'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $profileId);
        $stmt->execute();
        $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $c;
    }
}

if (!function_exists('ep_list_outcomes_for_profile')) {
    function ep_list_outcomes_for_profile(mysqli $db, int $profileId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $db->prepare(
            'SELECT * FROM enterprise_outcomes
             WHERE enterprise_profile_id = ?
             ORDER BY recorded_at DESC
             LIMIT ?'
        );
        $stmt->bind_param('ii', $profileId, $limit);
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

if (!function_exists('ep_list_reviews_for_opportunity')) {
    function ep_list_reviews_for_opportunity(mysqli $db, int $opportunityId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $db->prepare(
            'SELECT * FROM enterprise_opportunity_reviews
             WHERE enterprise_opportunity_id = ?
             ORDER BY reviewed_at DESC
             LIMIT ?'
        );
        $stmt->bind_param('ii', $opportunityId, $limit);
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

if (!function_exists('ep_get_skill_for_profile')) {
    function ep_get_skill_for_profile(mysqli $db, int $skillId, int $profileId): ?array
    {
        $stmt = $db->prepare(
            'SELECT * FROM enterprise_skills WHERE id = ? AND enterprise_profile_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $skillId, $profileId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('ep_notification_recipient')) {
    /** @return array{user_id:string,role:string} */
    function ep_notification_recipient(): array
    {
        if (!empty($_SESSION['Sid'])) {
            return ['user_id' => (string)$_SESSION['Sid'], 'role' => 'student'];
        }
        $uid = ep_current_user_id();
        return ['user_id' => $uid > 0 ? (string)$uid : '', 'role' => 'staff'];
    }
}

if (!function_exists('ep_opportunity_post_data')) {
    /** @return array<string,mixed> */
    function ep_opportunity_post_data(): array
    {
        $keys = [
            'opportunity_type', 'title', 'short_description', 'full_description', 'category_id',
            'pricing_type', 'unit_price', 'minimum_price', 'maximum_price', 'currency',
            'service_area', 'availability_status', 'current_capacity', 'capacity_period',
            'innovation_stage', 'prototype_status', 'problem_statement', 'proposed_solution',
            'investment_required', 'investment_purpose', 'expected_capacity', 'expected_capacity_period',
            'employment_potential', 'preferred_employment_type', 'preferred_location', 'cost_visibility',
        ];
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = trim((string)($_POST[$key] ?? ''));
        }
        return $data;
    }
}

if (!function_exists('ep_media_serve_url')) {
    function ep_media_serve_url(int $mediaId): string
    {
        return '/wucportal/enterprise/media.php?id=' . $mediaId;
    }
}
