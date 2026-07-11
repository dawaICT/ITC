<?php
require_once 'db/connect.php';

echo "<h3>All Roles in access_right Table</h3>";
$result = $db->query("SELECT DISTINCT assigned_access, COUNT(*) as count FROM access_right GROUP BY assigned_access ORDER BY count DESC");

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Role</th><th>Count</th><th>Normalized</th><th>Mapped To</th></tr>";

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

while ($row = $result->fetch_assoc()) {
    $role = $row['assigned_access'];
    $roleNorm = strtolower(trim($role));
    $roleCanon = $map[$roleNorm] ?? $roleNorm;
    echo "<tr>";
    echo "<td>" . htmlspecialchars($role) . "</td>";
    echo "<td>" . $row['count'] . "</td>";
    echo "<td>" . htmlspecialchars($roleNorm) . "</td>";
    echo "<td>" . htmlspecialchars($roleCanon) . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>eLearning Allowed Roles</h3>";
echo "systems_admin, lecturer, head_of_department, dean, registrar";
?>
