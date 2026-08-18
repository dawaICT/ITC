<?php
declare(strict_types=1);

if (!function_exists('ep_interest_types')) {
    /** @return array<string,string> */
    function ep_interest_types(): array
    {
        return [
            'product_purchase' => 'Product purchase interest',
            'service_request' => 'Service request',
            'employment' => 'Employment opportunity',
            'mentorship' => 'Mentorship offer',
            'partnership' => 'Partnership proposal',
            'equipment_support' => 'Equipment support',
            'training' => 'Training opportunity',
            'distribution' => 'Distribution proposal',
            'market_linkage' => 'Market-linkage opportunity',
            'funding' => 'Funding or investment enquiry',
            'general' => 'General information request',
        ];
    }
}

if (!function_exists('ep_lead_statuses')) {
    /** @return list<string> */
    function ep_lead_statuses(): array
    {
        return [
            'new', 'acknowledged', 'assigned', 'contact_attempted', 'contacted',
            'meeting_scheduled', 'information_requested', 'under_review', 'proposal_sent',
            'converted', 'closed_unsuccessful', 'invalid', 'spam',
        ];
    }
}

if (!function_exists('ep_lead_age_label')) {
    function ep_lead_age_label(mysqli $db, string $createdAt): string
    {
        $days = (int)floor((time() - strtotime($createdAt)) / 86400);
        $ovd = (int)(ep_setting($db, 'lead_overdue_days', '6') ?? 6);
        $esc = (int)(ep_setting($db, 'lead_escalated_days', '10') ?? 10);
        if ($days <= 2) {
            return 'New';
        }
        if ($days < $ovd) {
            return 'Requires Attention';
        }
        if ($days < $esc) {
            return 'Overdue';
        }
        return 'Escalated';
    }
}

