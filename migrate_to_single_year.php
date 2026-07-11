<?php
/**
 * Migrate Academic Year to Single Year Format
 * 
 * Converts academic year values from dual format (2025/2026 or 2025-2026)
 * to single year format (2025) across all tables
 */

require 'db/connect.php';

echo "=== Academic Year Migration to Single Year Format ===\n";
echo "Converting all academic years to single year (e.g., 2025)\n\n";

function extractYear($value) {
    if (empty($value) || $value === null) {
        return null;
    }
    
    $value = trim($value);
    
    // Already single year
    if (preg_match('/^(\d{4})$/', $value, $matches)) {
        $year = (int)$matches[1];
        // Valid academic year (2000-2100)
        if ($year >= 2000 && $year < 2100) {
            return $year;
        }
        // Single digit like "1" is not an academic year
        return null;
    }
    
    // Extract first year from YYYY/YYYY format
    if (preg_match('/^(\d{4})\/\d{4}$/', $value, $matches)) {
        return (int)$matches[1];
    }
    
    // Extract first year from YYYY-YYYY format
    if (preg_match('/^(\d{4})-\d{4}$/', $value, $matches)) {
        return (int)$matches[1];
    }
    
    return null;
}

$db->begin_transaction();

try {
    // 1. Migrate student_program.intake
    echo "1. Migrating student_program.intake...\n";
    $result = $db->query("SELECT Sid, intake FROM student_program WHERE intake IS NOT NULL");
    $updates = 0;
    while ($row = $result->fetch_assoc()) {
        $new_intake = extractYear($row['intake']);
        if ($new_intake !== null && $new_intake != $row['intake']) {
            $stmt = $db->prepare("UPDATE student_program SET intake = ? WHERE Sid = ?");
            $stmt->bind_param("is", $new_intake, $row['Sid']);
            $stmt->execute();
            $updates++;
            echo "   {$row['Sid']}: '{$row['intake']}' → {$new_intake}\n";
        }
    }
    echo "   ✓ Updated $updates records\n\n";

    // 2. Migrate invoices.academic_year
    echo "2. Migrating invoices.academic_year...\n";
    $result = $db->query("SELECT id, academic_year FROM invoices WHERE academic_year IS NOT NULL");
    $updates = 0;
    while ($row = $result->fetch_assoc()) {
        $new_year = extractYear($row['academic_year']);
        if ($new_year !== null && $new_year != $row['academic_year']) {
            $stmt = $db->prepare("UPDATE invoices SET academic_year = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_year, $row['id']);
            $stmt->execute();
            $updates++;
            if ($updates <= 5) {
                echo "   Invoice {$row['id']}: '{$row['academic_year']}' → {$new_year}\n";
            }
        }
    }
    echo "   ✓ Updated $updates records\n\n";

    // 3. Migrate student_courses.academic_year (only for valid student references)
    echo "3. Migrating student_courses.academic_year...\n";
    $result = $db->query("
        SELECT sc.id, sc.academic_year 
        FROM student_courses sc
        INNER JOIN students s ON sc.student_id = s.SID
        WHERE sc.academic_year IS NOT NULL
    ");
    $updates = 0;
    while ($row = $result->fetch_assoc()) {
        $new_year = extractYear($row['academic_year']);
        if ($new_year !== null && $new_year != $row['academic_year']) {
            $stmt = $db->prepare("UPDATE student_courses SET academic_year = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_year, $row['id']);
            $stmt->execute();
            $updates++;
            if ($updates <= 5) {
                echo "   Course {$row['id']}: '{$row['academic_year']}' → {$new_year}\n";
            }
        }
    }
    echo "   ✓ Updated $updates records\n\n";

    // 4. Migrate semester_registration.academic_year (if exists)
    $tables = $db->query("SHOW TABLES LIKE 'semester_registration'");
    if ($tables && $tables->num_rows > 0) {
        echo "4. Migrating semester_registration.academic_year...\n";
        $result = $db->query("SELECT id, academic_year FROM semester_registration WHERE academic_year IS NOT NULL");
        $updates = 0;
        while ($row = $result->fetch_assoc()) {
            $new_year = extractYear($row['academic_year']);
            if ($new_year !== null && $new_year != $row['academic_year']) {
                $stmt = $db->prepare("UPDATE semester_registration SET academic_year = ? WHERE id = ?");
                $stmt->bind_param("ii", $new_year, $row['id']);
                $stmt->execute();
                $updates++;
                if ($updates <= 5) {
                    echo "   Registration {$row['id']}: '{$row['academic_year']}' → {$new_year}\n";
                }
            }
        }
        echo "   ✓ Updated $updates records\n\n";
    }

    // 5. Migrate student_payments.academic_year (if column exists)
    $check = $db->query("SHOW COLUMNS FROM student_payments LIKE 'academic_year'");
    if ($check && $check->num_rows > 0) {
        echo "5. Migrating student_payments.academic_year...\n";
        $result = $db->query("SELECT payment_id, academic_year FROM student_payments WHERE academic_year IS NOT NULL");
        $updates = 0;
        while ($row = $result->fetch_assoc()) {
            $new_year = extractYear($row['academic_year']);
            if ($new_year !== null && $new_year != $row['academic_year']) {
                $stmt = $db->prepare("UPDATE student_payments SET academic_year = ? WHERE payment_id = ?");
                $stmt->bind_param("ii", $new_year, $row['payment_id']);
                $stmt->execute();
                $updates++;
                if ($updates <= 5) {
                    echo "   Payment {$row['payment_id']}: '{$row['academic_year']}' → {$new_year}\n";
                }
            }
        }
        echo "   ✓ Updated $updates records\n\n";
    }

    $db->commit();
    echo "\n✓ All academic year values migrated to single year format\n";
    echo "✓ Changes committed successfully\n\n";

    // Show summary
    echo "=== Verification ===\n";
    echo "Checking data types:\n";
    $tables_to_check = [
        'student_program' => 'intake',
        'invoices' => 'academic_year',
        'student_courses' => 'academic_year'
    ];
    
    foreach ($tables_to_check as $table => $column) {
        $result = $db->query("SELECT DISTINCT $column FROM $table ORDER BY $column DESC LIMIT 5");
        echo "$table.$column: ";
        $values = [];
        while ($row = $result->fetch_row()) {
            $values[] = $row[0];
        }
        echo implode(', ', $values) . "\n";
    }

} catch (Exception $e) {
    $db->rollback();
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "✗ All changes rolled back\n";
}

$db->close();
?>
