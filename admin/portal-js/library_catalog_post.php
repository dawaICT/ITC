<?php
define('IS_SCRIPT', true);
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/permissions.php';
if (!isset($_SESSION['staff_id'])) { header('Location: ../index.php'); exit; }
enforcePermission($_SESSION['staff_id'], 'library_catalog');

function redirect_back($msg=null, $err=false){
  if ($msg) $_SESSION[$err?'error_message':'success_message']=$msg;
  header('Location: ../library_catalog.php');
  exit;
}

$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
$title = trim($_POST['title'] ?? '');
$authors = trim($_POST['authors'] ?? '');
$isbn = trim($_POST['isbn'] ?? '');
$item_type = trim($_POST['item_type'] ?? 'book');
$pub_year = $_POST['pub_year'] !== '' ? (int)$_POST['pub_year'] : null;
$publisher = trim($_POST['publisher'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($title === '') redirect_back('Title is required', true);

if ($id) {
  $stmt = $db->prepare("UPDATE library_items SET title=?, authors=?, isbn=?, item_type=?, pub_year=?, publisher=?, description=? WHERE id=?");
  $stmt->bind_param('ssssissi', $title, $authors, $isbn, $item_type, $pub_year, $publisher, $description, $id);
  $ok = $stmt->execute();
} else {
  $stmt = $db->prepare("INSERT INTO library_items (title, authors, isbn, item_type, pub_year, publisher, description) VALUES (?,?,?,?,?,?,?)");
  $stmt->bind_param('ssssiss', $title, $authors, $isbn, $item_type, $pub_year, $publisher, $description);
  $ok = $stmt->execute();
}

if ($ok) redirect_back('Saved successfully');
redirect_back('Save failed', true);
?>


