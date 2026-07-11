<?php
/**
 * WUC Portal - Consolidated CSS Includes
 * Include this file in your page headers to load the consolidated CSS styles
 */
?>
<!-- Base Styles -->
<link rel="stylesheet" href="<?php echo $baseUrl; ?>/css/main.css">
<link rel="stylesheet" href="<?php echo $baseUrl; ?>/css/sidebar.css">

<!-- Component Styles -->
<link rel="stylesheet" href="<?php echo $baseUrl; ?>/css/components.css">
<link rel="stylesheet" href="<?php echo $baseUrl; ?>/css/forms.css">
<link rel="stylesheet" href="<?php echo $baseUrl; ?>/css/tables.css">

<!-- Page-specific Styles -->
<link rel="stylesheet" href="<?php echo $baseUrl; ?>/css/portal-dashboard.css">
<link rel="stylesheet" href="<?php echo $baseUrl; ?>/css/dashboard.css">

<!-- Font Awesome (For Icons) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Inter typeface (portal brand font) -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<!-- Typography override (loads last to gently reduce font sizes) -->
<link rel="stylesheet" href="<?php echo $baseUrl; ?>/css/typography-override.css?v=<?php echo time(); ?>">

<!-- JavaScript -->
<script src="<?php echo $baseUrl; ?>/js/sidebar.js"></script>
