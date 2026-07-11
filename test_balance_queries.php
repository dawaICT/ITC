<?php
// Test script for balanceStatement.php database queries
require_once __DIR__ . '/db/connect.php';

// Test with a sample student ID (replace with actual student ID for testing)
$studentId = 'WUC/2020/001'; // Replace with actual student ID

$total_fees = 0;
$total_paid = 0;
$programCode = '';
$feeItems = [];
$registeredPeriods = []; // Array of [year_of_study, semester] pairs
$paymentsByPeriod = []; // Payments grouped by [year_of_study, semester]

try {
    echo "Testing database connection and queries...\n\n";

    // Get student's program code
    $progStmt = $db->prepare("SELECT program_code FROM student_program WHERE Sid = ? LIMIT 1");
    $progStmt->bind_param("s", $studentId);
    $progStmt->execute();
    $result = $progStmt->get_result();
    if ($row = $result->fetch_object()) {
        $programCode = $row->program_code;
        echo "Program Code: $programCode\n";
    } else {
        echo "No program code found for student $studentId\n";
    }
    $progStmt->close();

    // Get year+semester combinations where student has registered (invoice generated)
    if ($programCode) {
        $regStmt = $db->prepare("SELECT DISTINCT year_of_study, semester FROM semester_registration WHERE student_id = ? ORDER BY year_of_study, semester");
        $regStmt->bind_param("s", $studentId);
        $regStmt->execute();
        $regResult = $regStmt->get_result();
        $count = 0;
        while ($p = $regResult->fetch_object()) {
            $registeredPeriods[] = [(int)$p->year_of_study, $p->semester];
            $count++;
        }
        echo "Registered periods found: $count\n";
        $regStmt->close();

        // Get payments grouped by year_of_study and semester
        $payStmt = $db->prepare("SELECT year_of_study, semester_term, SUM(amount_paid) as paid FROM student_payments WHERE Sid = ? AND payment_status = 'completed' AND year_of_study IS NOT NULL AND semester_term IS NOT NULL GROUP BY year_of_study, semester_term");
        $payStmt->bind_param("s", $studentId);
        $payStmt->execute();
        $payResult = $payStmt->get_result();
        $payCount = 0;
        while ($pay = $payResult->fetch_object()) {
            $paymentsByPeriod[$pay->year_of_study][$pay->semester_term] = (float)$pay->paid;
            $payCount++;
        }
        echo "Payment periods found: $payCount\n";
        $payStmt->close();
    }

    if ($programCode && !empty($registeredPeriods)) {
        // Build WHERE clause for year+semester combinations
        $conditions = [];
        $params = [$programCode];
        $types = "s";
        foreach ($registeredPeriods as $period) {
            $conditions[] = "(year_of_study = ? AND semester = ?)";
            $params[] = $period[0];
            $params[] = $period[1];
            $types .= "ii";
        }
        $whereClause = implode(' OR ', $conditions);

        // Calculate total fees only for registered year+semester combinations
        $feesQuery = "SELECT SUM(amount) as t FROM fee_structure WHERE program_code = ? AND status = 'active' AND ($whereClause)";
        $feesStmt = $db->prepare($feesQuery);
        $feesStmt->bind_param($types, ...$params);
        $feesStmt->execute();
        $feesResult = $feesStmt->get_result();
        if ($row = $feesResult->fetch_object()) {
            $total_fees = (float)($row->t ?? 0);
        }
        echo "Total fees: $total_fees\n";
        $feesStmt->close();

        // Get fee items only for registered year+semester combinations
        $itemsQuery = "SELECT fee_description, year_of_study, semester, amount FROM fee_structure WHERE program_code = ? AND status = 'active' AND ($whereClause) ORDER BY year_of_study, semester, fee_description";
        $itemsStmt = $db->prepare($itemsQuery);
        $itemsStmt->bind_param($types, ...$params);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();
        $itemCount = 0;
        while ($item = $itemsResult->fetch_object()) {
            $feeItems[] = $item;
            $itemCount++;
        }
        echo "Fee items found: $itemCount\n";
        $itemsStmt->close();
    }

    // Calculate total completed payments made
    $paidStmt = $db->prepare("SELECT SUM(amount_paid) as t FROM student_payments WHERE Sid = ? AND payment_status = 'completed'");
    $paidStmt->bind_param("s", $studentId);
    $paidStmt->execute();
    $paidResult = $paidStmt->get_result();
    if ($row = $paidResult->fetch_object()) {
        $total_paid = (float)($row->t ?? 0);
    }
    echo "Total paid: $total_paid\n";
    $paidStmt->close();

    echo "\nTest completed successfully! Database queries are working.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    error_log('Balance Statement Test Error: ' . $e->getMessage());
}
?>