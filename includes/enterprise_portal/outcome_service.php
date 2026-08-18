<?php
declare(strict_types=1);

if (!function_exists('ep_outcome_types')) {
    /** @return array<string,string> */
    function ep_outcome_types(): array
    {
        return [
            'employment_offer' => 'Employment offer',
            'employment_started' => 'Employment started',
            'product_order' => 'Product order',
            'product_order_completed' => 'Product order completed',
            'service_contract' => 'Service contract',
            'service_completed' => 'Service completed',
            'mentorship_started' => 'Mentorship started',
            'partnership_created' => 'Partnership created',
            'equipment_received' => 'Equipment received',
            'training_received' => 'Training received',
            'funding_referral' => 'Funding referral',
            'funding_approved' => 'Funding approved',
            'funding_received' => 'Funding received',
            'market_linkage' => 'Market linkage',
            'innovation_support' => 'Innovation support',
        ];
    }
}

if (!function_exists('ep_record_outcome')) {
    /**
     * Outcomes are recorded only after confirmation — typically from a converted lead.
     * @param array<string,mixed> $data
     * @return array{ok:bool,message:string,id?:int}
     */
    function ep_record_outcome(mysqli $db, array $data): array
    {
        $type = (string)($data['outcome_type'] ?? '');
        if (!isset(ep_outcome_types()[$type])) {
            return ['ok' => false, 'message' => 'Invalid outcome type.'];
        }
        $interestId = isset($data['enterprise_interest_id']) && $data['enterprise_interest_id'] !== ''
            ? (int)$data['enterprise_interest_id'] : null;
        $oppId = isset($data['enterprise_opportunity_id']) && $data['enterprise_opportunity_id'] !== ''
            ? (int)$data['enterprise_opportunity_id'] : null;
        $profileId = isset($data['enterprise_profile_id']) && $data['enterprise_profile_id'] !== ''
            ? (int)$data['enterprise_profile_id'] : null;

        if ($interestId) {
            $interest = ep_get_interest($db, $interestId);
            if (!$interest) {
                return ['ok' => false, 'message' => 'Related interest not found.'];
            }
            if ((string)$interest['lead_status'] !== 'converted') {
                $upd = ep_update_lead_status($db, $interestId, 'converted', 'Outcome recorded', '', 'Converted to outcome');
                if (!$upd['ok']) {
                    return $upd;
                }
            }
            $oppId = $oppId ?: (int)$interest['enterprise_opportunity_id'];
            $profileId = $profileId ?: (int)$interest['enterprise_profile_id'];
        }
        if (!$oppId && !$profileId) {
            return ['ok' => false, 'message' => 'Outcome must link to an opportunity or profile.'];
        }

        $stage = trim((string)($data['outcome_stage'] ?? 'recorded')) ?: 'recorded';
        $value = isset($data['estimated_value']) && $data['estimated_value'] !== '' ? (float)$data['estimated_value'] : null;
        $currency = trim((string)($data['currency'] ?? 'ZMW')) ?: 'ZMW';
        $jobs = isset($data['jobs_created']) && $data['jobs_created'] !== '' ? (int)$data['jobs_created'] : null;
        $desc = trim((string)($data['description'] ?? ''));
        $now = date('Y-m-d H:i:s');
        $actor = ep_current_actor();

        $stmt = $db->prepare("INSERT INTO enterprise_outcomes (
            enterprise_interest_id, enterprise_opportunity_id, enterprise_profile_id,
            outcome_type, outcome_stage, estimated_value, currency, jobs_created,
            description, verification_status, recorded_by, recorded_at
        ) VALUES (?,?,?,?,?,?,?,?,?,'unverified',?,?)");
        $stmt->bind_param(
            'iiissdsisss',
            $interestId, $oppId, $profileId, $type, $stage, $value, $currency, $jobs, $desc, $actor, $now
        );
        // i i i s s d s i s s s = iiissdsisss
        if (!$stmt->execute()) {
            $stmt->close();
            return ['ok' => false, 'message' => 'Could not record outcome.'];
        }
        $id = (int)$db->insert_id;
        $stmt->close();
        ep_audit($db, 'enterprise_portal.outcome_recorded', ['outcome_id' => $id, 'type' => $type]);
        if (function_exists('ep_notify_outcome')) {
            ep_notify_outcome($db, $id);
        }
        return ['ok' => true, 'message' => 'Outcome recorded.', 'id' => $id];
    }
}

if (!function_exists('ep_list_outcomes')) {
    function ep_list_outcomes(mysqli $db, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $db->prepare('SELECT * FROM enterprise_outcomes ORDER BY recorded_at DESC LIMIT ?');
        $stmt->bind_param('i', $limit);
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

if (!function_exists('ep_submit_complaint')) {
    /** @param array<string,mixed> $data */
    function ep_submit_complaint(mysqli $db, array $data): array
    {
        $oppId = isset($data['enterprise_opportunity_id']) ? (int)$data['enterprise_opportunity_id'] : null;
        $type = trim((string)($data['complaint_type'] ?? ''));
        $desc = trim((string)($data['description'] ?? ''));
        $allowed = ['misleading', 'fraud', 'copyright', 'unavailable', 'incorrect_contact', 'inappropriate', 'safety'];
        if (!in_array($type, $allowed, true)) {
            return ['ok' => false, 'message' => 'Invalid complaint type.'];
        }
        if ($desc === '' || strlen($desc) > 4000) {
            return ['ok' => false, 'message' => 'Please describe the issue.'];
        }
        $name = trim((string)($data['reporter_name'] ?? '')) ?: null;
        $email = trim((string)($data['reporter_email'] ?? '')) ?: null;
        $stmt = $db->prepare('INSERT INTO enterprise_complaints (enterprise_opportunity_id, reporter_name, reporter_email, complaint_type, description, status) VALUES (?,?,?,?,?,\'submitted\')');
        $stmt->bind_param('issss', $oppId, $name, $email, $type, $desc);
        $stmt->execute();
        $id = (int)$db->insert_id;
        $stmt->close();
        ep_audit($db, 'enterprise_portal.complaint_submitted', ['complaint_id' => $id]);
        return ['ok' => true, 'message' => 'Report submitted. It will be reviewed privately.', 'id' => $id];
    }
}
