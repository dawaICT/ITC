<?php
/**
 * Test script to verify invoice flow from registration to balance statement
 * This script can be run to test the complete invoice functionality
 */

require_once 'includes/Database.php';

echo "<h2>Invoice Flow Test</h2>";

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Test 1: Check if student_payments table has the required columns
    echo "<h3>Test 1: Database Schema Check</h3>";
    $stmt = $conn->query("DESCRIBE student_payments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $requiredColumns = ['Sid', 'amount_paid', 'balance', 'channel', 'payment_date', 'academic_year', 'semester_term', 'payment_status', 'reference_number', 'description', 'invoice', 'narration', 'Year', 'dte_time'];
    
    $missingColumns = [];
    $existingColumns = array_column($columns, 'Field');
    
    foreach ($requiredColumns as $col) {
        if (!in_array($col, $existingColumns)) {
            $missingColumns[] = $col;
        }
    }
    
    if (empty($missingColumns)) {
        echo "<p style='color: green;'>✓ All required columns exist in student_payments table</p>";
    } else {
        echo "<p style='color: red;'>✗ Missing columns: " . implode(', ', $missingColumns) . "</p>";
    }
    
    // Test 2: Check if invoices table exists
    echo "<h3>Test 2: Invoices Table Check</h3>";
    $stmt = $conn->query("SHOW TABLES LIKE 'invoices'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Invoices table exists</p>";
    } else {
        echo "<p style='color: red;'>✗ Invoices table does not exist</p>";
    }
    
    // Test 3: Check recent invoice records
    echo "<h3>Test 3: Recent Invoice Records</h3>";
    $stmt = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Recent invoices (last 24 hours): " . $result['count'] . "</p>";
    
    // Test 4: Check recent payment records
    echo "<h3>Test 4: Recent Payment Records</h3>";
    $stmt = $conn->query("SELECT COUNT(*) as count FROM student_payments WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Recent payment records (last 24 hours): " . $result['count'] . "</p>";
    
    // Test 5: Sample data check
    echo "<h3>Test 5: Sample Data Check</h3>";
    $stmt = $conn->query("SELECT sp.Sid, sp.balance, sp.invoice, sp.narration, sp.payment_date 
                         FROM student_payments sp 
                         WHERE sp.channel = 'invoice' 
                         ORDER BY sp.payment_date DESC 
                         LIMIT 5");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($records)) {
        echo "<p style='color: green;'>✓ Found " . count($records) . " recent invoice records</p>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Student ID</th><th>Balance (ZMW)</th><th>Invoice</th><th>Description</th><th>Date</th></tr>";
        foreach ($records as $record) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($record['Sid']) . "</td>";
            echo "<td>ZMW " . number_format($record['balance'], 2) . "</td>";
            echo "<td>" . htmlspecialchars($record['invoice']) . "</td>";
            echo "<td>" . htmlspecialchars($record['narration']) . "</td>";
            echo "<td>" . htmlspecialchars($record['payment_date']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠ No recent invoice records found</p>";
    }
    
    echo "<h3>Test Complete</h3>";
    echo "<p>If all tests pass, the invoice flow should work correctly.</p>";
    echo "<p><a href='new_student_registration.php'>Test Registration Flow</a> | <a href='../accounts/balanceStatement.php'>Test Balance Statement</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
