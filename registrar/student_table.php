
<?php
require_once __DIR__ . '/../db/connect.php';
error_reporting(0);

?>

<!DOCTYPE html>   
<html lang="en">   
<head>   
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Bootstrap.">     
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script type="text/javascript" src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script type="text/javascript" src="dist/js/bootstrap.min.js"></script>
<link rel="stylesheet" type="text/css" href="w3/w3.css">
    <link rel="stylesheet" type="text/css" href="css_main/admin.css">
    <link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
</head>  
<body>  
<div class="container-fluid px-3">
  <div class="row">
    <div class="col-12 w3-animate-right">
                  <?php
                      $number = 1;
                      $records = [];

                       if($results = $db->query("SELECT * FROM students")) {
                              if($count = $results->num_rows) {

                              while($row = $results->fetch_object()){

                                $records[] = $row;
                            }

                            $results->free();
                          }
                          else {
                            echo "<div class='alert alert-info'>No student records found.</div>";
                          }
                        }

                    ?>
            <div class="table-responsive">
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
                                <td><?php echo htmlspecialchars($r->SID ?? ''); ?></td>
                                <td><?php echo htmlspecialchars(trim(($r->Fname ?? '') . ' ' . ($r->Lname ?? ''))); ?></td>
                                <td><?php echo htmlspecialchars($r->sex ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($r->program ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($r->level ?? $r->year ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($r->intake ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($r->study_mode ?? $r->mode ?? ''); ?></td>
                                <td class="w3-center">
                                  <a class='btn w3-green w3-tiny' href="view_student_admin.php?view=<?php echo urlencode($r->SID ?? '') ?>"><span class="glyphicon glyphicon-eye-open"></span>
                                    </a>
                                    <a class='btn w3-light-blue w3-tiny' href="termly_progress.php?view=<?php echo urlencode($r->SID ?? '') ?>"><span class="glyphicon glyphicon-edit"></span>
                                    </a>
                                    <a class='btn w3-red w3-tiny' href="termly_progress.php?view=<?php echo urlencode($r->SID ?? '') ?>"><span class="glyphicon glyphicon-trash"></span>
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
    </div>
</body>  
<script>
$(document).ready(function(){
    $('#myTable').DataTable({
        scrollX: true,
        autoWidth: false
    });
});
</script>
</html> 
