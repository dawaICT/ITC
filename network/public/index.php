<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';

if (!ep_network_public_directory_enabled($db)) {
    http_response_code(503);
    echo 'The public directory is currently unavailable.';
    exit;
}

$filters = ep_network_public_filters_from_request();
$categories = ep_list_categories($db, true);
$rows = ep_search_published_opportunities($db, $filters, 48, 0);
$hasActiveFilters = $filters !== [];
$resultCount = count($rows);
$base = '/wucportal/network/public/index.php';

ep_network_public_page_begin('Public directory');
?>
<section class="ep-public-hero">
    <div class="container">
        <div class="ep-public-eyebrow"><i class="fas fa-certificate"></i> Verified listings</div>
        <h1>Public directory</h1>
        <p class="lead">Discover verified skills, products, services, innovations, and employment profiles—no sign-in required.</p>
        <div class="ep-public-type-pills" aria-label="Browse by type">
            <?php
            $pillTypes = array_slice(ep_opportunity_types(), 0, 6, true);
            foreach ($pillTypes as $k => $l):
                $activePill = (($filters['type'] ?? '') === $k);
            ?>
                <a class="ep-public-pill<?= $activePill ? ' is-active' : '' ?>" href="<?= ep_h($base) ?>?type=<?= ep_h(rawurlencode($k)) ?>#directory"><?= ep_h($l) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<div class="container ep-public-directory pb-5" id="directory">
    <form method="get" class="ep-public-filters" action="<?= ep_h($base) ?>">
        <div class="ep-card ep-public-filter-card">
            <div class="ep-public-filter-hdr">
                <h2><i class="fas fa-sliders"></i> Find opportunities</h2>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label class="form-label" for="ep-q">Keyword</label>
                    <input type="text" id="ep-q" name="q" class="form-control" value="<?= ep_h((string)($filters['q'] ?? '')) ?>" maxlength="120">
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
                    <button class="btn btn-primary w-100" type="submit"><i class="fas fa-search me-1"></i>Search</button>
                </div>
            </div>
            <?php if ($hasActiveFilters): ?>
                <div class="mt-3 pt-2 border-top">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= ep_h($base) ?>">Clear all</a>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <?php ep_network_public_disclaimer_banner(); ?>

    <div class="ep-public-results-meta">
        <h2><?= $hasActiveFilters ? 'Search results' : 'Published opportunities' ?></h2>
        <span class="ep-public-count"><?= (int)$resultCount ?> listing<?= $resultCount === 1 ? '' : 's' ?></span>
    </div>

    <?php if ($rows === []): ?>
        <div class="ep-card ep-empty-state ep-public-empty">
            <h2>No listings to show</h2>
            <p class="ep-muted">Try different filters or check back later.</p>
            <a class="btn btn-outline-primary" href="/wucportal/network/login.php">Member sign in</a>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($rows as $row): ?>
                <?php
                $type = (string)($row['opportunity_type'] ?? '');
                $participant = trim((string)(($row['business_name'] ?? '') ?: ($row['professional_title'] ?? ''))) ?: 'Participant';
                $code = (string)$row['public_code'];
                ?>
                <div class="col-md-6 col-lg-4">
                    <article class="ep-card ep-listing-card">
                        <div class="ep-listing-type">
                            <i class="fas <?= ep_h(ep_network_public_type_icon($type)) ?>"></i>
                            <?= ep_h(ep_status_label($type)) ?>
                        </div>
                        <h2><?= ep_h((string)$row['title']) ?></h2>
                        <p class="ep-listing-desc"><?= ep_h((string)$row['short_description']) ?></p>
                        <div class="ep-listing-meta"><div><i class="fas fa-building me-1"></i><?= ep_h($participant) ?></div></div>
                        <a class="btn btn-sm btn-outline-primary mt-2" href="/wucportal/network/public/view.php?code=<?= ep_h(rawurlencode($code)) ?>">View details</a>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php ep_network_public_page_end(); ?>
