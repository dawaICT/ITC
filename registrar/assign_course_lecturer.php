<?php
require "../db/connect.php";
error_reporting();

if(!empty($_POST)){
      if(isset($_POST["course_code"], $_POST["staff_id"])) {

        $course_code = trim($_POST["course_code"]);
        $staff_id = trim($_POST["staff_id"]);

        if (!empty($course_code) && !empty($staff_id)) {
            // Retrieve current academic year
            $curAy = date('Y');
            if ($st = $db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = 'current_academic_year' LIMIT 1")) {
                if ($st->execute()) {
                    $res = $st->get_result();
                    if ($res && $res->num_rows) {
                        $curAy = (string)$res->fetch_assoc()['setting_value'];
                    }
                }
                $st->close();
            }

            // Retrieve course mappings from program_courses
            $pc_query = "SELECT program_code, year, semester FROM program_courses WHERE course_code = ?";
            $pc_stmt = $db->prepare($pc_query);
            $pc_stmt->bind_param("s", $course_code);
            $pc_stmt->execute();
            $pc_result = $pc_stmt->get_result();
            
            $inserted = 0;
            
            if ($pc_result->num_rows > 0) {
                while ($pc_row = $pc_result->fetch_assoc()) {
                    $prog = $pc_row['program_code'];
                    $y = (int)$pc_row['year'];
                    $sem = (string)$pc_row['semester'];
                    
                    // Get program academic structure
                    $p_info_stmt = $db->prepare("SELECT academic_structure FROM programs WHERE program_code = ? LIMIT 1");
                    $p_info_stmt->bind_param("s", $prog);
                    $p_info_stmt->execute();
                    $p_info = $p_info_stmt->get_result()->fetch_assoc();
                    $p_info_stmt->close();
                    
                    $academic_structure = $p_info['academic_structure'] ?? 'certificate_term';
                    
                    if ($academic_structure === 'short_course') {
                        $check_query = "SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ? AND program_code = ?";
                        $check_stmt = $db->prepare($check_query);
                        $check_stmt->bind_param("sss", $course_code, $staff_id, $prog);
                        $check_stmt->execute();
                        $has_assignment = $check_stmt->get_result()->num_rows > 0;
                        $check_stmt->close();
                        
                        if (!$has_assignment) {
                            $insert = $db->prepare("INSERT INTO course_lecturer (course_code, staff_id, program_code, academic_year, status) VALUES (?, ?, ?, ?, 'active')");
                            $insert->bind_param("ssss", $course_code, $staff_id, $prog, $curAy);
                            $insert->execute();
                            $insert->close();
                            $inserted++;
                        }
                    } else {
                        $check_query = "SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ? AND program_code = ? AND year_of_study = ? AND semester = ?";
                        $check_stmt = $db->prepare($check_query);
                        $check_stmt->bind_param("sssis", $course_code, $staff_id, $prog, $y, $sem);
                        $check_stmt->execute();
                        $has_assignment = $check_stmt->get_result()->num_rows > 0;
                        $check_stmt->close();
                        
                        if (!$has_assignment) {
                            $insert = $db->prepare("INSERT INTO course_lecturer (course_code, staff_id, program_code, academic_year, year_of_study, semester, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                            $insert->bind_param("ssssis", $course_code, $staff_id, $prog, $curAy, $y, $sem);
                            $insert->execute();
                            $insert->close();
                            $inserted++;
                        }
                    }
                }
            } else {
                $check_query = "SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ?";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bind_param("ss", $course_code, $staff_id);
                $check_stmt->execute();
                $has_assignment = $check_stmt->get_result()->num_rows > 0;
                $check_stmt->close();
                
                if (!$has_assignment) {
                    $insert = $db->prepare("INSERT INTO course_lecturer (course_code, staff_id, academic_year, status) VALUES (?, ?, ?, 'active')");
                    $insert->bind_param("sss", $course_code, $staff_id, $curAy);
                    $insert->execute();
                    $insert->close();
                    $inserted++;
                }
            }
            $pc_stmt->close();
            
            if ($inserted > 0) {
                require_once __DIR__ . '/../includes/notification_integrations.php';
                wuc_notify_course_assigned($db, $staff_id, $course_code, (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'registrar'));
                echo "<script>alert('Lecturer assigned course module successfully! ($inserted context mappings created)')</script>";
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