<?php
declare(strict_types=1);

/**
 * Commodity price governance — human-controlled publication with typed sources.
 */

if (!function_exists('ep_commodity_price_types')) {
    /** @return array<string,string> */
    function ep_commodity_price_types(): array
    {
        return [
            'official_procurement_price' => 'Official procurement price',
            'government_reference_price' => 'Government reference price',
            'commodity_exchange_price' => 'Commodity exchange price',
            'verified_buyer_offer' => 'Verified buyer offer',
            'local_market_reference' => 'Local market reference',
            'farmer_asking_price' => 'Farmer asking price',
        ];
    }
}

if (!function_exists('ep_price_type_label')) {
    function ep_price_type_label(string $type): string
    {
        return ep_commodity_price_types()[$type] ?? $type;
    }
}

if (!function_exists('ep_price_is_official_class')) {
    function ep_price_is_official_class(string $type): bool
    {
        return in_array($type, ['official_procurement_price', 'government_reference_price'], true);
    }
}

if (!function_exists('ep_unit_to_base')) {
    function ep_unit_to_base(mysqli $db, string $unitCode, float $qty): array
    {
        $stmt = $db->prepare('SELECT unit_code, base_unit_code, to_base_factor FROM enterprise_measurement_units WHERE unit_code = ? AND is_active = 1 LIMIT 1');
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Unit lookup failed.'];
        }
        $stmt->bind_param('s', $unitCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Unknown or inactive unit.'];
        }
        $base = $qty * (float)$row['to_base_factor'];
        return [
            'ok' => true,
            'quantity_base' => round($base, 6),
            'base_unit_code' => (string)$row['base_unit_code'],
        ];
    }
}

if (!function_exists('ep_create_commodity_price_draft')) {
    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,price_id?:int,message?:string,errors?:list<string>}
     */
    function ep_create_commodity_price_draft(mysqli $db, array $data): array
    {
        if (!ep_agri_table_exists($db, 'enterprise_commodity_prices')) {
            return ['ok' => false, 'message' => 'Price schema not migrated.'];
        }
        $errors = [];
        $commodityId = (int)($data['commodity_id'] ?? 0);
        $price = (float)($data['price'] ?? 0);
        $unit = trim((string)($data['quantity_unit'] ?? ''));
        $location = trim((string)($data['location_label'] ?? ''));
        $type = trim((string)($data['price_type'] ?? ''));
        $sourceId = (int)($data['source_id'] ?? 0);
        $effectiveFrom = trim((string)($data['effective_from'] ?? ''));
        $effectiveTo = trim((string)($data['effective_to'] ?? ''));
        $sourceRef = trim((string)($data['source_reference'] ?? ''));

        if ($commodityId <= 0) {
            $errors[] = 'Commodity is required.';
        }
        if ($price <= 0) {
            $errors[] = 'Price must be greater than zero.';
        }
        if ($unit === '') {
            $errors[] = 'Unit is required.';
        }
        if ($location === '') {
            $errors[] = 'Location is required.';
        }
        if (!isset(ep_commodity_price_types()[$type])) {
            $errors[] = 'Invalid price type.';
        }
        if ($sourceId <= 0) {
            $errors[] = 'Price source is required (no source = no publication).';
        }
        if ($effectiveFrom === '') {
            $errors[] = 'Effective date is required (no effective date = no publication).';
        }
        // Guard mislabelling
        if ($type === 'verified_buyer_offer' && str_contains(strtolower($sourceRef), 'official government')) {
            $errors[] = 'Buyer offers must never be labelled as official government prices.';
        }
        if ($type === 'farmer_asking_price' && ep_price_is_official_class($type)) {
            $errors[] = 'Farmer asking prices cannot be official classes.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => implode(' ', $errors)];
        }

        $actor = ep_adapters()['identity']->currentActorLabel() ?: 'system';
        $currency = trim((string)($data['currency'] ?? 'ZMW')) ?: 'ZMW';
        $grade = trim((string)($data['grade_label'] ?? ''));
        $province = trim((string)($data['province'] ?? ''));
        $district = trim((string)($data['district'] ?? ''));
        $varietyId = (int)($data['variety_id'] ?? 0);
        $varietyLit = $varietyId > 0 ? (string)$varietyId : 'NULL';
        $toLit = $effectiveTo !== '' ? ep_sql_quote_nullable($db, $effectiveTo) : 'NULL';

        $sql = sprintf(
            "INSERT INTO enterprise_commodity_prices
            (commodity_id, variety_id, grade_label, price, currency, quantity_unit, location_label, province, district,
             price_type, source_id, source_reference, effective_from, effective_to, created_by, publication_status)
            VALUES (%d, %s, %s, %.2f, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, '%s', 'draft')",
            $commodityId,
            $varietyLit,
            ep_sql_quote_nullable($db, $grade !== '' ? $grade : null),
            $price,
            ep_sql_quote_nullable($db, $currency),
            ep_sql_quote_nullable($db, $unit),
            ep_sql_quote_nullable($db, $location),
            ep_sql_quote_nullable($db, $province !== '' ? $province : null),
            ep_sql_quote_nullable($db, $district !== '' ? $district : null),
            ep_sql_quote_nullable($db, $type),
            $sourceId,
            ep_sql_quote_nullable($db, $sourceRef !== '' ? $sourceRef : null),
            ep_sql_quote_nullable($db, $effectiveFrom),
            $toLit,
            $db->real_escape_string($actor)
        );
        if (!$db->query($sql)) {
            return ['ok' => false, 'message' => $db->error];
        }
        $id = (int)$db->insert_id;
        ep_adapters()['audit']->log($db, 'agriculture.price_draft_created', [
            'price_id' => $id,
            'price_type' => $type,
            'commodity_id' => $commodityId,
        ]);
        return ['ok' => true, 'price_id' => $id];
    }
}

