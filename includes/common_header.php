<?php
// Common header for all pages
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__FILE__)));
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Get the current directory path relative to root
$current_dir = dirname($_SERVER['PHP_SELF']);
$root_dir = dirname(dirname($_SERVER['PHP_SELF']));
$css_path = str_repeat('../', substr_count($current_dir, '/') - substr_count($root_dir, '/')) . 'css/';

// Ensure the path is correct for the root directory
if ($current_dir === '/') {
    $css_path = 'css/';
}

require_once __DIR__ . '/page_meta.php';
$wucDocTitle = wuc_portal_title(isset($page_title) ? (string)$page_title : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($wucDocTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php wuc_portal_favicon_links(); ?>

    <!-- Common CSS Files -->
    <link href="<?php echo $css_path; ?>layout.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Page Specific CSS -->
    <?php if (isset($page_specific_css)): ?>
        <?php foreach ($page_specific_css as $css): ?>
            <link href="<?php echo htmlspecialchars($css); ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body> 