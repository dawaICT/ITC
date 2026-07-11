<?php
echo "<h2>eLearning Directory Test</h2>";

echo "<h3>Current Location</h3>";
echo "File: " . __FILE__ . "<br>";
echo "Dir: " . __DIR__ . "<br>";

echo "<h3>Files in elearning directory</h3>";
$files = scandir(__DIR__);
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        echo "- $file<br>";
    }
}

echo "<h3>Session Info</h3>";
if (session_status() === PHP_SESSION_NONE) { session_start(); }
echo "staff_id: " . ($_SESSION['staff_id'] ?? 'NOT SET') . "<br>";

echo "<h3>Test Access</h3>";
echo "<a href='manage.php?course_code=TEST101'>Click to test manage.php</a><br>";
echo "<a href='manage.php'>manage.php without params</a><br>";

echo "<h3>Direct File Check</h3>";
if (file_exists(__DIR__ . '/manage.php')) {
    echo "✓ manage.php EXISTS<br>";
    echo "Size: " . filesize(__DIR__ . '/manage.php') . " bytes<br>";
    echo "Readable: " . (is_readable(__DIR__ . '/manage.php') ? 'YES' : 'NO') . "<br>";
} else {
    echo "✗ manage.php NOT FOUND<br>";
}
?>
