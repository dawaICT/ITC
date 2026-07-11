<?php
define('IS_SCRIPT', true);
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/permissions.php';
if (!isset($_SESSION['staff_id'])) { header('Location: ../index.php'); exit; }
$digitalStaffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '';
if (!hasPermission($digitalStaffId, 'library_digital') && !(function_exists('isAdmin') && isAdmin($digitalStaffId))) {
  die("Access Denied: You don't have permission to perform this action.");
}

function redirect_back($msg=null, $err=false){
  if ($msg) $_SESSION[$err?'error_message':'success_message']=$msg;
  header('Location: ../library_digital.php');
  exit;
}

function admin_digital_post_table_exists(mysqli $db, string $table): bool {
  $safe = $db->real_escape_string($table);
  if ($res = @$db->query("SHOW TABLES LIKE '{$safe}'")) {
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
  }
  return false;
}

function admin_digital_post_columns(mysqli $db, string $table): array {
  $columns = [];
  if (admin_digital_post_table_exists($db, $table) && ($res = @$db->query("SHOW COLUMNS FROM `{$table}`"))) {
    while ($row = $res->fetch_assoc()) {
      $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
    }
    $res->free();
  }
  return $columns;
}

function admin_digital_post_insert(mysqli $db, string $table, array $values): int {
  $columns = array_keys($values);
  $columnSql = implode(', ', array_map(static fn($column) => "`{$column}`", $columns));
  $placeholders = implode(', ', array_fill(0, count($columns), '?'));
  $types = str_repeat('s', count($columns));
  $params = array_values($values);
  $stmt = $db->prepare("INSERT INTO `{$table}` ({$columnSql}) VALUES ({$placeholders})");
  if (!$stmt) {
    throw new Exception('Prepare failed');
  }
  $bind = [$types];
  foreach ($params as $index => $value) {
    $bind[] = &$params[$index];
  }
  call_user_func_array([$stmt, 'bind_param'], $bind);
  if (!$stmt->execute()) {
    throw new Exception('Insert failed');
  }
  $stmt->close();
  return (int)$db->insert_id;
}

$title = trim($_POST['title'] ?? '');
$resource_type = trim($_POST['resource_type'] ?? 'ebook');
$access_level = trim($_POST['access_level'] ?? 'registered');
$url = trim($_POST['url'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($title === '') redirect_back('Title is required', true);
if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
  redirect_back('Enter a valid resource URL.', true);
}

$resourceColumns = admin_digital_post_columns($db, 'library_digital_resources');
if (!$resourceColumns) {
  redirect_back('Digital library table is not configured.', true);
}

$db->begin_transaction();
try {
  $insert = [];
  $map = [
    'title' => $title,
    'resource_type' => $resource_type,
    'access_level' => $access_level,
    'visibility' => $access_level === 'registered' ? 'students' : $access_level,
    'url' => $url,
    'subject' => $subject,
    'course_code' => $subject,
    'description' => $description,
  ];
  foreach ($map as $logical => $value) {
    if (isset($resourceColumns[$logical])) {
      $insert[$resourceColumns[$logical]] = $value;
    }
  }
  if (isset($resourceColumns['created_at'])) {
    $insert[$resourceColumns['created_at']] = date('Y-m-d H:i:s');
  }
  if (isset($resourceColumns['updated_at'])) {
    $insert[$resourceColumns['updated_at']] = date('Y-m-d H:i:s');
  }
  if (!$insert) {
    throw new Exception('No compatible columns');
  }
  $resource_id = admin_digital_post_insert($db, 'library_digital_resources', $insert);

  if ($access_level === 'role' && admin_digital_post_table_exists($db, 'library_digital_resource_roles')) {
    $roles = isset($_POST['role_ids']) && is_array($_POST['role_ids']) ? $_POST['role_ids'] : [];
    if (!empty($roles)) {
      $ins = $db->prepare("INSERT INTO library_digital_resource_roles (resource_id, PosID) VALUES (?,?)");
      if ($ins) {
        foreach ($roles as $rid) { $rid = trim($rid); if ($rid==='') continue; $ins->bind_param('is', $resource_id, $rid); $ins->execute(); }
        $ins->close();
      }
    }
  }
  $db->commit();
  redirect_back('Saved');
} catch (Exception $e) {
  $db->rollback();
  redirect_back('Failed', true);
}
?>


