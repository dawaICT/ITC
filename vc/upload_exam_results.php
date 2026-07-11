<?php
error_reporting(0);
require_once dirname(__DIR__) . '/includes/result_entry_helpers.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php';
if(!empty($_POST)){
      if(isset($_POST["Sid"], $_POST["Course_Code"], $_POST["Exam_marks"], $_POST["Year"])) {

        $Sid = trim($_POST["Sid"]);
        $Course_Code = trim($_POST["Course_Code"]);
        $Exam_marks = trim($_POST["Exam_marks"]);
        $semester = trim($_POST["semester"] ?? '1');
        $Year = trim($_POST["Year"]);

       if (!empty($Sid) && !empty($Course_Code) && !empty($Exam_marks) && !empty($Year)) {
            $eligibility = result_validate_entry($db, $Sid, $Course_Code, $semester, $Year);
            $actor = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
            $programType = (($eligibility['type'] ?? '') === 'short_course') ? 'short_course' : 'semester';
            $save = ($eligibility['ok'] && is_numeric($Exam_marks) && (float)$Exam_marks >= 0 && (float)$Exam_marks <= 100)
                ? result_save_exam_mark($db, $Sid, $Course_Code, $semester, $Year, (float)$Exam_marks, $programType, $actor)
                : ['ok' => false, 'message' => (string)($eligibility['ok'] ? 'Exam mark must be between 0 and 100.' : $eligibility['message'])];

            echo "<script>alert(" . json_encode($save['message']) . ")</script>";
              echo"<script>window.open('exams.php','_self')</script>";
            }
            else {
              echo "<script>alert('Failed! something went wrong.')</script>";
              echo"<script>window.open('exams.php','_self')</script>";

            }

        }
    }
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
<link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
</head>   
<body>
  <div id="exams" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-yellow"> 
        <span onclick="document.getElementById('exams').style.display='none'" 
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Upload Examination results</h3>
      </header>
      <div class="w3-container">
        <form  action="#" method="post" enctype="">
              <div class="form-group">
                    <label for="Sid">Sutdent ID:</label><br>
                    <input type="text" class="form-control" id="Sid" name="Sid" placeholder="Enter student ID">
                </div>
                <div class="form-group">
                  <label for="Course_Code">Course code:</label><br>
                  <select class="form-control" name="Course_Code" id="Course_Code">
                    <option disabled selected>Select course</option>
                  <?php

                    if($Results = $db->query("SELECT * FROM courses")) {
                        if($count = $Results->num_rows) {

                        while($row = $Results->fetch_object()){

                          $Records[] = $row;
                      }

                      $Results->free();
                    }
                  }
                  ?>
                  <?php
                    foreach($Records as $r) {
                    ?>
                    <option value="<?php echo ($r->course_code); ?>"><?php echo ($r->course_code); ?></option>

                    <?php 
                    }  
                    ?>
                </select> 
                </div>
                <div class="form-group">
                    <label for="Exam_marks">Marks obtained:</label><br>
                    <input type="number" class="form-control" id="Exam_marks" name="Exam_marks" min="0" max="100" placeholder="Enter marks obtained">
                </div>
                  <div class="form-group">
                    <label>Year: </label>
                      <select class="w3-input w3-border col-xs-3 form-control"  id="year" name="Year">
                        <option disabled selected>Select year</option>
                      </select>
                        <script type="text/javascript"
                            src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"> 
                        </script>
                        <script type="text/javascript">
                        let Startyear = 2000;
                        let Endyear = new Date().getFullYear();
                        for (x = Endyear; x > Startyear; x--)
                        {
                          $('#year').append($('<option />').val(x).html(x));
                        }
                        </script>

                  </div><br><br>
              <div class="form-group">
                <!--input type="submit" value="Submit"-->
                <button class="btn btn-block bg-primary" type="submit" name="submit">SUBMIT</button>
              </div> 
        </form><!--registration form ends--> 
      </div>
    </div>
  </div>
</body>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
      </script>
      <script src="dist/js/bootstrap.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>