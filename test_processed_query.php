<?php
require_once "db/connect.php";

$query = "SELECT id, Fname, Lname, sex, email, mobile, country, nrc_pass, program, mode, intake, year, dte_adm, results, status FROM processed_applicants ORDER BY dte_adm DESC LIMIT 0, 50";
$stmt = $db->prepare($query);
if ($stmt) {
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        echo "Query executed successfully. Rows: " . $result->num_rows . "\n";
        while ($row = $result->fetch_object()) {
            echo "ID: " . $row->id . ", Name: " . $row->Fname . " " . $row->Lname . "\n";
        }
        $result->free();
    } else {
        echo "Execute failed: " . $stmt->error . "\n";
    }
    $stmt->close();
} else {
    echo "Prepare failed: " . $db->error . "\n";
}
?>