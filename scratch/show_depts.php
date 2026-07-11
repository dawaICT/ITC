<?php
require_once __DIR__ . '/../db/connect.php';

$res = $db->query("SELECT id, department_name, department_code, section_id FROM departments");
while ($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']} | Name: {$row['department_name']} | Code: {$row['department_code']} | Section: {$row['section_id']}" . PHP_EOL;
}
