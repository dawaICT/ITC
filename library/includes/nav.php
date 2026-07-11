<?php
/**
 * Library module — sidebar configuration.
 *
 * Library pages still live under /admin/library_*.php (those pages are
 * already gated on library_* permissions, not admin), so the menu items
 * point there. The point of routing through this nav is that a Librarian
 * sees the same portal sidebar and chrome as every other module, instead
 * of a self-contained one-off.
 */

require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$staffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '';

$libItems = [
    ['href' => '/wucportal/library/',                       'icon' => 'fas fa-home',             'label' => 'Library Home',  'active_on' => 'library/index.php'],
];

// ?from=library tells the shared /admin/library_*.php pages to render with the
// Library sidebar instead of the Admin sidebar — without it, librarians end up
// looking at the Admin module.
if ($staffId !== '' && (canCatalog($staffId) || canManageLibrary($staffId))) {
    $libItems[] = ['href' => '/wucportal/admin/library_catalog.php?from=library',     'icon' => 'fas fa-journal-whills', 'label' => 'Catalog',     'active_on' => 'library_catalog.php'];
}
if ($staffId !== '' && (canCirculate($staffId) || canManageLibrary($staffId))) {
    $libItems[] = ['href' => '/wucportal/admin/library_circulation.php?from=library', 'icon' => 'fas fa-right-left',     'label' => 'Circulation', 'active_on' => 'library_circulation.php'];
}
if ($staffId !== '' && (hasPermission($staffId, 'library_fines') || canManageLibrary($staffId))) {
    $libItems[] = ['href' => '/wucportal/admin/library_fines.php?from=library',       'icon' => 'fas fa-coins',          'label' => 'Fines',       'active_on' => 'library_fines.php'];
}
if ($staffId !== '' && (hasPermission($staffId, 'library_digital') || canManageLibrary($staffId))) {
    $libItems[] = ['href' => '/wucportal/admin/library_digital.php?from=library',     'icon' => 'fas fa-cloud-arrow-down','label' => 'Digital',    'active_on' => 'library_digital.php'];
}

$module_config = [
    'role_label'           => 'Librarian',
    // NOTE: no 'required_access' here — library pages are gated per
    // library_* permission (hasPermission), not per role, so a role gate
    // would lock out staff who hold library permissions without the role.
    'footer_profile_href'  => '/wucportal/admin/profile.php',
    'footer_logout_href'   => '/wucportal/logout.php?to=staff',
    'brand_color_primary'  => '#1B2A4A', // ITC navy (matches unified sidebar)
    'brand_color_secondary'=> '#0B1530', // Deep navy (card-header gradient)
    'menu_sections' => [
        [
            'title' => 'Library',
            'items' => $libItems,
        ],
    ],
];

require dirname(__DIR__, 2) . '/includes/nav_unified.php';
