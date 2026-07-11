<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/repository/pages.php';
repo_ensure_schema($db);

function repo_public_header(string $title, string $subtitle = ''): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo repo_h($title); ?> - WUCPortal</title>
        <?php require_once dirname(__DIR__) . '/includes/page_meta.php'; wuc_portal_favicon_links(); ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
        <link rel="stylesheet" href="/wucportal/css/wuc-premium.css">
        <link rel="stylesheet" href="/wucportal/css/repository.css?v=20260703b">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </head>
    <body class="bg-light">
    <main class="py-4">
        <div class="container portal-dashboard repository-page repository-public">
            <div class="dashboard-header">
                <h2><i class="fas fa-folder-open"></i> <?php echo repo_h($title); ?></h2>
                <hr>
                <?php if ($subtitle !== ''): ?><p class="welcome-subtitle"><?php echo repo_h($subtitle); ?></p><?php endif; ?>
            </div>
    <?php
}

function repo_public_footer(): void
{
    ?>
        </div>
    </main>
    </body>
    </html>
    <?php
}
