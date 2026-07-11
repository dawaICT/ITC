<?php
require_once 'db/connect.php';

$result = $db->query('SELECT COUNT(*) as count FROM online_applicants');
$row = $result->fetch_assoc();
echo 'Total applicants: ' . $row['count'] . "\n";

if ($row['count'] > 0) {
    $result = $db->query('SELECT * FROM online_applicants LIMIT 1');
    $applicant = $result->fetch_assoc();
    echo "Sample applicant data:\n";
    foreach ($applicant as $key => $value) {
        echo "$key: $value\n";
    }
}

$db->close();
?>