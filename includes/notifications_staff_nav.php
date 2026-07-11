<?php
declare(strict_types=1);

/**
 * Load the logged-in staff member's module sidebar on notifications.php.
 *
 * Module nav files normally end with nav_unified.php. We set $GLOBALS['wuc_nav_config_only']
 * so the first include only builds $module_config, then absolutize relative hrefs
 * (notifications.php lives at /wucportal/ root, not inside the module folder).
 */

require_once __DIR__ . '/helpers/redirect_helper.php';
require_once __DIR__ . '/staff_role_helpers.php';
require_once __DIR__ . '/role_helpers.php';
require_once __DIR__ . '/portal_alerts.php';

if (!function_exists('wuc_notifications_absolutize_menu_hrefs')) {
    /**
     * @param array<int,array<string,mixed>> $sections
     * @return array<int,array<string,mixed>>
     */
    function wuc_notifications_absolutize_menu_hrefs(array $sections, string $moduleBase): array
    {
        $base = rtrim($moduleBase, '/') . '/';
        foreach ($sections as $si => $section) {
            if (empty($section['items']) || !is_array($section['items'])) {
                continue;
            }
            foreach ($section['items'] as $ii => $item) {
                $href = trim((string)($item['href'] ?? ''));
                if ($href === '' || $href === '#' || $href[0] === '/' || preg_match('#^https?://#i', $href)) {
                    continue;
                }
                $sections[$si]['items'][$ii]['href'] = $base . ltrim($href, '/');
            }
        }
        return $sections;
    }
}

if (!function_exists('wuc_notifications_staff_nav_target')) {
    /**
     * @return array{nav_file:string,module_base:string,footer_file:string}|null
     */
    function wuc_notifications_staff_nav_target(string $primaryRole, array $allRoles): ?array
    {
        $root = dirname(__DIR__);
        $map = [
            'systems_admin'      => ['admin/includes/nav.php',      '/wucportal/admin/',      'admin/includes/footer.php'],
            'admission_officer'  => ['admissions/includes/nav.php', '/wucportal/admissions/', 'admissions/includes/footer.php'],
            'registrar'          => ['registrar/includes/nav.php',  '/wucportal/registrar/',  'registrar/includes/footer.php'],
            'accountant'         => ['accounts/includes/nav.php',   '/wucportal/accounts/',   'accounts/includes/footer.php'],
            'head_of_department' => ['hod/includes/nav.php',        '/wucportal/hod/',        'hod/includes/footer.php'],
            'dean'               => ['dean/includes/nav.php',       '/wucportal/dean/',       'dean/includes/footer.php'],
            'lecturer'           => ['lecturers/includes/nav.php',  '/wucportal/lecturers/',  'lecturers/includes/footer.php'],
            'transport_officer'  => ['transport/includes/nav.php',  '/wucportal/transport/',  'transport/includes/footer.php'],
            'librarian'          => ['library/includes/nav.php',    '/wucportal/library/',    'library/includes/footer.php'],
            'exams_officer'      => ['vc/includes/nav.php',         '/wucportal/vc/',         'vc/includes/footer.php'],
        ];

        $priority = [
            'systems_admin',
            'admission_officer',
            'registrar',
            'accountant',
            'head_of_department',
            'dean',
            'lecturer',
            'transport_officer',
            'librarian',
            'exams_officer',
        ];

        $candidates = array_values(array_unique(array_merge(
            [$primaryRole],
            $allRoles
        )));

        // The ACTIVE role wins: a multi-role user working as Admissions/HOS
        // must get that module's chrome, not the admin sidebar just because
        // systems_admin sits first in the priority list.
        foreach (array_values(array_unique(array_merge([$primaryRole], $priority))) as $roleKey) {
            if (!in_array($roleKey, $candidates, true) || !isset($map[$roleKey])) {
                continue;
            }
            [$navRel, $base, $footerRel] = $map[$roleKey];
            $navFile = $root . '/' . $navRel;
            if (is_file($navFile)) {
                return [
                    'nav_file' => $navFile,
                    'module_base' => $base,
                    'footer_file' => $root . '/' . $footerRel,
                ];
            }
        }

        if (is_file($root . '/lecturers/includes/nav.php')) {
            return [
                'nav_file' => $root . '/lecturers/includes/nav.php',
                'module_base' => '/wucportal/lecturers/',
                'footer_file' => $root . '/lecturers/includes/footer.php',
            ];
        }

        return null;
    }
}

$primaryRole = wuc_portal_alert_normalize_role((string)($_SESSION['role'] ?? 'staff'));
$allRoles = array_map(
    static fn($r) => wuc_portal_alert_normalize_role((string)$r),
    (array)($_SESSION['all_roles'] ?? [])
);

$target = wuc_notifications_staff_nav_target($primaryRole, $allRoles);

if ($target === null) {
    $module_config = [
        'role_label' => getRoleDisplayNames()[$primaryRole] ?? 'Staff',
        'menu_sections' => [
            [
                'title' => 'Portal',
                'items' => [
                    [
                        'href' => wuc_staff_landing_url($primaryRole),
                        'icon' => 'fas fa-tachometer-alt',
                        'label' => 'Dashboard',
                        'active_on' => 'index.php',
                    ],
                ],
            ],
        ],
        'footer_profile_href' => '/wucportal/portal_selection.php',
        'footer_logout_href' => '/wucportal/logout.php?to=staff',
    ];
    $wuc_notifications_staff_footer = dirname(__DIR__) . '/admin/includes/footer.php';
} else {
    $GLOBALS['wuc_nav_config_only'] = true;
    require $target['nav_file'];
    unset($GLOBALS['wuc_nav_config_only']);

    if (!isset($module_config) || !is_array($module_config)) {
        $module_config = [];
    }

    if (!empty($module_config['menu_sections']) && is_array($module_config['menu_sections'])) {
        $module_config['menu_sections'] = wuc_notifications_absolutize_menu_hrefs(
            $module_config['menu_sections'],
            $target['module_base']
        );
    }

    if (empty($module_config['footer_profile_href']) || !str_starts_with((string)$module_config['footer_profile_href'], '/')) {
        $module_config['footer_profile_href'] = rtrim($target['module_base'], '/') . '/profile.php';
    }

    $wuc_notifications_staff_footer = is_file($target['footer_file'])
        ? $target['footer_file']
        : dirname(__DIR__) . '/admin/includes/footer.php';
}

$page_title = $page_title ?? 'Notifications';

require dirname(__DIR__) . '/includes/nav_unified.php';
