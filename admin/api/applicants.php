<?php
// Paginated + searchable applicant queue (online_applicants).
require __DIR__ . '/bootstrap.php';

echo json_encode(wuc_paginate($db, [
    'select'      => "id, Fname, Lname, sex, email, mobile, program, intake, mode, status, dte_adm",
    'from'        => "FROM online_applicants",
    'search_cols' => ['Fname', 'Lname', 'email', 'nrc_pass', 'program'],
    'order_by'    => "id DESC",
]));
