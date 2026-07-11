<?php

// ---------------------------------------------------------------------------
// Per-request permission cache (performance).
//
// The RBAC helpers below (getUserPermissions/hasPermission/getUserRoles/
// wuc_has_permission_unified) are invoked many times while rendering a single
// page — the sidebar builds its menu from them, every module guard calls them,
// dashboards call them per widget. Each raw call issues several SQL statements
// (and getUserPermissions additionally ran SHOW TABLES/SHOW COLUMNS probes).
// Because a user's roles/permissions do not change within one request, the
// results are memoized here keyed by (user, portal, permission). This turns a
// per-page burst of dozens of identical permission queries into one lookup
// each. The store is exposed by reference so a mutation endpoint that changes
// a user's roles mid-request can call wuc_permissions_flush_cache() to be safe.
if (!function_exists('wuc_permission_cache_store')) {
	function &wuc_permission_cache_store(): array {
		static $store = [
			'perms'   => [], // getUserPermissions()      keyed by "user|portal"
			'roles'   => [], // getUserRoles()            keyed by "user|portal"
			'ident'   => [], // users identity lookup     keyed by raw $user
			'admin'   => [], // systems_admin check       keyed by "userIdDb|portal"
			'has'     => [], // hasPermission() result    keyed by "user|a2|a3|portal"
			'unified' => [], // wuc_has_permission_unified keyed by "userIdDb|module|perm|portal"
		];
		return $store;
	}
}
if (!function_exists('wuc_permissions_flush_cache')) {
	function wuc_permissions_flush_cache(): void {
		$store = &wuc_permission_cache_store();
		foreach (array_keys($store) as $bucket) {
			$store[$bucket] = [];
		}
	}
}

// Resolve the active portal_id for the current request, if any.
// Portal-aware permissions: a role_permissions row with portal_id IS NULL is
// global (applies in every portal); a row with a concrete portal_id only
// applies when the user is operating inside that portal. The portal is taken
// from $_SESSION['current_portal'] (set by portal_access.php on portal entry)
// unless a caller passes an explicit code. Returns null when no portal context
// is available, which keeps every check unscoped (legacy behavior).
if (!function_exists('wuc_permissions_current_portal_id')) {
	function wuc_permissions_current_portal_id(mysqli $db, ?string $portalCode = null): ?int {
		static $cache = [];

		$code = strtolower(trim((string)($portalCode
			?? ($_SESSION['current_portal'] ?? ''))));
		if ($code === '') {
			return null;
		}
		if (array_key_exists($code, $cache)) {
			return $cache[$code];
		}

		// wuc_portal_id() lives in portal_access.php; load lazily to avoid a
		// hard include dependency and any include-order surprises.
		if (!function_exists('wuc_portal_id')) {
			$portalAccess = __DIR__ . '/portal_access.php';
			if (is_file($portalAccess)) {
				require_once $portalAccess;
			}
		}
		if (!function_exists('wuc_portal_id')) {
			return $cache[$code] = null;
		}

		return $cache[$code] = wuc_portal_id($db, $code);
	}
}

