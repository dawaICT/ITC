<?php
require_once __DIR__ . '/db/connect.php';

$tables_to_check = ['departments', 'sections', 'staff_section_assignments', 'programs', 'students', 'staff'];

foreach ($tables_to_check as $t) {
    echo "=== $t ===\n";
    $r = $db->query("SHOW TABLES LIKE '$t'");
    if ($r && $r->num_rows > 0) {
        echo "STATUS: EXISTS\n";
        $r->free();
        $r2 = $db->query("DESCRIBE `$t`");
        while ($row = $r2->fetch_assoc()) {
            echo "  " . $row['Field'] . " | " . $row['Type'] . " | Key=" . $row['Key'] . " | Null=" . $row['Null'] . " | Default=" . ($row['Default'] ?? 'NULL') . "\n";
        }
        $r2->free();
        $r3 = $db->query("SELECT COUNT(*) as cnt FROM `$t`");
        $cnt = $r3->fetch_assoc()['cnt'];
        echo "  ROW COUNT: $cnt\n";
        $r3->free();
    } else {
        echo "STATUS: MISSING\n";
    }
    echo "\n";
}

// Now test every SQL query from departments.php
echo "=== QUERY VALIDATION ===\n";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$queries = [
    "Q1: COUNT departments" => "SELECT COUNT(*) FROM departments",
    "Q2: COUNT programs" => "SELECT COUNT(*) FROM programs",
    "Q3: COUNT students" => "SELECT COUNT(*) FROM students",
    "Q4: COUNT DISTINCT hod_id" => "SELECT COUNT(DISTINCT hod_id) FROM departments WHERE hod_id IS NOT NULL",
];

// Check if staff_section_assignments exists for the HOS query
$r = $db->query("SHOW TABLES LIKE 'staff_section_assignments'");
$hasSSA = $r && $r->num_rows > 0;
if ($r) $r->free();

if ($hasSSA) {
    $queries["Q5: COUNT HOS from staff_section_assignments"] = "SELECT COUNT(DISTINCT staff_id) FROM staff_section_assignments WHERE role_key = 'head_of_department' AND status = 'active'";
}

// Check if sections exists
$r = $db->query("SHOW TABLES LIKE 'sections'");
$hasSections = $r && $r->num_rows > 0;
if ($r) $r->free();

if ($hasSections && $hasSSA) {
    $queries["Q6: HOS sections join"] = "SELECT s.section_id, s.section_name, s.section_type, s.department_id, ssa.staff_id, CONCAT(st.Fname, ' ', st.Lname) AS hos_name FROM sections s LEFT JOIN staff_section_assignments ssa ON ssa.section_id = s.section_id AND ssa.role_key = 'head_of_department' AND ssa.status = 'active' LEFT JOIN staff st ON st.staff_id = ssa.staff_id WHERE s.status = 'active' ORDER BY s.section_name ASC";
}

foreach ($queries as $label => $sql) {
    try {
        $r = $db->query($sql);
        $rows = $r->num_rows;
        echo "$label: OK ($rows rows)\n";
        $r->free();
    } catch (Throwable $e) {
        echo "$label: FAILED - " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
