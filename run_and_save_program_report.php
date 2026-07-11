<?php
ob_start();
require_once __DIR__ . '/check_program_assignments.php';
$report = ob_get_clean();
$file = __DIR__ . '/program_assignment_report.txt';
file_put_contents($file, "Report generated: " . date('Y-m-d H:i:s') . "\n\n" . $report);
echo "Saved report to $file\n";