<?php
// Unified navigation/header for module pages (Admissions, Dean, etc.)
// Expecting the including file (e.g., dean/includes/nav.php) to define $module_config

// Config-only pass: module nav.php builds $module_config then requires this file.
if (!empty($GLOBALS['wuc_nav_config_only'])) {
    return;
}

// Resolve project root and include dependencies
$rootPath = dirname(__DIR__);
require_once $rootPath . '/includes/security.php';
require_once $rootPath . '/db/connect.php';
require_once $rootPath . '/includes/audit.php';
require_once $rootPath . '/includes/staff_role_helpers.php';
require_once $rootPath . '/includes/portal_access.php';
require_once $rootPath . '/includes/portal_context.php';
require_once $rootPath . '/includes/portal_alerts.php';

wuc_apply_security_headers(true);

if (session_status() === PHP_SESSION_NONE) {
	wuc_configure_session_cookie();
	session_start();
}

// Optional: Set a session variable to track page navigation
if (!isset($_SESSION['last_page'])) {
	$_SESSION['last_page'] = [];
}
$_SESSION['last_page'][] = [
	'page' => basename($_SERVER['PHP_SELF']),
	'time' => date('Y-m-d H:i:s')
];

// Keep only last 10 pages
if (count($_SESSION['last_page']) > 10) {
	array_shift($_SESSION['last_page']);
}

// Enforce login for staff modules
if (!isset($_SESSION['user_id']) && isset($_SESSION['staff_id'])) {
	$_SESSION['user_id'] = $_SESSION['staff_id'];
}

if (!isset($_SESSION['user_id'])) {
	$_SESSION['loginSuperadmin'] = 'Please you need to login!';
	wuc_safe_redirect('/wucportal/staff_login.php');
}

$wucRequiredArea = isset($module_config['required_access']) ? (string)$module_config['required_access'] : '';
$wucRequiredPortal = $wucRequiredArea === 'elearning' ? 'elearning' : 'academic';
wuc_require_portal_access($db, $wucRequiredPortal);
wuc_set_portal_context(wuc_portal_context_from_request());

// A fresh authenticated session may only contain the staff identifier. Hydrate
// authoritative positions before any module-level role check so every unified
// module applies the same access decision.
$navStaffId = (string) ($_SESSION['user_id'] ?? '');
if ($navStaffId !== '') {
	wuc_hydrate_staff_roles($db, $navStaffId);
}

// Module-level role enforcement. A module nav opts in by setting
// $module_config['required_access'] to one of the area keys below; every page
// in that module then requires the matching role(s), so the backend rejects
// what the menus wouldn't show. Modules that don't set it keep the previous
// login-only behaviour.
if (!empty($module_config['required_access'])) {
	require_once $rootPath . '/includes/role_helpers.php';
	$wucAreaChecks = [
		'admin'      => 'canAccessAdmin',
		'admissions' => 'canAccessAdmissions',
		'finance'    => 'canAccessFinance',
		'academics'  => 'canAccessAcademics',
		'library'    => 'canAccessLibrary',
		'registrar'  => 'canAccessRegistrar',
		'transport'  => 'canAccessTransport',
		'elearning'  => 'canAccessElearning',
	];
	$wucAreaKey = (string)$module_config['required_access'];
	if ($wucAreaKey === 'hod') {
		// HOD module serves academic AND transport section heads, so it is
		// gated by role rather than by the academic-section check.
		$wucAllowed = hasAnyRole([ROLE_HEAD_OF_DEPARTMENT, ROLE_DEAN, ROLE_SYSTEMS_ADMIN]);
	} elseif ($wucAreaKey === 'elearning') {
		$wucAllowed = function_exists('canAccessElearning') ? canAccessElearning() : false;
		if (!$wucAllowed && function_exists('isSystemsAdmin') && isSystemsAdmin()) {
			$wucAllowed = true;
		}
		$navCourseStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
		if (!$wucAllowed && $navCourseStaffId !== '' && isset($db) && $db instanceof mysqli) {
			if ($stmtAssigned = $db->prepare("SELECT 1 FROM course_lecturer WHERE staff_id = ? AND COALESCE(status, 'active') <> 'inactive' LIMIT 1")) {
				$stmtAssigned->bind_param('s', $navCourseStaffId);
				$stmtAssigned->execute();
				$resAssigned = $stmtAssigned->get_result();
				$wucAllowed = $resAssigned && $resAssigned->num_rows > 0;
				$stmtAssigned->close();
			}
		}
	} else {
		$wucCheckFn = $wucAreaChecks[$wucAreaKey] ?? null;
		$wucAllowed = ($wucCheckFn === null || !function_exists($wucCheckFn)) ? true : $wucCheckFn();
	}
	if (!$wucAllowed) {
		$_SESSION['errorMessage'] = 'Access denied. Your role does not include access to this module.';
		wuc_safe_redirect('/wucportal/portal_selection.php');
	}
}

