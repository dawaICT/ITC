<?php
declare(strict_types=1);

if (!function_exists('eh_hash_sensitive')) {
    function eh_hash_sensitive(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }
}

if (!function_exists('eh_interest_rate_limited')) {
    function eh_interest_rate_limited(mysqli $db, string $ip, string $email): bool
    {
        $minutes = (int)(eh_setting($db, 'interest_rate_limit_minutes', '5') ?? 5);
        $max = (int)(eh_setting($db, 'interest_rate_limit_count', '3') ?? 3);
        $ipHash = eh_hash_sensitive($ip);
        $emailHash = eh_hash_sensitive($email);
        $stmt = $db->prepare("
            SELECT COUNT(*) c FROM enterprise_interest_rate_limits
            WHERE (ip_hash = ? OR email_hash = ?)
              AND submitted_at >= (NOW() - INTERVAL ? MINUTE)
        ");
        $stmt->bind_param('ssi', $ipHash, $emailHash, $minutes);
        $stmt->execute();
        $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $c >= $max;
    }
}

if (!function_exists('eh_record_interest_rate')) {
    function eh_record_interest_rate(mysqli $db, string $ip, string $email): void
    {
        $ipHash = eh_hash_sensitive($ip);
        $emailHash = eh_hash_sensitive($email);
        $stmt = $db->prepare('INSERT INTO enterprise_interest_rate_limits (ip_hash, email_hash) VALUES (?, ?)');
        $stmt->bind_param('ss', $ipHash, $emailHash);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('eh_submit_interest')) {
    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool, message:string, id?:int}
     */
    function eh_submit_interest(mysqli $db, int $itemId, array $input, string $ip = ''): array
    {
        // Honeypot
        if (trim((string)($input['website'] ?? '')) !== '') {
            return ['ok' => true, 'message' => 'Thank you. Your interest has been recorded.']; // silent drop
        }

        $item = eh_get_item($db, $itemId);
        if (!$item || ($item['status'] ?? '') !== 'published') {
            return ['ok' => false, 'message' => 'This opportunity is not available.'];
        }

        $name = trim((string)($input['visitor_name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $type = trim((string)($input['interest_type'] ?? ''));
        $message = trim((string)($input['message'] ?? ''));
        $consent = !empty($input['consent_accepted']);
        $org = trim((string)($input['organization'] ?? ''));
        $range = trim((string)($input['investment_range'] ?? ''));
        $qty = isset($input['quantity_requested']) && $input['quantity_requested'] !== ''
            ? (int)$input['quantity_requested'] : null;
        $contact = trim((string)($input['preferred_contact_method'] ?? 'email'));

        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Full name is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
            $errors[] = 'A valid email is required.';
        }
        if ($phone === '' || mb_strlen($phone) > 40 || !preg_match('/^[0-9+\-\s()]{7,40}$/', $phone)) {
            $errors[] = 'A valid phone number is required.';
        }
        if (!array_key_exists($type, eh_interest_types())) {
            $errors[] = 'Select a valid interest type.';
        }
        if ($message === '' || mb_strlen($message) > 2000) {
            $errors[] = 'Message is required (max 2000 characters).';
        }
        if (!$consent) {
            $errors[] = 'Consent is required.';
        }
        if ($errors) {
            return ['ok' => false, 'message' => implode(' ', $errors)];
        }

        // Duplicate protection (same email + item within 24h)
        $dup = $db->prepare("
            SELECT id FROM enterprise_interests
            WHERE enterprise_item_id = ? AND email = ?
              AND created_at >= (NOW() - INTERVAL 1 DAY)
            LIMIT 1
        ");
        $dup->bind_param('is', $itemId, $email);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $dup->close();
            return ['ok' => false, 'message' => 'You already submitted interest for this opportunity recently.'];
        }
        $dup->close();

        if ($ip !== '' && eh_interest_rate_limited($db, $ip, $email)) {
            return ['ok' => false, 'message' => 'Too many submissions. Please try again later.'];
        }

        $ipHash = $ip !== '' ? eh_hash_sensitive($ip) : null;
        $source = 'showcase';
        $stmt = $db->prepare("INSERT INTO enterprise_interests (
            enterprise_item_id, visitor_name, organization, email, phone, interest_type,
            investment_range, quantity_requested, message, preferred_contact_method,
            consent_accepted, source, ip_hash
        ) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?)");
        $stmt->bind_param(
            'issssssissss',
            $itemId, $name, $org, $email, $phone, $type, $range, $qty, $message, $contact, $source, $ipHash
        );
        // Fix null qty binding — use 0 then nullify if needed
        if ($qty === null) {
            $stmt->close();
            $qtyZero = 0;
            $stmt = $db->prepare("INSERT INTO enterprise_interests (
                enterprise_item_id, visitor_name, organization, email, phone, interest_type,
                investment_range, quantity_requested, message, preferred_contact_method,
                consent_accepted, source, ip_hash
            ) VALUES (?,?,?,?,?,?,?,NULL,?,?,1,?,?)");
            $stmt->bind_param(
                'issssssssss',
                $itemId, $name, $org, $email, $phone, $type, $range, $message, $contact, $source, $ipHash
            );
        }
        $stmt->execute();
        $id = (int)$db->insert_id;
        $stmt->close();

        if ($ip !== '') {
            eh_record_interest_rate($db, $ip, $email);
        }

        eh_audit($db, 'enterprise_hub.interest_submitted', [
            'interest_id' => $id,
            'item_id' => $itemId,
            'interest_type' => $type,
        ]);

        if (function_exists('eh_notify_new_interest')) {
            eh_notify_new_interest($db, $item, $id);
        }

        return ['ok' => true, 'message' => 'Thank you. Your expression of interest has been received.', 'id' => $id];
    }
}

if (!function_exists('eh_get_interest')) {
    function eh_get_interest(mysqli $db, int $id): ?array
    {
        $stmt = $db->prepare("
            SELECT ii.*, i.title, i.public_code, i.status AS item_status, p.business_name
            FROM enterprise_interests ii
            JOIN enterprise_items i ON i.id = ii.enterprise_item_id
            JOIN enterprise_profiles p ON p.id = i.enterprise_profile_id
            WHERE ii.id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('eh_update_interest_followup')) {
    function eh_update_interest_followup(mysqli $db, int $id, array $data): array
    {
        if (!eh_can($db, 'enterprise.interests.manage')) {
            return ['ok' => false, 'message' => 'Permission denied.'];
        }
        $status = (string)($data['follow_up_status'] ?? '');
        if (!array_key_exists($status, eh_follow_up_statuses())) {
            return ['ok' => false, 'message' => 'Invalid follow-up status.'];
        }
        $assigned = trim((string)($data['assigned_to'] ?? ''));
        $notes = trim((string)($data['internal_notes'] ?? ''));
        $closedAt = in_array($status, ['converted', 'closed', 'not_suitable'], true) ? date('Y-m-d H:i:s') : null;

        $stmt = $db->prepare("UPDATE enterprise_interests SET
            follow_up_status=?, assigned_to=?, internal_notes=?, closed_at=?, updated_at=NOW()
            WHERE id=?");
        $stmt->bind_param('ssssi', $status, $assigned, $notes, $closedAt, $id);
        $stmt->execute();
        $stmt->close();

        eh_audit($db, 'enterprise_hub.interest_updated', [
            'interest_id' => $id,
            'follow_up_status' => $status,
            'assigned_to' => $assigned,
        ]);

        if ($assigned !== '' && function_exists('eh_notify_interest_assigned')) {
            eh_notify_interest_assigned($db, $id, $assigned);
        }

        return ['ok' => true, 'message' => 'Interest updated.'];
    }
}

if (!function_exists('eh_list_interests')) {
    function eh_list_interests(mysqli $db, array $filters = [], int $limit = 100): array
    {
        $where = ['1=1'];
        $types = '';
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'ii.follow_up_status = ?';
            $types .= 's';
            $params[] = $filters['status'];
        }
        if (!empty($filters['item_id'])) {
            $where[] = 'ii.enterprise_item_id = ?';
            $types .= 'i';
            $params[] = (int)$filters['item_id'];
        }
        if (!empty($filters['profile_id'])) {
            $where[] = 'i.enterprise_profile_id = ?';
            $types .= 'i';
            $params[] = (int)$filters['profile_id'];
        }
        $sql = "SELECT ii.*, i.title, i.public_code, p.business_name
                FROM enterprise_interests ii
                JOIN enterprise_items i ON i.id = ii.enterprise_item_id
                JOIN enterprise_profiles p ON p.id = i.enterprise_profile_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ii.created_at DESC LIMIT ?";
        $types .= 'i';
        $params[] = $limit;
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
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
