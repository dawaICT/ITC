
<?php
include 'db/connect.php';
error_reporting(0);

?>

<!DOCTYPE html>   
<html lang="en">   
<head>   
<meta charset="utf-8">      
<meta name="description" content="Bootstrap.">   
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.10.2/css/jquery.dataTables.min.css"></style>
<script type="text/javascript" src="https://cdn.datatables.net/1.10.2/js/jquery.dataTables.min.js"></script>
<script src="dist/js/bootstrap.min.js"></script>
<link rel="stylesheet" type="text/css" href="w3/w3.css">
    <link rel="stylesheet" type="text/css" href="css_main/admin.css">
    <link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="dist/css/bootstrap-theme.min.css"></head>  
<body>  
<div class="container">
  <div class="row">
    <div class="col-sm-11 w3-animate-right">
                  <?php
                      $number = 1;

                       if($results = $db->query("SELECT * FROM students")) {
                              if($count = $results->num_rows) {

                              while($row = $results->fetch_object()){

                                $records[] = $row;
                            }

                            $results->free();
                          }
                          else {
                            echo "<script>alert('No records found in this course!')</script>";
                            die();
                          }
                        }

                    ?>
            <table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light">
                          <tr>
                            <th>No.</th>
                            <th>Student No</th>
                              <th>Names</th>
                              <th>Gender</th>
                              <th>Program</th>
                              <th>Level</th>
                              <th>Intake</th>
                              <th>Study mode</th>
                              <th class="w3-center">Action</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php
                          foreach($records as $r) {
                            ?>
                              <tr>
                                <td><?php echo $number++; ?>.</td>
                                <td><?php echo ($r->SID); ?></td>
                                <td><?php echo ($r->Fname); ?> <?php echo ($r->Lname); ?></td>
                                <td><?php echo ($r->sex); ?></td>
                                <td><?php echo ($r->program); ?></td>
                                <td><?php echo ($r->level); ?></td>
                                <td><?php echo ($r->intake); ?></td>
                                <td><?php echo ($r->study_mode); ?></td>
                                <td class="w3-center">
                                  <a class='btn w3-green w3-tiny' href="view_student_admin.php?view=<?php echo $r->SID?>"><span class="glyphicon glyphicon-eye-open"></span>
                                    </a>
                                    <a class='btn w3-light-blue w3-tiny' href="termly_progress.php?view=<?php echo $r->SID?>"><span class="glyphicon glyphicon-edit"></span>
                                    </a>
                                    <a class='btn w3-red w3-tiny' href="termly_progress.php?view=<?php echo $r->SID?>"><span class="glyphicon glyphicon-trash"></span>
                                    </a>

                                </td>

                               </tr>
                          <?php 
                          }  
                          ?>
                        </tbody>
                      </table>
                    </div>
    </div>
</body>  
<script>
$(document).ready(function(){
    $('#myTable').dataTable();
});
</script>
</html> 