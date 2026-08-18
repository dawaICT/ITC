<?php
declare(strict_types=1);

if (!function_exists('eh_get_profile')) {
    function eh_get_profile(mysqli $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_profiles WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('eh_get_profile_for_owner')) {
    function eh_get_profile_for_owner(mysqli $db, int $ownerUserId, ?string $studentId = null): ?array
    {
        if ($studentId) {
            $stmt = $db->prepare('SELECT * FROM enterprise_profiles WHERE student_id = ? AND status <> \'archived\' ORDER BY id DESC LIMIT 1');
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
        if ($ownerUserId > 0) {
            $stmt = $db->prepare('SELECT * FROM enterprise_profiles WHERE owner_user_id = ? AND status <> \'archived\' ORDER BY id DESC LIMIT 1');
            $stmt->bind_param('i', $ownerUserId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        }
        return null;
    }
}

if (!function_exists('eh_save_profile')) {
    function eh_save_profile(mysqli $db, array $data, ?int $id = null): int
    {
        $fields = [
            'owner_user_id', 'student_id', 'business_name', 'profile_type', 'description',
            'province', 'district', 'public_phone', 'public_email', 'business_registration_status',
            'registration_number', 'years_operating', 'programme_code', 'programme_name', 'status',
        ];
        $vals = [];
        foreach ($fields as $f) {
            $vals[$f] = $data[$f] ?? null;
        }
        $vals['owner_user_id'] = (int)$vals['owner_user_id'];
        $vals['years_operating'] = $vals['years_operating'] !== null && $vals['years_operating'] !== ''
            ? (int)$vals['years_operating'] : null;
        $vals['status'] = $vals['status'] ?: 'active';

        if ($id) {
            $stmt = $db->prepare("UPDATE enterprise_profiles SET
                business_name=?, profile_type=?, description=?, province=?, district=?,
                public_phone=?, public_email=?, business_registration_status=?, registration_number=?,
                years_operating=?, programme_code=?, programme_name=?, status=?, updated_at=NOW()
                WHERE id=?");
            $stmt->bind_param(
                'sssssssssisssi',
                $vals['business_name'], $vals['profile_type'], $vals['description'], $vals['province'],
                $vals['district'], $vals['public_phone'], $vals['public_email'],
                $vals['business_registration_status'], $vals['registration_number'],
                $vals['years_operating'], $vals['programme_code'], $vals['programme_name'],
                $vals['status'], $id
            );
            $stmt->execute();
            $stmt->close();
            eh_audit($db, 'enterprise_hub.profile_updated', ['profile_id' => $id]);
            return $id;
        }

        $stmt = $db->prepare("INSERT INTO enterprise_profiles (
            owner_user_id, student_id, business_name, profile_type, description, province, district,
            public_phone, public_email, business_registration_status, registration_number,
            years_operating, programme_code, programme_name, status
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param(
            'issssssssssisss',
            $vals['owner_user_id'], $vals['student_id'], $vals['business_name'], $vals['profile_type'],
            $vals['description'], $vals['province'], $vals['district'], $vals['public_phone'],
            $vals['public_email'], $vals['business_registration_status'], $vals['registration_number'],
            $vals['years_operating'], $vals['programme_code'], $vals['programme_name'], $vals['status']
        );
        $stmt->execute();
        $newId = (int)$db->insert_id;
        $stmt->close();
        eh_audit($db, 'enterprise_hub.profile_created', ['profile_id' => $newId]);
        return $newId;
    }
}

if (!function_exists('eh_get_item')) {
    function eh_get_item(mysqli $db, int $id): ?array
    {
        $stmt = $db->prepare("
            SELECT i.*, c.category_name, c.category_slug, p.business_name, p.student_id, p.owner_user_id,
                   p.province, p.district, p.public_phone, p.public_email, p.programme_name, p.programme_code,
                   p.profile_type
            FROM enterprise_items i
            LEFT JOIN enterprise_categories c ON c.id = i.category_id
            JOIN enterprise_profiles p ON p.id = i.enterprise_profile_id
            WHERE i.id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('eh_get_item_by_code')) {
    function eh_get_item_by_code(mysqli $db, string $code, bool $publishedOnly = true): ?array
    {
        $sql = "
            SELECT i.*, c.category_name, c.category_slug, p.business_name, p.province, p.district,
                   p.public_phone, p.public_email, p.programme_name, p.programme_code, p.profile_type
            FROM enterprise_items i
            LEFT JOIN enterprise_categories c ON c.id = i.category_id
            JOIN enterprise_profiles p ON p.id = i.enterprise_profile_id
            WHERE i.public_code = ?
        ";
        if ($publishedOnly) {
            $sql .= " AND i.status = 'published'";
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

if (!function_exists('eh_list_categories')) {
    function eh_list_categories(mysqli $db, bool $activeOnly = true): array
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
            $res->free();
        }
        return $rows;
    }
}

if (!function_exists('eh_get_costs')) {
    function eh_get_costs(mysqli $db, int $itemId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_costs WHERE enterprise_item_id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('eh_get_readiness')) {
    function eh_get_readiness(mysqli $db, int $itemId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_readiness_assessments WHERE enterprise_item_id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('eh_list_reviews')) {
    function eh_list_reviews(mysqli $db, int $itemId): array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_reviews WHERE enterprise_item_id = ? ORDER BY reviewed_at DESC');
        $stmt->bind_param('i', $itemId);
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

if (!function_exists('eh_list_items_for_profile')) {
    function eh_list_items_for_profile(mysqli $db, int $profileId): array
    {
        $stmt = $db->prepare("
            SELECT i.*, c.category_name
            FROM enterprise_items i
            LEFT JOIN enterprise_categories c ON c.id = i.category_id
            WHERE i.enterprise_profile_id = ? AND i.status <> 'archived'
            ORDER BY i.updated_at DESC
        ");
        $stmt->bind_param('i', $profileId);
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

if (!function_exists('eh_create_item')) {
    function eh_create_item(mysqli $db, array $data): int
    {
        $publicCode = eh_generate_public_code();
        for ($i = 0; $i < 5; $i++) {
            $chk = $db->prepare('SELECT id FROM enterprise_items WHERE public_code = ? LIMIT 1');
            $chk->bind_param('s', $publicCode);
            $chk->execute();
            $exists = (bool)$chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$exists) {
                break;
            }
            $publicCode = eh_generate_public_code();
        }

        $slug = eh_unique_slug($db, (string)$data['title']);
        $profileId = (int)$data['enterprise_profile_id'];
        $categoryId = !empty($data['category_id']) ? (int)$data['category_id'] : null;
        $itemType = (string)$data['item_type'];
        $title = trim((string)$data['title']);
        $short = trim((string)($data['short_description'] ?? ''));
        $full = trim((string)($data['full_description'] ?? ''));
        $currency = (string)($data['currency'] ?? 'ZMW');
        $capacity = isset($data['current_capacity']) && $data['current_capacity'] !== '' ? (int)$data['current_capacity'] : null;
        $capPeriod = ($data['capacity_period'] ?? null) !== '' ? ($data['capacity_period'] ?? null) : null;
        $invReq = isset($data['investment_required']) && $data['investment_required'] !== '' ? (float)$data['investment_required'] : null;
        $invPurpose = ($data['investment_purpose'] ?? null) !== '' ? ($data['investment_purpose'] ?? null) : null;
        $expCap = isset($data['expected_capacity']) && $data['expected_capacity'] !== '' ? (int)$data['expected_capacity'] : null;
        $expPeriod = ($data['expected_capacity_period'] ?? null) !== '' ? ($data['expected_capacity_period'] ?? null) : null;
        $jobs = isset($data['employment_potential']) && $data['employment_potential'] !== '' ? (int)$data['employment_potential'] : null;

        if ($categoryId === null) {
            $stmt = $db->prepare("INSERT INTO enterprise_items (
                enterprise_profile_id, category_id, item_type, title, slug, short_description, full_description,
                currency, current_capacity, capacity_period, investment_required, investment_purpose,
                expected_capacity, expected_capacity_period, employment_potential, public_code, status
            ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
            $stmt->bind_param(
                'isssssisdsiisis',
                $profileId, $itemType, $title, $slug, $short, $full, $currency,
                $capacity, $capPeriod, $invReq, $invPurpose, $expCap, $expPeriod, $jobs, $publicCode
            );
        } else {
            $stmt = $db->prepare("INSERT INTO enterprise_items (
                enterprise_profile_id, category_id, item_type, title, slug, short_description, full_description,
                currency, current_capacity, capacity_period, investment_required, investment_purpose,
                expected_capacity, expected_capacity_period, employment_potential, public_code, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
            $stmt->bind_param(
                'iisssssisdsiisis',
                $profileId, $categoryId, $itemType, $title, $slug, $short, $full, $currency,
                $capacity, $capPeriod, $invReq, $invPurpose, $expCap, $expPeriod, $jobs, $publicCode
            );
        }
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not create item: ' . $err);
        }
        $id = (int)$db->insert_id;
        $stmt->close();
        eh_audit($db, 'enterprise_hub.item_created', ['item_id' => $id, 'public_code' => $publicCode]);
        return $id;
    }
}

if (!function_exists('eh_update_item')) {
    function eh_update_item(mysqli $db, int $itemId, array $data): void
    {
        $item = eh_get_item($db, $itemId);
        if (!$item) {
            throw new RuntimeException('Item not found.');
        }
        if (!in_array($item['status'], ['draft', 'changes_requested'], true) && !eh_can($db, 'enterprise.approve')) {
            throw new RuntimeException('This item cannot be edited in its current status.');
        }

        $title = trim((string)$data['title']);
        $slug = eh_unique_slug($db, $title, $itemId);
        $categoryId = !empty($data['category_id']) ? (int)$data['category_id'] : null;
        $itemType = (string)$data['item_type'];
        $short = trim((string)($data['short_description'] ?? ''));
        $full = trim((string)($data['full_description'] ?? ''));
        $capacity = isset($data['current_capacity']) && $data['current_capacity'] !== '' ? (int)$data['current_capacity'] : null;
        $capPeriod = $data['capacity_period'] ?? null;
        $invReq = isset($data['investment_required']) && $data['investment_required'] !== '' ? (float)$data['investment_required'] : null;
        $invPurpose = $data['investment_purpose'] ?? null;
        $expCap = isset($data['expected_capacity']) && $data['expected_capacity'] !== '' ? (int)$data['expected_capacity'] : null;
        $expPeriod = $data['expected_capacity_period'] ?? null;
        $jobs = isset($data['employment_potential']) && $data['employment_potential'] !== '' ? (int)$data['employment_potential'] : null;

        $stmt = $db->prepare("UPDATE enterprise_items SET
            category_id=?, item_type=?, title=?, slug=?, short_description=?, full_description=?,
            current_capacity=?, capacity_period=?, investment_required=?, investment_purpose=?,
            expected_capacity=?, expected_capacity_period=?, employment_potential=?, updated_at=NOW()
            WHERE id=?");
        $stmt->bind_param(
            'isssssissdsiisi',
            $categoryId, $itemType, $title, $slug, $short, $full,
            $capacity, $capPeriod, $invReq, $invPurpose, $expCap, $expPeriod, $jobs, $itemId
        );
        $stmt->execute();
        $stmt->close();
        eh_audit($db, 'enterprise_hub.item_updated', ['item_id' => $itemId]);
    }
}

if (!function_exists('eh_student_dashboard_stats')) {
    function eh_student_dashboard_stats(mysqli $db, int $profileId): array
    {
        $stats = [
            'draft' => 0, 'submitted' => 0, 'changes_requested' => 0,
            'lecturer_verified' => 0, 'published' => 0, 'interests' => 0,
            'approved' => 0, 'rejected' => 0,
        ];
        $stmt = $db->prepare('SELECT status, COUNT(*) AS c FROM enterprise_items WHERE enterprise_profile_id = ? AND status <> \'archived\' GROUP BY status');
        $stmt->bind_param('i', $profileId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $stats[$r['status']] = (int)$r['c'];
        }
        $stmt->close();

        $i = $db->prepare("
            SELECT COUNT(*) AS c FROM enterprise_interests ii
            JOIN enterprise_items it ON it.id = ii.enterprise_item_id
            WHERE it.enterprise_profile_id = ?
        ");
        $i->bind_param('i', $profileId);
        $i->execute();
        $stats['interests'] = (int)($i->get_result()->fetch_assoc()['c'] ?? 0);
        $i->close();
        return $stats;
    }
}

if (!function_exists('eh_admin_dashboard_stats')) {
    function eh_admin_dashboard_stats(mysqli $db): array
    {
        $out = [
            'profiles' => 0, 'submitted' => 0, 'pending_lecturer' => 0, 'pending_admin' => 0,
            'published_products' => 0, 'published_services' => 0, 'published_total' => 0,
            'investment_requested' => 0.0, 'potential_jobs' => 0, 'new_interests' => 0,
            'converted_interests' => 0,
        ];
        $q = static function (mysqli $db, string $sql) {
            $res = $db->query($sql);
            $row = $res ? $res->fetch_assoc() : null;
            if ($res) {
                $res->free();
            }
            return $row;
        };
        $out['profiles'] = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_profiles WHERE status='active'")['c'] ?? 0);
        $out['submitted'] = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_items WHERE status='submitted'")['c'] ?? 0);
        $out['pending_lecturer'] = $out['submitted'];
        $out['pending_admin'] = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_items WHERE status='lecturer_verified'")['c'] ?? 0);
        $out['published_products'] = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_items WHERE status='published' AND item_type='product'")['c'] ?? 0);
        $out['published_services'] = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_items WHERE status='published' AND item_type='service'")['c'] ?? 0);
        $out['published_total'] = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_items WHERE status='published'")['c'] ?? 0);
        $out['investment_requested'] = (float)($q($db, "SELECT COALESCE(SUM(investment_required),0) s FROM enterprise_items WHERE status='published'")['s'] ?? 0);
        $out['potential_jobs'] = (int)($q($db, "SELECT COALESCE(SUM(employment_potential),0) s FROM enterprise_items WHERE status='published'")['s'] ?? 0);
        $out['new_interests'] = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_interests WHERE follow_up_status='new'")['c'] ?? 0);
        $out['converted_interests'] = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_interests WHERE follow_up_status='converted'")['c'] ?? 0);
        return $out;
    }
}

if (!function_exists('eh_search_published')) {
    function eh_search_published(mysqli $db, array $filters = [], int $limit = 24, int $offset = 0): array
    {
        $where = ["i.status = 'published'"];
        $types = '';
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = '(i.title LIKE ? OR i.short_description LIKE ? OR p.business_name LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $types .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'i.category_id = ?';
            $types .= 'i';
            $params[] = (int)$filters['category_id'];
        }
        if (!empty($filters['category_slug'])) {
            $where[] = 'c.category_slug = ?';
            $types .= 's';
            $params[] = (string)$filters['category_slug'];
        }
        if (!empty($filters['item_type'])) {
            $where[] = 'i.item_type = ?';
            $types .= 's';
            $params[] = (string)$filters['item_type'];
        }
        if (!empty($filters['province'])) {
            $where[] = 'p.province = ?';
            $types .= 's';
            $params[] = (string)$filters['province'];
        }
        if (!empty($filters['programme'])) {
            $where[] = '(p.programme_code = ? OR p.programme_name LIKE ?)';
            $types .= 'ss';
            $params[] = (string)$filters['programme'];
            $params[] = '%' . $filters['programme'] . '%';
        }
        if (!empty($filters['investment_min'])) {
            $where[] = 'i.investment_required >= ?';
            $types .= 'd';
            $params[] = (float)$filters['investment_min'];
        }
        if (!empty($filters['investment_max'])) {
            $where[] = 'i.investment_required <= ?';
            $types .= 'd';
            $params[] = (float)$filters['investment_max'];
        }
        if (!empty($filters['featured'])) {
            $where[] = 'i.is_featured = 1';
        }

        $orderAllow = [
            'recent' => 'i.published_at DESC',
            'featured' => 'i.is_featured DESC, i.published_at DESC',
            'views' => 'i.view_count DESC',
            'investment' => 'i.investment_required DESC',
        ];
        $order = $orderAllow[$filters['sort'] ?? 'featured'] ?? $orderAllow['featured'];

        $sql = "SELECT i.*, c.category_name, c.category_slug, p.business_name, p.province, p.programme_name
                FROM enterprise_items i
                LEFT JOIN enterprise_categories c ON c.id = i.category_id
                JOIN enterprise_profiles p ON p.id = i.enterprise_profile_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$order}
                LIMIT ? OFFSET ?";
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

if (!function_exists('eh_increment_views')) {
    function eh_increment_views(mysqli $db, int $itemId): void
    {
        $stmt = $db->prepare("UPDATE enterprise_items SET view_count = view_count + 1 WHERE id = ? AND status = 'published'");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('eh_list_by_status')) {
    function eh_list_by_status(mysqli $db, string|array $statuses, int $limit = 100): array
    {
        $list = is_array($statuses) ? $statuses : [$statuses];
        $placeholders = implode(',', array_fill(0, count($list), '?'));
        $types = str_repeat('s', count($list)) . 'i';
        $sql = "SELECT i.*, c.category_name, p.business_name, p.student_id, p.programme_name
                FROM enterprise_items i
                LEFT JOIN enterprise_categories c ON c.id = i.category_id
                JOIN enterprise_profiles p ON p.id = i.enterprise_profile_id
                WHERE i.status IN ($placeholders)
                ORDER BY i.updated_at DESC
                LIMIT ?";
        $stmt = $db->prepare($sql);
        $params = array_merge($list, [$limit]);
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
