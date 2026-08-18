<?php
declare(strict_types=1);

/**
 * Farmer profile domain service (agriculture market access).
 */

if (!function_exists('ep_agri_table_exists')) {
    function ep_agri_table_exists(mysqli $db, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $safe = $db->real_escape_string($table);
        $res = @$db->query("SHOW TABLES LIKE '{$safe}'");
        $cache[$table] = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $cache[$table];
    }
}

if (!function_exists('ep_farmer_verification_levels')) {
    /** @return list<string> */
    function ep_farmer_verification_levels(): array
    {
        return [
            'unverified',
            'phone_verified',
            'cooperative_verified',
            'field_agent_verified',
            'production_verified',
            'commercial_supplier_verified',
        ];
    }
}

if (!function_exists('ep_generate_farmer_code')) {
    function ep_generate_farmer_code(): string
    {
        return 'FRM-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('ep_sql_quote_nullable')) {
    function ep_sql_quote_nullable(mysqli $db, ?string $v): string
    {
        if ($v === null || $v === '') {
            return 'NULL';
        }
        return "'" . $db->real_escape_string($v) . "'";
    }
}

if (!function_exists('ep_agent_can_access_farmer')) {
    function ep_agent_can_access_farmer(mysqli $db, array $farmer, int $agentUserId): bool
    {
        if ($agentUserId <= 0) {
            return false;
        }
        if (function_exists('ep_staff_can') && ep_staff_can($db, 'agriculture.farmers.verify')) {
            return true;
        }
        return (int)($farmer['assigned_agent_user_id'] ?? 0) === $agentUserId;
    }
}

if (!function_exists('ep_register_farmer')) {
    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,farmer_id?:int,farmer_code?:string,message?:string,errors?:list<string>}
     */
    function ep_register_farmer(mysqli $db, array $data, ?int $actingUserId = null): array
    {
        if (!ep_agri_table_exists($db, 'enterprise_farmer_profiles')) {
            return ['ok' => false, 'message' => 'Agriculture schema not migrated.'];
        }

        $errors = [];
        $name = trim((string)($data['full_name'] ?? ''));
        $phone = ep_normalize_msisdn((string)($data['mobile'] ?? $data['phone'] ?? ''));
        $province = trim((string)($data['province'] ?? ''));
        $district = trim((string)($data['district'] ?? ''));
        $camp = trim((string)($data['camp_or_village'] ?? ''));
        $lang = trim((string)($data['preferred_language'] ?? 'en')) ?: 'en';
        $channel = trim((string)($data['preferred_channel'] ?? 'sms')) ?: 'sms';
        $consentMethod = trim((string)($data['consent_method'] ?? 'agent_assisted'));
        $createUser = !empty($data['create_channel_user']);

        if ($name === '') {
            $errors[] = 'Farmer name is required.';
        }
        if ($phone === '') {
            $errors[] = 'Mobile contact is required.';
        }
        if ($province === '' || $district === '') {
            $errors[] = 'Province and district are required.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => implode(' ', $errors)];
        }

        $dup = $db->prepare("SELECT f.id, f.farmer_code FROM enterprise_communication_channels c
            INNER JOIN enterprise_farmer_profiles f ON f.id = c.farmer_profile_id
            WHERE c.phone_e164 = ? AND f.status <> 'archived' LIMIT 1");
        if ($dup) {
            $dup->bind_param('s', $phone);
            $dup->execute();
            $found = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($found) {
                return [
                    'ok' => false,
                    'message' => 'A farmer with this mobile number already exists (' . $found['farmer_code'] . ').',
                    'farmer_id' => (int)$found['id'],
                    'farmer_code' => (string)$found['farmer_code'],
                    'errors' => ['Duplicate farmer mobile number.'],
                ];
            }
        }

        $userId = null;
        if ($createUser) {
            $idRes = ep_adapters()['identity']->resolveOrCreatePhoneUser($db, $phone, $name);
            if (empty($idRes['ok'])) {
                return ['ok' => false, 'message' => $idRes['message'] ?? 'Could not create channel user.'];
            }
            $userId = (int)$idRes['user_id'];
        }

        $code = ep_generate_farmer_code();
        $actor = ep_adapters()['identity']->currentActorLabel() ?: 'system';
        $agentId = $actingUserId ?? ep_adapters()['identity']->currentUserId();
        $agentId = $agentId > 0 ? $agentId : null;

        $db->begin_transaction();
        try {
            $uidLit = $userId === null ? 'NULL' : (string)(int)$userId;
            $agentLit = $agentId === null ? 'NULL' : (string)(int)$agentId;
            $orgId = function_exists('ep_current_organization_id') ? ep_current_organization_id($db) : null;
            $orgCol = '';
            $orgVal = '';
            if ($orgId !== null && $orgId > 0 && function_exists('ep_organizations_table_ready') && ep_organizations_table_ready($db)) {
                $orgCol = ', organization_id';
                $orgVal = ', ' . (int)$orgId;
            }
            $q = sprintf(
                "INSERT INTO enterprise_farmer_profiles
                (farmer_code, user_id, full_name, province, district, camp_or_village, preferred_language,
                 preferred_channel, verification_level, consent_status, assigned_agent_user_id, status, created_by%s)
                VALUES ('%s', %s, %s, %s, %s, %s, %s, %s, 'unverified', 'recorded', %s, 'active', '%s'%s)",
                $orgCol,
                $db->real_escape_string($code),
                $uidLit,
                ep_sql_quote_nullable($db, $name),
                ep_sql_quote_nullable($db, $province),
                ep_sql_quote_nullable($db, $district),
                ep_sql_quote_nullable($db, $camp !== '' ? $camp : null),
                ep_sql_quote_nullable($db, $lang),
                ep_sql_quote_nullable($db, $channel),
                $agentLit,
                $db->real_escape_string($actor),
                $orgVal
            );
            if (!$db->query($q)) {
                throw new RuntimeException($db->error);
            }
            $farmerId = (int)$db->insert_id;

            $db->query(sprintf(
                "INSERT INTO enterprise_communication_channels
                (user_id, farmer_profile_id, channel_type, phone_e164, preferred_language, is_verified, is_primary)
                VALUES (%s, %d, 'sms', '%s', '%s', 0, 1)",
                $uidLit,
                $farmerId,
                $db->real_escape_string($phone),
                $db->real_escape_string($lang)
            ));

            ep_adapters()['audit']->log($db, 'agriculture.farmer_registered', [
                'farmer_id' => $farmerId,
                'farmer_code' => $code,
                'agent_user_id' => $agentId,
                'consent_method' => $consentMethod,
                'channel' => $channel,
                'phone_e164' => $phone,
            ]);

            $db->commit();
            return ['ok' => true, 'farmer_id' => $farmerId, 'farmer_code' => $code];
        } catch (Throwable $e) {
            $db->rollback();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('ep_get_farmer')) {
    function ep_get_farmer(mysqli $db, int $farmerId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_farmer_profiles WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $farmerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('ep_set_farmer_verification')) {
    function ep_set_farmer_verification(mysqli $db, int $farmerId, string $level): array
    {
        if (!in_array($level, ep_farmer_verification_levels(), true)) {
            return ['ok' => false, 'message' => 'Invalid verification level.'];
        }
        $stmt = $db->prepare('UPDATE enterprise_farmer_profiles SET verification_level = ? WHERE id = ?');
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Update failed.'];
        }
        $stmt->bind_param('si', $level, $farmerId);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            ep_adapters()['audit']->log($db, 'agriculture.farmer_verified', [
                'farmer_id' => $farmerId,
                'verification_level' => $level,
                'disclaimer' => 'Verification is not a guarantee of crop quality.',
            ]);
        }
        return ['ok' => (bool)$ok];
    }
}

if (!function_exists('ep_list_farmers_for_staff')) {
    /**
     * Agents see assigned farmers only; officers with verify/create see all active farmers.
     *
     * @return list<array<string,mixed>>
     */
    function ep_list_farmers_for_staff(mysqli $db, int $userId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $canSeeAll = function_exists('ep_staff_can') && (
            ep_staff_can($db, 'agriculture.farmers.verify')
            || ep_staff_can($db, 'agriculture.farmers.create')
        );
        $orgId = function_exists('ep_scope_organization_id') ? ep_scope_organization_id($db) : null;
        $orgSql = $orgId !== null ? ' AND f.organization_id = ' . (int)$orgId : '';
        if ($canSeeAll) {
            $sql = "SELECT f.*, c.phone_e164 FROM enterprise_farmer_profiles f
                LEFT JOIN enterprise_communication_channels c ON c.farmer_profile_id = f.id AND c.is_primary = 1
                WHERE f.status = 'active'{$orgSql} ORDER BY f.id DESC LIMIT {$limit}";
            $r = $db->query($sql);
        } else {
            $stmt = $db->prepare("SELECT f.*, c.phone_e164 FROM enterprise_farmer_profiles f
                LEFT JOIN enterprise_communication_channels c ON c.farmer_profile_id = f.id AND c.is_primary = 1
                WHERE f.status = 'active' AND f.assigned_agent_user_id = ?{$orgSql} ORDER BY f.id DESC LIMIT {$limit}");
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $r = $stmt->get_result();
        }
        $rows = [];
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        if (isset($stmt)) {
            $stmt->close();
        }
        return $rows;
    }
}
