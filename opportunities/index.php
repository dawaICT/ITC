<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!ep_public_directory_enabled($db)) {
    http_response_code(503);
    echo 'The Skills and Enterprise Directory is currently unavailable.';
    exit;
}

$filters = ep_public_filters_from_request();
$categories = ep_list_categories($db, true);
$rows = ep_search_published_opportunities($db, $filters, 48, 0);
$hasActiveFilters = $filters !== [];
$resultCount = count($rows);

ep_public_page_begin('Skills and Enterprise Directory');
?>
<section class="ep-public-hero">
    <div class="container">
        <div class="ep-public-eyebrow"><i class="fas fa-certificate"></i> Verified ITC listings</div>
        <h1>Skills and Enterprise Directory</h1>
        <p class="lead">Discover verified skills, products, services, innovations, and employment profiles from ITC participants.</p>
        <div class="ep-public-type-pills" aria-label="Browse by type">
            <?php
            $pillTypes = array_slice(ep_opportunity_types(), 0, 6, true);
            foreach ($pillTypes as $k => $l):
                $activePill = (($filters['type'] ?? '') === $k);
            ?>
                <a class="ep-public-pill<?= $activePill ? ' is-active' : '' ?>" href="/wucportal/opportunities/index.php?type=<?= ep_h(rawurlencode($k)) ?>#directory"><?= ep_h($l) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<div class="container ep-public-directory pb-5" id="directory">
    <form method="get" class="ep-public-filters" action="/wucportal/opportunities/index.php">
        <div class="ep-card ep-public-filter-card">
            <div class="ep-public-filter-hdr">
                <h2><i class="fas fa-sliders"></i> Find opportunities</h2>
                <span class="ep-muted small d-none d-md-inline">Filter by keyword, type, or category</span>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label class="form-label" for="ep-q">Keyword</label>
                    <div class="input-group ep-public-input-group">
                        <span class="input-group-text"><i class="fas fa-magnifying-glass"></i></span>
                        <input type="text" id="ep-q" name="q" class="form-control" placeholder="Title, skill, product…" value="<?= ep_h((string)($filters['q'] ?? '')) ?>" maxlength="120">
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label" for="ep-type">Type</label>
                    <select id="ep-type" name="type" class="form-select">
                        <option value="">All types</option>
                        <?php foreach (ep_opportunity_types() as $k => $l): ?>
                            <option value="<?= ep_h($k) ?>" <?= (($filters['type'] ?? '') === $k) ? 'selected' : '' ?>><?= ep_h($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label" for="ep-category">Category</label>
                    <select id="ep-category" name="category_id" class="form-select">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ((int)($filters['category_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= ep_h((string)$c['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label d-none d-lg-block">&nbsp;</label>
                    <button class="btn btn-primary w-100 ep-public-search-btn" type="submit"><i class="fas fa-search me-1"></i>Search</button>
                </div>
                <div class="col-12">
                    <div class="ep-public-featured-toggle">
                        <input class="form-check-input" type="checkbox" value="1" id="ep-featured" name="featured" <?= !empty($filters['featured']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ep-featured"><i class="fas fa-star text-warning me-1"></i>Featured listings only</label>
                    </div>
                </div>
            </div>
            <?php if ($hasActiveFilters): ?>
                <div class="mt-3 pt-2 border-top d-flex flex-wrap gap-2 align-items-center">
                    <span class="small ep-muted">Active filters</span>
                    <a class="btn btn-sm btn-outline-secondary" href="/wucportal/opportunities/index.php">Clear all</a>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <?php ep_public_disclaimer_banner(); ?>

    <div class="ep-public-results-meta">
        <h2>
            <?php if ($hasActiveFilters): ?>
                Search results
            <?php else: ?>
                Published opportunities
            <?php endif; ?>
        </h2>
        <span class="ep-public-count"><?= (int)$resultCount ?> listing<?= $resultCount === 1 ? '' : 's' ?></span>
    </div>

    <?php if ($rows === []): ?>
        <div class="ep-card ep-empty-state ep-public-empty">
            <div class="ep-empty-icon" aria-hidden="true"><i class="fas fa-store"></i></div>
            <?php if ($hasActiveFilters): ?>
                <h2>No matching listings</h2>
                <p class="ep-muted mb-3">Try a broader keyword, or clear filters to see all published opportunities.</p>
                <a class="btn btn-outline-primary" href="/wucportal/opportunities/index.php">Clear filters</a>
            <?php else: ?>
                <h2>Directory is ready — listings coming soon</h2>
                <p class="ep-muted mb-3">Verified opportunities appear here after participants publish through the Skills and Enterprise Portal.</p>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a class="btn btn-primary" href="/wucportal/enterprise/join.php"><i class="fas fa-user-plus me-1"></i>Join the portal</a>
                    <a class="btn btn-outline-secondary" href="/wucportal/portal_selection.php">Portal selection</a>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($rows as $row): ?>
                <?php
                $type = (string)($row['opportunity_type'] ?? '');
                $participant = trim((string)(($row['business_name'] ?? '') ?: ($row['professional_title'] ?? ''))) ?: 'Participant';
                $priceLabel = '';
                if (!empty($row['investment_required']) && (float)$row['investment_required'] > 0) {
                    $priceLabel = 'Seeking ' . ep_money($row['investment_required'], (string)($row['currency'] ?? 'ZMW'));
                } elseif ($row['unit_price'] !== null && $row['unit_price'] !== '' && (float)$row['unit_price'] > 0) {
                    $priceLabel = ep_money($row['unit_price'], (string)($row['currency'] ?? 'ZMW'));
                }
                $code = (string)$row['public_code'];
                ?>
                <div class="col-md-6 col-lg-4">
                    <article class="ep-card ep-listing-card">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                            <div class="ep-listing-type">
                                <i class="fas <?= ep_h(ep_public_type_icon($type)) ?>"></i>
                                <?= ep_h(ep_status_label($type)) ?>
                            </div>
                            <?php if (!empty($row['is_featured'])): ?>
                                <span class="badge bg-warning text-dark">Featured</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($row['category_name'])): ?>
                            <div class="small text-muted mb-2"><?= ep_h((string)$row['category_name']) ?></div>
                        <?php endif; ?>
                        <h2><?= ep_h((string)$row['title']) ?></h2>
                        <p class="ep-listing-desc"><?= ep_h((string)$row['short_description']) ?></p>
                        <div class="ep-listing-meta">
                            <div><i class="fas fa-building me-1"></i><?= ep_h($participant) ?></div>
                            <?php if (!empty($row['province'])): ?>
                                <div><i class="fas fa-location-dot me-1"></i><?= ep_h((string)$row['province']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($priceLabel !== ''): ?>
                            <div class="ep-listing-price"><?= ep_h($priceLabel) ?></div>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-primary mt-auto align-self-start" href="/wucportal/opportunities/view.php?code=<?= ep_h(rawurlencode($code)) ?>">
                            View details <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php ep_public_page_end(); ?>
