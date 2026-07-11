<?php
require __DIR__ . '/../db/connect.php';
header('Content-Type: application/json; charset=utf-8');
$res = $db->query("SHOW TABLES LIKE 'invoices'");
$found = ($res && $res->num_rows) ? true : false;
echo json_encode(['table_invoices_exists' => $found]);
