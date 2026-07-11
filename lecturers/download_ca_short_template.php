<?php
require_once __DIR__ . '/includes/guard.php';
/**
 * Short-course CA CSV template. Columns match upload_ca_short.php:
 *   SID, Course_Code, A1, A2, A3, T1, T2
 */

$filename = 'Short_Course_CA_Template_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

fputcsv($output, ['SID', 'Course_Code', 'A1', 'A2', 'A3', 'T1', 'T2']);
fputcsv($output, ['STU901', 'ITC-ICT-01', '80', '75', '', '70', '']);
fputcsv($output, ['STU902', 'ITC-ICT-01', '65', '', '', '72', '']);
fputcsv($output, []);
fputcsv($output, ['INSTRUCTIONS:', '', '', '', '', '', '']);
fputcsv($output, ['1. Delete the example and instruction rows before uploading.', '', '', '', '', '', '']);
fputcsv($output, ['2. Course_Code must be a short course assigned to you.', '', '', '', '', '', '']);
fputcsv($output, ['3. Each component is a local CA mark out of 100; leave blank where not used.', '', '', '', '', '', '']);

fclose($output);
exit;
