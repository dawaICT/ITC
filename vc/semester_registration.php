<?php
include "includes/admin.php";

error_reporting(0);

if(!empty($_POST)){
      if(isset($_POST["program_code"], $_POST["SID"], $_POST["semester"], $_POST["year"])) {

        $program_code = trim($_POST["program_code"]);
        $SID = trim($_POST["SID"]);
        $semester = trim($_POST["semester"]);
        $year = trim($_POST["year"]);

        $checkStmt = $db->prepare("SELECT SID FROM semester_registration WHERE SID = ? AND program_code = ? AND semester = ? LIMIT 1");
        $index = null;
        if ($checkStmt) {
            $checkStmt->bind_param("sss", $SID, $program_code, $semester);
            $checkStmt->execute();
            $checkStmt->bind_result($index);
            $checkStmt->fetch();
            $checkStmt->close();
        }

        if (isset($index)) {
            echo"<script>alert('Ooops! This student has already been registered for this semester')</script>";
            echo"<script>window.open('semester_registration.php','_self')</script>";

              }

        else if (!empty($program_code) && !empty($SID) && !empty($semester) && !empty($year)) {
            $insert = $db->prepare("INSERT INTO semester_registration (program_code, SID, semester, year) VALUE(?,?,?,?)");
            $insert ->bind_param("ssss", $program_code, $SID, $semester, $year);

            if ($insert->execute()) {
              echo "<script>alert('Student semester registration successful')</script>";
              echo"<script>window.open('semester_registration.php','_self')</script>";
              }
            }
            else {
              echo "<script>alert('Process failed!')</script>";
              echo"<script>window.open('semester_registration.php','_self')</script>";

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
  <div class="w3-container">
    <div class="row">
      <div class="col-sm-2"></div>
      <div class="col-sm-10 w3-animate-right">
        <div class="row">
          <h3>Student semester registration</h3>
          <hr>
        </div>
        <div class="row">
      <div class="w3-container">
        <form action="semester_registration.php" method="post" role="form">

                <div class="form-group">
                  <lable for="SID">Student #:</lable><br>
                  <input type="text" class="form-control" name="SID" autofocus id="SID" 
                  placeholder="Enter student number" autocomplete="off" required>
                </div>
                <div class="form-group">
                  <lable for="program_code">Program code:</lable><br>
                  <select class="form-control" name="program_code" id="program_code">
                    <option disabled selected>Select course code</option>
                  <?php

                    if($results = $db->query("SELECT program_code FROM programs")) {
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
                    <option><?php echo ($r->program_code); ?></option>

                    <?php 
                    }  
                    ?>
                </select> 
                </div>
                <div class="form-group">
                  <lable for="semester">Semester:</lable><br>
                      <select class="form-control" name="semester" id="semester">
                        <option>1</option>
                        <option>2</option>
                        <option>3</option>
                        <option>4</option>
                        <option>5</option>
                        <option>6</option>
                        <option>7</option>
                        <option>8</option>
                        <option>9</option>
                        <option>10</option>
                        <option>11</option>
                        <option>12</option>
                      </select>
                </div>
                <select class="form-control"  id="year" name="year">
                        <option disabled selected>year of study</option>
                      </select>
                        <script type="text/javascript"
                            src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"> 
                        </script>
                        <script type="text/javascript">
                        let startYear = 2000;
                        let endYear = new Date().getFullYear();
                        for (i = endYear; i > startYear; i--)
                        {
                          $('#year').append($('<option/>').val(i).html(i));
                        }
                        </script>
              <br><br>
                <div class="form-group">
                  <!--input type="submit" value="Submit"-->
                  <button class="btn btn-sm btn-success btn-block" type="submit">Register</button>
                </div>  
              </div>

        </form><!--registration form ends-->
    </div>
  </div>
</body>
<script src="assets/vendor/sweetalert2/dist/sweetalert2.min.js"></script>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
  </script>
  <script src="dist/js/bootstrap.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>