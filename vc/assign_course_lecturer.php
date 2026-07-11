<?php

include 'db/connect.php';
error_reporting(0);

if(!empty($_POST)){
      if(isset($_POST["course_code"], $_POST["staff_id"])) {

        $course_code = trim($_POST["course_code"]);
        $staff_id = trim($_POST["staff_id"]);

        $checkStmt = $db->prepare("SELECT course_code FROM course_lecturer WHERE course_code = ? AND staff_id = ? LIMIT 1");
        $index = null;
        if ($checkStmt) {
            $checkStmt->bind_param("ss", $course_code, $staff_id);
            $checkStmt->execute();
            $checkStmt->bind_result($index);
            $checkStmt->fetch();
            $checkStmt->close();
        }

        if (isset($index)) {
            echo"<script>alert('Failed! This course has already been assigned to the lecturer')</script>";
            echo"<script>window.open('courses.php','_self')</script>";

              }

        else  if (!empty($course_code) && !empty($staff_id)) {
            $insert = $db->prepare("INSERT INTO course_lecturer (course_code, staff_id) VALUE(?,?)");
            $insert ->bind_param("ss", $course_code, $staff_id);

            if ($insert->execute()) {
              echo "<script>alert('Course assigned successfully')</script>";
              echo"<script>window.open('courses.php','_self')</script>";
              }
            }
            else {
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
                  <lable for="course_code">Course code:</lable><br>
                  <select class="form-control" name="course_code" id="course_code">
                    <option disabled selected>Select course</option>
                  <?php

                    if($results = $db->query("SELECT * FROM courses")) {
                              if($count = $results->num_rows) {

                              while($row = $results->fetch_object()){

                                $records[] = $row;
                            }

                            $results->free();
                          }
                        }
                  ?>
                  <?php
                    foreach($records as $r) {
                    ?>
                    <option value="<?php echo ($r->Course_Code); ?>"><?php echo ($r->course_name); ?></option>

                    <?php 
                    }  
                    ?>
                </select> 
                </div>
                <div class="form-group">
                  <lable for="staff_id">Lecturer ID:</lable><br>
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
              <br><br>
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