if (!function_exists('ep_publish_commodity_price')) {
    function ep_publish_commodity_price(mysqli $db, int $priceId, bool $isSecondApproval = false): array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_commodity_prices WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Lookup failed.'];
        }
        $stmt->bind_param('i', $priceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Price not found.'];
        }
        if ((int)$row['source_id'] <= 0 || empty($row['effective_from'])) {
            return ['ok' => false, 'message' => 'Cannot publish without source and effective date.'];
        }
        if (($row['publication_status'] ?? '') === 'published') {
            return ['ok' => false, 'message' => 'Published prices must not be overwritten; create a correction instead.'];
        }

        $actor = ep_adapters()['identity']->currentActorLabel() ?: 'system';
        $type = (string)$row['price_type'];
        $needsDual = ep_price_is_official_class($type) && ep_official_price_approval_mode() === 'dual';

        if ($needsDual && !$isSecondApproval && empty($row['verified_by'])) {
            if ($actor === (string)$row['created_by']) {
                return ['ok' => false, 'message' => 'First approver must differ from price creator under dual approval.'];
            }
            $u = $db->prepare("UPDATE enterprise_commodity_prices
                SET publication_status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ?");
            if (!$u) {
                return ['ok' => false, 'message' => 'Could not record first approval.'];
            }
            $u->bind_param('si', $actor, $priceId);
            $u->execute();
            $u->close();
            ep_adapters()['audit']->log($db, 'agriculture.price_first_approval', ['price_id' => $priceId]);
            return ['ok' => true, 'message' => 'First approval recorded; dual approval required before publication.'];
        }

        if ($needsDual && empty($row['verified_by']) && !$isSecondApproval) {
            return ['ok' => false, 'message' => 'First verification required.'];
        }

        if ($needsDual && $isSecondApproval) {
            if ($actor === (string)$row['verified_by']) {
                return ['ok' => false, 'message' => 'Second approver must be a different officer.'];
            }
        }

        $secondFlag = ($needsDual && $isSecondApproval) ? 1 : 0;
        $secondLit = $secondFlag ? ("'" . $db->real_escape_string($actor) . "'") : 'NULL';
        $sql = sprintf(
            "UPDATE enterprise_commodity_prices SET
                publication_status = 'published',
                verified_by = COALESCE(verified_by, '%s'),
                verified_at = COALESCE(verified_at, NOW()),
                second_approved_by = COALESCE(second_approved_by, %s),
                second_approved_at = IF(%d = 1, NOW(), second_approved_at)
             WHERE id = %d",
            $db->real_escape_string($actor),
            $secondLit,
            $secondFlag,
            $priceId
        );
        if (!$db->query($sql)) {
            return ['ok' => false, 'message' => $db->error];
        }
        ep_adapters()['audit']->log($db, 'agriculture.price_published', [
            'price_id' => $priceId,
            'price_type' => $type,
            'label' => ep_price_type_label($type),
        ]);
        return ['ok' => true, 'message' => 'Price published.'];
    }
}

if (!function_exists('ep_current_commodity_prices')) {
    /**
     * Never returns expired or unpublished prices.
     * @return list<array<string,mixed>>
     */
    function ep_current_commodity_prices(mysqli $db, array $filters = []): array
    {
        if (!ep_agri_table_exists($db, 'enterprise_commodity_prices')) {
            return [];
        }
        $where = [
            "p.publication_status = 'published'",
            'p.effective_from <= CURDATE()',
            '(p.effective_to IS NULL OR p.effective_to >= CURDATE())',
        ];
        $types = '';
        $params = [];
        if (!empty($filters['commodity_id'])) {
            $where[] = 'p.commodity_id = ?';
            $types .= 'i';
            $params[] = (int)$filters['commodity_id'];
        }
        if (!empty($filters['province'])) {
            $where[] = 'p.province = ?';
            $types .= 's';
            $params[] = (string)$filters['province'];
        }
        if (!empty($filters['price_type'])) {
            $where[] = 'p.price_type = ?';
            $types .= 's';
            $params[] = (string)$filters['price_type'];
        }
        $sql = 'SELECT p.*, c.commodity_name, c.commodity_code, s.source_name, s.source_type
                FROM enterprise_commodity_prices p
                INNER JOIN enterprise_commodities c ON c.id = p.commodity_id
                INNER JOIN enterprise_commodity_price_sources s ON s.id = p.source_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.effective_from DESC, p.id DESC LIMIT 100';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $row['price_type_label'] = ep_price_type_label((string)$row['price_type']);
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ep_format_price_sms')) {
    function ep_format_price_sms(array $price): string
    {
        return sprintf(
            '%s %s: %s %s/%s (%s). Source: %s. Effective: %s. Not a guaranteed sale price.',
            (string)($price['commodity_name'] ?? 'Commodity'),
            (string)($price['location_label'] ?? ''),
            (string)($price['currency'] ?? 'ZMW'),
            number_format((float)($price['price'] ?? 0), 2),
            (string)($price['quantity_unit'] ?? ''),
            ep_price_type_label((string)($price['price_type'] ?? '')),
            (string)($price['source_name'] ?? 'n/a'),
            (string)($price['effective_from'] ?? '')
        );
    }
}
