<?php

require "../db/connect.php";
error_reporting(0);

if(!empty($_POST)){
      if(isset($_POST["deptId"], $_POST["deptName"])) {

        $deptId = trim($_POST["deptId"]);
        $deptName = trim($_POST["deptName"]);

        $index = null;
        $check = $db->prepare("SELECT department_id FROM departments WHERE department_id = ? OR department_name = ?");
        $check->bind_param("ss", $deptId, $deptName);
        $check->execute();
        $check_rs = $check->get_result()->fetch_assoc();
        if ($check_rs) {
          $index = $check_rs['department_id'];
        }

        if (isset($index)) {
            echo"<script>alert('Failed! This department has already been added')</script>";
            echo"<script>window.open('departments.php','_self')</script>";

              }

        else  if (!empty($deptId) && !empty($deptName)) {
            $insert = $db->prepare("INSERT INTO departments (department_id, department_name) VALUES (?,?)");
            $insert ->bind_param("ss", $deptId, $deptName);

            if ($insert->execute()) {
              echo "<script>alert('New department added successfully')</script>";
              echo"<script>window.open('departments.php','_self')</script>";
              }
            }
            else {
              echo "<script>alert('Process failed!')</script>";
              echo"<script>window.open('departments.php','_self')</script>";

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
  <div id="dept" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue"> 
        <span onclick="document.getElementById('dept').style.display='none'" 
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Add New Department</h3>
      </header>
      <div class="w3-container">
        <form action="add_depart.php" method="post" role="form">

                <div class="form-group">
                  <label for="deptId">Department ID:</label><br>
                  <input type="text" class="form-control" name="deptId" autofocus id="deptId" 
                   placeholder="Enter department ID" autocomplete="off" required>

                </div>
                <div class="form-group">
                  <label for="deptName">Department Name:</label><br>
                  <input type="text" class="form-control" name="deptName" autofocus id="deptName" 
                  placeholder="Enter department name" autocomplete="off" required>
                </div>
              <br><br>
                <div class="form-group">
                  <!--input type="submit" value="Submit"-->
                  <button class="btn btn-sm btn-success btn-block" type="submit">Add</button>
                </div>  
              </div>

        </form><!--registration form ends-->
    </div>
  </div>
</body>
<script src="assets/vendor/sweetalert2/dist/sweetalert2.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>