// Function to check if a user has a specific permission
if (!function_exists('wuc_has_permission_unified')) {
	function wuc_has_permission_unified(mysqli $db, int $userIdDb, string $moduleKey, string $permissionKey, ?string $portalCode = null): bool {
		$fullKey = $moduleKey . '.' . $permissionKey;
		$manageKey = $moduleKey . '.manage';

		// Portal scoping: when a portal context exists, restrict role_permissions
		// to rows that are either global (portal_id IS NULL) or bound to that
		// portal. With today's all-NULL data this is a no-op; it activates as
		// soon as portal_id values are populated.
		$portalId = wuc_permissions_current_portal_id($db, $portalCode);

		// Per-request memoization (see wuc_permission_cache_store()).
		$store = &wuc_permission_cache_store();
		$ck = $userIdDb . '|' . $moduleKey . '|' . $permissionKey . '|' . ($portalId ?? 'null');
		if (array_key_exists($ck, $store['unified'])) {
			return $store['unified'][$ck];
		}
		$remember = static function (bool $v) use (&$store, $ck): bool {
			return $store['unified'][$ck] = $v;
		};

		$portalClause = $portalId !== null ? ' AND (ur.portal_id IS NULL OR ur.portal_id = ?) AND (rp.portal_id IS NULL OR rp.portal_id = ?)' : '';

		// 1. Check user_roles -> role_permissions -> permissions
		$sql = "SELECT 1 FROM user_roles ur
				JOIN role_permissions rp ON rp.role_id = ur.role_id
				JOIN permissions p ON p.permission_id = rp.permission_id
				JOIN modules m ON m.module_id = rp.module_id
				WHERE ur.user_id = ?
				  AND m.module_key = ?
				  AND (p.permission_key = ? OR p.permission_key = ?)
				  AND ur.status = 'active'
				  AND rp.status = 'active'
				  AND p.status = 'active'
				  AND m.status = 'active'" . $portalClause;

		if ($stmt = $db->prepare($sql)) {
			if ($portalId !== null) {
				$stmt->bind_param('isssii', $userIdDb, $moduleKey, $fullKey, $manageKey, $portalId, $portalId);
			} else {
				$stmt->bind_param('isss', $userIdDb, $moduleKey, $fullKey, $manageKey);
			}
			$stmt->execute();
			$allowed = $stmt->get_result()->num_rows > 0;
			$stmt->close();
			if ($allowed) {
				return $remember(true);
			}
		}

		// 2. Check user_module_access (direct permissions)
		$today = date('Y-m-d');
		$sqlDirect = "SELECT 1 FROM user_module_access uma
					  JOIN permissions p ON p.permission_id = uma.permission_id
					  JOIN modules m ON m.module_id = uma.module_id
					  WHERE uma.user_id = ? 
						AND m.module_key = ?
						AND (p.permission_key = ? OR p.permission_key = ?)
						AND uma.status = 'active'
						AND p.status = 'active'
						AND m.status = 'active'
						AND (uma.start_date IS NULL OR uma.start_date <= ?)
						AND (uma.end_date IS NULL OR uma.end_date >= ?)";
						
		if ($stmt = $db->prepare($sqlDirect)) {
			$stmt->bind_param('isssss', $userIdDb, $moduleKey, $fullKey, $manageKey, $today, $today);
			$stmt->execute();
			$allowed = $stmt->get_result()->num_rows > 0;
			$stmt->close();
			if ($allowed) {
				return $remember(true);
			}
		}

		return $remember(false);
	}
}

