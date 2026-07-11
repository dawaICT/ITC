<?php
require_once '../db/connect.php'; 

// Initialize database connection if not already done
if (!isset($db)) {
    die("Database connection not established");
}

// Helper to detect an available column name on a table
$__detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        $colEsc = $db->real_escape_string($col);
        if ($res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$colEsc}'")) {
            if ($res->num_rows > 0) { $res->free(); return $col; }
            $res->free();
        }
    }
    return null;
};

// Resolve department join and name column dynamically
$staffDeptCol = $__detectColumn($db, 'staff', ['DeptID', 'deptId', 'department_id']);
$deptIdNumericCol = $__detectColumn($db, 'departments', ['DeptID', 'id', 'department_id']);
$deptIdCodeCol = $__detectColumn($db, 'departments', ['deptId', 'department_code']);
$deptNameCol = $__detectColumn($db, 'departments', ['DeptName', 'deptName', 'department_name', 'name']);

echo "Detected staffDeptCol: $staffDeptCol\n";
echo "Detected deptIdNumericCol: $deptIdNumericCol\n";
echo "Detected deptNameCol: $deptNameCol\n";

$deptJoin = '';
$groupByExpr = "sp.staff_id"; // Default grouping
if ($staffDeptCol && $deptNameCol) {
    if ($deptIdNumericCol) {
        $deptJoin = "LEFT JOIN departments d ON s.`{$staffDeptCol}` = d.`{$deptIdNumericCol}`";
        $deptNameExpr = "d.`{$deptNameCol}`";
        $groupByExpr = "{$deptNameExpr}";
    } else {
        $deptNameExpr = "NULL";
    }
} else {
    $deptNameExpr = "NULL";
}

echo "Generated Join: $deptJoin\n";

// Fetch staff positions data with staff details, position names, and permissions
$query = "SELECT DISTINCT sp.staff_id AS St_id,
          s.staff_id,
          s.title,
          s.Fname,
          s.Lname,
          {$deptNameExpr} AS DeptName,
          GROUP_CONCAT(DISTINCT p.PosName SEPARATOR ', ') AS PosName,
          GROUP_CONCAT(DISTINCT rp.permission_name SEPARATOR ', ') AS Permissions
          FROM staff_positions sp
          JOIN staff s ON sp.staff_id = s.staff_id
          JOIN positions p ON sp.PosID = p.PosID
          {$deptJoin}
          LEFT JOIN role_permissions rp ON sp.PosID = rp.PosID
          GROUP BY sp.staff_id, s.staff_id, s.title, s.Fname, s.Lname, {$groupByExpr}
          ORDER BY s.Fname";

echo "Query: $query\n";

$result = mysqli_query($db, $query);
if (!$result) {
    echo "Query failed: " . mysqli_error($db) . "\n";
} else {
    echo "Query successful! Found " . mysqli_num_rows($result) . " rows.\n";
}
?>
