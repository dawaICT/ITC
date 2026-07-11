<?php
// Debug session variables for students.php access
session_start();

echo "=== Session Debug for students.php ===\n";
echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . "\n\n";

echo "Session Variables:\n";
foreach ($_SESSION as $key => $value) {
    echo "- $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
}

echo "\nAuthentication Checks:\n";

// Check 1: staff_id
if (!isset($_SESSION['staff_id'])) {
    echo "❌ FAIL: staff_id not set - would redirect to login\n";
} else {
    echo "✅ PASS: staff_id is set (" . $_SESSION['staff_id'] . ")\n";
}

// Check 2: user_role
if (!isset($_SESSION['user_role'])) {
    echo "❌ FAIL: user_role not set - would redirect to login\n";
} elseif ($_SESSION['user_role'] !== 'admin') {
    echo "❌ FAIL: user_role is '" . $_SESSION['user_role'] . "' not 'admin' - would redirect to login\n";
} else {
    echo "✅ PASS: user_role is admin\n";
}

echo "\nDatabase Checks:\n";

// Check if staff exists
require 'db/connect.php';
if ($db && isset($_SESSION['staff_id'])) {
    $staff_id = $_SESSION['staff_id'];
    $result = $db->query("SELECT s.Fname, s.Lname, p.PosName FROM staff s LEFT JOIN staff_positions sp ON s.staff_id = sp.staff_id LEFT JOIN positions p ON sp.PosID = p.PosID WHERE s.staff_id = '$staff_id'");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo "✅ Staff found: " . $row['Fname'] . ' ' . $row['Lname'] . "\n";
        echo "Position: " . ($row['PosName'] ?? 'No position assigned') . "\n";
    } else {
        echo "❌ Staff not found in database\n";
    }
} else {
    echo "❌ Database connection failed or staff_id not set\n";
}

echo "\n=== End Debug ===\n";
?>