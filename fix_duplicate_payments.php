<?php
/**
 * Clean up duplicate payment records
 * Removes duplicate payments keeping only the first one per student/amount/date
 */

require_once __DIR__ . '/db/connect.php';

echo "=== Cleaning up Duplicate Payment Records ===\n\n";

// Step 1: Find duplicates (same student, amount, date)
echo "Step 1: Finding duplicate payments...\n";
$duplicates = $db->query("
    SELECT Sid, amount_paid, DATE(payment_date) as pay_date, COUNT(*) as cnt, MIN(payment_id) as keep_id
    FROM student_payments
    GROUP BY Sid, amount_paid, DATE(payment_date)
    HAVING cnt > 1
    ORDER BY Sid, pay_date
");

$totalDuplicates = 0;
$deleteIds = [];

while ($row = $duplicates->fetch_assoc()) {
    $totalDuplicates += ($row['cnt'] - 1);
    echo "  - {$row['Sid']} | ZMW {$row['amount_paid']} | {$row['pay_date']}: {$row['cnt']} payments (keeping id={$row['keep_id']})\n";

    // Get IDs to delete (all except the first one)
    $toDelete = $db->query("
        SELECT payment_id FROM student_payments
        WHERE Sid = '{$db->real_escape_string($row['Sid'])}'
        AND amount_paid = {$row['amount_paid']}
        AND DATE(payment_date) = '{$row['pay_date']}'
        AND payment_id != {$row['keep_id']}
    ");

    while ($del = $toDelete->fetch_assoc()) {
        $deleteIds[] = $del['payment_id'];
    }
}

if (empty($deleteIds)) {
    echo "\nNo duplicate payments found!\n";
} else {
    echo "\nTotal duplicate payments to remove: $totalDuplicates\n";

    // Step 2: Delete duplicates
    echo "\nStep 2: Removing duplicate payments...\n";
    $idList = implode(',', $deleteIds);
    $result = $db->query("DELETE FROM student_payments WHERE payment_id IN ($idList)");

    if ($result) {
        echo "  Deleted {$db->affected_rows} duplicate payments.\n";
    } else {
        echo "  ERROR: " . $db->error . "\n";
    }
}

// Step 3: Add unique constraint to prevent future duplicates
echo "\nStep 3: Adding unique constraint...\n";

// Check if constraint already exists
$existingIndex = $db->query("SHOW INDEX FROM student_payments WHERE Key_name = 'unique_student_payment'");
if ($existingIndex->num_rows > 0) {
    echo "  Unique constraint 'unique_student_payment' already exists.\n";
} else {
    // Create a composite unique index on student, amount, and payment_date (without DATE function)
    $result = $db->query("
        ALTER TABLE student_payments
        ADD UNIQUE INDEX unique_student_payment (Sid, amount_paid, payment_date)
    ");

    if ($result) {
        echo "  Added unique constraint on (Sid, amount_paid, payment_date).\n";
    } else {
        echo "  ERROR adding constraint: " . $db->error . "\n";
    }
}

// Step 4: Verify
echo "\nStep 4: Verification...\n";
$check = $db->query("
    SELECT Sid, amount_paid, DATE(payment_date) as pay_date, COUNT(*) as cnt
    FROM student_payments
    GROUP BY Sid, amount_paid, DATE(payment_date)
    HAVING cnt > 1
");

if ($check->num_rows == 0) {
    echo "  ✓ No duplicate payments remain.\n";
} else {
    echo "  ✗ WARNING: Some duplicates still exist!\n";
}

$total = $db->query("SELECT COUNT(*) as cnt FROM student_payments")->fetch_assoc();
echo "  Total payments in table: {$total['cnt']}\n";

echo "\n=== Done ===\n";
