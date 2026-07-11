<?php
// Paginated + searchable staff directory. Note: password/lockout columns are
// deliberately never selected.
require __DIR__ . '/bootstrap.php';

echo json_encode(wuc_paginate($db, [
    'select'      => "staff_id, title, Fname, Lname, sex, email, mobile, role, status",
    'from'        => "FROM staff",
    'search_cols' => ['staff_id', 'Fname', 'Lname', 'email', 'role'],
    'order_by'    => "Lname ASC, Fname ASC",
]));
