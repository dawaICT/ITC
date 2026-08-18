<?php
declare(strict_types=1);

/**
 * Produce listings, buyer demands, filter-assisted matching, farmer consent.
 */

if (!function_exists('ep_generate_listing_code')) {
    function ep_generate_listing_code(string $prefix = 'PL'): string
    {
        return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('ep_create_produce_listing')) {
    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,listing_id?:int,listing_code?:string,message?:string,errors?:list<string>}
     */
    function ep_create_produce_listing(mysqli $db, array $data): array
    {
        if (!ep_agri_table_exists($db, 'enterprise_produce_listings')) {
            return ['ok' => false, 'message' => 'Produce schema not migrated.'];
        }
        $errors = [];
        $farmerId = (int)($data['farmer_profile_id'] ?? 0);
        $commodityId = (int)($data['commodity_id'] ?? 0);
        $qty = (float)($data['quantity'] ?? 0);
        $unit = trim((string)($data['quantity_unit'] ?? ''));
        $location = trim((string)($data['location_label'] ?? ''));
        $expires = trim((string)($data['expires_at'] ?? ''));
        if ($farmerId <= 0) {
            $errors[] = 'Farmer is required.';
        }
        if ($commodityId <= 0) {
            $errors[] = 'Commodity is required.';
        }
        if ($qty <= 0) {
            $errors[] = 'Quantity must be greater than zero.';
        }
        if ($unit === '') {
            $errors[] = 'Unit is required.';
        }
        if ($location === '') {
            $errors[] = 'Location is required.';
        }
        if ($expires === '') {
            $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
        }
        $norm = ep_unit_to_base($db, $unit, $qty);
        if (empty($norm['ok'])) {
            $errors[] = $norm['message'] ?? 'Invalid unit.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => implode(' ', $errors)];
        }

        $farmer = ep_get_farmer($db, $farmerId);
        if (!$farmer || (string)($farmer['status'] ?? '') !== 'active') {
            return ['ok' => false, 'message' => 'Farmer not found or not active.'];
        }
        $actingUserId = (int)($data['acting_user_id'] ?? ep_adapters()['identity']->currentUserId());
        if ($actingUserId > 0 && !ep_agent_can_access_farmer($db, $farmer, $actingUserId)) {
            return ['ok' => false, 'message' => 'You may only create listings for assigned farmers.'];
        }
        if (!ep_record_belongs_to_scope($db, isset($farmer['organization_id']) ? (int)$farmer['organization_id'] : null)) {
            return ['ok' => false, 'message' => 'Farmer is outside your organization workspace.'];
        }

        $code = ep_generate_listing_code('PL');
        $actor = ep_adapters()['identity']->currentActorLabel() ?: 'system';
        $availDate = trim((string)($data['availability_date'] ?? '')) ?: date('Y-m-d');
        $asking = isset($data['asking_price']) && $data['asking_price'] !== '' ? (float)$data['asking_price'] : null;
        $askingLit = $asking === null ? 'NULL' : sprintf('%.2f', $asking);

        $listOrgCol = '';
        $listOrgVal = '';
        $listOrgId = (int)($farmer['organization_id'] ?? 0);
        if ($listOrgId <= 0 && function_exists('ep_scope_organization_id')) {
            $listOrgId = (int)(ep_scope_organization_id($db) ?? 0);
        }
        if ($listOrgId > 0 && ep_agri_table_exists($db, 'enterprise_produce_listings')) {
            $chk = @$db->query("SHOW COLUMNS FROM enterprise_produce_listings LIKE 'organization_id'");
            if ($chk && $chk->num_rows > 0) {
                $listOrgCol = ', organization_id';
                $listOrgVal = ', ' . $listOrgId;
            }
        }

        $sql = sprintf(
            "INSERT INTO enterprise_produce_listings
            (listing_code, farmer_profile_id, commodity_id, grade_label, quantity, quantity_unit, quantity_base, base_unit_code,
             location_label, province, district, availability_date, availability_status, asking_price, currency,
             price_preference, collection_preference, verification_status, expires_at, status, created_by%s)
            VALUES ('%s', %d, %d, %s, %.3f, %s, %.6f, %s, %s, %s, %s, %s, 'available', %s, 'ZMW',
                    %s, %s, 'unverified', %s, 'active', %s%s)",
            $listOrgCol,
            $db->real_escape_string($code),
            $farmerId,
            $commodityId,
            ep_sql_quote_nullable($db, trim((string)($data['grade_label'] ?? '')) ?: null),
            $qty,
            ep_sql_quote_nullable($db, $unit),
            (float)$norm['quantity_base'],
            ep_sql_quote_nullable($db, (string)$norm['base_unit_code']),
            ep_sql_quote_nullable($db, $location),
            ep_sql_quote_nullable($db, trim((string)($data['province'] ?? '')) ?: null),
            ep_sql_quote_nullable($db, trim((string)($data['district'] ?? '')) ?: null),
            ep_sql_quote_nullable($db, $availDate),
            $askingLit,
            ep_sql_quote_nullable($db, trim((string)($data['price_preference'] ?? 'negotiable')) ?: 'negotiable'),
            ep_sql_quote_nullable($db, trim((string)($data['collection_preference'] ?? 'buyer_collection')) ?: 'buyer_collection'),
            ep_sql_quote_nullable($db, $expires),
            "'" . $db->real_escape_string($actor) . "'",
            $listOrgVal
        );
        if (!$db->query($sql)) {
            return ['ok' => false, 'message' => $db->error];
        }
        $id = (int)$db->insert_id;
        ep_adapters()['audit']->log($db, 'agriculture.produce_listed', ['listing_id' => $id, 'listing_code' => $code]);
        return ['ok' => true, 'listing_id' => $id, 'listing_code' => $code];
    }
}