// Audit page view if helper available
if (function_exists('audit_log_page_view') && isset($db) && $db instanceof mysqli) {
	audit_log_page_view($db);
}

// Load current staff basic details for footer
$user_name = '';
$user_role_label = isset($module_config['role_label']) ? $module_config['role_label'] : 'User';

$Records1 = [];
$staffLookupId = (string)($_SESSION['user_id'] ?? '');
if ($staffLookupId !== '' && ($stmtStaff = $db->prepare("SELECT title, Fname, Lname FROM staff WHERE staff_id = ? LIMIT 1"))) {
	$stmtStaff->bind_param('s', $staffLookupId);
	$stmtStaff->execute();
	$staffResult = $stmtStaff->get_result();
	while ($row = $staffResult->fetch_object()) {
		$Records1[] = $row;
	}
	$stmtStaff->close();
}
if (!empty($Records1)) {
	$r = $Records1[0];
	$user_name = trim($r->title.' '.$r->Fname.' '.$r->Lname);
}

// Helper: compute active state
$current_basename = basename($_SERVER['PHP_SELF']);
$menu_sections = isset($module_config['menu_sections']) && is_array($module_config['menu_sections']) ? $module_config['menu_sections'] : [];
// Footer quick action overrides
$footer_profile_href = isset($module_config['footer_profile_href']) && is_string($module_config['footer_profile_href']) ? $module_config['footer_profile_href'] : 'profile.php';
$footer_logout_href = isset($module_config['footer_logout_href']) && is_string($module_config['footer_logout_href']) ? $module_config['footer_logout_href'] : '/wucportal/logout.php?to=staff';

// Assets configuration
$additional_css = isset($module_config['additional_css']) && is_array($module_config['additional_css']) ? $module_config['additional_css'] : [];
// Pages may also expose extra stylesheets via $additional_styles (set before including the header); fold them in.
if (isset($additional_styles) && is_array($additional_styles)) {
    $additional_css = array_merge($additional_css, $additional_styles);
}
$additional_scripts = isset($module_config['additional_scripts']) && is_array($module_config['additional_scripts']) ? $module_config['additional_scripts'] : [];
$navUnreadAlertCount = 0;
if (isset($db) && $db instanceof mysqli && !empty($_SESSION['user_id'])) {
	$navStaffRole = wuc_portal_alert_normalize_role((string)($_SESSION['role'] ?? 'staff'));
	if (!function_exists('wuc_portal_alerts_sync_sources')) {
		require_once $rootPath . '/includes/notification_integrations.php';
	}
	// Throttle the source-sync: mirroring source notifications into portal_alerts
	// issues several reads/writes and does not need to run on every navigation.
	// Run it at most once per 45s per session+role; the cheap, indexed unread
	// count below still runs every request so the badge stays current.
	$wucAlertSyncKey = 'wuc_alerts_last_sync_' . $navStaffRole;
	if (time() - (int)($_SESSION[$wucAlertSyncKey] ?? 0) >= 45) {
		wuc_portal_alerts_sync_sources($db, (string)$_SESSION['user_id'], $navStaffRole);
		$_SESSION[$wucAlertSyncKey] = time();
	}
	$navUnreadAlertCount = wuc_portal_alerts_unread_count($db, (string)$_SESSION['user_id'], $navStaffRole);
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

<!-- CSRF Token -->
<?php
if (empty($_SESSION['csrf_token'])) {
    try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    catch (Exception $e) { $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32)); }
}
$navUnifiedCsrf = isset($csrf_token) ? $csrf_token : $_SESSION['csrf_token'];
?>
<meta name="csrf-token" content="<?php echo htmlspecialchars($navUnifiedCsrf, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="csrf-param" content="csrf_token">
<meta name="csrf-header" content="X-CSRF-Token">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- DataTables -->
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">

