<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!ep_public_directory_enabled($db)) {
    http_response_code(503);
    echo 'Unavailable.';
    exit;
}

$slug = trim((string)($_GET['slug'] ?? ''));
$cat = null;
foreach (ep_list_categories($db, true) as $c) {
    if ((string)$c['category_slug'] === $slug) {
        $cat = $c;
        break;
    }
}
if (!$cat) {
    http_response_code(404);
    echo 'Category not found.';
    exit;
}

$rows = ep_search_published_opportunities($db, ['category_id' => (int)$cat['id']], 48, 0);
ep_public_page_begin((string)$cat['category_name']);
ep_public_disclaimer_banner();
?>
<div class="container py-4">
    <h1 class="h3 mb-3"><?= ep_h((string)$cat['category_name']) ?></h1>
    <?php if ($rows === []): ?>
        <div class="ep-card"><p class="ep-muted mb-0">No published opportunities in this category.</p></div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($rows as $row): ?>
                <div class="col-md-6">
                    <div class="ep-card">
                        <h2 class="h5"><?= ep_h((string)$row['title']) ?></h2>
                        <p class="small ep-muted"><?= ep_h((string)$row['short_description']) ?></p>
                        <a href="/wucportal/opportunities/view.php?code=<?= ep_h(rawurlencode((string)$row['public_code'])) ?>">View</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php ep_public_page_end(); ?>
