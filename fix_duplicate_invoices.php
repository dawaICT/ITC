<?php
/**
 * Fix Duplicate Invoices
 * 1. Removes duplicate invoices (keeps the first one per student/academic_year/semester)
 * 2. Adds a unique constraint to prevent future duplicates
 */

require_once __DIR__ . '/db/connect.php';

echo "=== Fixing Duplicate Invoices ===\n\n";

// Step 1: Find duplicates
echo "Step 1: Finding duplicates...\n";
$duplicates = $db->query("
    SELECT student_id, academic_year, semester, COUNT(*) as cnt, MIN(id) as keep_id
    FROM invoices 
    GROUP BY student_id, academic_year, semester 
    HAVING cnt > 1
");

$totalDuplicates = 0;
$deleteIds = [];

while ($row = $duplicates->fetch_assoc()) {
    $totalDuplicates += ($row['cnt'] - 1);
    echo "  - {$row['student_id']} | {$row['academic_year']} Sem {$row['semester']}: {$row['cnt']} invoices (keeping id={$row['keep_id']})\n";
    
    // Get IDs to delete (all except the first one)
    $toDelete = $db->query("
        SELECT id FROM invoices 
        WHERE student_id = '{$db->real_escape_string($row['student_id'])}' 
        AND academic_year = '{$db->real_escape_string($row['academic_year'])}' 
        AND semester = '{$db->real_escape_string($row['semester'])}'
        AND id != {$row['keep_id']}
    ");
    
    while ($del = $toDelete->fetch_assoc()) {
        $deleteIds[] = $del['id'];
    }
}

if (empty($deleteIds)) {
    echo "\nNo duplicates found!\n";
} else {
    echo "\nTotal duplicate invoices to remove: $totalDuplicates\n";
    
    // Step 2: Delete duplicates
    echo "\nStep 2: Removing duplicates...\n";
    $idList = implode(',', $deleteIds);
    $result = $db->query("DELETE FROM invoices WHERE id IN ($idList)");
    
    if ($result) {
        echo "  Deleted {$db->affected_rows} duplicate invoices.\n";
    } else {
        echo "  ERROR: " . $db->error . "\n";
    }
}

// Step 3: Add unique constraint to prevent future duplicates
echo "\nStep 3: Adding unique constraint...\n";

// Check if constraint already exists
$existingIndex = $db->query("SHOW INDEX FROM invoices WHERE Key_name = 'unique_student_semester'");
if ($existingIndex->num_rows > 0) {
    echo "  Unique constraint 'unique_student_semester' already exists.\n";
} else {
    $result = $db->query("
        ALTER TABLE invoices 
        ADD UNIQUE INDEX unique_student_semester (student_id, academic_year, semester)
    ");
    
    if ($result) {
        echo "  Added unique constraint on (student_id, academic_year, semester).\n";
    } else {
        echo "  ERROR adding constraint: " . $db->error . "\n";
    }
}

// Verify
echo "\nStep 4: Verification...\n";
$check = $db->query("
    SELECT student_id, academic_year, semester, COUNT(*) as cnt
    FROM invoices 
    GROUP BY student_id, academic_year, semester 
    HAVING cnt > 1
");

if ($check->num_rows == 0) {
    echo "  ✓ No duplicate invoices remain.\n";
} else {
    echo "  ✗ WARNING: Some duplicates still exist!\n";
}

$total = $db->query("SELECT COUNT(*) as cnt FROM invoices")->fetch_assoc();
echo "  Total invoices in table: {$total['cnt']}\n";

echo "\n=== Done ===\n";
