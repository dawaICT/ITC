<?php
/**
 * Standard document opener for student e-learning utility pages.
 *
 * Set before include:
 *   $page_title      — tab title segment (defaults to "Student Portal")
 *   $head_extra_css  — optional array of stylesheet href strings
 *   $head_extra_html — optional raw HTML appended inside <head>
 */
declare(strict_types=1);

if (!function_exists('wuc_portal_title')) {
    require_once dirname(__DIR__, 2) . '/includes/page_meta.php';
}

if (defined('WUC_STUDENT_HEADER_OPEN')) {
    return;
}
define('WUC_STUDENT_HEADER_OPEN', true);

$page_title = isset($page_title) ? (string) $page_title : 'Student Portal';
$wucDocTitle = wuc_portal_title($page_title);
$head_extra_css = isset($head_extra_css) && is_array($head_extra_css) ? $head_extra_css : [];
$head_extra_html = isset($head_extra_html) ? (string) $head_extra_html : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($wucDocTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <?php wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/wucportal/assets/css/main.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/css/elearning-ui.css">
<?php foreach ($head_extra_css as $cssHref): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars((string) $cssHref, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>
<?= $head_extra_html ?>
</head>
<body class="bg-light">
<?php require_once __DIR__ . '/navbar.php'; ?>
