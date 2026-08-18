<?php
declare(strict_types=1);

/**
 * JSON stats for exhibition dashboard — published data only.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

if (function_exists('wuc_security_headers')) {
    wuc_security_headers();
}

if (!eh_exhibition_mode($db)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Exhibition mode is not enabled.']);
    exit;
}

$q = static function (mysqli $db, string $sql): ?array {
    $res = $db->query($sql);
    if (!$res) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return $row ?: null;
};

$publishedTotal = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_items WHERE status='published'")['c'] ?? 0);
$publishedProducts = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_items WHERE status='published' AND item_type='product'")['c'] ?? 0);
$publishedServices = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_items WHERE status='published' AND item_type='service'")['c'] ?? 0);
$investmentSum = (float)($q($db, "SELECT COALESCE(SUM(investment_required),0) s FROM enterprise_items WHERE status='published'")['s'] ?? 0);
$jobs = (int)($q($db, "SELECT COALESCE(SUM(employment_potential),0) s FROM enterprise_items WHERE status='published'")['s'] ?? 0);
$interests = (int)($q($db, "
    SELECT COUNT(*) c
    FROM enterprise_interests ii
    JOIN enterprise_items i ON i.id = ii.enterprise_item_id
    WHERE i.status = 'published'
")['c'] ?? 0);
$featured = (int)($q($db, "SELECT COUNT(*) c FROM enterprise_items WHERE status='published' AND is_featured=1")['c'] ?? 0);

echo json_encode([
    'ok' => true,
    'published_total' => $publishedTotal,
    'published_products' => $publishedProducts,
    'published_services' => $publishedServices,
    'featured_count' => $featured,
    'investment_sum' => $investmentSum,
    'investment_formatted' => number_format($investmentSum, 0),
    'potential_jobs' => $jobs,
    'jobs' => $jobs,
    'interests' => $interests,
    'generated_at' => date('c'),
], JSON_UNESCAPED_SLASHES);
