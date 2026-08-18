<?php
declare(strict_types=1);

/**
 * Opportunity lifecycle — status transitions only through this service.
 */

if (!function_exists('ep_opportunity_transitions')) {
    /** @return array<string,list<string>> */
    function ep_opportunity_transitions(): array
    {
        return [
            'draft' => ['submitted', 'archived'],
            'submitted' => ['changes_requested', 'reviewer_verified', 'rejected'],
            'changes_requested' => ['submitted'],
            'reviewer_verified' => ['approved', 'rejected'],
            'rejected' => ['archived'],
            'approved' => ['published'],
            'published' => ['unpublished', 'update_required'],
            'update_required' => ['submitted'],
            'unpublished' => ['published', 'archived'],
            'archived' => [],
        ];
    }
}

if (!function_exists('ep_get_opportunity')) {
    function ep_get_opportunity(mysqli $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT o.*, p.owner_user_id, p.membership_id, p.business_name, p.professional_title
            FROM enterprise_opportunities o
            JOIN enterprise_member_profiles p ON p.id = o.enterprise_profile_id
            WHERE o.id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('ep_get_opportunity_by_code')) {
    function ep_get_opportunity_by_code(mysqli $db, string $code, bool $publishedOnly = true): ?array
    {
        $sql = 'SELECT o.*, p.business_name, p.professional_title, p.short_bio, p.province, p.district,
                       p.preferred_contact_method, p.public_phone, p.public_email, p.programme_name,
                       c.category_name, c.category_slug
                FROM enterprise_opportunities o
                JOIN enterprise_member_profiles p ON p.id = o.enterprise_profile_id
                LEFT JOIN enterprise_categories c ON c.id = o.category_id
                WHERE o.public_code = ?';
        if ($publishedOnly) {
            $sql .= " AND o.status = 'published'";
        }
        $sql .= ' LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('ep_list_opportunities_for_profile')) {
    function ep_list_opportunities_for_profile(mysqli $db, int $profileId, int $limit = 0): array
    {
        $limit = (int)$limit;
        // Keep the historical unbounded behaviour for callers that omit $limit
        // (management/list pages), but allow dashboards to request a page.
        if ($limit > 0) {
            $limit = max(1, min(500, $limit));
            $stmt = $db->prepare(
                'SELECT id, enterprise_profile_id, title, status, opportunity_type, public_code, updated_at, created_at
                 FROM enterprise_opportunities
                 WHERE enterprise_profile_id = ?
                 ORDER BY updated_at DESC
                 LIMIT ?'
            );
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('ii', $profileId, $limit);
        } else {
            // Full row for edit/management callers; columns vary slightly by migration set.
            $stmt = $db->prepare(
                'SELECT * FROM enterprise_opportunities
                 WHERE enterprise_profile_id = ?
                 ORDER BY updated_at DESC'
            );
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('i', $profileId);
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

if (!function_exists('ep_count_opportunities_for_profile')) {
    /** @return array{total:int,published:int,pending_review:int} */
    function ep_count_opportunities_for_profile(mysqli $db, int $profileId): array
    {
        $out = ['total' => 0, 'published' => 0, 'pending_review' => 0];
        $stmt = $db->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN status IN ('submitted','reviewer_verified','approved') THEN 1 ELSE 0 END) AS pending_review
             FROM enterprise_opportunities
             WHERE enterprise_profile_id = ? AND status <> 'archived'"
        );
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('i', $profileId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $out['total'] = (int)($row['total'] ?? 0);
        $out['published'] = (int)($row['published'] ?? 0);
        $out['pending_review'] = (int)($row['pending_review'] ?? 0);
        return $out;
    }
}

if (!function_exists('ep_validate_opportunity_payload')) {
    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    function ep_validate_opportunity_payload(array $data, string $type): array
    {
        $errors = [];
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        $short = trim((string)($data['short_description'] ?? ''));
        if ($short === '') {
            $errors[] = 'Short description is required.';
        }

        switch ($type) {
            case 'employment_profile':
                if (trim((string)($data['preferred_employment_type'] ?? '')) === '') {
                    $errors[] = 'Preferred employment type is required.';
                }
                if (trim((string)($data['preferred_location'] ?? '')) === '') {
                    $errors[] = 'Preferred location is required.';
                }
                break;
            case 'service':
                if (trim((string)($data['service_area'] ?? '')) === '') {
                    $errors[] = 'Coverage / service area is required.';
                }
                if (trim((string)($data['pricing_type'] ?? '')) === '') {
                    $errors[] = 'Pricing method is required.';
                }
                if (trim((string)($data['availability_status'] ?? '')) === '') {
                    $errors[] = 'Availability is required.';
                }
                break;
            case 'product':
                if (trim((string)($data['pricing_type'] ?? '')) === '') {
                    $errors[] = 'Pricing type is required.';
                }
                $pt = (string)($data['pricing_type'] ?? '');
                if (in_array($pt, ['fixed_price', 'starting_from'], true) && (!isset($data['unit_price']) || $data['unit_price'] === '')) {
                    $errors[] = 'Unit price is required for this pricing type.';
                }
                if (!isset($data['current_capacity']) || $data['current_capacity'] === '') {
                    $errors[] = 'Production capacity is required.';
                }
                if (trim((string)($data['capacity_period'] ?? '')) === '') {
                    $errors[] = 'Capacity period is required.';
                }
                break;
            case 'innovation':
                if (trim((string)($data['problem_statement'] ?? '')) === '') {
                    $errors[] = 'Problem being solved is required.';
                }
                if (trim((string)($data['proposed_solution'] ?? '')) === '') {
                    $errors[] = 'Proposed solution is required.';
                }
                if (trim((string)($data['innovation_stage'] ?? '')) === '') {
                    $errors[] = 'Innovation stage is required.';
                }
                break;
            case 'business_idea':
                if (trim((string)($data['problem_statement'] ?? '')) === '') {
                    $errors[] = 'Market need / problem is required.';
                }
                if (trim((string)($data['proposed_solution'] ?? '')) === '') {
                    $errors[] = 'Proposed solution is required.';
                }
                break;
            case 'investment_opportunity':
                if (!isset($data['investment_required']) || (float)$data['investment_required'] <= 0) {
                    $errors[] = 'Investment amount required must be greater than zero.';
                }
                if (trim((string)($data['investment_purpose'] ?? '')) === '') {
                    $errors[] = 'Use of funds is required.';
                }
                break;
            case 'professional_skill':
            default:
                break;
        }
        return $errors;
    }
}

if (!function_exists('ep_save_opportunity')) {
    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,message:string,id?:int}
     */
    function ep_save_opportunity(mysqli $db, int $profileId, array $data, ?int $id = null): array
    {
        $type = (string)($data['opportunity_type'] ?? '');
        if (!isset(ep_opportunity_types()[$type])) {
            return ['ok' => false, 'message' => 'Invalid opportunity type.'];
        }
        $errors = ep_validate_opportunity_payload($data, $type);
        if ($errors !== []) {
            return ['ok' => false, 'message' => implode(' ', $errors)];
        }

        $title = trim((string)$data['title']);
        $slug = ep_slugify($title) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        // Normalize to strings for safe binding; SQL uses NULLIF for empty optionals.
        $cat = (string)(isset($data['category_id']) && $data['category_id'] !== '' ? (int)$data['category_id'] : '');
        $short = trim((string)($data['short_description'] ?? ''));
        $full = trim((string)($data['full_description'] ?? ''));
        $pricingType = trim((string)($data['pricing_type'] ?? ''));
        $unitPrice = trim((string)($data['unit_price'] ?? ''));
        $minPrice = trim((string)($data['minimum_price'] ?? ''));
        $maxPrice = trim((string)($data['maximum_price'] ?? ''));
        $currency = trim((string)($data['currency'] ?? 'ZMW')) ?: 'ZMW';
        $serviceArea = trim((string)($data['service_area'] ?? ''));
        $availability = trim((string)($data['availability_status'] ?? 'available')) ?: 'available';
        $capacity = trim((string)($data['current_capacity'] ?? ''));
        $capacityPeriod = trim((string)($data['capacity_period'] ?? ''));
        $innovationStage = trim((string)($data['innovation_stage'] ?? ''));
        $prototypeStatus = trim((string)($data['prototype_status'] ?? ''));
        $problem = trim((string)($data['problem_statement'] ?? ''));
        $solution = trim((string)($data['proposed_solution'] ?? ''));
        $investReq = trim((string)($data['investment_required'] ?? ''));
        $investPurpose = trim((string)($data['investment_purpose'] ?? ''));
        $expectedCap = trim((string)($data['expected_capacity'] ?? ''));
        $expectedCapPeriod = trim((string)($data['expected_capacity_period'] ?? ''));
        $employmentPotential = trim((string)($data['employment_potential'] ?? ''));
        $prefEmp = trim((string)($data['preferred_employment_type'] ?? ''));
        $prefLoc = trim((string)($data['preferred_location'] ?? ''));
        $costVis = trim((string)($data['cost_visibility'] ?? 'private')) ?: 'private';

        if ($id) {
            $existing = ep_get_opportunity($db, $id);
            if (!$existing || (int)$existing['enterprise_profile_id'] !== $profileId) {
                return ['ok' => false, 'message' => 'Opportunity not found.'];
            }
            if (!in_array((string)$existing['status'], ['draft', 'changes_requested', 'update_required'], true)) {
                if ((string)$existing['status'] === 'published') {
                    ep_transition_opportunity($db, $id, 'update_required', 'Major content change requires re-review.');
                } elseif (!in_array((string)$existing['status'], ['rejected', 'unpublished'], true)) {
                    return ['ok' => false, 'message' => 'This opportunity cannot be edited in its current status.'];
                }
            }
            $stmt = $db->prepare("UPDATE enterprise_opportunities SET
                category_id = NULLIF(?, ''),
                opportunity_type = ?, title = ?, short_description = ?, full_description = ?,
                pricing_type = NULLIF(?, ''), unit_price = NULLIF(?, ''), minimum_price = NULLIF(?, ''),
                maximum_price = NULLIF(?, ''), currency = ?, service_area = NULLIF(?, ''),
                availability_status = ?, current_capacity = NULLIF(?, ''), capacity_period = NULLIF(?, ''),
                innovation_stage = NULLIF(?, ''), prototype_status = NULLIF(?, ''),
                problem_statement = NULLIF(?, ''), proposed_solution = NULLIF(?, ''),
                investment_required = NULLIF(?, ''), investment_purpose = NULLIF(?, ''),
                expected_capacity = NULLIF(?, ''), expected_capacity_period = NULLIF(?, ''),
                employment_potential = NULLIF(?, ''), preferred_employment_type = NULLIF(?, ''),
                preferred_location = NULLIF(?, ''), cost_visibility = ?, updated_at = NOW()
                WHERE id = ? AND enterprise_profile_id = ?");
            $types = str_repeat('s', 26) . 'ii';
            $stmt->bind_param(
                $types,
                $cat, $type, $title, $short, $full, $pricingType, $unitPrice, $minPrice, $maxPrice, $currency,
                $serviceArea, $availability, $capacity, $capacityPeriod, $innovationStage, $prototypeStatus,
                $problem, $solution, $investReq, $investPurpose, $expectedCap, $expectedCapPeriod,
                $employmentPotential, $prefEmp, $prefLoc, $costVis, $id, $profileId
            );
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                return ['ok' => false, 'message' => 'Could not update opportunity: ' . $err];
            }
            $stmt->close();
            ep_audit($db, 'enterprise_portal.opportunity_updated', ['opportunity_id' => $id]);
            return ['ok' => true, 'message' => 'Opportunity saved.', 'id' => $id];
        }

        $code = ep_public_code();
        for ($i = 0; $i < 5; $i++) {
            $chk = $db->prepare('SELECT id FROM enterprise_opportunities WHERE public_code = ? LIMIT 1');
            $chk->bind_param('s', $code);
            $chk->execute();
            $exists = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$exists) {
                break;
            }
            $code = ep_public_code();
        }

        $stmt = $db->prepare("INSERT INTO enterprise_opportunities (
            enterprise_profile_id, category_id, opportunity_type, title, slug, short_description, full_description,
            pricing_type, unit_price, minimum_price, maximum_price, currency, service_area, availability_status,
            current_capacity, capacity_period, innovation_stage, prototype_status, problem_statement, proposed_solution,
            investment_required, investment_purpose, expected_capacity, expected_capacity_period, employment_potential,
            preferred_employment_type, preferred_location, public_code, cost_visibility, status
        ) VALUES (
            ?, NULLIF(?, ''), ?, ?, ?, ?, ?,
            NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), ?,
            NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
            NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
            NULLIF(?, ''), NULLIF(?, ''), ?, ?, 'draft'
        )");
        $types = 'i' . str_repeat('s', 28);
        $stmt->bind_param(
            $types,
            $profileId, $cat, $type, $title, $slug, $short, $full,
            $pricingType, $unitPrice, $minPrice, $maxPrice, $currency, $serviceArea, $availability,
            $capacity, $capacityPeriod, $innovationStage, $prototypeStatus, $problem, $solution,
            $investReq, $investPurpose, $expectedCap, $expectedCapPeriod, $employmentPotential,
            $prefEmp, $prefLoc, $code, $costVis
        );
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['ok' => false, 'message' => 'Could not save opportunity: ' . $err];
        }
        $newId = (int)$db->insert_id;
        $stmt->close();
        ep_audit($db, 'enterprise_portal.opportunity_created', ['opportunity_id' => $newId]);
        return ['ok' => true, 'message' => 'Opportunity created as draft.', 'id' => $newId];
    }
}


