<?php
include "includes/admin.php";
require_once __DIR__ . '/../includes/helpers/academic_structure_helpers.php';

error_reporting(0);

if(!empty($_POST)){
      if(isset($_POST["program_code"], $_POST["SID"], $_POST["semester"], $_POST["year"])) {

        $program_code = trim($_POST["program_code"]);
        $SID = trim($_POST["SID"]);
        $semester = trim($_POST["semester"]);
        $year = trim($_POST["year"]);
        $guard = wuc_legacy_course_registration_guard($db, $SID, $program_code, $year, $semester);
        if (!$guard['ok']) {
          echo "<script>alert(" . json_encode($guard['reason']) . ")</script>";
          echo"<script>window.open('semester_registration.php','_self')</script>";
          exit;
        }
        $period_type = $guard['period_type'];

      $academic_year = (string) date('Y');
      if ($ayStmt = $db->prepare("SELECT academic_year FROM academic_periods WHERE period_type = ? AND is_current = 1 ORDER BY id DESC LIMIT 1")) {
          $ayStmt->bind_param('s', $period_type);
          if ($ayStmt->execute()) {
              $ayRow = $ayStmt->get_result()->fetch_assoc();
              if (!empty($ayRow['academic_year'])) {
                  $academic_year = preg_match('/\d{4}/', (string)$ayRow['academic_year'], $m) ? $m[0] : (string)$ayRow['academic_year'];
              }
          }
          $ayStmt->close();
      }

      $index = null;
      $checkStmt = $db->prepare("SELECT student_id FROM semester_registration WHERE student_id = ? AND program_code = ? AND semester = ? AND period_type = ? AND year_of_study = ? AND academic_year = ? LIMIT 1");
        if ($checkStmt) {
          $checkStmt->bind_param('ssisss', $SID, $program_code, $semester, $period_type, $year, $academic_year);
          $checkStmt->execute();
          $check_rs = $checkStmt->get_result()->fetch_assoc();
          if ($check_rs) { $index = $check_rs['student_id']; }
          $checkStmt->close();
        }

        if (isset($index)) {
            echo"<script>alert('This student is already registered for this period')</script>";
            echo"<script>window.open('semester_registration.php','_self')</script>";
            exit;
              }

        else if (!empty($program_code) && !empty($SID) && !empty($semester) && !empty($year)) {
            $insert = $db->prepare("INSERT INTO semester_registration (program_code, student_id, semester, period_type, year_of_study, academic_year, financial_status, registration_date, created_at) VALUES (?,?,?,?,?,?,'Pending',NOW(),NOW())");
            $insert ->bind_param("ssssss", $program_code, $SID, $semester, $period_type, $year, $academic_year);

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
                  <div id="student-lookup-msg" class="form-text"></div>
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
<script>
(function () {
  var sidInput = document.getElementById('SID');
  var lookupMsg = document.getElementById('student-lookup-msg');
  var regBtn = document.querySelector('button[type="submit"]');
  if (!sidInput || !lookupMsg) return;
  sidInput.addEventListener('blur', function () {
    var sid = sidInput.value.trim();
    if (!sid) {
      lookupMsg.textContent = '';
      if (regBtn) regBtn.disabled = false;
      return;
    }
    fetch('../admin/ajax/student_lookup.php?sid=' + encodeURIComponent(sid), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          lookupMsg.className = 'form-text text-success';
          lookupMsg.textContent = data.name + (data.program_code ? ' — ' + data.program_code : '');
          if (regBtn) regBtn.disabled = false;
        } else {
          lookupMsg.className = 'form-text text-danger';
          lookupMsg.textContent = data.message || 'Student not found';
          if (regBtn) regBtn.disabled = true;
        }
      })
      .catch(function () {});
  });
})();
</script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
