<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/enterprise_hub/bootstrap.php';
require_once __DIR__ . '/includes/helpers.php';

wuc_secure_session_start();
if (function_exists('wuc_security_headers')) {
    wuc_security_headers();
}

$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
$slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?? '';

$category = null;
if ($slug !== '') {
    foreach (eh_list_categories($db, true) as $cat) {
        if ((string)$cat['category_slug'] === $slug) {
            $category = $cat;
            break;
        }
    }
}

if (!$category) {
    http_response_code(404);
    $pageTitle = 'Category not found';
    require __DIR__ . '/includes/public_header.php';
    echo '<main class="eh-main"><div class="container"><div class="alert alert-warning">Category not found.</div>';
    echo '<a class="btn btn-eh-primary" href="/wucportal/showcase/index.php">Back to catalogue</a></div>';
    require __DIR__ . '/includes/public_footer.php';
    exit;
}

$filters = [
    'category_slug' => $slug,
    'sort' => 'featured',
];
$items = eh_search_published($db, $filters, 48, 0);

$pageTitle = (string)$category['category_name'];
$pageDescription = 'Published Skills-to-Trade opportunities in ' . (string)$category['category_name'];
require __DIR__ . '/includes/public_header.php';
?>
<section class="eh-theme-banner">
    <div class="container position-relative" style="z-index:1">
        <span class="banner-year">Category</span>
        <h1><?php echo eh_h((string)$category['category_name']); ?></h1>
        <p>Fostering Trade Investment 2026 — browse published opportunities in this category.</p>
    </div>
</section>

<main class="eh-main">
<div class="container">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/wucportal/showcase/index.php">Catalogue</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?php echo eh_h((string)$category['category_name']); ?></li>
        </ol>
    </nav>

    <?php if (!$items): ?>
        <div class="text-center py-5 text-muted eh-filter-panel">
            <i class="fas fa-folder-open fa-3x mb-3 opacity-50"></i>
            <p class="mb-0">No published opportunities in this category yet.</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($items as $item) {
                showcase_render_item_card($db, $item);
            } ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
