<?php
echo "=== SIMPLE TEST ===\n";

require 'db/connect.php';
echo "Database connected\n";

require 'admin/semester_courses.php';
echo "semester_courses.php included\n";

if (function_exists('display_semester_courses')) {
    echo "Function exists\n";

    ob_start();
    display_semester_courses();
    $output = ob_get_clean();

    echo "Output length: " . strlen($output) . "\n";
    if (strlen($output) > 0) {
        echo "First 100 chars: " . substr($output, 0, 100) . "\n";
    }
} else {
    echo "Function does not exist\n";
}

echo "=== END TEST ===\n";
?>