if (!function_exists('ep_expire_produce_listings')) {
    function ep_expire_produce_listings(mysqli $db): int
    {
        if (!ep_agri_table_exists($db, 'enterprise_produce_listings')) {
            return 0;
        }
        $db->query("UPDATE enterprise_produce_listings
            SET status = 'expired', availability_status = 'unavailable'
            WHERE status = 'active' AND expires_at < NOW()");
        return (int)$db->affected_rows;
    }
}

if (!function_exists('ep_expire_buyer_crop_demands')) {
    function ep_expire_buyer_crop_demands(mysqli $db): int
    {
        if (!ep_agri_table_exists($db, 'enterprise_buyer_crop_demands')) {
            return 0;
        }
        $db->query("UPDATE enterprise_buyer_crop_demands
            SET status = 'expired'
            WHERE status IN ('open','approved','pending_review') AND expires_at < NOW()");
        return (int)$db->affected_rows;
    }
}

if (!function_exists('ep_create_buyer_crop_demand')) {
    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,demand_id?:int,demand_code?:string,message?:string,errors?:list<string>}
     */
    function ep_create_buyer_crop_demand(mysqli $db, array $data): array
    {
        if (!ep_agri_table_exists($db, 'enterprise_buyer_crop_demands')) {
            return ['ok' => false, 'message' => 'Demand schema not migrated.'];
        }
        $errors = [];
        $commodityId = (int)($data['commodity_id'] ?? 0);
        $qty = (float)($data['quantity_required'] ?? 0);
        $unit = trim((string)($data['quantity_unit'] ?? ''));
        $location = trim((string)($data['location_label'] ?? ''));
        $buyerStatus = trim((string)($data['buyer_verification_status'] ?? 'unverified'));
        $expires = trim((string)($data['expires_at'] ?? '')) ?: date('Y-m-d H:i:s', strtotime('+30 days'));

        if ($commodityId <= 0) {
            $errors[] = 'Commodity is required.';
        }
        if ($qty <= 0) {
            $errors[] = 'Quantity required must be greater than zero.';
        }
        if ($unit === '') {
            $errors[] = 'Unit is required.';
        }
        if ($location === '') {
            $errors[] = 'Location is required.';
        }
        $norm = ep_unit_to_base($db, $unit, $qty);
        if (empty($norm['ok'])) {
            $errors[] = $norm['message'] ?? 'Invalid unit.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => implode(' ', $errors)];
        }

        $code = ep_generate_listing_code('BD');
        $actor = ep_adapters()['identity']->currentActorLabel() ?: 'system';
        $buyerUserId = (int)($data['buyer_user_id'] ?? 0);
        $buyerLit = $buyerUserId > 0 ? (string)$buyerUserId : 'NULL';
        $offered = isset($data['offered_price']) && $data['offered_price'] !== '' ? sprintf('%.2f', (float)$data['offered_price']) : 'NULL';

        $demOrgCol = '';
        $demOrgVal = '';
        $demOrgId = function_exists('ep_scope_organization_id') ? (int)(ep_scope_organization_id($db) ?? 0) : 0;
        if ($demOrgId > 0) {
            $chk = @$db->query("SHOW COLUMNS FROM enterprise_buyer_crop_demands LIKE 'organization_id'");
            if ($chk && $chk->num_rows > 0) {
                $demOrgCol = ', organization_id';
                $demOrgVal = ', ' . $demOrgId;
            }
        }

        $sql = sprintf(
            "INSERT INTO enterprise_buyer_crop_demands
            (demand_code, buyer_user_id, commodity_id, quantity_required, quantity_unit, quantity_base, base_unit_code,
             location_label, province, district, collection_or_delivery, delivery_deadline, offered_price, pricing_method,
             payment_terms, buyer_verification_status, expires_at, status, created_by%s)
            VALUES ('%s', %s, %d, %.3f, %s, %.6f, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'pending_review', %s%s)",
            $demOrgCol,
            $db->real_escape_string($code),
            $buyerLit,
            $commodityId,
            $qty,
            ep_sql_quote_nullable($db, $unit),
            (float)$norm['quantity_base'],
            ep_sql_quote_nullable($db, (string)$norm['base_unit_code']),
            ep_sql_quote_nullable($db, $location),
            ep_sql_quote_nullable($db, trim((string)($data['province'] ?? '')) ?: null),
            ep_sql_quote_nullable($db, trim((string)($data['district'] ?? '')) ?: null),
            ep_sql_quote_nullable($db, trim((string)($data['collection_or_delivery'] ?? 'buyer_collection')) ?: 'buyer_collection'),
            ep_sql_quote_nullable($db, trim((string)($data['delivery_deadline'] ?? '')) ?: null),
            $offered,
            ep_sql_quote_nullable($db, trim((string)($data['pricing_method'] ?? 'quote_on_request')) ?: 'quote_on_request'),
            ep_sql_quote_nullable($db, trim((string)($data['payment_terms'] ?? '')) ?: null),
            ep_sql_quote_nullable($db, $buyerStatus),
            ep_sql_quote_nullable($db, $expires),
            "'" . $db->real_escape_string($actor) . "'",
            $demOrgVal
        );
        if (!$db->query($sql)) {
            return ['ok' => false, 'message' => $db->error];
        }
        $id = (int)$db->insert_id;
        ep_adapters()['audit']->log($db, 'agriculture.demand_created', ['demand_id' => $id, 'demand_code' => $code]);
        return ['ok' => true, 'demand_id' => $id, 'demand_code' => $code];
    }
}

if (!function_exists('ep_suggest_produce_matches')) {
    /**
     * Filter-assisted suggestions only — never creates a commercial commitment.
     * @return list<array<string,mixed>>
     */
    function ep_suggest_produce_matches(mysqli $db, int $demandId): array
    {
        ep_expire_produce_listings($db);
        ep_expire_buyer_crop_demands($db);
        $stmt = $db->prepare("SELECT * FROM enterprise_buyer_crop_demands WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $demandId);
        $stmt->execute();
        $demand = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$demand) {
            return [];
        }
        if (!in_array((string)$demand['status'], ['open', 'approved', 'pending_review'], true)) {
            return [];
        }
        if ((string)$demand['buyer_verification_status'] !== 'verified') {
            return []; // unverified buyers cannot receive farmer referrals
        }
        if ((string)$demand['status'] === 'expired') {
            return [];
        }
        if (strtotime((string)$demand['expires_at']) < time()) {
            return [];
        }
        if (!ep_record_belongs_to_scope($db, isset($demand['organization_id']) ? (int)$demand['organization_id'] : null)) {
            return [];
        }

        $orgScope = function_exists('ep_scope_organization_id') ? ep_scope_organization_id($db) : null;
        $orgClause = $orgScope !== null ? ' AND f.organization_id = ' . (int)$orgScope : '';

        $sql = "SELECT l.*, f.full_name, f.verification_level, f.farmer_code, c.commodity_name
                FROM enterprise_produce_listings l
                INNER JOIN enterprise_farmer_profiles f ON f.id = l.farmer_profile_id
                INNER JOIN enterprise_commodities c ON c.id = l.commodity_id
                WHERE l.commodity_id = ?
                  AND l.status = 'active'
                  AND l.availability_status = 'available'
                  AND l.expires_at > NOW()
                  AND l.quantity_base > 0{$orgClause}
                ORDER BY l.quantity_base DESC
                LIMIT 50";
        $q = $db->prepare($sql);
        $commodityId = (int)$demand['commodity_id'];
        $q->bind_param('i', $commodityId);
        $q->execute();
        $res = $q->get_result();
        $out = [];
        $need = (float)$demand['quantity_base'];
        while ($row = $res->fetch_assoc()) {
            $score = 40.0;
            $why = ['commodity'];
            if (!empty($demand['province']) && !empty($row['province']) && strcasecmp((string)$demand['province'], (string)$row['province']) === 0) {
                $score += 25;
                $why[] = 'province';
            }
            if (!empty($demand['district']) && !empty($row['district']) && strcasecmp((string)$demand['district'], (string)$row['district']) === 0) {
                $score += 15;
                $why[] = 'district';
            }
            if ((float)$row['quantity_base'] >= $need * 0.5) {
                $score += 10;
                $why[] = 'quantity';
            }
            if (in_array((string)$row['verification_level'], ['field_agent_verified', 'production_verified', 'commercial_supplier_verified'], true)) {
                $score += 10;
                $why[] = 'verification';
            }
            $row['match_score'] = $score;
            $row['match_explanation'] = 'Filter match: ' . implode(', ', $why) . '. Officer review required — not a sale.';
            $row['quantity_proposed'] = min((float)$row['quantity'], (float)$demand['quantity_required']);
            $out[] = $row;
        }
        $q->close();
        usort($out, static fn($a, $b) => $b['match_score'] <=> $a['match_score']);
        return $out;
    }
}

if (!function_exists('ep_record_produce_match')) {
    /**
     * Officer-mediated match suggestion record.
     * @return array{ok:bool,match_id?:int,message?:string}
     */
    function ep_record_produce_match(mysqli $db, int $demandId, int $listingId, float $qtyProposed, string $unit, string $explanation = '', ?float $score = null): array
    {
        ep_expire_produce_listings($db);
        ep_expire_buyer_crop_demands($db);

        $d = $db->prepare('SELECT buyer_verification_status, status, expires_at FROM enterprise_buyer_crop_demands WHERE id = ?');
        $d->bind_param('i', $demandId);
        $d->execute();
        $demand = $d->get_result()->fetch_assoc();
        $d->close();
        if (!$demand) {
            return ['ok' => false, 'message' => 'Demand not found.'];
        }
        if ((string)$demand['buyer_verification_status'] !== 'verified') {
            return ['ok' => false, 'message' => 'Only verified buyers may receive farmer referrals.'];
        }
        if ((string)$demand['status'] === 'expired' || strtotime((string)$demand['expires_at']) < time()) {
            return ['ok' => false, 'message' => 'Demand is expired or closed.'];
        }
        if ($qtyProposed <= 0) {
            return ['ok' => false, 'message' => 'Proposed quantity must be greater than zero.'];
        }

        $lst = $db->prepare("SELECT l.*, f.status AS farmer_status FROM enterprise_produce_listings l
            INNER JOIN enterprise_farmer_profiles f ON f.id = l.farmer_profile_id
            WHERE l.id = ? LIMIT 1");
        $lst->bind_param('i', $listingId);
        $lst->execute();
        $listing = $lst->get_result()->fetch_assoc();
        $lst->close();
        if (!$listing) {
            return ['ok' => false, 'message' => 'Listing not found.'];
        }
        if ((string)$listing['status'] !== 'active' || (string)$listing['availability_status'] !== 'available') {
            return ['ok' => false, 'message' => 'Listing is not available for matching.'];
        }
        if (strtotime((string)$listing['expires_at']) < time()) {
            return ['ok' => false, 'message' => 'Listing has expired.'];
        }
        if ((string)$listing['farmer_status'] !== 'active') {
            return ['ok' => false, 'message' => 'Farmer is not active.'];
        }

        $officer = ep_adapters()['identity']->currentUserId();
        $officerLit = $officer > 0 ? (string)$officer : 'NULL';
        $sql = sprintf(
            "INSERT INTO enterprise_produce_matches
            (demand_id, listing_id, match_score, match_explanation, quantity_proposed, quantity_unit, officer_user_id, officer_decision)
            VALUES (%d, %d, %s, %s, %.3f, %s, %s, 'suggested')",
            $demandId,
            $listingId,
            $score === null ? 'NULL' : sprintf('%.2f', $score),
            ep_sql_quote_nullable($db, $explanation !== '' ? $explanation : 'Officer-reviewed filter match. Not a completed sale.'),
            $qtyProposed,
            ep_sql_quote_nullable($db, $unit),
            $officerLit
        );
        if (!$db->query($sql)) {
            return ['ok' => false, 'message' => $db->error];
        }
        $id = (int)$db->insert_id;
        ep_adapters()['audit']->log($db, 'agriculture.match_suggested', [
            'match_id' => $id,
            'demand_id' => $demandId,
            'listing_id' => $listingId,
            'note' => 'Match is not a completed sale.',
        ]);
        return ['ok' => true, 'match_id' => $id];
    }
}

if (!function_exists('ep_farmer_consent_channels')) {
    /** @return list<string> */
    function ep_farmer_consent_channels(): array
    {
        return ['web', 'ussd_pin', 'structured_sms', 'agent_assisted', 'call_centre'];
    }
}

if (!function_exists('ep_record_farmer_match_consent')) {
    function ep_record_farmer_match_consent(mysqli $db, int $matchId, string $decision, string $channel): array
    {
        $decision = strtolower($decision);
        if (!in_array($decision, ['accepted', 'declined'], true)) {
            return ['ok' => false, 'message' => 'Decision must be accepted or declined.'];
        }
        if (!in_array($channel, ep_farmer_consent_channels(), true)) {
            return ['ok' => false, 'message' => 'Invalid consent channel.'];
        }
        // No preselected consent — caller must pass explicit decision.
        $stmt = $db->prepare('UPDATE enterprise_produce_matches
            SET farmer_consent_status = ?, farmer_consent_channel = ?, farmer_consent_at = NOW()
            WHERE id = ? AND farmer_consent_status = \'pending\'');
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Update failed.'];
        }
        $stmt->bind_param('ssi', $decision, $channel, $matchId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected < 1) {
            return ['ok' => false, 'message' => 'Consent already recorded or match missing.'];
        }
        ep_adapters()['audit']->log($db, 'agriculture.farmer_consent', [
            'match_id' => $matchId,
            'decision' => $decision,
            'channel' => $channel,
            'note' => 'Declining is not penalized.',
        ]);
        return ['ok' => true];
    }
}
