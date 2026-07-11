<?php
/** Shared compatibility chrome injected into legacy staff-module documents. */

require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/staff_profile_helpers.php';
require_once __DIR__ . '/staff_role_helpers.php';

if (!function_exists('wuc_legacy_chrome_h')) {
    function wuc_legacy_chrome_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$legacyChrome = isset($legacy_chrome) && is_array($legacy_chrome) ? $legacy_chrome : [];
$legacyModule = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($legacyChrome['module'] ?? 'staff')));
$legacyLabel = (string) ($legacyChrome['label'] ?? 'Staff Portal');
$legacyRoleLabel = (string) ($legacyChrome['role_label'] ?? 'Staff');
$legacyRequiredRoles = array_values((array) ($legacyChrome['required_roles'] ?? []));
$legacyMenu = array_values((array) ($legacyChrome['menu'] ?? []));

wuc_enforce_session_guard([
    'context' => $legacyModule . '-legacy',
    'session_keys' => ['staff_id', 'user_id'],
    'activity_keys' => ['last_activity', 'last_active_time'],
    'timeout' => 1800,
    'post_grace' => 30,
    'login_path' => '/wucportal/staff_login.php',
    'flash_key' => 'errorMessage',
    'timeout_message' => 'Your session has expired. Please log in again.',
    'login_message' => 'Please log in to continue.',
]);

$legacyStaffId = (string) ($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
if ($legacyRequiredRoles) {
    $resolvedRoles = wuc_resolve_staff_roles($db, $legacyStaffId);
    $assignedRoles = (array) ($resolvedRoles['all_roles'] ?? []);
    $requiredCanonical = array_map(
        static fn($role) => wuc_normalize_staff_role((string) $role, false),
        $legacyRequiredRoles
    );
    if (!array_intersect($assignedRoles, $requiredCanonical)
        && !in_array('systems_admin', $assignedRoles, true)) {
        $_SESSION['errorMessage'] = 'Access denied. Your role does not include access to this module.';
        wuc_safe_redirect('/wucportal/portal_selection.php');
    }
}

$legacyProfile = wuc_get_staff_profile($db, $legacyStaffId);
$legacyName = $legacyProfile
    ? trim((string) $legacyProfile->title . ' ' . (string) $legacyProfile->Fname . ' ' . (string) $legacyProfile->Lname)
    : $legacyStaffId;
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$moduleEsc = wuc_legacy_chrome_h($legacyModule);
require_once __DIR__ . '/page_meta.php';
$legacyPageTitle = isset($page_title) ? (string) $page_title : '';
$headAssets = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="/wucportal/{$moduleEsc}/w3/w3.css">
<link rel="stylesheet" href="/wucportal/assets/css/main.css">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
<link rel="stylesheet" href="/wucportal/css/project-reusable.css">
<link rel="stylesheet" href="/wucportal/css/wuc-premium.css">
HTML;

ob_start();
?>
<nav class="navbar navbar-expand-lg legacy-portal-nav sticky-top" aria-label="<?= wuc_legacy_chrome_h($legacyLabel) ?> navigation">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <img src="/wucportal/images/itc_logo.png" alt="ITC" width="38" height="38">
            <strong><?= wuc_legacy_chrome_h($legacyLabel) ?></strong>
        </a>
        <button class="navbar-toggler bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#legacyModuleNav" aria-controls="legacyModuleNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="legacyModuleNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php foreach ($legacyMenu as $item):
                    $href = (string) ($item['href'] ?? '#');
                    $label = (string) ($item['label'] ?? 'Link');
                    $icon = preg_replace('/[^a-z0-9 -]/i', '', (string) ($item['icon'] ?? 'fas fa-circle'));
                ?>
                    <li class="nav-item"><a class="nav-link" href="<?= wuc_legacy_chrome_h($href) ?>"><i class="<?= wuc_legacy_chrome_h($icon) ?> me-1"></i><?= wuc_legacy_chrome_h($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="d-lg-flex align-items-center gap-3">
                <div class="legacy-user mb-2 mb-lg-0"><strong><?= wuc_legacy_chrome_h($legacyName) ?></strong><?= wuc_legacy_chrome_h($legacyRoleLabel) ?></div>
                <form method="post" action="/wucportal/logout.php" class="m-0">
                    <input type="hidden" name="target" value="staff">
                    <input type="hidden" name="csrf_token" value="<?= wuc_legacy_chrome_h($_SESSION['csrf_token']) ?>">
                    <button class="btn btn-sm legacy-logout" type="submit"><i class="fas fa-sign-out-alt me-1"></i>Logout</button>
                </form>
            </div>
        </div>
    </div>
</nav>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
<?php
$legacyNavHtml = ob_get_clean();

// Legacy pages own their document shell. Inject assets into <head> and chrome
// immediately after <body>, avoiding the nested HTML documents they previously
// produced when both the include and page emitted a shell.
ob_start(static function (string $html) use ($headAssets, $legacyNavHtml, $legacyPageTitle): string {
    $html = wuc_portal_inject_head_meta($html, $legacyPageTitle);

    if (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $headAssets . "\n</head>", $html, 1) ?? $html;
    } else {
        $html = $headAssets . "\n" . $html;
    }

    if (preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
        $bodyTag = $match[0][0];
        $offset = $match[0][1] + strlen($bodyTag);
        return substr($html, 0, $offset) . "\n" . $legacyNavHtml . substr($html, $offset);
    }
    return $legacyNavHtml . $html;
});
