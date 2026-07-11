<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/repository/pages.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
repo_ensure_schema($db);
if (!repo_is_admin()) {
    http_response_code(403);
    exit('Admin access is required.');
}

function repo_admin_schema_ready(mysqli $db): bool
{
    return repo_table_exists($db, 'repository_categories')
        && repo_table_exists($db, 'repository_materials');
}

function repo_admin_header(string $title, string $subtitle = ''): void
{
    global $page_title, $db;
    $page_title = $title;
    $additional_styles = ['css/repository.css'];
    ?>
    <?php require_once dirname(__DIR__) . '/includes/nav.php'; ?>
        <div class="container-fluid px-4 portal-dashboard repository-page">
            <div class="dashboard-header">
                <h2><i class="fas fa-folder-open"></i> <?php echo repo_h($title); ?></h2>
                <hr>
                <?php if ($subtitle !== ''): ?><p class="welcome-subtitle"><?php echo repo_h($subtitle); ?></p><?php endif; ?>
            </div>
            <?php if ($flash = repo_flash()): ?>
                <div class="alert alert-<?php echo repo_h($flash['type']); ?>"><?php echo repo_h($flash['message']); ?></div>
            <?php endif; ?>
            <?php if (!repo_admin_schema_ready($db)): ?>
                <div class="alert alert-warning">
                    <strong>Repository schema not installed.</strong>
                    Apply <code>migrations/20260703_digital_learning_repository.sql</code> as MySQL root, then refresh.
                </div>
            <?php endif; ?>
    <?php
}

function repo_admin_footer(): void
{
    ?>
        </div>
    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
    <?php
}

function repo_admin_filters_from_request(?string $status = null): array
{
    $filters = [
        'q' => trim((string)($_GET['q'] ?? '')),
        'material_type' => trim((string)($_GET['material_type'] ?? '')),
        'course_code' => trim((string)($_GET['course_code'] ?? '')),
        'programme_code' => trim((string)($_GET['programme_code'] ?? '')),
        'department_id' => trim((string)($_GET['department_id'] ?? '')),
        'sort' => trim((string)($_GET['sort'] ?? '')),
    ];
    if ($status !== null) {
        $filters['status'] = $status;
    }
    if ($filters['sort'] === 'views') {
        $filters['most_viewed'] = true;
    } elseif ($filters['sort'] === 'downloads') {
        $filters['most_downloaded'] = true;
    }
    return $filters;
}
