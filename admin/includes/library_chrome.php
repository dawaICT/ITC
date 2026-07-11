<?php
/**
 * Library page chrome switch.
 *
 * The four /admin/library_*.php pages are shared between two modules:
 *   - Admins reach them from the Admin sidebar  -> render with Admin chrome
 *   - Librarians reach them from /library/      -> render with Library chrome
 *
 * The library sidebar adds ?from=library to its links so this file knows
 * which chrome to bootstrap. Call this BEFORE any output and BEFORE
 * the page's permission check.
 *
 *   $page_title = 'Library Catalog';
 *   require_once __DIR__ . '/includes/library_chrome.php';
 *   wuc_library_chrome_boot();
 *   enforcePermission($_SESSION['staff_id'], 'library_catalog');
 *
 * After this call:
 *   - $db is available (mysqli)
 *   - Session is started and user is authenticated
 *   - Sidebar + portal chrome have already been emitted
 *   - $wuc_library_back_href points at the right "Back" target for the active module
 *
 * The page closes with the existing admin/includes/footer.php either way —
 * both navs use the same nav_unified.php wrapper divs.
 */

function wuc_library_chrome_boot(): void
{
    $fromLibrary = isset($_GET['from']) && $_GET['from'] === 'library';

    if ($fromLibrary) {
        // ---- Library-module chrome ----
        require_once __DIR__ . '/../../includes/session_guard.php';
        require_once __DIR__ . '/../../db/connect.php';
        require_once __DIR__ . '/../../includes/permissions.php';

        wuc_enforce_session_guard([
            'context' => 'library',
            'session_keys' => ['staff_id', 'user_id'],
            'activity_keys' => ['last_activity', 'last_active_time'],
            'timeout' => 1800,
            'post_grace' => 30,
            'login_path' => '/wucportal/staff_login.php',
            'flash_key' => 'errorMessage',
            'timeout_message' => 'Your session has expired. Please log in again.',
            'login_message' => 'Please log in to access the Library.',
        ]);

        $GLOBALS['wuc_library_back_href']    = '/wucportal/library/';
        $GLOBALS['wuc_library_active_module'] = 'library';

        require __DIR__ . '/../../library/includes/nav.php';
    } else {
        // ---- Admin-module chrome (legacy default) ----
        require_once __DIR__ . '/header.php';

        $GLOBALS['wuc_library_back_href']    = 'library.php';
        $GLOBALS['wuc_library_active_module'] = 'admin';
    }

    // Permissions helper is required by every caller for enforcePermission().
    require_once __DIR__ . '/../../includes/permissions.php';
}

/**
 * Convenience: append ?from=library to a relative library_*.php URL when the
 * caller is currently being rendered inside the library module. Used for the
 * sibling-nav buttons at the top of /admin/library.php so admins keep admin
 * chrome and librarians keep library chrome as they hop between pages.
 */
function wuc_library_link(string $href): string
{
    $active = $GLOBALS['wuc_library_active_module'] ?? 'admin';
    if ($active !== 'library') {
        return $href;
    }
    return $href . (str_contains($href, '?') ? '&' : '?') . 'from=library';
}
