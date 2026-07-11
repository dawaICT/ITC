<?php
// Paginated + searchable fee structure.
require __DIR__ . '/bootstrap.php';

echo json_encode(wuc_paginate($db, [
    'select'      => "id, entity_type, program_code, year_of_study, semester,
                      fee_type, fee_description, amount, status",
    'from'        => "FROM fee_structure",
    'search_cols' => ['program_code', 'fee_type', 'fee_description', 'entity_type'],
    'order_by'    => "program_code ASC, year_of_study ASC",
]));
