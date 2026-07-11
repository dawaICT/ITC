<?php
// Paginated + searchable audit log (~3,900 rows). Uses idx_audit_created for the
// ORDER BY, so only the requested page is scanned.
require __DIR__ . '/bootstrap.php';

echo json_encode(wuc_paginate($db, [
    'select'      => "id, user_id, action, module, role_name, ip_address, created_at",
    'from'        => "FROM audit_logs",
    'search_cols' => ['user_id', 'action', 'module', 'role_name'],
    'order_by'    => "created_at DESC, id DESC",
    'default_per' => 50,
]));
