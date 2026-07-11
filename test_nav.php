<?php
session_start();
$_SESSION['staff_id'] = 1; // fake session
require_once "admissions/includes/nav.php";
echo "DB set: " . (isset($db) ? "yes" : "no") . "\n";
if (isset($db)) {
    echo "DB type: " . gettype($db) . "\n";
}
?>