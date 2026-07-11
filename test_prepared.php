<?php
require 'db/connect.php';
$query = "SELECT 
    id, Fname, Lname, sex, email, mobile, country, nrc_pass, 
    program, mode, intake, year, dte_adm, results, status 
    FROM processed_applicants 
    ORDER BY dte_adm DESC LIMIT ?, ?";
$stmt = $db->prepare($query);
if ($stmt) {
    $offset = 0;
    $per_page = 50;
    $stmt->bind_param("ii", $offset, $per_page);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        echo "Rows: " . $result->num_rows . "\n";
        while ($row = $result->fetch_assoc()) {
            echo "ID: " . $row['id'] . "\n";
        }
    } else {
        echo "Execute failed: " . $stmt->error . "\n";
    }
    $stmt->close();
} else {
    echo "Prepare failed: " . $db->error . "\n";
}
?>