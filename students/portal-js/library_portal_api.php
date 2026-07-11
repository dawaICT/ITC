<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/library_resource_helpers.php';
if (!headers_sent()) { header('Content-Type: application/json'); }
if (!isset($_SESSION['Sid'])) { echo json_encode(['error'=>'auth']); exit; }
$Sid = $_SESSION['Sid'];

function json_ok($d){ echo json_encode($d); exit; }
function json_err($m){ http_response_code(400); echo json_encode(['error'=>$m]); exit; }

function table_columns(mysqli $db, string $table): array {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $cacheKey = 'wuc_schema_cols_' . $table;
  if (function_exists('apcu_fetch')) {
    $ok = false;
    $cached = apcu_fetch($cacheKey, $ok);
    if ($ok && is_array($cached)) {
      return $cache[$table] = $cached;
    }
  }
  $cols = [];
  $safe = $db->real_escape_string($table);
  if ($res = $db->query("SHOW COLUMNS FROM `$safe`")) {
    while ($row = $res->fetch_assoc()) $cols[] = (string)$row['Field'];
    $res->free();
  }
  if (function_exists('apcu_store')) {
    apcu_store($cacheKey, $cols, 300);
  }
  return $cache[$table] = $cols;
}

function has_col(mysqli $db, string $table, string $col): bool {
  return in_array($col, table_columns($db, $table), true);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'search') {
  $q = trim($_GET['q'] ?? '');
  $type = trim($_GET['type'] ?? '');
  $where = [];
  if ($q !== '') {
    global $db; $safe = '%' . $db->real_escape_string($q) . '%';
    $where[] = "(title LIKE '$safe' OR authors LIKE '$safe' OR isbn LIKE '$safe' OR keywords LIKE '$safe' OR subject LIKE '$safe')";
  }
  if ($type !== '') { global $db; $safeType=$db->real_escape_string($type); $where[] = "item_type='$safeType'"; }
  // scope=mine restricts results to resources linked to the student's registered
  // courses / programme / department (plus institution-wide public resources).
  // Anything else keeps the legacy "search everything" behaviour, so existing
  // catalogue browsing is unaffected.
  if (trim($_GET['scope'] ?? '') === 'mine') {
    global $db;
    $visibleIds = lr_visible_resource_ids($db, lr_student_scope($db, $Sid), 'item', 'students');
    if (!$visibleIds) { json_ok(['results'=>[], 'scoped'=>true]); }
    $where[] = 'i.id IN (' . implode(',', array_map('intval', $visibleIds)) . ')';
  }
  $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
  $sql = "SELECT i.*, (SELECT COUNT(*) FROM library_copies c WHERE c.item_id=i.id AND c.status='available') AS available_copies FROM library_items i $whereSql ORDER BY created_at DESC LIMIT 100";
  $rows=[]; if ($res=$db->query($sql)) { while($r=$res->fetch_assoc()) $rows[]=$r; }
  // analytics search
  if ($q !== '') { $stmt=$db->prepare("INSERT INTO library_usage_analytics (resource_id,user_type,user_id,event_type,meta) VALUES (NULL,'student',?,'search',JSON_OBJECT('q',?))"); $stmt->bind_param('ss',$Sid,$q); $stmt->execute(); }
  json_ok(['results'=>$rows]);
}

if ($action === 'reserve') {
  $item_id = (int)($_POST['item_id'] ?? 0); if ($item_id <= 0) json_err('invalid');
  // ensure no available copies
  $res=$db->query("SELECT COUNT(*) c FROM library_copies WHERE item_id=$item_id AND status='available'");
  $available = $res ? (int)$res->fetch_assoc()['c'] : 0;
  if ($available>0) json_err('Item currently available; please check shelves');
  $stmt=$db->prepare("INSERT INTO library_reservations (item_id, borrower_type, borrower_id, reserved_at, status) VALUES (?,?,?,NOW(),'active')");
  $type='student'; $stmt->bind_param('iss',$item_id,$type,$Sid);
  if ($stmt->execute()) json_ok(['message'=>'Reservation placed']);
  json_err('Failed');
}

if ($action === 'borrow') {
  $item_id = (int)($_POST['item_id'] ?? 0); if ($item_id <= 0) json_err('invalid');
  // find an available copy
  $res=$db->query("SELECT id FROM library_copies WHERE item_id=$item_id AND status='available' LIMIT 1");
  if (!$res || !$res->num_rows) json_err('No available copies');
  $copy = $res->fetch_assoc()['id'];
  // calculate due date: 14 days from now
  $due = date('Y-m-d H:i:s', strtotime('+14 days'));
  $loanCols = table_columns($db, 'library_loans');
  $fields = [];
  $placeholders = [];
  $types = '';
  $params = [];
  $add = function(string $col, string $type, $value) use (&$fields, &$placeholders, &$types, &$params, $loanCols) {
    if (!in_array($col, $loanCols, true)) return;
    $fields[] = "`$col`";
    $placeholders[] = '?';
    $types .= $type;
    $params[] = $value;
  };
  $add('copy_id', 'i', $copy);
  $add('item_id', 'i', $item_id);
  $add('borrower_type', 's', 'student');
  $add('borrower_id', 's', $Sid);
  $add('student_id', 's', $Sid);
  $add('due_date', 's', $due);
  $add('due_at', 's', $due);
  $add('borrowed_at', 's', date('Y-m-d H:i:s'));
  $add('status', 's', 'active');
  if (empty($fields)) json_err('Loan table is not configured');
  $stmt=$db->prepare("INSERT INTO library_loans (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")");
  if (!$stmt) json_err('Failed to prepare loan');
  $stmt->bind_param($types, ...$params);
  if ($stmt->execute()) {
    // update copy status
    $db->query("UPDATE library_copies SET status='loaned' WHERE id=$copy");
    json_ok(['message'=>'Item borrowed successfully']);
  }
  json_err('Failed to borrow');
}