if (!function_exists('_wuc_has_permission_impl')) {
	// Uncached implementation. hasPermission() wraps this with a per-request
	// result cache (see wuc_permission_cache_store()) so repeated identical
	// checks during one page render hit memory instead of the database.
	function _wuc_has_permission_impl($user, $arg2, $arg3 = null): bool {
		global $db;
		if (!$db instanceof mysqli || $user === null || $user === '') {
			return false;
		}
		
		$userIdDb = null;
		$staffId = null;
		
		// Resolve user identity from session first to optimize
		if (isset($_SESSION['user_id_db']) && (string)$_SESSION['user_id_db'] === (string)$user) {
			$userIdDb = (int)$_SESSION['user_id_db'];
			$staffId = $_SESSION['staff_id'] ?? null;
		} elseif (isset($_SESSION['staff_id']) && $_SESSION['staff_id'] === $user) {
			$userIdDb = isset($_SESSION['user_id_db']) ? (int)$_SESSION['user_id_db'] : null;
			$staffId = $_SESSION['staff_id'];
		}
		
		// Database lookup if unresolved (the users row is cached per raw
		// identifier so repeated checks for the same user skip this query).
		if ($userIdDb === null || $staffId === null) {
			$store = &wuc_permission_cache_store();
			$identKey = (string)$user;
			if (!array_key_exists($identKey, $store['ident'])) {
				$foundRow = null;
				$stmt = $db->prepare("SELECT user_id, staff_id FROM users WHERE user_id = ? OR staff_id = ? OR username = ? LIMIT 1");
				if ($stmt) {
					$userStr = (string)$user;
					$userInt = (int)$user;
					$stmt->bind_param("iss", $userInt, $userStr, $userStr);
					$stmt->execute();
					$foundRow = $stmt->get_result()->fetch_assoc() ?: null;
					$stmt->close();
				}
				$store['ident'][$identKey] = $foundRow;
			}
			if ($store['ident'][$identKey]) {
				$userIdDb = (int)$store['ident'][$identKey]['user_id'];
				$staffId = $store['ident'][$identKey]['staff_id'];
			}
		}
		
		if ($userIdDb === null) {
			return false;
		}
		
		// Admin override (systems_admin role) — memoized per user+portal so a
		// page running many permission checks for one user issues this at most
		// once instead of before every single check.
		$store = &wuc_permission_cache_store();
		$portalId = wuc_permissions_current_portal_id($db);
		$adminKey = $userIdDb . '|' . ($portalId ?? 'null');
		if (!array_key_exists($adminKey, $store['admin'])) {
			$isAdmin = false;
			$portalClause = $portalId !== null ? ' AND (ur.portal_id IS NULL OR ur.portal_id = ?)' : '';
			$stmt = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id WHERE ur.user_id = ? AND r.role_name = 'systems_admin' AND ur.status = 'active' AND r.status = 'active'" . $portalClause);
			if ($stmt) {
				if ($portalId !== null) {
					$stmt->bind_param("ii", $userIdDb, $portalId);
				} else {
					$stmt->bind_param("i", $userIdDb);
				}
				$stmt->execute();
				$isAdmin = $stmt->get_result()->num_rows > 0;
				$stmt->close();
			}
			$store['admin'][$adminKey] = $isAdmin;
		}
		if ($store['admin'][$adminKey]) {
			return true;
		}
		
		// 3-parameter call: hasPermission($userIdDb, $moduleKey, $permissionKey)
		if ($arg3 !== null) {
			$moduleKey = (string)$arg2;
			$permissionKey = (string)$arg3;
			if (wuc_has_permission_unified($db, $userIdDb, $moduleKey, $permissionKey)) {
				return true;
			}
		} else {
			// 2-parameter call: hasPermission($staffId, $permissionName)
			$permissionName = (string)$arg2;
			
			$map = [
				'elearn_manage_course' => ['elearning', 'manage'],
				'elearn_schedule_sessions' => ['elearning', 'manage'],
				'elearn_assessments' => ['elearning', 'manage'],
				'elearn_grade' => ['elearning', 'manage'],
				'elearn_forum_moderate' => ['elearning', 'manage'],
				// elearn_admin_all is resolved via legacy role_permissions / explicit grant — not elearning.manage
				'elearn_reporting' => ['reports', 'view'],
				'library_manage' => ['library', 'manage'],
				'library_catalog' => ['library', 'manage'],
				'library_circulation' => ['library', 'manage'],
				'library_fines' => ['library', 'manage'],
				'library_digital' => ['library', 'manage'],
				'view_courses' => ['courses', 'view'],
				'edit_courses' => ['courses', 'edit'],
				'manage_grades' => ['ca_upload', 'upload'],
				'view_students' => ['student_records', 'view'],
				'upload_materials' => ['elearning', 'upload'],
				'manage_assignments' => ['elearning', 'manage'],
				'view_profile' => ['dashboard', 'view'],
				'view_schedule' => ['dashboard', 'view'],
				'transport_view' => ['transport', 'view'],
				'transport_manage' => ['transport', 'manage'],
				'exam_view' => ['ca_upload', 'view'],
				'exam_enter_marks' => ['ca_upload', 'upload'],
				'exam_upload_results' => ['ca_upload', 'upload'],
				'exam_manage' => ['ca_approval', 'approve'],
				'city_guilds_manage' => ['student_records', 'manage']
			];
			
			$moduleKey = null;
			$permissionKey = null;
			if (isset($map[$permissionName])) {
				$moduleKey = $map[$permissionName][0];
				$permissionKey = $map[$permissionName][1];
			} elseif (strpos($permissionName, '.') !== false) {
				list($moduleKey, $permissionKey) = explode('.', $permissionName, 2);
			}
			
			if ($moduleKey !== null && $permissionKey !== null) {
				if (wuc_has_permission_unified($db, $userIdDb, $moduleKey, $permissionKey)) {
					return true;
				}
			}
		}
		
		// Legacy fallback: check role_permissions_legacy
		if ($staffId !== null) {
			$legacySql = "SELECT 1 FROM role_permissions_legacy rp
						  INNER JOIN staff_positions sp ON rp.PosID = sp.PosID
						  WHERE sp.staff_id = ? AND rp.permission_name = ?";
			if ($stmt = $db->prepare($legacySql)) {
				$permissionName = ($arg3 !== null) ? ($arg2 . '.' . $arg3) : $arg2;
				$stmt->bind_param("ss", $staffId, $permissionName);
				$stmt->execute();
				$allowed = $stmt->get_result()->num_rows > 0;
				$stmt->close();
				if ($allowed) {
					return true;
				}
			}
		}
		
		return false;
	}
}

