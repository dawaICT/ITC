<?php
require_once __DIR__ . '/../db/connect.php';

echo "Testing database connection and query structure...\n";

try {
    // Test the new query structure without status field
    $stmt = $db->prepare("SELECT id, invoice_number, academic_year, semester, amount
        FROM invoices WHERE student_id = ? ORDER BY id ASC");

    $stmt->bind_param("s", $test_id);
    $test_id = 'test123';
    $stmt->execute();
    $result = $stmt->get_result();

    echo "✓ Query structure test: SUCCESS\n";
    echo "✓ Number of columns in result: " . $result->field_count . "\n";

    // Show column names
    $fields = $result->fetch_fields();
    echo "✓ Columns returned: ";
    $column_names = array();
    foreach ($fields as $field) {
        $column_names[] = $field->name;
    }
    echo implode(', ', $column_names) . "\n";

    $stmt->close();
    echo "✓ Database connection and query: WORKING\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
?>
