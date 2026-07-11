<?php
define('IS_SCRIPT', true);
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/permissions.php';
header('Content-Type: application/json');
if (!isset($_SESSION['staff_id'])) { echo json_encode(['error'=>'auth']); exit; }
$digitalStaffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '';
if (!hasPermission($digitalStaffId, 'library_digital') && !(function_exists('isAdmin') && isAdmin($digitalStaffId))) { echo json_encode(['error'=>'forbidden']); exit; }

function json_ok($d){ echo json_encode($d); exit; }
function json_err($m){ http_response_code(400); echo json_encode(['error'=>$m]); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function admin_digital_table_exists(mysqli $db, string $table): bool {
  $safe = $db->real_escape_string($table);
  if ($res = @$db->query("SHOW TABLES LIKE '{$safe}'")) {
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
  }
  return false;
}

function admin_digital_columns(mysqli $db, string $table): array {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $columns = [];
  if (admin_digital_table_exists($db, $table) && ($res = @$db->query("SHOW COLUMNS FROM `{$table}`"))) {
    while ($row = $res->fetch_assoc()) $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
    $res->free();
  }
  return $cache[$table] = $columns;
}

function admin_digital_expr(array $columns, array $candidates, string $fallback, string $alias): string {
  foreach ($candidates as $candidate) {
    $key = strtolower($candidate);
    if (isset($columns[$key])) {
      $column = $columns[$key];
      return "r.`{$column}` AS `{$alias}`";
    }
  }
  return "{$fallback} AS `{$alias}`";
}

if ($action === 'list') {
  $resourceCols = admin_digital_columns($db, 'library_digital_resources');
  if (!$resourceCols) json_err('Digital library is not configured.');

  $select = [
    'r.`id`',
    admin_digital_expr($resourceCols, ['title'], "''", 'title'),
    admin_digital_expr($resourceCols, ['resource_type', 'type'], "'resource'", 'resource_type'),
    admin_digital_expr($resourceCols, ['access_level', 'visibility'], "'registered'", 'access_level'),
    admin_digital_expr($resourceCols, ['subject', 'course_code'], "''", 'subject'),
    admin_digital_expr($resourceCols, ['url', 'file_path'], "''", 'url'),
    admin_digital_expr($resourceCols, ['description'], "''", 'description'),
    admin_digital_expr($resourceCols, ['created_at'], "NULL", 'created_at'),
  ];

  $analyticsJoin = '';
  $viewExpr = '0 AS views';
  $downloadExpr = '0 AS downloads';
  if (admin_digital_table_exists($db, 'library_usage_analytics')) {
    $analyticsJoin = 'LEFT JOIN library_usage_analytics ua ON ua.resource_id = r.id';
    $viewExpr = "SUM(CASE WHEN ua.event_type='view' THEN 1 ELSE 0 END) AS views";
    $downloadExpr = "SUM(CASE WHEN ua.event_type='download' THEN 1 ELSE 0 END) AS downloads";
  }
  $select[] = $viewExpr;
  $select[] = $downloadExpr;

  $order = isset($resourceCols['created_at']) ? "r.`{$resourceCols['created_at']}` DESC, r.id DESC" : 'r.id DESC';
  $sql = "SELECT " . implode(",\n                 ", $select) . "
          FROM library_digital_resources r
          {$analyticsJoin}
          GROUP BY r.id
          ORDER BY {$order}
          LIMIT 500";
  $rows=[];
  if ($res=$db->query($sql)) {
    while($r=$res->fetch_assoc()) $rows[]=$r;
    $res->free();
  } else {
    json_err('Unable to load digital resources.');
  }
  json_ok(['results'=>$rows]);
}

json_err('unknown');
?>