if (!function_exists('ep_interest_throttled')) {
    function ep_interest_throttled(mysqli $db, string $ipHash, string $emailHash): bool
    {
        $stmt = $db->prepare("SELECT COUNT(*) c FROM enterprise_interest_throttle
            WHERE (ip_hash = ? OR email_hash = ?) AND submitted_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->bind_param('ss', $ipHash, $emailHash);
        $stmt->execute();
        $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $c >= 5;
    }
}

if (!function_exists('ep_submit_interest')) {
    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,message:string,id?:int}
     */
    function ep_submit_interest(mysqli $db, int $opportunityId, array $data, string $ip = ''): array
    {
        if (!ep_setting_bool($db, 'public_directory', true)) {
            return ['ok' => false, 'message' => 'The public directory is currently unavailable.'];
        }
        $opp = ep_get_opportunity($db, $opportunityId);
        if (!$opp || (string)$opp['status'] !== 'published') {
            return ['ok' => false, 'message' => 'This opportunity is not available for interest.'];
        }

        $name = trim((string)($data['visitor_name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $type = trim((string)($data['interest_type'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));
        $consent = !empty($data['consent_accepted']);

        if ($name === '' || strlen($name) > 120) {
            return ['ok' => false, 'message' => 'Please provide your name.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) {
            return ['ok' => false, 'message' => 'Please provide a valid email address.'];
        }
        if ($phone === '' || strlen($phone) > 40) {
            return ['ok' => false, 'message' => 'Please provide a phone number.'];
        }
        if (!isset(ep_interest_types()[$type])) {
            return ['ok' => false, 'message' => 'Invalid interest type.'];
        }
        if ($message === '' || strlen($message) > 4000) {
            return ['ok' => false, 'message' => 'Please provide a message (max 4000 characters).'];
        }
        if (!$consent) {
            return ['ok' => false, 'message' => 'Consent is required to submit an expression of interest.'];
        }

        $ipHash = hash('sha256', $ip !== '' ? $ip : 'unknown');
        $emailHash = hash('sha256', strtolower($email));
        if (ep_interest_throttled($db, $ipHash, $emailHash)) {
            return ['ok' => false, 'message' => 'Too many submissions. Please try again later.'];
        }

        $dup = $db->prepare("SELECT id FROM enterprise_opportunity_interests
            WHERE enterprise_opportunity_id = ? AND email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
        $dup->bind_param('is', $opportunityId, $email);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $dup->close();
            return ['ok' => false, 'message' => 'You recently submitted interest for this opportunity.'];
        }
        $dup->close();

        $details = [
            'quantity' => $data['quantity_requested'] ?? null,
            'required_date' => $data['required_date'] ?? null,
            'delivery_location' => $data['delivery_location'] ?? null,
            'budget' => $data['budget'] ?? null,
            'service_needed' => $data['service_needed'] ?? null,
            'scope' => $data['scope'] ?? null,
            'employer' => $data['employer'] ?? null,
            'role_title' => $data['role_title'] ?? null,
            'employment_type' => $data['employment_type'] ?? null,
            'funding_type' => $data['funding_type'] ?? null,
            'funding_conditions' => $data['funding_conditions'] ?? null,
        ];
        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);
        $org = trim((string)($data['organization'] ?? '')) ?: null;
        $invRange = trim((string)($data['investment_range'] ?? '')) ?: null;
        $qty = isset($data['quantity_requested']) && $data['quantity_requested'] !== '' ? (int)$data['quantity_requested'] : null;
        $contact = trim((string)($data['preferred_contact_method'] ?? 'portal_mediated')) ?: 'portal_mediated';

        $stmt = $db->prepare("INSERT INTO enterprise_opportunity_interests (
            enterprise_opportunity_id, visitor_name, organization, email, phone, interest_type,
            interest_details_json, investment_range, quantity_requested, preferred_contact_method,
            message, consent_accepted, lead_status, source, ip_hash
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,'new','directory',?)");
        $stmt->bind_param(
            'isssssssisss',
            $opportunityId, $name, $org, $email, $phone, $type,
            $detailsJson, $invRange, $qty, $contact, $message, $ipHash
        );
        if (!$stmt->execute()) {
            $stmt->close();
            return ['ok' => false, 'message' => 'Could not save interest.'];
        }
        $id = (int)$db->insert_id;
        $stmt->close();

        $th = $db->prepare('INSERT INTO enterprise_interest_throttle (ip_hash, email_hash) VALUES (?,?)');
        $th->bind_param('ss', $ipHash, $emailHash);
        $th->execute();
        $th->close();

        ep_audit($db, 'enterprise_portal.interest_submitted', [
            'interest_id' => $id, 'opportunity_id' => $opportunityId, 'type' => $type,
        ]);
        if (function_exists('ep_notify_interest')) {
            ep_notify_interest($db, $id);
        }
        return ['ok' => true, 'message' => 'Thank you. Your expression of interest has been recorded.', 'id' => $id];
    }
}

if (!function_exists('ep_update_lead_status')) {
    function ep_update_lead_status(mysqli $db, int $interestId, string $status, string $notes = '', string $assignedTo = '', string $closureReason = ''): array
    {
        if (!in_array($status, ep_lead_statuses(), true)) {
            return ['ok' => false, 'message' => 'Invalid lead status.'];
        }
        if (in_array($status, ['closed_unsuccessful', 'invalid', 'spam'], true) && trim($closureReason) === '') {
            return ['ok' => false, 'message' => 'A closure reason is required.'];
        }
        $stmt = $db->prepare('SELECT * FROM enterprise_opportunity_interests WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $interestId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Lead not found.'];
        }

        $closedAt = in_array($status, ['converted', 'closed_unsuccessful', 'invalid', 'spam'], true) ? date('Y-m-d H:i:s') : null;
        $assign = $assignedTo !== '' ? $assignedTo : ($row['assigned_to'] ?? null);
        if ($status === 'assigned' && (!$assign || $assign === '')) {
            $assign = ep_current_actor();
        }
        $internal = $notes !== '' ? trim($notes) : ($row['internal_notes'] ?? null);

        $upd = $db->prepare('UPDATE enterprise_opportunity_interests SET lead_status=?, assigned_to=?, internal_notes=?, closed_at=?, closure_reason=?, updated_at=NOW() WHERE id=?');
        $upd->bind_param('sssssi', $status, $assign, $internal, $closedAt, $closureReason, $interestId);
        $upd->execute();
        $upd->close();
        ep_audit($db, 'enterprise_portal.lead_status', ['interest_id' => $interestId, 'status' => $status]);
        return ['ok' => true, 'message' => 'Lead updated.'];
    }
}

if (!function_exists('ep_list_interests')) {
    function ep_list_interests(mysqli $db, string $leadStatus = '', int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if ($leadStatus !== '') {
            $stmt = $db->prepare('SELECT i.*, o.title, o.public_code FROM enterprise_opportunity_interests i
                JOIN enterprise_opportunities o ON o.id = i.enterprise_opportunity_id
                WHERE i.lead_status = ? ORDER BY i.created_at DESC LIMIT ?');
            $stmt->bind_param('si', $leadStatus, $limit);
        } else {
            $stmt = $db->prepare('SELECT i.*, o.title, o.public_code FROM enterprise_opportunity_interests i
                JOIN enterprise_opportunities o ON o.id = i.enterprise_opportunity_id
                ORDER BY i.created_at DESC LIMIT ?');
            $stmt->bind_param('i', $limit);
        }
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

if (!function_exists('ep_get_interest')) {
    function ep_get_interest(mysqli $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT i.*, o.title, o.public_code, o.enterprise_profile_id
            FROM enterprise_opportunity_interests i
            JOIN enterprise_opportunities o ON o.id = i.enterprise_opportunity_id
            WHERE i.id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}
