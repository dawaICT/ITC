<?php
// Ensure this script is accessed securely
require_once 'includes/admin.php'; 

// Headers to force download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=students_list_' . date('Y-m-d') . '.csv');

// Open the output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel compatibility with special characters
fwrite($output, "\xEF\xBB\xBF");

// Output column headings
fputcsv($output, array('Student ID', 'First Name', 'Last Name', 'Gender', 'Program', 'Intake Year'));

// Fetch data using the same optimized logic as the main view
if (isset($db)) {
    $sql = "SELECT s.SID, s.Fname, s.Lname, s.sex, sp.intake, p.program_name 
            FROM students s 
            LEFT JOIN student_program sp ON s.SID COLLATE utf8mb4_general_ci = sp.Sid COLLATE utf8mb4_general_ci
            LEFT JOIN programs p ON sp.program_code = p.program_code 
            ORDER BY s.SID ASC";
    
    $result = $db->query($sql);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Clean/Format data if necessary
            $gender = ($row['sex'] == 'M') ? 'Male' : 'Female';
            
            fputcsv($output, array(
                $row['SID'], 
                $row['Fname'], 
                $row['Lname'], 
                $gender,
                $row['program_name'], 
                $row['intake']
            ));
        }
    }
}

fclose($output);
exit();
?>