if ($action === 'my_loans') {
  $dueCol = has_col($db, 'library_loans', 'due_date') ? 'due_date' : (has_col($db, 'library_loans', 'due_at') ? 'due_at' : null);
  $returnCol = has_col($db, 'library_loans', 'returned_at') ? 'returned_at' : null;
  $sidCol = has_col($db, 'library_loans', 'borrower_id') ? 'borrower_id' : (has_col($db, 'library_loans', 'student_id') ? 'student_id' : null);
  if ($dueCol === null || $sidCol === null) json_ok(['results'=>[]]);
  $activeWhere = $returnCol ? "AND l.`$returnCol` IS NULL" : (has_col($db, 'library_loans', 'status') ? "AND l.`status` = 'active'" : '');
  $typeWhere = has_col($db, 'library_loans', 'borrower_type') ? "AND l.borrower_type='student'" : '';
  $sql="SELECT l.id,i.title,l.`$dueCol` AS due_date, 1 as active, (CASE WHEN l.`$dueCol` > NOW() THEN 1 ELSE 0 END) as can_renew FROM library_loans l JOIN library_copies c ON c.id=l.copy_id JOIN library_items i ON i.id=COALESCE(c.item_id,l.item_id) WHERE l.`$sidCol`=? $typeWhere $activeWhere ORDER BY l.`$dueCol` ASC";
  $stmt=$db->prepare($sql); $stmt->bind_param('s',$Sid); $stmt->execute(); $res=$stmt->get_result(); $rows=[]; while($r=$res->fetch_assoc()) $rows[]=$r; json_ok(['results'=>$rows]);
}

if ($action === 'renew') {
  $loan_id = (int)($_POST['loan_id'] ?? 0); if ($loan_id<=0) json_err('invalid');
  // extend by 7 days if not overdue
  $dueCol = has_col($db, 'library_loans', 'due_date') ? 'due_date' : (has_col($db, 'library_loans', 'due_at') ? 'due_at' : null);
  $returnCol = has_col($db, 'library_loans', 'returned_at') ? 'returned_at' : null;
  $sidCol = has_col($db, 'library_loans', 'borrower_id') ? 'borrower_id' : (has_col($db, 'library_loans', 'student_id') ? 'student_id' : null);
  if ($dueCol === null || $sidCol === null) json_err('Loan table is not configured');
  $typeWhere = has_col($db, 'library_loans', 'borrower_type') ? "AND borrower_type='student'" : '';
  $returnSelect = $returnCol ? "`$returnCol` AS returned_at" : "NULL AS returned_at";
  $res=$db->query("SELECT `$dueCol` AS due_date, $returnSelect FROM library_loans WHERE id=$loan_id $typeWhere AND `$sidCol`='".$db->real_escape_string($Sid)."' LIMIT 1");
  if (!$res || !$res->num_rows) json_err('not found');
  $row=$res->fetch_assoc(); if ($row['returned_at']) json_err('already returned');
  if (time() > strtotime($row['due_date'])) json_err('Cannot renew overdue item');
  $stmt=$db->prepare("UPDATE library_loans SET `$dueCol`=DATE_ADD(`$dueCol`, INTERVAL 7 DAY) WHERE id=?");
  $stmt->bind_param('i',$loan_id); if ($stmt->execute()) json_ok(['message'=>'Renewed for 7 days']);
  json_err('failed');
}

if ($action === 'course_library') {
  // The student's course-based library: every registered course with the
  // resources (catalogue items, digital resources, lecturer notes) linked to it.
  global $db;
  $scope = lr_student_scope($db, $Sid);
  $courses = $scope['courses'];
  if (!$courses) { json_ok(['courses'=>[]]); }

  // Friendly course titles where the courses table provides them.
  $titles = [];
  if (wuc_table_exists($db, 'courses')) {
    $ph = implode(',', array_fill(0, count($courses), '?'));
    $rows = lr_fetch_rows($db,
      "SELECT course_code, course_name FROM courses WHERE course_code IN ($ph)",
      str_repeat('s', count($courses)), $courses);
    foreach ($rows as $r) { $titles[$r['course_code']] = $r['course_name']; }
  }

  $out = [];
  foreach ($courses as $code) {
    $bundle = lr_course_resources($db, $code, 'students');
    $total = count($bundle['items']) + count($bundle['digital']) + count($bundle['notes']);
    $out[] = [
      'course_code' => $code,
      'course_name' => $titles[$code] ?? $code,
      'total'       => $total,
      'items'       => $bundle['items'],
      'digital'     => $bundle['digital'],
      'notes'       => $bundle['notes'],
    ];
  }
  json_ok(['courses'=>$out]);
}

json_err('unknown');
?>


