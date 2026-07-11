<?php

include 'db/connect.php';
error_reporting(0);

if(!empty($_POST)){
      if(isset($_POST["SID"], $_POST["course_code"], $_POST["semester"], $_POST["assess_type"], $_POST["assess_num"], $_POST["marks"], $_POST["year"])) {

        $SID = trim($_POST["SID"]);
        $course_code = trim($_POST["course_code"]);
        $semester = trim($_POST["semester"]);
        $assess_type = trim($_POST["assess_type"]);
        $assess_num = trim($_POST["assess_num"]);
        $marks = trim($_POST["marks"]);
        $year = trim($_POST["year"]);

        // Enforce: student must be registered for this course in the academic year.
        $regExists = false;
        $regCheckSql = "SELECT 1 FROM course_registration
                        WHERE Sid = ?
                          AND course_code = ?
                          AND (CAST(academic_year AS CHAR) = ? OR CAST(Year AS CHAR) = ?)
                          AND COALESCE(is_active, 1) = 1
                        LIMIT 1";
        if ($regStmt = $db->prepare($regCheckSql)) {
            $regStmt->bind_param('ssss', $SID, $course_code, $year, $year);
            $regStmt->execute();
            $regStmt->store_result();
            $regExists = $regStmt->num_rows > 0;
            $regStmt->close();
        }
        if (!$regExists) {
            echo"<script>alert('Student is not registered for this course in the selected academic year.')</script>";
            echo"<script>window.open('assessments.php','_self')</script>";
            exit;
        }

        else  if (!empty($SID) && !empty($course_code) && !empty($semester) && !empty($assess_type) && !empty($assess_num) && !empty($marks) && !empty($year)) {
            $insert = $db->prepare("INSERT INTO assessments (SID, course_code, semester, assess_type, assess_num, marks, year) VALUE(?,?,?,?,?,?,?)");
            $insert ->bind_param("sssssss", $SID, $course_code, $semester, $assess_type, $assess_num, $marks, $year);

            if ($insert->execute()) {
              echo "<script>alert('Results uploaded submited successfully')</script>";
              echo"<script>window.open('assessments.php','_self')</script>";
              }
            }
            else {
              echo "<script>alert('Upload failed!')</script>";
              echo"<script>window.open('assessments.php','_self')</script>";

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
  <div id="results" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue"> 
        <span onclick="document.getElementById('results').style.display='none'" 
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Upload assessment results</h3>
      </header>
      <div class="w3-container">
        <form action="upload_assessments.php" method="post" role="form">

                <div class="form-group">
                  <lable for="SID">Student #:</lable><br>
                  <input type="text" class="form-control" name="SID" autofocus id="SID" 
                  placeholder="Enter student number" autocomplete="off" required>
                </div>
                <div class="form-group">
                  <lable for="course_code">Course code:</lable><br>
                  <select class="form-control" name="course_code" id="course_code">
                    <option selected disabled>Select code</option>
                  <?php

                   if($results = $db->query("SELECT DISTINCT (course_code) FROM courses")) {
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
                    <option><?php echo ($r->course_code); ?></option>

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
                <div class="form-group">
                  <lable for="assess_type">Assessment type:</lable><br>
                      <select class="form-control" name="assess_type" id="assess_type">
                        <option>Assignment</option>
                        <option>Test</option>
                        <option>Make-up</option>
                      </select>
                </div>
                <div class="form-group">
                  <lable for="assess_num">Assessment No:</lable><br>
                      <select class="form-control" name="assess_num" id="assess_num">
                        <option>1</option>
                        <option>2</option>
                        <option>3</option>
                        <option>4</option>
                      </select>
                </div>
                <div class="form-group">
                  <lable for="marks">Marks Obtained:</lable><br>
                  <input type="number" class="form-control" name="marks" min="0" max="100" autofocus id="marks" 
                  placeholder="Enter marks obtained" autocomplete="off" required>
                </div>
                <lable for="year">Year of study:</lable><br>
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
                          $('#year').append($('<option />').val(i).html(i));
                        }
                        </script>
              <br>
                <div class="form-group">
                  <!--input type="submit" value="Submit"-->
                  <button class="btn btn-sm btn-success btn-block" type="submit">SUBMIT</button>
                </div>  
              </div>

        </form><!--registration form ends-->
    </div>
  </div>
</body>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
      </script>
      <script src="dist/js/bootstrap.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
