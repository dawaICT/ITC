<?php
require "../db/connect.php";

if(isset($_POST["Export"])){

     header('Content-Type: text/csv; charset=utf-8');  
     header('Content-Disposition: attachment; filename=data.csv');  
     $output = fopen("php://output", "w");  
     fputcsv($output, array('SID', 'First name', 'Last name', 'Program', 'Amount paid','Balance',
     'Channel','Reference No','Date paid'));  

     $query = "SELECT * from student_payments INNER JOIN student_program
     ON student_payments.Sid COLLATE utf8mb4_general_ci = student_program.Sid COLLATE utf8mb4_general_ci INNER JOIN students
     ON student_program.Sid COLLATE utf8mb4_general_ci = students.SID COLLATE utf8mb4_general_ci
     WHERE student_payments.channel='".$_SESSION['channel']."' 
     ORDER BY payment_id DESC";  
     $result = mysqli_query($db, $query);  
     while($row = mysqli_fetch_assoc($result))  
     {  
        fputcsv($output, $row);  
     }  
     fclose($output);  
} 

?>