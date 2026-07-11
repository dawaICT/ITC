<?php
// Library AJAX API for admin module
define('IS_SCRIPT', true);
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../../includes/csrf_guard.php';
require_once __DIR__ . '/../../includes/library_resource_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['staff_id'])) { echo json_encode(['error' => 'auth']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function json_ok($data) { echo json_encode($data); exit; }
function json_err($msg) { http_response_code(400); echo json_encode(['error' => $msg]); exit; }

// Anyone who can catalogue, manage the library, or manage digital resources may
// curate resource links (the digital-resources page is gated on library_digital).
function lib_can_link(): bool {
  $sid = $_SESSION['staff_id'];
  return canCatalog($sid) || canManageLibrary($sid) || hasPermission($sid, 'library_digital');
}

switch ($action) {
  case 'stats':
    if (!canManageLibrary($_SESSION['staff_id']) && !canCirculate($_SESSION['staff_id']) && !canCatalog($_SESSION['staff_id'])) json_err('forbidden');
    $stats = [
      'items' => 0,
      'copies_available' => 0,
      'active_loans' => 0,
      'outstanding_fines' => '0.00',
    ];
    if ($res = $db->query("SELECT COUNT(*) c FROM library_items")) { $stats['items'] = (int)$res->fetch_assoc()['c']; }
    if ($res = $db->query("SELECT COUNT(*) c FROM library_copies WHERE status='available'")) { $stats['copies_available'] = (int)$res->fetch_assoc()['c']; }
    if ($res = $db->query("SELECT COUNT(*) c FROM library_loans WHERE returned_at IS NULL")) { $stats['active_loans'] = (int)$res->fetch_assoc()['c']; }
    if ($res = $db->query("SELECT COALESCE(SUM(amount),0) amt FROM library_fines WHERE settled=0")) { $stats['outstanding_fines'] = number_format((float)$res->fetch_assoc()['amt'], 2, '.', ''); }
    audit_log($db, $_SESSION['staff_id'], 'library_view_stats');
    json_ok($stats);

  case 'search':
    if (!canManageLibrary($_SESSION['staff_id']) && !canCirculate($_SESSION['staff_id']) && !canCatalog($_SESSION['staff_id'])) json_err('forbidden');
    $q = trim($_GET['q'] ?? '');
    $type = trim($_GET['type'] ?? '');
    $where = [];
    if ($q !== '') {
      $safe = '%' . $db->real_escape_string($q) . '%';
      $where[] = "(title LIKE '$safe' OR authors LIKE '$safe' OR isbn LIKE '$safe' OR keywords LIKE '$safe' OR subject LIKE '$safe')";
    }
    if ($type !== '') {
      $safeType = $db->real_escape_string($type);
      $where[] = "item_type='$safeType'";
    }
    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT i.*, (
              SELECT COUNT(*) FROM library_copies c WHERE c.item_id=i.id AND c.status='available'
            ) AS available_copies
            FROM library_items i $whereSql ORDER BY created_at DESC LIMIT 100";
    $rows = [];
    if ($res = $db->query($sql)) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
    audit_log($db, $_SESSION['staff_id'], 'library_search', json_encode(['q'=>$q,'type'=>$type]));
    json_ok(['results' => $rows]);

  case 'link_options':
    // Dropdown data for the resource-linking UI.
    if (!lib_can_link()) json_err('forbidden');
    $courses = $programmes = $departments = [];
    if (wuc_table_exists($db, 'courses') && ($r = $db->query("SELECT course_code, course_name FROM courses ORDER BY course_code LIMIT 1000"))) {
      while ($x = $r->fetch_assoc()) { $courses[] = $x; }
    }
    if (wuc_table_exists($db, 'programs') && ($r = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_code LIMIT 500"))) {
      while ($x = $r->fetch_assoc()) { $programmes[] = $x; }
    }
    if (wuc_table_exists($db, 'departments') && ($r = $db->query("SELECT id, department_name FROM departments ORDER BY department_name LIMIT 200"))) {
      while ($x = $r->fetch_assoc()) { $departments[] = $x; }
    }
    json_ok(['courses' => $courses, 'programmes' => $programmes, 'departments' => $departments]);

  case 'resource_links':
    // Existing links for one resource.
    if (!lib_can_link()) json_err('forbidden');
    $kind = ($_GET['kind'] ?? 'item') === 'digital' ? 'digital' : 'item';
    $rid = (int)($_GET['resource_id'] ?? 0);
    if ($rid <= 0) json_err('invalid resource');
    $links = lr_fetch_rows($db,
      "SELECT id, scope_type, scope_ref, visibility, created_at
       FROM library_resource_links WHERE resource_kind = ? AND resource_id = ? ORDER BY scope_type, scope_ref",
      'si', [$kind, $rid]);
    json_ok(['links' => $links]);

  case 'link_add':
    if (!lib_can_link()) json_err('forbidden');
    wuc_ajax_require_csrf();
    $kind = ($_POST['kind'] ?? 'item') === 'digital' ? 'digital' : 'item';
    $rid = (int)($_POST['resource_id'] ?? 0);
    $scopeType = (string)($_POST['scope_type'] ?? '');
    $scopeRef = trim((string)($_POST['scope_ref'] ?? ''));
    $visibility = (string)($_POST['visibility'] ?? 'all');
    if ($rid <= 0) json_err('invalid resource');
    if (!in_array($scopeType, ['course','programme','department','public'], true)) json_err('invalid scope');
    if ($scopeType !== 'public' && $scopeRef === '') json_err('scope target required');
    $ok = lr_link_resource($db, $kind, $rid, $scopeType, $scopeType === 'public' ? null : $scopeRef, $visibility, (string)$_SESSION['staff_id']);
    if (!$ok) json_err('could not save link');
    audit_log($db, $_SESSION['staff_id'], 'library_link_add', json_encode(['kind'=>$kind,'resource_id'=>$rid,'scope_type'=>$scopeType,'scope_ref'=>$scopeRef]));
    json_ok(['saved' => true]);

  case 'link_remove':
    if (!lib_can_link()) json_err('forbidden');
    wuc_ajax_require_csrf();
    $linkId = (int)($_POST['link_id'] ?? 0);
    if ($linkId <= 0) json_err('invalid link');
    if (!lr_unlink_resource($db, $linkId)) json_err('could not remove link');
    audit_log($db, $_SESSION['staff_id'], 'library_link_remove', json_encode(['link_id'=>$linkId]));
    json_ok(['removed' => true]);

  default:
    json_err('unknown');
}
?>


