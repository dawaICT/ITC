<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__, 2) . '/includes/repository/pages.php';

repo_ensure_schema($db);

function repo_student_header(string $title, string $subtitle = ''): void
{
    global $db;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo repo_h($title); ?> - WUCPortal</title>
        <?php require_once dirname(__DIR__, 2) . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    </head>
    <body class="bg-light">
    <?php require_once dirname(__DIR__) . '/includes/navbar.php'; ?>
    <link rel="stylesheet" href="/wucportal/css/repository.css?v=20260703b">
    <main class="content-wrapper pt-3 pb-5">
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

function repo_student_footer(): void
{
    ?>
        </div>
    </main>
    </body>
    </html>
    <?php
}
