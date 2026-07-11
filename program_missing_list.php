<?php
$file = __DIR__ . '/final_report.txt';
$db = new mysqli('127.0.0.1','root','','wucportal');
if ($db->connect_error) {
    file_put_contents($file, "DB connection failed: " . $db->connect_error . "\n", FILE_APPEND);
    exit;
}
$report = "\n--- Program Missing List (" . date('Y-m-d H:i:s') . ") ---\n";
$res = $db->query("SELECT s.SID, s.Fname, s.Lname FROM students s LEFT JOIN student_program sp ON s.SID = sp.Sid WHERE sp.Sid IS NULL LIMIT 50");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $report .= "{$r['SID']} - {$r['Fname']} {$r['Lname']}\n";
    }
} else {
    $report .= "Query failed: " . $db->error . "\n";
}
file_put_contents($file, $report, FILE_APPEND);
$db->close();
echo "Written program missing list to final_report.txt\n";
?>