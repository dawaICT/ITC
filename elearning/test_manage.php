<?php
// Test what URL is being accessed
echo "<h2>Debug Info</h2>";
echo "<strong>Request URI:</strong> " . htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'Not set') . "<br>";
echo "<strong>Script Name:</strong> " . htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? 'Not set') . "<br>";
echo "<strong>File:</strong> " . __FILE__ . "<br>";
echo "<strong>Session staff_id:</strong> ";
session_start();
echo htmlspecialchars($_SESSION['staff_id'] ?? 'Not set') . "<br>";

echo "<h3>Testing includes</h3>";

try {
    require_once __DIR__ . '/../lecturers/includes/guard.php';
    echo "✓ guard.php loaded<br>";
} catch (Exception $e) {
    echo "✗ guard.php failed: " . htmlspecialchars($e->getMessage()) . "<br>";
}

try {
    require_once __DIR__ . '/../db/connect.php';
    echo "✓ connect.php loaded<br>";
} catch (Exception $e) {
    echo "✗ connect.php failed: " . htmlspecialchars($e->getMessage()) . "<br>";
}

try {
    require_once __DIR__ . '/../includes/permissions.php';
    echo "✓ permissions.php loaded<br>";
} catch (Exception $e) {
    echo "✗ permissions.php failed: " . htmlspecialchars($e->getMessage()) . "<br>";
}

echo "<h3>Direct link test</h3>";
echo "<a href='manage.php?course_code=CS101'>Test manage.php with course code</a>";
?>
