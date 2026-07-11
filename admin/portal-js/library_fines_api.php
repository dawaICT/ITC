<?php
define('IS_SCRIPT', true);
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../../includes/permissions.php';
header('Content-Type: application/json');
if (!isset($_SESSION['staff_id'])) { echo json_encode(['error'=>'auth']); exit; }
if (!hasPermission($_SESSION['staff_id'], 'library_fines')) { echo json_encode(['error'=>'forbidden']); exit; }

function json_ok($d){ echo json_encode($d); exit; }
function json_err($m){ http_response_code(400); echo json_encode(['error'=>$m]); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'list') {
  $rows=[]; if ($res = $db->query("SELECT * FROM library_fines WHERE settled=0 ORDER BY created_at ASC LIMIT 500")) { while ($r=$res->fetch_assoc()) $rows[]=$r; }
  json_ok(['results'=>$rows]);
}

if ($action === 'settle') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) json_err('invalid');
  $stmt = $db->prepare("UPDATE library_fines SET settled=1, settled_at=NOW() WHERE id=?");
  $stmt->bind_param('i', $id);
  if (!$stmt->execute()) json_err('fail');
  audit_log($db, $_SESSION['staff_id'], 'library_fine_settle', json_encode(['id'=>$id]));
  json_ok(['message'=>'settled']);
}

json_err('unknown');
?>