<!-- SweetAlert2 -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">

<!-- Shared admin styles - unified across all modules -->
<link rel="stylesheet" href="/wucportal/css/unified-sidebar.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/wucportal/css/ui-portal.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/wucportal/css/admin-style.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/wucportal/css/project-reusable.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/wucportal/admin/css/admin-sidebar.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/wucportal/admin/css/sidebar-typography.css?v=<?php echo time(); ?>">

<!-- Consolidated Portal Design System.
     main.css already @imports forms.css and tables.css (and cards/buttons/modals),
     so those are intentionally NOT re-linked here to avoid duplicate fetches. -->
<link rel="stylesheet" href="/wucportal/assets/css/main.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/wucportal/assets/css/dashboard.css?v=<?php echo time(); ?>">
<!-- Accounts module styling for full consistency (only for accounts module) -->
<?php if (strpos($_SERVER['PHP_SELF'], '/accounts/') !== false): ?>
<link rel="stylesheet" href="/wucportal/accounts/css/accounts-sidebar.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="/wucportal/accounts/css/admin-dashboard.css?v=<?php echo time(); ?>">
<?php endif; ?><?php foreach ($additional_css as $cssPath):
	$href = strpos($cssPath, 'http') === 0 ? $cssPath : ('/wucportal/'.ltrim($cssPath, '/'));
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo time(); ?>">
<?php endforeach; ?>

<!-- Typography override to ensure compact base & sidebar sizing -->
<link rel="stylesheet" href="/wucportal/css/typography-override.css?v=<?php echo time(); ?>">

<!-- Shared utility layer: spacing/typography/alignment helpers used portal-wide. -->
<link rel="stylesheet" href="/wucportal/css/consistent-styles.css?v=<?php echo time(); ?>">

<!-- Premium theme layer: design tokens, elevation, motion, responsive hardening.
     Loaded last so it refines every stylesheet above; paired interaction layer
     (scroll reveal, count-up stats, submit guard, safe links) is deferred. -->
<link rel="stylesheet" href="/wucportal/css/wuc-premium.css?v=<?php echo time(); ?>">
<script src="/wucportal/js/wuc-premium.js?v=<?php echo time(); ?>" defer></script>
<script src="/wucportal/js/wuc-print-fit.js?v=<?php echo time(); ?>" defer></script>

<!-- Collapsible sidebar categories (progressive enhancement; shared by every module) -->
<script src="/wucportal/js/sidebar-collapsible.js?v=<?php echo time(); ?>" defer></script>

<!-- Global print layer: A4 paper, hides chrome, shows logo letterhead.
     media="print" so it has zero screen impact and overrides earlier print rules. -->
<link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css?v=<?php echo time(); ?>">

