<?php
require_once 'db/connect.php';

$db->query("UPDATE online_applicants SET status = 'pending' WHERE status NOT IN ('pending', 'accepted', 'rejected')");
echo 'Updated existing applicants status to pending where invalid.' . "\n";

$db->close();
?>