<?php
// Paginated + searchable course registrations.
require __DIR__ . '/bootstrap.php';

echo json_encode(wuc_paginate($db, [
    'select'      => "id, Sid, course_code, semester, Year, academic_year, status, registration_date",
    'from'        => "FROM course_registration",
    'search_cols' => ['Sid', 'course_code', 'academic_year', 'status'],
    'order_by'    => "registration_date DESC, id DESC",
]));
