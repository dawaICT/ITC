<?php
// Paginated + searchable CA / continuous-assessment records (semester_assessment).
require __DIR__ . '/bootstrap.php';

echo json_encode(wuc_paginate($db, [
    'select'      => "id, Sid, Course_Code, Total_CA, Exam, status, semester, Year",
    'from'        => "FROM semester_assessment",
    'search_cols' => ['Sid', 'Course_Code', 'status'],
    'order_by'    => "id DESC",
]));
