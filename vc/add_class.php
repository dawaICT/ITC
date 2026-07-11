<?php

include 'db/connect.php';
error_reporting(0);

if(!empty($_POST)){
      if(isset($_POST["program_code"], $_POST["program_name"])) {

        $program_code = trim($_POST["program_code"]);
        $program_name = trim($_POST["program_name"]);

        $checkStmt = $db->prepare("SELECT program_code FROM programs WHERE program_code = ? AND program_name = ? LIMIT 1");
        $index = null;
        if ($checkStmt) {
            $checkStmt->bind_param("ss", $program_code, $program_name);
            $checkStmt->execute();
            $checkStmt->bind_result($index);
            $checkStmt->fetch();
            $checkStmt->close();
        }

        if (isset($index)) {
            echo"<script>alert('Failed! This program has already been added')</script>";
            echo"<script>window.open('programs.php','_self')</script>";

              }

        else  if (!empty($program_code) && !empty($program_name)) {
            $insert = $db->prepare("INSERT INTO programs (program_code, program_name) VALUE(?,?)");
            $insert ->bind_param("ss", $program_code, $program_name);

            if ($insert->execute()) {
              echo "<script>alert('New program added successfully')</script>";
              echo"<script>window.open('programs.php','_self')</script>";
              }
            }
            else {
              echo "<script>alert('Process failed!')</script>";
              echo"<script>window.open('programs.php.php','_self')</script>";

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
  <div id="Class" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue"> 
        <span onclick="document.getElementById('Class').style.display='none'" 
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Add new program</h3>
      </header>
      <div class="w3-container">
        <form action="add_class.php" method="post" role="form">

                <div class="form-group">
                  <lable for="program_code">Program code:</lable><br>
                  <input type="text" class="form-control" name="program_code" autofocus id="program_code" 
                   placeholder="Enter program code" autocomplete="off" required>

                </div>
                <div class="form-group">
                  <lable for="program_name">Program name:</lable><br>
                  <input type="text" class="form-control" name="program_name" autofocus id="program_name" 
                  placeholder="Enter program name" autocomplete="off" required>
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