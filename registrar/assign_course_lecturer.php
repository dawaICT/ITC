<?php
require "../db/connect.php";
error_reporting();

if(!empty($_POST)){
      if(isset($_POST["course_code"], $_POST["staff_id"])) {

        $course_code = trim($_POST["course_code"]);
        $staff_id = trim($_POST["staff_id"]);

        if (!empty($course_code) && !empty($staff_id)) {
            require_once __DIR__ . '/../includes/helpers/lecturer_course_helpers.php';
            $assignResult = wuc_assign_lecturer_to_course($db, $staff_id, $course_code, [
                'allow_unmapped' => true,
            ]);
            $changed = (int)($assignResult['inserted'] ?? 0) + (int)($assignResult['updated'] ?? 0);

            if ($changed > 0) {
                require_once __DIR__ . '/../includes/notification_integrations.php';
                wuc_notify_course_assigned($db, $staff_id, $course_code, (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'registrar'));
                $msg = json_encode((string)$assignResult['message'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo "<script>alert({$msg})</script>";
            } else {
                echo "<script>alert('Course module is already assigned to the selected lecturer under all mapping contexts!')</script>";
            }
            echo"<script>window.open('courses.php','_self')</script>";
        } else {
            echo "<script>alert('Process failed!')</script>";
            echo"<script>window.open('courses.php','_self')</script>";
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
</head>   
<body>
  <div id="assign" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue"> 
        <span onclick="document.getElementById('assign').style.display='none'" 
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Assign course to lecturer</h3>
      </header>
      <div class="w3-container">
        <form action="assign_course_lecturer.php" method="post" role="form">

                <div class="form-group">
                  <label for="course_code">Course code:</label><br>
                  <select class="form-control" name="course_code" id="course_code">
                    <option disabled selected>Select course</option>
                  <?php

                    if($results = $db->query("SELECT * FROM courses")) {
                              if($count = $results->num_rows) {

                              while($rows = $results->fetch_object()){

                                $records[] = $rows;
                            }

                            $results->free();
                          }
                        }
                  ?>
                  <?php
                    foreach($records as $r) {
                    ?>
                    <option value="<?php echo ($r->course_code); ?>"><?php echo ($r->course_name); ?></option>

                    <?php 
                    }  
                    ?>
                </select> 
                </div>

                <div class="form-group">
                  <label for="staff_id">Lecturer ID:</label><br>
                  <select class="form-control" name="staff_id" id="staff_id">
                    <option disabled selected>Assign lecturer</option>
                  <?php

                    if($results1 = $db->query("SELECT * FROM staff")) {
                              if($count1 = $results1->num_rows) {

                              while($row = $results1->fetch_object()){

                                $records1[] = $row;
                            }

                            $results1->free();
                          }
                        }
                  ?>
                  <?php
                    foreach($records1 as $r) {
                    ?>
                    <option value="<?php echo ($r->staff_id); ?>"><?php echo ($r->title); ?> <?php echo ($r->Fname); ?> <?php echo ($r->Lname); ?></option>

                    <?php 
                    }  
                    ?>
                </select> 
                </div>
                <div class="form-group">
                  <!--input type="submit" value="Submit"-->
                  <button class="btn btn-sm btn-success btn-block" type="submit">Assign</button>
                </div>  
              </div>

        </form><!--registration form ends-->
    </div>
  </div>
</body>
<script src="assets/vendor/sweetalert2/dist/sweetalert2.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>