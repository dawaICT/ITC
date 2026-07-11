<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__, 2) . '/includes/repository/pages.php';

repo_ensure_schema($db);

function repo_lecturer_header(string $title, string $subtitle = ''): void
{
    global $db, $page_title;
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
    <?php
}

function repo_lecturer_footer(): void
{
    ?>
        </div>
    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
    <?php
}
