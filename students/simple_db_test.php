<?php
// Simple Database Test
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "Starting database test...\n";

// Test PDO connection
try {
    require_once 'includes/Database.php';
    $database = new Database();
    $conn = $database->getConnection();
    echo "✓ PDO connection successful\n";

    // Test basic query
    $stmt = $conn->query("SELECT COUNT(*) as count FROM invoices");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "✓ Invoices table has " . $result['count'] . " records\n";

    $stmt = $conn->query("SELECT COUNT(*) as count FROM student_payments");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "✓ Student payments table has " . $result['count'] . " records\n";

    // Test balance statement queries with a sample student ID
    $student_id = 'test123';

    $fees_query = $conn->prepare("SELECT SUM(amount) as total_fees FROM invoices WHERE student_id = :student_id");
    $fees_query->execute(['student_id' => $student_id]);
    $fees_row = $fees_query->fetch(PDO::FETCH_OBJ);
    $total_fees = (float)($fees_row->total_fees ?? 0);
    echo "✓ Fees query successful: ZMK" . number_format($total_fees, 2) . "\n";

    $payments_query = $conn->prepare("SELECT SUM(amount_paid) as total_paid FROM student_payments WHERE Sid = :student_id");
    $payments_query->execute(['student_id' => $student_id]);
    $payments_row = $payments_query->fetch(PDO::FETCH_OBJ);
    $total_paid = (float)($payments_row->total_paid ?? 0);
    echo "✓ Payments query successful: ZMK" . number_format($total_paid, 2) . "\n";

    echo "✓ All database tests passed!\n";

} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
}
?>