if (!function_exists('hasPermission')) {
	function hasPermission($user, $arg2, $arg3 = null): bool {
		global $db;
		if (!$db instanceof mysqli || $user === null || $user === '') {
			return false;
		}
		// Per-request result cache: nav rendering, module guards and dashboard
		// widgets call hasPermission() with the same arguments many times per
		// page. Cache the boolean keyed by (user, arg2, arg3, portal).
		$store = &wuc_permission_cache_store();
		$portalKey = (string)(wuc_permissions_current_portal_id($db) ?? 'null');
		$hpKey = (string)$user . '|' . (string)$arg2 . '|' . (string)($arg3 ?? '') . '|' . $portalKey;
		if (array_key_exists($hpKey, $store['has'])) {
			return $store['has'][$hpKey];
		}
		return $store['has'][$hpKey] = _wuc_has_permission_impl($user, $arg2, $arg3);
	}
}

if (!function_exists('wuc_permission_denied')) {
	function wuc_permission_denied(string $message = 'You do not have permission to access this page.', string $redirect = ''): void {
		global $db;
		if ($db instanceof mysqli) {
			if (!function_exists('audit_log_current_user')) {
				$auditHelper = __DIR__ . '/audit.php';
				if (is_file($auditHelper)) {
					require_once $auditHelper;
				}
			}
			if (function_exists('audit_log_current_user')) {
				audit_log_current_user($db, 'security.permission_denied', [
					'message' => $message,
					'redirect' => $redirect,
				]);
			}
		}
		http_response_code(403);
		$_SESSION['errorMessage'] = $message;
		if ($redirect !== '' && !headers_sent()) {
			header('Location: ' . $redirect);
			exit;
		}

		if (!headers_sent()) {
			header('Content-Type: text/html; charset=UTF-8');
		}
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>Access Denied</title>';
		echo '<link rel="stylesheet" href="/wucportal/dist/css/bootstrap.min.css">';
		echo '</head><body class="bg-light"><main class="container py-5">';
		echo '<div class="alert alert-danger shadow-sm">';
		echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
		echo '</div>';
		echo '<a class="btn btn-primary" href="/wucportal/portal_selection.php">Choose Portal</a>';
		echo '</main></body></html>';
		exit;
	}
}

if (!function_exists('wuc_current_user_identifier')) {
	function wuc_current_user_identifier(): string {
		return (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? $_SESSION['user_id_db'] ?? '');
	}
}

