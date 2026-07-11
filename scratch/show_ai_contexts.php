<?php
require_once __DIR__ . '/../db/connect.php';

$res = $db->query("SELECT ac.id, p.portal_code, ac.module_name, ac.user_role, ac.context_type, ac.rules FROM ai_contexts ac JOIN portals p ON p.id = ac.portal_id");
while ($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']} | Portal: {$row['portal_code']} | Mod: {$row['module_name']} | Role: {$row['user_role']} | Type: {$row['context_type']}" . PHP_EOL;
    echo "Rules: " . substr($row['rules'], 0, 150) . "..." . PHP_EOL . PHP_EOL;
}
