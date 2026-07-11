<?php
// 1. Session and Admin Init (the shared admin guard owns session startup)
require_once dirname(__FILE__) . "/admin.php";

// 2. Production Error Handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/../logs/error.log');

// 3. Robust Database Check
if (!isset($db) || $db->connect_error) {
    die("Database connection failed. Please check your configuration.");
}

// 4. Optimized Column Detection (Cached for current request)
// Kept for backward compatibility with pages expecting these variables
$__detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
    $res = $db->query("SHOW COLUMNS FROM `{$table}`");
    if (!$res) return null;
    $existing = [];
    while ($row = $res->fetch_assoc()) { $existing[] = $row['Field']; }
    $res->free();
    
    foreach ($candidates as $col) {
        if (in_array($col, $existing)) return $col;
    }
    return null;
};

// Map Columns
$staffDeptCol = $__detectColumn($db, 'staff', ['DeptID', 'deptId', 'department_id']);
$deptIdNumericCol = $__detectColumn($db, 'departments', ['DeptID', 'id', 'department_id']);
$deptNameCol = $__detectColumn($db, 'departments', ['DeptName', 'deptName', 'department_name', 'name']);

// Build Join Logic (Preserved for legacy pages relying on $deptJoin)
$deptJoin = '';
if ($staffDeptCol && $deptIdNumericCol) {
    $deptJoin = "LEFT JOIN departments d ON s.`{$staffDeptCol}` = d.`{$deptIdNumericCol}`";
}
$deptNameExpr = ($deptJoin !== '' && $deptNameCol) ? "d.`{$deptNameCol}`" : "NULL";

// 5. Fetch User Data (Preserved for pages relying on $user object with department info)
$user = null;
$user_name = 'Guest';
$sid = $_SESSION['staff_id'] ?? null;

if ($sid) {
    $sql = "SELECT s.*, {$deptNameExpr} AS deptName FROM staff s {$deptJoin} WHERE s.staff_id = ? LIMIT 1";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_object()) {
            $user = $row;
            $user_name = trim(($user->Fname ?? '') . ' ' . ($user->Lname ?? ''));
        }
        $stmt->close();
    }
}

// Global Variables
$base_url = "/wucportal/admin";
$root_url = "/wucportal";
$asset_ver = "1.0.3"; 

// 6. Output Navigation using Unified System
// This replaces the HTML output, sidebar inclusion, and head section
require_once dirname(__FILE__) . '/nav.php';
?>
