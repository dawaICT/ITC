<?php
declare(strict_types=1);

/**
 * Shared rendering helpers for the public showcase (optional include).
 */

if (!function_exists('showcase_item_primary_media_id')) {
    function showcase_item_primary_media_id(mysqli $db, int $itemId): ?int
    {
        static $cache = [];
        if (array_key_exists($itemId, $cache)) {
            return $cache[$itemId];
        }
        $stmt = $db->prepare('SELECT id FROM enterprise_item_media WHERE enterprise_item_id = ? ORDER BY is_primary DESC, sort_order, id LIMIT 1');
        if (!$stmt) {
            return $cache[$itemId] = null;
        }
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $cache[$itemId] = $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('showcase_media_url')) {
    function showcase_media_url(?int $mediaId, bool $thumb = true): string
    {
        if (!$mediaId) {
            return '';
        }
        $url = '/wucportal/showcase/media.php?id=' . $mediaId;
        if ($thumb) {
            $url .= '&thumb=1';
        }
        return $url;
    }
}

if (!function_exists('showcase_item_is_verified')) {
    function showcase_item_is_verified(array $item): bool
    {
        return !empty($item['lecturer_verified_at'])
            || !empty($item['approved_at'])
            || (($item['status'] ?? '') === 'published');
    }
}

if (!function_exists('showcase_render_item_card')) {
    function showcase_render_item_card(mysqli $db, array $item): void
    {
        $code = (string)($item['public_code'] ?? '');
        $title = (string)($item['title'] ?? 'Untitled');
        $business = (string)($item['business_name'] ?? '');
        $category = (string)($item['category_name'] ?? '');
        $typeKey = (string)($item['item_type'] ?? '');
        $types = eh_item_types();
        $typeLabel = $types[$typeKey] ?? ucwords(str_replace('_', ' ', $typeKey));
        $short = (string)($item['short_description'] ?? '');
        $province = (string)($item['province'] ?? '');
        $currency = (string)($item['currency'] ?? 'ZMW');
        $unitPrice = $item['unit_price'] ?? null;
        $inv = $item['investment_required'] ?? null;
        $mediaId = showcase_item_primary_media_id($db, (int)$item['id']);
        $img = showcase_media_url($mediaId, true);
        $href = '/wucportal/showcase/item.php?code=' . rawurlencode($code);
        $verified = showcase_item_is_verified($item);
        $icon = eh_item_type_icon($typeKey);
        $priceLabel = null;
        if ($unitPrice !== null && $unitPrice !== '' && (float)$unitPrice > 0) {
            $priceLabel = eh_money($unitPrice, $currency);
        } elseif ($inv !== null && $inv !== '' && (float)$inv > 0) {
            $priceLabel = 'Seeking ' . eh_money($inv, $currency);
        }
        ?>
        <div class="col-sm-6 col-lg-4">
            <article class="eh-card position-relative">
                <?php if ($img !== ''): ?>
                    <img class="eh-card-img" src="<?php echo eh_h($img); ?>" alt="<?php echo eh_h($title); ?>" loading="lazy">
                <?php else: ?>
                    <div class="eh-card-img-placeholder" aria-hidden="true">
                        <div class="text-center px-3">
                            <i class="fas <?php echo eh_h($icon); ?> d-block mb-2"></i>
                            <span class="small fw-semibold opacity-75"><?php echo eh_h($typeLabel); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="p-3">
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        <?php if (!empty($item['is_featured'])): ?>
                            <span class="badge text-bg-warning text-dark">Featured</span>
                        <?php endif; ?>
                        <?php if ($verified): ?>
                            <span class="eh-badge-verified"><i class="fas fa-check-circle me-1"></i>Verified</span>
                        <?php endif; ?>
                        <?php if ($typeLabel !== ''): ?>
                            <span class="badge text-bg-secondary"><?php echo eh_h($typeLabel); ?></span>
                        <?php endif; ?>
                        <?php if ($category !== ''): ?>
                            <span class="badge bg-light text-dark border"><?php echo eh_h($category); ?></span>
                        <?php endif; ?>
                    </div>
                    <h3 class="h6 mb-1">
                        <a class="stretched-link text-decoration-none text-dark" href="<?php echo eh_h($href); ?>">
                            <?php echo eh_h($title); ?>
                        </a>
                    </h3>
                    <?php if ($business !== ''): ?>
                        <div class="eh-meta mb-2"><i class="fas fa-building me-1"></i><?php echo eh_h($business); ?></div>
                    <?php endif; ?>
                    <?php if ($short !== ''): ?>
                        <p class="small text-muted mb-2"><?php echo eh_h(mb_strlen($short) > 110 ? mb_substr($short, 0, 107) . '…' : $short); ?></p>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 small mt-auto">
                        <?php if ($province !== ''): ?>
                            <span class="eh-meta"><i class="fas fa-map-marker-alt me-1"></i><?php echo eh_h($province); ?></span>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <?php if ($priceLabel !== null): ?>
                            <span class="fw-semibold text-primary"><?php echo eh_h($priceLabel); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        </div>
        <?php
    }
}

if (!function_exists('showcase_list_provinces')) {
    /** @return list<string> */
    function showcase_list_provinces(mysqli $db): array
    {
        $rows = [];
        $res = $db->query("
            SELECT DISTINCT p.province
            FROM enterprise_profiles p
            JOIN enterprise_items i ON i.enterprise_profile_id = p.id
            WHERE i.status = 'published' AND p.province IS NOT NULL AND p.province <> ''
            ORDER BY p.province
        ");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = (string)$r['province'];
            }
            $res->free();
        }
        return $rows;
    }
}

if (!function_exists('showcase_filters_from_request')) {
    /** @return array<string,mixed> */
    function showcase_filters_from_request(): array
    {
        $filters = [];
        $q = trim((string)($_GET['q'] ?? $_GET['keyword'] ?? ''));
        if ($q !== '') {
            $filters['q'] = mb_substr($q, 0, 120);
        }
        $cat = trim((string)($_GET['category'] ?? $_GET['category_slug'] ?? ''));
        if ($cat !== '') {
            $filters['category_slug'] = preg_replace('/[^a-z0-9\-]/', '', strtolower($cat)) ?: '';
            if ($filters['category_slug'] === '') {
                unset($filters['category_slug']);
            }
        }
        $catId = (int)($_GET['category_id'] ?? 0);
        if ($catId > 0) {
            $filters['category_id'] = $catId;
        }
        $type = trim((string)($_GET['item_type'] ?? ''));
        if ($type !== '' && array_key_exists($type, eh_item_types())) {
            $filters['item_type'] = $type;
        }
        $province = trim((string)($_GET['province'] ?? ''));
        if ($province !== '') {
            $filters['province'] = mb_substr($province, 0, 80);
        }
        $min = trim((string)($_GET['investment_min'] ?? ''));
        if ($min !== '' && is_numeric($min)) {
            $filters['investment_min'] = (float)$min;
        }
        $max = trim((string)($_GET['investment_max'] ?? ''));
        if ($max !== '' && is_numeric($max)) {
            $filters['investment_max'] = (float)$max;
        }
        $sort = trim((string)($_GET['sort'] ?? ''));
        if (in_array($sort, ['recent', 'featured', 'views', 'investment'], true)) {
            $filters['sort'] = $sort;
        }
        return $filters;
    }
}
