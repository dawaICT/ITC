<?php
require __DIR__ . '/../db/connect.php';
header('Content-Type: application/json; charset=utf-8');
$res = $db->query("SHOW COLUMNS FROM invoices");
$cols = [];
if ($res) {
  while ($r = $res->fetch_assoc()) $cols[] = $r;
}
echo json_encode($cols, JSON_PRETTY_PRINT);
