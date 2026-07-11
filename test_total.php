<?php
require 'db/connect.php';
$total_result = $db->query('SELECT COUNT(*) as total FROM processed_applicants');
if($total_result){
    $row = $total_result->fetch_assoc();
    echo 'Total: ' . $row['total'];
} else {
    echo 'Error: ' . $db->error;
}
?>