if (!function_exists('ep_transition_opportunity')) {
    /** @return array{ok:bool,message:string} */
    function ep_transition_opportunity(mysqli $db, int $id, string $toStatus, string $comments = '', string $stage = 'system'): array
    {
        $db->begin_transaction();
        try {
            $stmt = $db->prepare('SELECT * FROM enterprise_opportunities WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                throw new RuntimeException('Opportunity not found.');
            }
            $from = (string)$row['status'];
            $allowed = ep_opportunity_transitions()[$from] ?? [];
            if (!in_array($toStatus, $allowed, true)) {
                throw new RuntimeException("Cannot change opportunity from {$from} to {$toStatus}.");
            }
            if (in_array($toStatus, ['changes_requested', 'rejected'], true) && trim($comments) === '') {
                throw new RuntimeException('Review comments are required.');
            }
            if ($toStatus === 'submitted') {
                $errors = ep_validate_opportunity_payload($row, (string)$row['opportunity_type']);
                if ($errors !== []) {
                    throw new RuntimeException(implode(' ', $errors));
                }
                if ((string)$row['opportunity_type'] === 'investment_opportunity') {
                    $r = $db->prepare('SELECT id FROM enterprise_opportunity_readiness WHERE enterprise_opportunity_id = ? LIMIT 1');
                    $r->bind_param('i', $id);
                    $r->execute();
                    $hasReady = (bool)$r->get_result()->fetch_assoc();
                    $r->close();
                    if (!$hasReady) {
                        throw new RuntimeException('Investment opportunities require a completed readiness assessment before submission.');
                    }
                }
                if ((string)$row['opportunity_type'] === 'product') {
                    $c = $db->prepare('SELECT id FROM enterprise_opportunity_costs WHERE enterprise_opportunity_id = ? LIMIT 1');
                    $c->bind_param('i', $id);
                    $c->execute();
                    $hasCost = (bool)$c->get_result()->fetch_assoc();
                    $c->close();
                    if (!$hasCost) {
                        throw new RuntimeException('Products require costing information before submission.');
                    }
                    $m = $db->prepare('SELECT id FROM enterprise_media WHERE enterprise_opportunity_id = ? AND is_primary = 1 LIMIT 1');
                    $m->bind_param('i', $id);
                    $m->execute();
                    $hasImg = (bool)$m->get_result()->fetch_assoc();
                    $m->close();
                    if (!$hasImg) {
                        throw new RuntimeException('Products require a primary image before submission.');
                    }
                }
            }

            $now = date('Y-m-d H:i:s');
            $sets = ['status = ?', 'updated_at = NOW()'];
            $types = 's';
            $params = [$toStatus];
            if ($toStatus === 'submitted') {
                $sets[] = 'submitted_at = ?';
                $types .= 's';
                $params[] = $now;
            } elseif ($toStatus === 'reviewer_verified') {
                $sets[] = 'reviewer_verified_at = ?';
                $sets[] = 'last_verified_at = ?';
                $types .= 'ss';
                $params[] = $now;
                $params[] = $now;
            } elseif ($toStatus === 'approved') {
                $sets[] = 'approved_at = ?';
                $types .= 's';
                $params[] = $now;
            } elseif ($toStatus === 'published') {
                $sets[] = 'published_at = COALESCE(published_at, ?)';
                $sets[] = 'unpublished_at = NULL';
                $sets[] = 'next_review_at = DATE_ADD(?, INTERVAL 90 DAY)';
                $types .= 'ss';
                $params[] = $now;
                $params[] = $now;
            } elseif ($toStatus === 'unpublished') {
                $sets[] = 'unpublished_at = ?';
                $types .= 's';
                $params[] = $now;
            } elseif ($toStatus === 'archived') {
                $sets[] = 'archived_at = ?';
                $types .= 's';
                $params[] = $now;
            }
            $types .= 'i';
            $params[] = $id;
            $sql = 'UPDATE enterprise_opportunities SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $upd = $db->prepare($sql);
            $upd->bind_param($types, ...$params);
            $upd->execute();
            $upd->close();

            $reviewer = ep_current_actor();
            $rev = $db->prepare('INSERT INTO enterprise_opportunity_reviews
                (enterprise_opportunity_id, reviewer_id, review_stage, decision, comments, previous_status, resulting_status, reviewed_at)
                VALUES (?,?,?,?,?,?,?,?)');
            $rev->bind_param('isssssss', $id, $reviewer, $stage, $toStatus, $comments, $from, $toStatus, $now);
            $rev->execute();
            $rev->close();

            $db->commit();
            ep_audit($db, 'enterprise_portal.opportunity_transition', [
                'opportunity_id' => $id, 'from' => $from, 'to' => $toStatus, 'stage' => $stage,
            ]);
            if (function_exists('ep_notify_opportunity')) {
                ep_notify_opportunity($db, $id, $toStatus, $comments);
            }
            return ['ok' => true, 'message' => 'Status updated.'];
        } catch (Throwable $e) {
            $db->rollback();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('ep_review_opportunity')) {
    /** Technical verification (does not publish). */
    function ep_review_opportunity(mysqli $db, int $id, string $decision, string $comments = ''): array
    {
        $opp = ep_get_opportunity($db, $id);
        if (!$opp) {
            return ['ok' => false, 'message' => 'Opportunity not found.'];
        }
        // Conflict: cannot review own
        if ((int)$opp['owner_user_id'] === ep_current_user_id() && ep_current_user_id() > 0) {
            return ['ok' => false, 'message' => 'You cannot review your own opportunity.'];
        }
        $map = [
            'verify' => 'reviewer_verified',
            'changes' => 'changes_requested',
            'reject' => 'rejected',
        ];
        if (!isset($map[$decision])) {
            return ['ok' => false, 'message' => 'Invalid review decision.'];
        }
        return ep_transition_opportunity($db, $id, $map[$decision], $comments, 'technical');
    }
}

if (!function_exists('ep_admin_decide_opportunity')) {
    function ep_admin_decide_opportunity(mysqli $db, int $id, string $decision, string $comments = ''): array
    {
        $map = [
            'approve' => 'approved',
            'reject' => 'rejected',
            'publish' => 'published',
            'unpublish' => 'unpublished',
        ];
        if (!isset($map[$decision])) {
            return ['ok' => false, 'message' => 'Invalid decision.'];
        }
        $to = $map[$decision];
        if ($to === 'published') {
            $opp = ep_get_opportunity($db, $id);
            if (!$opp || !in_array((string)$opp['status'], ['approved', 'unpublished'], true)) {
                return ['ok' => false, 'message' => 'Only approved or previously published opportunities can be published.'];
            }
            if ((string)$opp['status'] === 'approved') {
                // approved → published
            }
        }
        return ep_transition_opportunity($db, $id, $to, $comments, 'administrative');
    }
}

if (!function_exists('ep_search_published_opportunities')) {
    /** @param array<string,mixed> $filters */
    function ep_search_published_opportunities(mysqli $db, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $where = ["o.status = 'published'"];
        $types = '';
        $params = [];

        if (!empty($filters['q'])) {
            $q = '%' . $filters['q'] . '%';
            $where[] = '(o.title LIKE ? OR o.short_description LIKE ? OR o.full_description LIKE ?)';
            $types .= 'sss';
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
        }
        if (!empty($filters['type'])) {
            $where[] = 'o.opportunity_type = ?';
            $types .= 's';
            $params[] = (string)$filters['type'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'o.category_id = ?';
            $types .= 'i';
            $params[] = (int)$filters['category_id'];
        }
        if (!empty($filters['province'])) {
            $where[] = 'p.province = ?';
            $types .= 's';
            $params[] = (string)$filters['province'];
        }
        if (!empty($filters['availability'])) {
            $where[] = 'o.availability_status = ?';
            $types .= 's';
            $params[] = (string)$filters['availability'];
        }
        if (!empty($filters['featured'])) {
            $where[] = 'o.is_featured = 1';
        }

        $sql = 'SELECT o.id, o.public_code, o.title, o.slug, o.short_description, o.opportunity_type,
                       o.pricing_type, o.unit_price, o.currency, o.availability_status, o.readiness_level,
                       o.investment_required, o.is_featured, o.published_at, o.view_count,
                       p.business_name, p.professional_title, p.province, p.programme_name,
                       c.category_name
                FROM enterprise_opportunities o
                JOIN enterprise_member_profiles p ON p.id = o.enterprise_profile_id
                LEFT JOIN enterprise_categories c ON c.id = o.category_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY o.is_featured DESC, o.published_at DESC
                LIMIT ? OFFSET ?';
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
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

if (!function_exists('ep_increment_view_count')) {
    function ep_increment_view_count(mysqli $db, int $id): void
    {
        $stmt = $db->prepare('UPDATE enterprise_opportunities SET view_count = view_count + 1 WHERE id = ? AND status = \'published\'');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('ep_list_categories')) {
    function ep_list_categories(mysqli $db, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM enterprise_categories';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY display_order, category_name';
        $res = $db->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        return $rows;
    }
}
