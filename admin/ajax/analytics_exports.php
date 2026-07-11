<?php
/**
 * Decision Support & Analytics Dashboard - CSV Exports
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/audit.php';

wuc_require_any_permission(
    ['reports.view', 'reports.manage'],
    'You do not have permission to export analytics reports.',
    '/wucportal/portal_selection.php'
);

$export = $_GET['export'] ?? '';
if (function_exists('audit_log_current_user') && in_array($export, ['workload', 'risks', 'fees'], true)) {
    audit_log_current_user($db, 'reports.analytics.export', [
        'export' => $export,
    ]);
}

if ($export === 'workload') {
if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="lecturer_workload_' . date('Y-m-d') . '.csv"');
    }
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Staff ID', 'First Name', 'Last Name', 'Active Courses Assigned']);
    
    $sql = "SELECT st.staff_id, st.Fname, st.Lname, COUNT(cl.course_code) as courses_count 
            FROM staff st
            INNER JOIN course_lecturer cl ON cl.staff_id = st.staff_id
            WHERE cl.status = 'active' AND st.role = 'Lecturer'
            GROUP BY st.staff_id, st.Fname, st.Lname
            ORDER BY courses_count DESC";
            
    if ($res = $db->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                $row['staff_id'],
                $row['Fname'],
                $row['Lname'],
                $row['courses_count']
            ]);
        }
        $res->free();
    }
    fclose($output);
    exit;
}

if ($export === 'risks') {
if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="academic_risks_' . date('Y-m-d') . '.csv"');
    }
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'First Name', 'Last Name', 'Risk Level', 'Risk Reasons', 'Last Evaluated']);
    
    $sql = "SELECT r.student_id, s.Fname, s.Lname, r.risk_level, r.risk_reason, r.generated_at
            FROM student_risk_summary r
            INNER JOIN students s ON r.student_id = s.SID
            ORDER BY FIELD(r.risk_level, 'High', 'Medium', 'Low'), r.generated_at DESC";
            
    if ($res = $db->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                $row['student_id'],
                $row['Fname'],
                $row['Lname'],
                $row['risk_level'],
                $row['risk_reason'],
                $row['generated_at']
            ]);
        }
        $res->free();
    }
    fclose($output);
    exit;
}

if ($export === 'fees') {
if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fee_collection_' . date('Y-m-d') . '.csv"');
    }
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'First Name', 'Last Name', 'Total Payable (ZMW)', 'Amount Paid (ZMW)', 'Balance (ZMW)']);
    
    $sql = "SELECT f.student_id, s.Fname, s.Lname, f.total_payable, f.amount_paid, f.balance
            FROM student_fee_accounts f
            INNER JOIN students s ON f.student_id = s.SID
            ORDER BY f.balance DESC";
            
    if ($res = $db->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                $row['student_id'],
                $row['Fname'],
                $row['Lname'],
                $row['total_payable'],
                $row['amount_paid'],
                $row['balance']
            ]);
        }
        $res->free();
    }
    fclose($output);
    exit;
}

echo "Invalid export type.";
?>
