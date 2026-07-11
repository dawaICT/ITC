<?php
require 'db/connect.php';

$result = $db->query("SHOW COLUMNS FROM programs LIKE 'term_based'");
if ($result->num_rows > 0) {
    echo "term_based column exists in programs table\n";
} else {
    echo "term_based column does NOT exist in programs table\n";
}
?>