<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/bootstrap.php';
require_once __DIR__ . '/includes/helpers.php';

wuc_secure_session_start();
if (function_exists('wuc_security_headers')) {
    wuc_security_headers();
}

$filters = showcase_filters_from_request();
$submitted = isset($_GET['q']) || isset($_GET['keyword']) || isset($_GET['category'])
    || isset($_GET['item_type']) || isset($_GET['province'])
    || isset($_GET['investment_min']) || isset($_GET['investment_max']);

$results = $submitted ? eh_search_published($db, $filters, 36, 0) : [];
$categories = eh_list_categories($db, true);
$provinces = showcase_list_provinces($db);
$itemTypes = eh_item_types();

$pageTitle = 'Search opportunities';
$pageDescription = 'Search the Skills-to-Trade public catalogue by keyword, category, type, province and investment range.';
require __DIR__ . '/includes/public_header.php';
?>
<section class="eh-theme-banner">
    <div class="container position-relative" style="z-index:1">
        <span class="banner-year">Theme 2026</span>
        <h1>Search Skills-to-Trade</h1>
        <p>Find products, services and investment opportunities fostering trade investment.</p>
    </div>
</section>

<main class="eh-main">
<div class="container">
    <form class="eh-filter-panel mb-4" method="get" action="/wucportal/showcase/search.php" role="search">
        <div class="row g-3 align-items-end">
            <div class="col-md-6 col-lg-4">
                <label class="form-label" for="q">Keyword</label>
                <input class="form-control form-control-lg" type="search" id="q" name="q"
                       value="<?php echo eh_h((string)($filters['q'] ?? '')); ?>"
                       placeholder="Title, enterprise or description" maxlength="120">
            </div>
            <div class="col-md-6 col-lg-3">
                <label class="form-label" for="category">Category</label>
                <select class="form-select" id="category" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo eh_h((string)$cat['category_slug']); ?>"
                            <?php echo (($filters['category_slug'] ?? '') === $cat['category_slug']) ? 'selected' : ''; ?>>
                            <?php echo eh_h((string)$cat['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 col-lg-3">
                <label class="form-label" for="item_type">Item type</label>
                <select class="form-select" id="item_type" name="item_type">
                    <option value="">All types</option>
                    <?php foreach ($itemTypes as $key => $label): ?>
                        <option value="<?php echo eh_h($key); ?>"
                            <?php echo (($filters['item_type'] ?? '') === $key) ? 'selected' : ''; ?>>
                            <?php echo eh_h($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 col-lg-2">
                <label class="form-label" for="province">Province</label>
                <select class="form-select" id="province" name="province">
                    <option value="">All</option>
                    <?php foreach ($provinces as $prov): ?>
                        <option value="<?php echo eh_h($prov); ?>"
                            <?php echo (($filters['province'] ?? '') === $prov) ? 'selected' : ''; ?>>
                            <?php echo eh_h($prov); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label" for="investment_min">Min investment</label>
                <input class="form-control" type="number" id="investment_min" name="investment_min" min="0" step="100"
                       value="<?php echo isset($filters['investment_min']) ? eh_h((string)$filters['investment_min']) : ''; ?>">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label" for="investment_max">Max investment</label>
                <input class="form-control" type="number" id="investment_max" name="investment_max" min="0" step="100"
                       value="<?php echo isset($filters['investment_max']) ? eh_h((string)$filters['investment_max']) : ''; ?>">
            </div>
            <div class="col-md-6 col-lg-3">
                <label class="form-label" for="sort">Sort</label>
                <select class="form-select" id="sort" name="sort">
                    <?php
                    $sortOpts = ['featured' => 'Featured first', 'recent' => 'Most recent', 'views' => 'Most viewed', 'investment' => 'Investment'];
                    $curSort = (string)($filters['sort'] ?? 'featured');
                    foreach ($sortOpts as $k => $lab):
                    ?>
                        <option value="<?php echo eh_h($k); ?>" <?php echo $curSort === $k ? 'selected' : ''; ?>>
                            <?php echo eh_h($lab); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 col-lg-3">
                <button class="btn btn-eh-primary w-100" type="submit">
                    <i class="fas fa-search me-1"></i>Search
                </button>
            </div>
        </div>
    </form>

    <?php if ($submitted): ?>
        <h2 class="eh-section-title">
            <i class="fas fa-list"></i>
            Results
            <span class="badge text-bg-light text-dark border ms-1"><?php echo count($results); ?></span>
        </h2>
        <?php if (!$results): ?>
            <div class="text-center py-5 text-muted eh-filter-panel">
                <i class="fas fa-search fa-3x mb-3 opacity-50"></i>
                <p class="mb-0">No published opportunities matched your search.</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($results as $item) {
                    showcase_render_item_card($db, $item);
                } ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="eh-filter-panel text-muted">
            <i class="fas fa-info-circle me-1"></i>
            Enter a keyword or choose filters, then search. Only published opportunities are shown.
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
