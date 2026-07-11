<?php
require_once "../../includes/finance_helpers.php";
ensure_roles($db, ['Accountant','Systems Admin','Dean']);

$rows = [];
$sql = "SELECT s.SID AS student_id, sp.program_code,
               SUM(CASE WHEN DATEDIFF(CURDATE(), si.due_date) BETWEEN 0 AND 30 THEN si.amount ELSE 0 END) AS b_0_30,
               SUM(CASE WHEN DATEDIFF(CURDATE(), si.due_date) BETWEEN 31 AND 60 THEN si.amount ELSE 0 END) AS b_31_60,
               SUM(CASE WHEN DATEDIFF(CURDATE(), si.due_date) BETWEEN 61 AND 90 THEN si.amount ELSE 0 END) AS b_61_90,
               SUM(CASE WHEN DATEDIFF(CURDATE(), si.due_date) > 90 THEN si.amount ELSE 0 END) AS b_90_plus,
               SUM(CASE WHEN si.status='pending' AND si.due_date < CURDATE() THEN si.amount ELSE 0 END) AS total
        FROM finance_student_installments si
        JOIN students s ON s.SID = si.student_id
        LEFT JOIN student_program sp ON sp.Sid = s.SID AND sp.status='active'
        WHERE si.status='pending' AND si.due_date < CURDATE()
        GROUP BY s.SID, sp.program_code
        ORDER BY total DESC
        LIMIT 500";
$res = $db->query($sql);
if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
json_success($rows);