<!-- Brand variables and admissions light-mode override (applies to modules using unified nav) -->
<?php
// Portal identity is ITC navy + red, matching the unified sidebar. Modules may
// still override (e.g. Admissions uses #2E3190 blue + #F9AD59 orange) by setting
// brand_color_primary / brand_color_secondary in $module_config.
$brand_primary = isset($module_config['brand_color_primary']) ? $module_config['brand_color_primary'] : '#1B2A4A';
$brand_secondary = isset($module_config['brand_color_secondary']) ? $module_config['brand_color_secondary'] : '#0B1530';
$brand_accent = isset($module_config['brand_color_accent']) ? $module_config['brand_color_accent'] : '#C8102E';
?>
<style>
	:root {
		--logo-primary: <?php echo htmlspecialchars($brand_primary); ?>; /* dynamic primary */
		--logo-secondary: <?php echo htmlspecialchars($brand_secondary); ?>; /* dynamic secondary */
		--logo-accent: <?php echo htmlspecialchars($brand_accent); ?>; /* dynamic accent (brand red) */
		--primary-color: var(--logo-primary);
		--secondary-color: var(--logo-secondary);
		--accent-color: var(--logo-accent);
		--background-color: #ffffff;
		--card-bg: #ffffff;
		--card-border: #e2e8f0;
		--card-header-gradient: linear-gradient(135deg, var(--logo-primary) 0%, var(--logo-secondary) 100%);
		--transition-speed: 0.3s;
		--sidebar-gap: 16px;
		--sidebar-width: 280px;
	}

	/* Light theme surfaces — white cards on light bg, navy/red brand accents to match sidebar */
	.admin-card, .card { background-color: var(--card-bg); border: 1px solid var(--card-border); color: #0f1724; box-shadow: 0 6px 18px rgba(31,41,55,0.06); }
	.card .card-header { background: var(--card-header-gradient); color: #ffffff; border-bottom: 2px solid var(--accent-color); }
	.dashboard-title { color: var(--logo-primary); }

	/* Table header gradient matching brand */
	.table thead.table-dark { background: linear-gradient(90deg, var(--logo-primary) 0%, var(--logo-secondary) 100%); color: #fff; }

	/* Force light mode override for modules that should remain light-themed */
	body.has-unified-sidebar { background-color: transparent !important; color: inherit !important; border-color: transparent !important; }
	.card, .admin-card { background-color: var(--card-bg) !important; border-color: var(--card-border) !important; color: #0f1724 !important; box-shadow: 0 6px 18px rgba(31,41,55,0.06) !important; }
	.table, .table td, .table th { background-color: transparent !important; color: inherit !important; }

	@page {
		size: A4 portrait;
		margin: 12mm;
	}

	@media print {
		html,
		body {
			background: #fff !important;
			margin: 0 !important;
			padding: 0 !important;
			width: auto !important;
			min-width: 0 !important;
			overflow: visible !important;
			color: #000 !important;
			-webkit-print-color-adjust: exact;
			print-color-adjust: exact;
		}
		.sidebar,
		.sidebar-toggle,
		.sidebar-backdrop,
		.header-actions,
		.d-print-none,
		.no-print,
		button,
		.btn {
			display: none !important;
		}
		body.has-unified-sidebar,
		.main-wrapper,
		.main-content,
		.container,
		.container-fluid,
		.portal-dashboard,
		.admin-dashboard,
		.accounts-page,
		.hod-page,
		.students-page,
		.lecturer-page {
			margin: 0 !important;
			padding: 0 !important;
			width: 100% !important;
			max-width: 100% !important;
			min-width: 0 !important;
			position: static !important;
			transform: none !important;
		}
		.table-responsive,
		.table-container,
		.dataTables_wrapper {
			overflow: visible !important;
			width: 100% !important;
			max-width: 100% !important;
		}
		table {
			width: 100% !important;
			max-width: 100% !important;
			border-collapse: collapse !important;
		}
		table th,
		table td {
			white-space: normal !important;
			overflow-wrap: anywhere !important;
			vertical-align: top !important;
		}
		thead { display: table-header-group; }
		tfoot { display: table-footer-group; }
		tr { break-inside: avoid; page-break-inside: avoid; }
	}
</style>

<!-- jQuery and JS libs -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<!-- CSRF auto-attach: adds the X-CSRF-Token header to same-origin mutating
     AJAX requests (jQuery + fetch + XHR) so server-side CSRF checks pass without
     each caller having to wire the token in manually. Header-only, so request
     bodies (FormData/JSON) are never modified. -->
<script>
(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute('content') : '';
    var headerName = (document.querySelector('meta[name="csrf-header"]') || {}).getAttribute
        ? document.querySelector('meta[name="csrf-header"]').getAttribute('content') : 'X-CSRF-Token';
    if (!token) { return; }

    function isSameOrigin(url) {
        try {
            var u = new URL(url, window.location.href);
            return u.origin === window.location.origin;
        } catch (e) { return true; } // relative URLs are same-origin
    }
    function isMutating(method) {
        method = (method || 'GET').toUpperCase();
        return method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE';
    }

    // jQuery
    if (window.jQuery) {
        jQuery.ajaxSetup({
            beforeSend: function (xhr, settings) {
                if (isMutating(settings.type) && isSameOrigin(settings.url || '')) {
                    xhr.setRequestHeader(headerName, token);
                }
            }
        });
    }

    // fetch
    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            init = init || {};
            var url = (typeof input === 'string') ? input : (input && input.url) || '';
            var method = init.method || (typeof input === 'object' && input.method) || 'GET';
            if (isMutating(method) && isSameOrigin(url)) {
                var headers = new Headers(init.headers || (typeof input === 'object' ? input.headers : undefined) || {});
                if (!headers.has(headerName)) { headers.set(headerName, token); }
                init.headers = headers;
            }
            return origFetch(input, init);
        };
    }

    // Raw XMLHttpRequest
    if (window.XMLHttpRequest) {
        var origOpen = XMLHttpRequest.prototype.open;
        var origSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) {
            this.__csrfApply = isMutating(method) && isSameOrigin(url || '');
            return origOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            if (this.__csrfApply) {
                try { this.setRequestHeader(headerName, token); } catch (e) {}
            }
            return origSend.apply(this, arguments);
        };
    }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php foreach ($additional_scripts as $jsPath):
	$src = strpos($jsPath, 'http') === 0 ? $jsPath : ('/wucportal/'.ltrim($jsPath, '/'));
?>
<script src="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php endforeach; ?>
</head>

<body class="has-unified-sidebar">
<!-- Mobile Toggle Button -->
<button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="Open navigation menu" aria-controls="sidebar" aria-expanded="false">
	<i class="fas fa-bars" aria-hidden="true"></i>
</button>
<div class="sidebar-backdrop" data-unified-sidebar-backdrop></div>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
	<div class="sidebar-header">
		<div class="logo-container">
			<img src="/wucportal/images/favicon.png" alt="ITC Logo" class="logo">
			<span class="logo-text">ITC</span>
		</div>
	</div>
	<div class="sidebar-content">
		<div class="nav-section">
			<div class="nav-section-title">Updates</div>
			<?php $navNotificationsHref = '/wucportal/notifications.php' . ($wucRequiredArea === 'elearning' ? '?portal=elearning' : ''); ?>
			<a href="<?php echo htmlspecialchars($navNotificationsHref, ENT_QUOTES, 'UTF-8'); ?>" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'notifications.php' ? 'active' : ''; ?>">
				<i class="fas fa-bell"></i><span>Notifications</span>
				<?php if ($navUnreadAlertCount > 0): ?>
					<span class="badge bg-danger ms-auto"><?php echo htmlspecialchars((string)$navUnreadAlertCount); ?></span>
				<?php endif; ?>
			</a>
		</div>
		<?php
		// Dynamically filter menu sections based on user permissions
		if (isset($menu_sections) && is_array($menu_sections) && isset($_SESSION['user_id_db'])) {
			$userIdDb = $_SESSION['user_id_db'];
			require_once __DIR__ . '/auth.php';

			$getModuleKeyForMenuItem = function($href, $label, $sectionTitle) {
				$href = strtolower($href);
				$label = strtolower($label);
				$sectionTitle = strtolower($sectionTitle);

				if (strpos($href, 'users_roles.php') !== false) return 'users_roles';
				if (strpos($href, 'settings.php') !== false) return 'settings';

				if (strpos($href, '/transport/') !== false || strpos($label, 'transport') !== false || strpos($sectionTitle, 'transport') !== false) {
					return 'transport';
				}
				if (strpos($href, '/library/') !== false || strpos($label, 'library') !== false || strpos($sectionTitle, 'library') !== false) {
					return 'library';
				}
				if (strpos($href, '/elearning/') !== false || strpos($label, 'elearning') !== false || strpos($label, 'e-learning') !== false || strpos($sectionTitle, 'e-learning') !== false) {
					return 'elearning';
				}
				if (strpos($href, 'upload_ca.php') !== false || strpos($label, 'upload ca') !== false) {
					return 'ca_upload';
				}
				if (strpos($href, '/hod/') !== false && (strpos($href, 'index.php') !== false || strpos($href, 'approve') !== false || strpos($label, 'ca approval') !== false)) {
					return 'ca_approval';
				}
				if (strpos($href, '/accounts/') !== false || strpos($label, 'fees') !== false || strpos($label, 'payment') !== false || strpos($label, 'invoice') !== false) {
					return 'fees';
				}
				if (strpos($href, 'reports.php') !== false || strpos($href, 'report_') !== false || strpos($label, 'report') !== false || strpos($sectionTitle, 'report') !== false) {
					return 'reports';
				}
				if (strpos($href, '/admissions/') !== false && (strpos($href, 'applicants') !== false || strpos($href, 'processedapp') !== false || strpos($href, 'index.php') !== false)) {
					return 'admissions';
				}
				if (strpos($href, 'applicants.php') !== false || strpos($href, 'processedapp.php') !== false || strpos($label, 'applicant') !== false) {
					return 'admissions';
				}
				if (strpos($href, 'regnewstud.php') !== false || strpos($href, 'regoldstud.php') !== false || strpos($href, 'register_student.php') !== false || strpos($label, 'registration') !== false || strpos($label, 'register') !== false) {
					return 'student_registration';
				}
				if (strpos($href, 'students_by_admin.php') !== false || strpos($href, 'students.php') !== false || strpos($href, 'search_student.php') !== false || strpos($label, 'student') !== false) {
					return 'student_records';
				}
				if (strpos($href, 'assign_course.php') !== false || strpos($label, 'lecturer assignment') !== false || strpos($label, 'faculty assignment') !== false) {
					return 'lecturer_assignment';
				}
				if (strpos($href, 'programs.php') !== false || strpos($label, 'program') !== false) {
					return 'programmes';
				}
				if (strpos($href, 'courses.php') !== false || strpos($href, 'course_catalogue.php') !== false || strpos($label, 'course') !== false) {
					return 'courses';
				}
				if (strpos($href, 'departments.php') !== false || strpos($label, 'department') !== false) {
					return 'departments';
				}

				return 'dashboard';
			};

			$filteredSections = [];
			foreach ($menu_sections as $section) {
				$filteredItems = [];
				$sectionTitle = $section['title'] ?? '';

				if (!empty($section['items']) && is_array($section['items'])) {
					foreach ($section['items'] as $item) {
						$href = $item['href'] ?? '';
						$label = $item['label'] ?? '';
						
						if (strtolower($label) === 'dashboard' || strpos(strtolower($href), 'index.php') !== false && strpos(strtolower($href), '/staff/') !== false) {
							$filteredItems[] = $item;
							continue;
						}

						$explicitModule = strtolower(trim((string)($item['module'] ?? '')));
						$modKey = $explicitModule !== '' ? $explicitModule : $getModuleKeyForMenuItem($href, $label, $sectionTitle);
						
                // Lecturer portal items are role-inherent; do not hide them when RBAC
                // rows were not yet provisioned for a new lecturer account.
                $navPath = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
                $inLecturerPortal = strpos($navPath, '/lecturers/') !== false;
                $lecturerInherentModules = ['dashboard', 'courses', 'ca_upload', 'reports', 'elearning'];
                if ($inLecturerPortal
                    && (hasRole(ROLE_LECTURER) || hasRole(ROLE_HEAD_OF_DEPARTMENT) || hasRole(ROLE_DEAN))
                    && in_array($modKey, $lecturerInherentModules, true)
                ) {
                    $filteredItems[] = $item;
                    continue;
                }

                if (canAccessModule($userIdDb, $modKey)) {
							$filteredItems[] = $item;
						}
					}
				}

				if (!empty($filteredItems)) {
					$section['items'] = $filteredItems;
					$filteredSections[] = $section;
				}
			}
			$menu_sections = $filteredSections;
		}
		?>
		<?php foreach ($menu_sections as $section): ?>
		<div class="nav-section">
			<?php if (!empty($section['title'])): ?><div class="nav-section-title"><?php echo htmlspecialchars($section['title']); ?></div><?php endif; ?>
			<?php if (!empty($section['items']) && is_array($section['items'])): ?>
				<?php foreach ($section['items'] as $item):
					$href = isset($item['href']) ? $item['href'] : '#';
					$icon = isset($item['icon']) ? $item['icon'] : 'fas fa-circle';
					$label = isset($item['label']) ? $item['label'] : '';
					$active_on = isset($item['active_on']) ? $item['active_on'] : basename($href);
					$active_query = isset($item['active_query']) && is_array($item['active_query']) ? $item['active_query'] : null;
					
					// Improved Active State Logic to handle paths (e.g. elearning/index.php)
					$current_path = $_SERVER['PHP_SELF'];
					$current_basename = basename($current_path);
					$is_active = false;
					
					$check_match = function($target) use ($current_basename, $current_path) {
						if (strpos($target, '/') !== false) {
							// Target contains path (e.g. 'elearning/index.php') - check frame suffix
							// Normalize slashes just in case
							$d_target = str_replace('\\', '/', $target);
							$d_current = str_replace('\\', '/', $current_path);
							return substr($d_current, -strlen($d_target)) === $d_target;
						} else {
							// Simple filename match
							return $current_basename === $target;
						}
					};

					if (is_array($active_on)) {
						foreach ($active_on as $target) {
							if ($check_match($target)) { $is_active = true; break; }
						}
					} else {
						$is_active = $check_match($active_on);
					}

					if ($active_query !== null) {
						foreach ($active_query as $queryKey => $expectedValue) {
							$currentValue = (string)($_GET[(string)$queryKey] ?? '');
							if ($currentValue !== (string)$expectedValue) {
								$is_active = false;
								break;
							}
						}
					}
				?>
				<a href="<?php echo htmlspecialchars($href); ?>" class="nav-item <?php echo $is_active ? 'active' : ''; ?>">
					<i class="<?php echo htmlspecialchars($icon); ?>"></i><span><?php echo htmlspecialchars($label); ?></span>
				</a>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	</div>

	<div class="sidebar-footer">
		<div class="user-info">
			<div class="user-avatar"><?php echo htmlspecialchars(substr($user_name, 0, 1)); ?></div>
			<div class="user-details">
				<div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
				<div class="user-role"><?php echo htmlspecialchars($user_role_label); ?></div>
			</div>
		</div>
		<div class="quick-actions">
			<a href="<?php echo htmlspecialchars($footer_profile_href); ?>" class="quick-action-btn">
				<i class="fas fa-user"></i>
				Profile
			</a>
			<?php
			$showWorkspaceSwitch = false;
			$allR = isset($_SESSION['all_roles']) && is_array($_SESSION['all_roles']) ? $_SESSION['all_roles'] : [];
			if (count($allR) > 1) {
				$currentRole = (string)($_SESSION['role'] ?? '');
				if (function_exists('wuc_normalize_staff_role')) {
					$currentRole = wuc_normalize_staff_role($currentRole, false);
				} else {
					$currentRole = strtolower(trim($currentRole));
				}
				$hasSysAdmin = in_array('systems_admin', $allR, true);
				// Hide switch only for *pure* HOS accounts. ITC900 (and other admins)
				// keep the switcher even when temporarily using the HOD workspace, so
				// they can switch contexts directly without leaving the portal.
				if ($currentRole !== 'head_of_department' || $hasSysAdmin) {
					$showWorkspaceSwitch = true;
				}
			}
			?>
			<?php if ($showWorkspaceSwitch): ?>
			<a href="/wucportal/role_selection.php" class="quick-action-btn" title="Switch Workspace">
				<i class="fas fa-exchange-alt"></i>
				Switch
			</a>
			<?php endif; ?>
            <!-- Secure Logout Form -->
            <?php 
            if (empty($_SESSION['csrf_token'])) {
                try {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (Exception $e) {
                    $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
                }
            }
            ?>
            <form method="POST" action="/wucportal/logout.php" class="quick-action-inline-form">
                <input type="hidden" name="target" value="staff">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <button type="submit" class="quick-action-btn quick-action-submit-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </button>
            </form>
		</div>
	</div>
</nav>

<script>
// Single sidebar controller for unified admin/module layouts.
window.WUC_UNIFIED_SIDEBAR_CONTROLLER = true;
document.addEventListener('DOMContentLoaded', function() {
	var sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
	var toggle = document.getElementById('sidebarToggle') || document.querySelector('.sidebar-toggle');
	var backdrop = document.querySelector('.sidebar-backdrop');
	var mobileQuery = window.matchMedia('(max-width: 991.98px)');

	function setSidebarOpen(open) {
		if (!sidebar) { return; }
		sidebar.classList.toggle('show', open);
		sidebar.classList.toggle('active', open);
		document.body.classList.toggle('sidebar-open', open);
		document.body.style.overflow = open && mobileQuery.matches ? 'hidden' : '';
		if (toggle) {
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			toggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
		}
		if (backdrop) {
			backdrop.classList.toggle('show', open);
		}
	}

	function isSidebarOpen() {
		return !!(sidebar && (sidebar.classList.contains('show') || sidebar.classList.contains('active')));
	}

	if (toggle && sidebar && toggle.dataset.sidebarControllerBound !== 'true') {
		toggle.dataset.sidebarControllerBound = 'true';
		toggle.addEventListener('click', function(event) {
			event.preventDefault();
			event.stopPropagation();
			setSidebarOpen(!isSidebarOpen());
		});
	}

	if (backdrop && backdrop.dataset.sidebarControllerBound !== 'true') {
		backdrop.dataset.sidebarControllerBound = 'true';
		backdrop.addEventListener('click', function() {
			setSidebarOpen(false);
		});
	}

	document.addEventListener('click', function(event) {
		if (!mobileQuery.matches || !isSidebarOpen()) { return; }
		var modal = document.querySelector('.modal.show');
		if (modal) { return; }
		if (sidebar.contains(event.target) || (toggle && toggle.contains(event.target))) { return; }
		setSidebarOpen(false);
	});

	document.addEventListener('keydown', function(event) {
		if (event.key === 'Escape' && isSidebarOpen()) {
			setSidebarOpen(false);
		}
	});

	sidebar && sidebar.querySelectorAll('a.nav-item').forEach(function(link) {
		link.addEventListener('click', function() {
			if (mobileQuery.matches) {
				setSidebarOpen(false);
			}
		});
	});

	mobileQuery.addEventListener('change', function(event) {
		if (!event.matches) {
			setSidebarOpen(false);
		}
	});

	if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
		document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(element) {
			new bootstrap.Tooltip(element);
		});
	}
});
</script>

<?php
// Floating conversational AI assistant for all staff modules.
require_once $rootPath . '/includes/ai_chat_widget.php';

$staffName = trim((string)($_SESSION['user_name'] ?? ($_SESSION['name'] ?? '')));
$userRole = trim((string)($_SESSION['role'] ?? ''));

// getRoleDisplayName() lives in role_helpers.php, which is only loaded above when
// the module sets $module_config['required_access']. Permission-gated modules
// (e.g. Library) leave that unset, so load it here to keep the widget title safe.
require_once $rootPath . '/includes/role_helpers.php';

$title = 'ITC Staff Assistant';
if ($userRole !== '') {
	$displayRole = getRoleDisplayName($userRole);
	$title = "ITC {$displayRole} Assistant";
}

$greeting = 'Hello. You may ask me about admissions, registration, grading, fees, reports, or how to use any part of the portal.';
if ($staffName !== '') {
	$greeting = "Hello, {$staffName}. You may ask me about admissions, registration, grading, fees, reports, or how to use any part of the portal.";
}

wuc_render_ai_chat_widget([
	'endpoint' => '/wucportal/includes/ai_staff_chat.php',
	'title'    => $title,
	'greeting' => $greeting,
	'scope'    => (string)($module_config['required_access'] ?? ''),
]);
?>

<!-- Main Content Wrapper - opened here, closed by footer include -->
<div class="main-wrapper">
	<div class="main-content">
<?php
// Shared flash/alert surface: guarantees session messages (including those set
// before a redirect) are shown consistently across every staff module.
require $rootPath . '/includes/flash_alerts.php';

// Rule-generated portal alerts (the "Portal Alerts" card) are surfaced only on
// notifications.php, which lists every alert. The bell nav item above already
// carries the unread-count badge ($navUnreadAlertCount) for other pages.
?>
<!-- Page content continues in including files; body and html closed by footer include -->
