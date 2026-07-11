<?php
// Paginated + searchable notifications feed.
require __DIR__ . '/bootstrap.php';

echo json_encode(wuc_paginate($db, [
    'select'      => "id, recipient_user_id, recipient_student_id, recipient_staff_id,
                      channel, module_name, title, status, created_at",
    'from'        => "FROM notifications",
    'search_cols' => ['title', 'message', 'module_name', 'channel'],
    'order_by'    => "created_at DESC, id DESC",
]));
