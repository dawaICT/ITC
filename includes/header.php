<?php
/**
 * Shared Header — ITC Portal
 * Outputs a proper <head> section with all consolidated CSS.
 *
 * Variables (set before include):
 *   $page_title  — string — page <title> text (defaults to 'ITC Portal')
 *   $extra_css   — array  — optional extra CSS paths to include
 */
require_once __DIR__ . '/page_meta.php';
$wucDocTitle = wuc_portal_title(isset($page_title) ? (string)$page_title : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Industrial Training Centre — Staff & Student Portal">
    <title><?= htmlspecialchars($wucDocTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <?php wuc_portal_favicon_links(); ?>

    <!-- Bootstrap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Portal Design System (single entry point — imports all modules) -->
    <link rel="stylesheet" href="/wucportal/assets/css/main.css">

    <!-- Legacy compatibility layer (kept for pages not yet migrated) -->
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">

    <?php if (!empty($extra_css) && is_array($extra_css)): ?>
        <?php foreach ($extra_css as $css_path): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($css_path) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
