<?php
declare(strict_types=1);

/**
 * Exhibition dashboard — public, read-only, high contrast.
 * Auto-refreshes stats via showcase/api/stats.php every 30s.
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/bootstrap.php';
require_once __DIR__ . '/includes/helpers.php';

wuc_secure_session_start();
if (function_exists('wuc_security_headers')) {
    wuc_security_headers();
}

if (!eh_exhibition_mode($db)) {
    http_response_code(404);
    $pageTitle = 'Exhibition unavailable';
    require __DIR__ . '/includes/public_header.php';
    echo '<main class="eh-main"><div class="container">';
    echo '<div class="alert alert-secondary">Exhibition mode is not enabled.</div>';
    echo '<a class="btn btn-eh-primary" href="/wucportal/showcase/index.php">Back to catalogue</a>';
    echo '</div>';
    require __DIR__ . '/includes/public_footer.php';
    exit;
}

$featured = eh_search_published($db, ['featured' => 1, 'sort' => 'featured'], 12, 0);
if (count($featured) < 6) {
    $more = eh_search_published($db, ['sort' => 'recent'], 12, 0);
    $seen = [];
    foreach ($featured as $f) {
        $seen[(int)$f['id']] = true;
    }
    foreach ($more as $m) {
        if (isset($seen[(int)$m['id']])) {
            continue;
        }
        $featured[] = $m;
        if (count($featured) >= 12) {
            break;
        }
    }
}

$stats = eh_admin_dashboard_stats($db);
// Interests against published items only
$interestRow = $db->query("
    SELECT COUNT(*) AS c
    FROM enterprise_interests ii
    JOIN enterprise_items i ON i.id = ii.enterprise_item_id
    WHERE i.status = 'published'
");
$publishedInterests = (int)(($interestRow ? $interestRow->fetch_assoc()['c'] : 0) ?? 0);
if ($interestRow) {
    $interestRow->free();
}

$bodyClass = 'eh-exhibition';
$pageTitle = 'Exhibition · Fostering Trade Investment 2026';
$extraHead = '<meta http-equiv="refresh" content="300">';
require __DIR__ . '/includes/public_header.php';
?>
<section class="eh-theme-banner">
    <div class="container position-relative" style="z-index:1">
        <span class="banner-year">Live Exhibition · 2026</span>
        <h1>Fostering Trade Investment</h1>
        <p>Skills-to-Trade and Investment Hub · Industrial Training Centre</p>
    </div>
</section>

<main class="eh-main">
<div class="container-fluid px-3 px-lg-4">
    <div class="row g-3 mb-4" id="ehExhibitionStats">
        <div class="col-6 col-lg-3">
            <div class="eh-stat">
                <div class="value" data-stat="published_total"><?php echo (int)$stats['published_total']; ?></div>
                <div class="label">Published opportunities</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="eh-stat">
                <div class="value" data-stat="investment_display"><?php echo eh_h(number_format((float)$stats['investment_requested'], 0)); ?></div>
                <div class="label">Investment sought (ZMW)</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="eh-stat">
                <div class="value" data-stat="potential_jobs"><?php echo (int)$stats['potential_jobs']; ?></div>
                <div class="label">Potential jobs</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="eh-stat">
                <div class="value" data-stat="interests_published"><?php echo $publishedInterests; ?></div>
                <div class="label">Expressions of interest</div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h2 class="eh-section-title mb-0 text-white bg-dark px-3 py-2 rounded">
            <i class="fas fa-star text-warning"></i>Featured showcase
        </h2>
        <span class="small text-muted" id="ehStatsUpdated">Stats refresh every 30s</span>
    </div>

    <div class="row g-3">
        <?php if (!$featured): ?>
            <div class="col-12">
                <div class="eh-filter-panel text-center py-5 text-muted">
                    No published opportunities available for exhibition.
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($featured as $item) {
                showcase_render_item_card($db, $item);
            } ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var statsUrl = '/wucportal/showcase/api/stats.php';
    function applyStats(data) {
        if (!data || !data.ok) return;
        var map = {
            published_total: data.published_total,
            potential_jobs: data.potential_jobs,
            interests_published: data.interests,
            investment_display: data.investment_formatted
        };
        Object.keys(map).forEach(function (key) {
            var el = document.querySelector('[data-stat="' + key + '"]');
            if (el && map[key] !== undefined && map[key] !== null) {
                el.textContent = String(map[key]);
            }
        });
        var stamp = document.getElementById('ehStatsUpdated');
        if (stamp) {
            stamp.textContent = 'Updated ' + new Date().toLocaleTimeString();
        }
    }
    function refresh() {
        fetch(statsUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(applyStats)
            .catch(function () { /* keep last values */ });
    }
    setInterval(refresh, 30000);
})();
</script>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
