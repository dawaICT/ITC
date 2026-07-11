<?php

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/role_helpers.php';
error_reporting(0);

wuc_apply_security_headers(false);
if (session_status() === PHP_SESSION_NONE) {
    wuc_configure_session_cookie();
    session_start();
}
if (!isset($_SESSION['user_id']) && isset($_SESSION['staff_id'])) {
    $_SESSION['user_id'] = $_SESSION['staff_id'];
}
if (!isset($_SESSION['user_id']) || (function_exists('canAccessFinance') && !canAccessFinance())) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (isset($_POST['export_csv'])) {

    // Outstanding balance per student, with aging calculated from the latest invoice.
    $sql = "SELECT
                i.SID AS Sid,
                GREATEST(SUM(i.amount) - COALESCE(p.total_paid, 0), 0) AS balance,
                MAX(i.date_generated) AS dte_time,
                DATEDIFF(CURDATE(), MAX(i.date_generated)) AS days_since_entry,
                CASE
                    WHEN DATEDIFF(CURDATE(), MAX(i.date_generated)) < 10 THEN 'Very Low'
                    WHEN DATEDIFF(CURDATE(), MAX(i.date_generated)) BETWEEN 10 AND 30 THEN 'Low'
                    WHEN DATEDIFF(CURDATE(), MAX(i.date_generated)) BETWEEN 31 AND 45 THEN 'Medium'
                    WHEN DATEDIFF(CURDATE(), MAX(i.date_generated)) BETWEEN 46 AND 60 THEN 'High'
                    WHEN DATEDIFF(CURDATE(), MAX(i.date_generated)) BETWEEN 61 AND 90 THEN 'Very High'
                    ELSE 'Critical'
                END AS comment
            FROM invoices i
            LEFT JOIN (
                SELECT student_id, SUM(amount) AS total_paid
                FROM payments
                WHERE LOWER(status) IN ('completed', 'paid', 'success')
                GROUP BY student_id
            ) p ON CONVERT(p.student_id USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(i.SID USING utf8mb4) COLLATE utf8mb4_general_ci
            GROUP BY i.SID, p.total_paid
            HAVING balance > 0
            ORDER BY dte_time DESC, i.SID";

    $result = $db->query($sql);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_account_receivables.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Student ID', 'Balance', 'Entry Date', 'Period (days)', 'Default status']);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row["Sid"],
                $row["balance"],
                $row["dte_time"],
                (int)$row["days_since_entry"],
                $row["comment"],
            ]);
        }
    }

    fclose($output);
    $db->close();
    exit;
}

http_response_code(405);
echo 'Export request must be submitted from the receivables report.';
?>
