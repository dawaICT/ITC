<?php
// Paginated + searchable student payments ledger.
require __DIR__ . '/bootstrap.php';

echo json_encode(wuc_paginate($db, [
    'select'      => "payment_id, Sid, amount_paid, balance, channel, payment_date,
                      academic_year, semester_term, payment_status, reference_number, receipt_number",
    'from'        => "FROM student_payments",
    'search_cols' => ['Sid', 'reference_number', 'receipt_number', 'channel'],
    'order_by'    => "payment_date DESC, payment_id DESC",
]));
