<?php
echo "Starting test\n";
$db = new mysqli('127.0.0.1', 'root', '', 'wucportal');
if ($db->connect_error) {
    echo 'Error: ' . $db->connect_error . "\n";
} else {
    echo 'Connected' . "\n";
    $db->close();
}
echo "Done\n";
?>