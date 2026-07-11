<?php

require '../db/connect.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/course_availability_helpers.php';
error_reporting(0);

if (!empty($_POST) && isset($_POST['course_code'], $_POST['program_code'], $_POST['year'])) {
    $course_code = trim((string)$_POST['course_code']);
    $program_code = trim((string)$_POST['program_code']);
    $year = max(1, (int)$_POST['year']);
    $semester = trim((string)($_POST['semester'] ?? ''));
    $periodSpecific = !empty($_POST['period_specific']);
    $deliveryPeriod = ($periodSpecific && $semester !== '') ? (int)$semester : null;

    if ($course_code === '' || $program_code === '' || $year < 1) {
        echo "<script>alert('Please fill in program, course, and year of study.')</script>";
        echo "<script>window.open('courses.php','_self')</script>";
        exit;
    }

    if ($periodSpecific && ($deliveryPeriod === null || $deliveryPeriod < 1)) {
        echo "<script>alert('Select a delivery period when limiting to one period only.')</script>";
        echo "<script>window.open('courses.php','_self')</script>";
        exit;
    }

    if ($periodSpecific) {
        $alignment = wuc_validate_curriculum_period($db, $program_code, $year, (string)$deliveryPeriod);
        if (!$alignment['ok']) {
            echo '<script>alert(' . json_encode($alignment['reason']) . ')</script>';
            echo "<script>window.open('courses.php','_self')</script>";
            exit;
        }
    }

    $result = wuc_insert_program_course_assignment(
        $db,
        $program_code,
        $course_code,
        $year,
        $deliveryPeriod,
        $periodSpecific
    );

    if ($result['ok']) {
        $msg = $result['skipped'] ? 'Course is already assigned for this year.' : 'Submitted successfully';
        echo '<script>alert(' . json_encode($msg) . ')</script>';
        echo "<script>window.open('courses.php','_self')</script>";
    } else {
        echo '<script>alert(' . json_encode('Submission failed: ' . $result['message']) . ')</script>';
        echo "<script>window.open('courses.php','_self')</script>";
    }
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
</head>
<body>
  <div id="semester" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue">
        <span onclick="document.getElementById('semester').style.display='none'"
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Programme courses</h3>
      </header>
      <div class="w3-container">
        <form action="semester_courses.php" method="post" role="form">

                <div class="form-group">
                  <label for="course_code">Course name:</label><br>
                  <select class="form-control" name="course_code" id="course_code" required>
                    <option disabled selected value="">Select course</option>
                  <?php
                    $reco = [];
                    if ($results = $db->query("SELECT course_code, course_name FROM courses ORDER BY course_name")) {
                        while ($row = $results->fetch_object()) {
                            $reco[] = $row;
                        }
                        $results->free();
                    }
                    foreach ($reco as $r) {
                        echo '<option value="' . htmlspecialchars($r->course_code) . '">'
                            . htmlspecialchars($r->course_name) . '</option>';
                    }
                  ?>
                </select>
                </div>
                <div class="form-group">
                  <label for="program_code">Program:</label><br>
                  <select class="form-control" name="program_code" id="program_code" required>
                    <option disabled selected value="">Select program</option>
                  <?php
                    $Recordz1 = [];
                    if ($Resultz1 = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_name")) {
                        while ($row = $Resultz1->fetch_object()) {
                            $Recordz1[] = $row;
                        }
                        $Resultz1->free();
                    }
                    foreach ($Recordz1 as $r) {
                        echo '<option value="' . htmlspecialchars($r->program_code) . '">'
                            . htmlspecialchars($r->program_name) . '</option>';
                    }
                  ?>
                </select>

                </div>
                <div class="form-group">
                  <label for="year">Year of study:</label><br>
                      <select class="form-control" name="year" id="year" required>
                        <option selected disabled value="">Select year</option>
                        <option value="1">Year 1</option>
                        <option value="2">Year 2</option>
                        <option value="3">Year 3</option>
                        <option value="4">Year 4</option>
                      </select>
                </div>

                <div class="form-group">
                  <label for="semester">Delivery period (optional):</label><br>
                      <select class="form-control" name="semester" id="semester" disabled>
                        <option value="">Full academic year</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                      </select>
                </div>

                <div class="form-group">
                  <label>
                    <input type="checkbox" name="period_specific" value="1" id="period_specific">
                    Limit to one period only (exception)
                  </label>
                </div>
              <br><br>
                <div class="form-group">
                  <button class="btn btn-sm btn-success btn-block" type="submit">SUBMIT</button>
                </div>
              </div>

        </form>
    </div>
  </div>
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
	<script src="dist/js/bootstrap.min.js"></script>
	<script>
	document.getElementById('period_specific').addEventListener('change', function () {
	  document.getElementById('semester').disabled = !this.checked;
	});
	</script>
</body>
</html>
