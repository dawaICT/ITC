<?php
declare(strict_types=1);

$page_title = 'Teaching Planner Template Guide';
require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/ai_markdown.php';

// Same audience as admin/teaching_planner.php: template authoring is a
// systems-administrator task, and this page renders the admin chrome.
if (!function_exists('isSystemsAdmin') || !isSystemsAdmin()) {
    http_response_code(403);
    $_SESSION['errorMessage'] = 'The Teaching Planner template guide requires Systems Administrator access.';
    header('Location: /wucportal/portal_selection.php');
    exit;
}

require_once __DIR__ . '/includes/nav.php';

$guidePath = dirname(__DIR__) . '/docs/TEACHING_PLANNER_TEMPLATE_GUIDE.md';
$guideMarkdown = is_file($guidePath) ? (string)file_get_contents($guidePath) : '';
?>
<link rel="stylesheet" href="/wucportal/css/teaching-planner.css">
<div class="container-fluid px-4 py-4 portal-dashboard tp-page">
    <section class="tp-hero mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="h3 mb-1"><i class="fas fa-book-open me-2"></i>Teaching Planner Template Guide</h1>
                <p class="mb-0 opacity-75">How to author, validate and activate Word templates for the Teaching Planner.</p>
            </div>
            <a href="teaching_planner.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Teaching Planner</a>
        </div>
    </section>
    <article class="tp-card">
        <div class="tp-card-body">
            <?php if ($guideMarkdown === ''): ?>
                <div class="tp-empty"><i class="fas fa-file-circle-question"></i>The guide document is not available. Contact the systems administrator.</div>
            <?php else: ?>
                <?= wuc_ai_output_block($guideMarkdown) ?>
            <?php endif; ?>
        </div>
    </article>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
