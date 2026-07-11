<?php
session_start();
require_once 'db/connect.php';

echo "<h3>Session Debug</h3>";
echo "staff_id: " . ($_SESSION['staff_id'] ?? 'NOT SET') . "<br>";
echo "Sid (student): " . ($_SESSION['Sid'] ?? 'NOT SET') . "<br>";

if (isset($_SESSION['staff_id'])) {
    $staffId = $_SESSION['staff_id'];
    $stmt = $db->prepare("SELECT staff_id, assigned_access FROM access_right WHERE staff_id = ?");
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<h3>Access Rights</h3>";
    if ($row = $result->fetch_assoc()) {
        echo "Staff ID: " . htmlspecialchars($row['staff_id']) . "<br>";
        echo "Assigned Access: " . htmlspecialchars($row['assigned_access']) . "<br>";
        
        // Show role mapping
        $role = $row['assigned_access'];
        $roleNorm = strtolower(trim($role));
        $map = [
            'systems admin' => 'systems_admin',
            'admin' => 'systems_admin',
            'lecturer' => 'lecturer',
            'assistant lecturer' => 'lecturer',
            'part time lecturer' => 'lecturer',
            'part-time lecturer' => 'lecturer',
            'tutor' => 'lecturer',
            'instructor' => 'lecturer',
            'head of department' => 'head_of_department',
            'dean' => 'dean',
            'registrar' => 'registrar',
        ];
        $roleCanon = $map[$roleNorm] ?? $roleNorm;
        echo "Normalized Role: " . htmlspecialchars($roleNorm) . "<br>";
        echo "Canonical Role: " . htmlspecialchars($roleCanon) . "<br>";
        
        echo "<h3>Allowed Roles for eLearning</h3>";
        $allowed = ['systems_admin','lecturer','head_of_department','dean','registrar'];
        echo implode(', ', $allowed) . "<br>";
        
        if (in_array($roleCanon, $allowed)) {
            echo "<br><strong style='color:green'>✓ Access should be GRANTED</strong>";
        } else {
            echo "<br><strong style='color:red'>✗ Access DENIED - Role not in allowed list</strong>";
        }
    } else {
        echo "<strong>No access rights found for this staff_id</strong>";
    }
}
?>