if (!function_exists('wuc_can_any_permission')) {
	function wuc_can_any_permission(array $permissions, ?string $user = null): bool {
		$user = (string)($user ?? wuc_current_user_identifier());
		if ($user === '') {
			return false;
		}

		if (function_exists('isSystemsAdmin') && isSystemsAdmin()) {
			return true;
		}
		if (function_exists('isAdmin') && isAdmin($user)) {
			return true;
		}
		if (hasPermission($user, 'admin_all')) {
			return true;
		}

		foreach ($permissions as $permission) {
			$permission = trim((string)$permission);
			if ($permission === '') {
				continue;
			}
			if (strpos($permission, '.') !== false) {
				[$module, $action] = explode('.', $permission, 2);
				if (hasPermission($user, $module, $action) || hasPermission($user, $permission)) {
					return true;
				}
				continue;
			}
			if (hasPermission($user, $permission)) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('wuc_require_any_permission')) {
	function wuc_require_any_permission(array $permissions, string $message = 'You do not have permission to access this page.', string $redirect = ''): void {
		if (!wuc_can_any_permission($permissions)) {
			wuc_permission_denied($message, $redirect);
		}
	}
}

if (!function_exists('wuc_require_portal_permission')) {
	function wuc_require_portal_permission(mysqli $db, string $portalCode, array $permissions, string $message = 'You do not have permission to access this page.', string $redirect = ''): void {
		if (!function_exists('wuc_require_portal_access')) {
			$portalAccess = __DIR__ . '/portal_access.php';
			if (is_file($portalAccess)) {
				require_once $portalAccess;
			}
		}
		if (function_exists('wuc_require_portal_access')) {
			wuc_require_portal_access($db, $portalCode, $message);
		}
		$_SESSION['current_portal'] = $portalCode;
		wuc_require_any_permission($permissions, $message, $redirect);
	}
}

if (!function_exists('wuc_permissions_session_has_systems_admin_role')) {
	function wuc_permissions_session_has_systems_admin_role(): bool {
		if (($_SESSION['role'] ?? '') === 'systems_admin') {
			return true;
		}
		$roles = $_SESSION['all_roles'] ?? [];
		return is_array($roles) && in_array('systems_admin', $roles, true);
	}
}

// Function to get all permissions for a user
if (!function_exists('getUserPermissions')) {
	function getUserPermissions($staff_id) {
		global $db;

		if (!$db instanceof mysqli) {
			return array();
		}

		// Per-request result cache. getUserPermissions() underpins
		// getUserModules()/canAccessModule() and the sidebar builder, so it is
		// called repeatedly for the same user while rendering a single page.
		$store = &wuc_permission_cache_store();
		$portalId = wuc_permissions_current_portal_id($db);
		$cacheKey = (string)$staff_id . '|' . ($portalId ?? 'null');
		if (array_key_exists($cacheKey, $store['perms'])) {
			return $store['perms'][$cacheKey];
		}

		// Schema-shape probes are stable for the life of the process. Run the
		// SHOW TABLES / SHOW COLUMNS checks once and reuse the result instead of
		// issuing three metadata queries on every call.
		static $schema = null;
		if ($schema === null) {
			$schema = ['role_permissions' => false, 'staff_positions' => false, 'legacy' => false];
			if ($rp = $db->query("SHOW TABLES LIKE 'role_permissions'")) {
				$schema['role_permissions'] = $rp->num_rows > 0;
				$rp->free();
			}
			if ($sp = $db->query("SHOW TABLES LIKE 'staff_positions'")) {
				$schema['staff_positions'] = $sp->num_rows > 0;
				$sp->free();
			}
			// role_permissions was migrated to the new RBAC schema (role_id,
			// module_id, permission_id, status — no permission_name/PosID). The
			// presence of the legacy permission_name column selects which query
			// shape to run below.
			if ($schema['role_permissions'] && ($cc = $db->query("SHOW COLUMNS FROM role_permissions LIKE 'permission_name'"))) {
				$schema['legacy'] = $cc->num_rows > 0;
				$cc->free();
			}
		}

		if (!$schema['role_permissions'] || !$schema['staff_positions']) {
			return $store['perms'][$cacheKey] = array();
		}
		$hasLegacySchema = $schema['legacy'];

		if (!$hasLegacySchema) {
			$userIdInt = (int)$staff_id;
			$perms = [];
			$portalId = wuc_permissions_current_portal_id($db);
			$portalClause = $portalId !== null ? ' AND (ur.portal_id IS NULL OR ur.portal_id = ?) AND (rp.portal_id IS NULL OR rp.portal_id = ?)' : '';
			$sqlRoles = "SELECT DISTINCT p.permission_key, m.module_key
						 FROM user_roles ur
						 JOIN role_permissions rp ON rp.role_id = ur.role_id
						 JOIN permissions p ON p.permission_id = rp.permission_id
						 JOIN modules m ON m.module_id = rp.module_id
						 WHERE ur.user_id = ?
						   AND ur.status = 'active'
						   AND rp.status = 'active'
						   AND p.status = 'active'
						   AND m.status = 'active'" . $portalClause;
			if ($stmt = $db->prepare($sqlRoles)) {
				if ($portalId !== null) {
					$stmt->bind_param('iii', $userIdInt, $portalId, $portalId);
				} else {
					$stmt->bind_param('i', $userIdInt);
				}
				$stmt->execute();
				$res = $stmt->get_result();
				while ($row = $res->fetch_assoc()) {
					$perms[$row['permission_key']] = [
						'permission_key' => $row['permission_key'],
						'module_key'     => $row['module_key'],
						'source'         => 'role',
					];
				}
				$stmt->close();
			}
			$today = date('Y-m-d');
			$sqlDirect = "SELECT p.permission_key, m.module_key
						  FROM user_module_access uma
						  JOIN permissions p ON p.permission_id = uma.permission_id
						  JOIN modules m ON m.module_id = uma.module_id
						  WHERE uma.user_id = ?
							AND uma.status = 'active'
							AND p.status = 'active'
							AND m.status = 'active'
							AND (uma.start_date IS NULL OR uma.start_date <= ?)
							AND (uma.end_date IS NULL OR uma.end_date >= ?)";
			if ($stmt = $db->prepare($sqlDirect)) {
				$stmt->bind_param('iss', $userIdInt, $today, $today);
				$stmt->execute();
				$res = $stmt->get_result();
				while ($row = $res->fetch_assoc()) {
					$perms[$row['permission_key']] = [
						'permission_key' => $row['permission_key'],
						'module_key'     => $row['module_key'],
						'source'         => 'direct',
					];
				}
				$stmt->close();
			}
			return $store['perms'][$cacheKey] = array_values($perms);
		}

		$query = "SELECT rp.permission_name, rp.permission_description
				  FROM role_permissions rp
				  INNER JOIN staff_positions sp ON rp.PosID = sp.PosID
				  WHERE sp.staff_id = ?";

		$stmt = $db->prepare($query);
		if (!$stmt) {
			error_log('getUserPermissions prepare failed: ' . $db->error);
			return $store['perms'][$cacheKey] = array();
		}
		$stmt->bind_param("s", $staff_id);
		$stmt->execute();
		$result = $stmt->get_result();

		$permissions = array();
		while ($row = $result->fetch_object()) {
			$permissions[$row->permission_name] = $row->permission_description;
		}
		$stmt->close();

		return $store['perms'][$cacheKey] = $permissions;
	}
}

// Library convenience guards
if (!function_exists('canManageLibrary')) {
	function canManageLibrary($staff_id) {
		return hasPermission($staff_id, 'library_manage') || hasPermission($staff_id, 'admin_all');
	}
}

if (!function_exists('canCirculate')) {
	function canCirculate($staff_id) {
		return hasPermission($staff_id, 'library_circulation') || canManageLibrary($staff_id);
	}
}

if (!function_exists('canCatalog')) {
	function canCatalog($staff_id) {
		return hasPermission($staff_id, 'library_catalog') || canManageLibrary($staff_id);
	}
}

// Function to check if user has access to a specific course
if (!function_exists('hasAccessToCourse')) {
	function hasAccessToCourse($staff_id, $course_code) {
		global $db;

		$query = "SELECT 1 FROM course_lecturer 
				  WHERE staff_id = ? AND course_code = ?";

		$stmt = $db->prepare($query);
		$stmt->bind_param("ss", $staff_id, $course_code);
		$stmt->execute();
		$result = $stmt->get_result();

		return $result->num_rows > 0;
	}
}

// General eLearning access (across courses)
if (!function_exists('canAccessElearning')) {
	function canAccessElearning($staff_id = null) {
		// Support both role_helpers style (no param) and permissions style (with param)
		if ($staff_id === null && isset($_SESSION['staff_id'])) {
			$staff_id = $_SESSION['staff_id'];
		}
		if ($staff_id === null) {
			return false;
		}
		return hasPermission($staff_id, 'elearn_access') ||
			   hasPermission($staff_id, 'elearn_admin_all') ||
			   hasPermission($staff_id, 'admin_all'); // Allow system administrators
	}
}

// eLearning course management permission
if (!function_exists('canManageElearningCourses')) {
	function canManageElearningCourses($staff_id) {
		return hasPermission($staff_id, 'elearn_manage_course') ||
			   hasPermission($staff_id, 'elearn_admin_all') ||
			   hasPermission($staff_id, 'admin_all'); // Allow system administrators
	}
}

// Check if user is an administrator (has admin_all permission)
if (!function_exists('isAdministrator')) {
	function isAdministrator($staff_id) {
		return hasPermission($staff_id, 'admin_all');
	}
}

// Function to enforce permission check
if (!function_exists('enforcePermission')) {
	function enforcePermission($staff_id, $permission_name) {
		if (!hasPermission($staff_id, $permission_name)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['errorMessage'] = 'Access denied: You do not have permission to perform this action.';
            }
            $target = '/wucportal/staff_login.php?error=unauthorized';
            if (!headers_sent()) {
                http_response_code(403);
                header('Location: ' . $target);
            } else {
                // Page chrome may already have been emitted (e.g. the shared
                // library pages boot their nav before this check); fall back to
                // a client-side redirect instead of a headers-already-sent fatal.
                $safe = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
                echo '<meta http-equiv="refresh" content="0;url=' . $safe . '">'
                    . '<p>Access denied. <a href="' . $safe . '">Continue to login</a>.</p>';
            }
            exit;
		}
	}
}
