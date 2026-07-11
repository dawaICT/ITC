<?php
// Paginated + searchable course catalogue (231 rows — a genuinely large table).
require __DIR__ . '/bootstrap.php';

echo json_encode(wuc_paginate($db, [
    'select'      => "id, course_code, course_name, category, duration, duration_unit,
                      course_fee, status, course_type",
    'from'        => "FROM courses",
    'search_cols' => ['course_code', 'course_name', 'category', 'course_type'],
    'order_by'    => "course_name ASC",
]));
