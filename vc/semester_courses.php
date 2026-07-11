<?php
// Modal fragment used by vc/semester.php; must also be safe when hit directly.
require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!empty($_POST)) {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        echo "<script>alert('Request verification failed. Please try again.')</script>";
        echo "<script>window.open('semester.php','_self')</script>";
    } elseif (isset($_POST["course_code"], $_POST["program_code"], $_POST["semester"], $_POST["year"])) {

        $course_code = trim((string)$_POST["course_code"]);
        $program_code = trim((string)$_POST["program_code"]);
        $semester = (int)$_POST["semester"];
        $year = (int)$_POST["year"];

        // Programme↔course mapping lives in program_courses (semester_courses never existed).
        $index = null;
        $checkStmt = $db->prepare("SELECT id FROM program_courses WHERE course_code = ? AND program_code = ? AND semester = ? AND year = ? LIMIT 1");
        if ($checkStmt) {
            $checkStmt->bind_param("ssii", $course_code, $program_code, $semester, $year);
            $checkStmt->execute();
            $checkStmt->bind_result($index);
            $checkStmt->fetch();
            $checkStmt->close();
        }

        // Period-alignment guard: term programmes take terms 1-3, semester
        // programmes semesters 1-2, short courses reject both.
        $periodError = null;
        if (function_exists('wuc_validate_curriculum_period')) {
            $validation = wuc_validate_curriculum_period($db, $program_code, $year, $semester);
            if (is_array($validation) && empty($validation['ok'])) {
                $periodError = (string)($validation['reason'] ?? 'This period does not match the programme structure.');
            }
        }

        if (isset($index)) {
            echo "<script>alert('Ooops! This course has already been added')</script>";
            echo "<script>window.open('semester.php','_self')</script>";
        } elseif ($periodError !== null) {
            echo "<script>alert(" . json_encode($periodError) . ")</script>";
            echo "<script>window.open('semester.php','_self')</script>";
        } elseif ($course_code !== '' && $program_code !== '' && $semester > 0 && $year > 0) {
            $insert = $db->prepare("INSERT INTO program_courses (course_code, program_code, semester, year) VALUES (?,?,?,?)");
            if ($insert) {
                $insert->bind_param("ssii", $course_code, $program_code, $semester, $year);
                if ($insert->execute()) {
                    echo "<script>alert('Submited successfully')</script>";
                } else {
                    echo "<script>alert('Submission failed!')</script>";
                }
                $insert->close();
            }
            echo "<script>window.open('semester.php','_self')</script>";
        } else {
            echo "<script>alert('Submission failed!')</script>";
            echo "<script>window.open('semester.php','_self')</script>";
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
  <div id="semester" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue"> 
        <span onclick="document.getElementById('semester').style.display='none'" 
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Semester courses</h3>
      </header>
      <div class="w3-container">
        <form action="semester_courses.php" method="post" role="form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                  <lable for="course_code">Course name:</lable><br>
                  <select class="form-control" name="course_code" id="course_code">
                    <option disabled selected>Select course</option>
                  <?php
                    $records = [];
                    if($results = $db->query("SELECT course_code, course_name FROM courses ORDER BY course_name")) {
                        while($row = $results->fetch_object()){
                            $records[] = $row;
                        }
                        $results->free();
                    }
                    foreach($records as $r) {
                    ?>
                    <option value="<?php echo htmlspecialchars((string)$r->course_code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$r->course_name, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php
                    }
                    ?>
                </select>
                </div>
                <div class="form-group">
                  <select class="form-control" name="program_code" id="program_code">
                    <option disabled selected>Select program</option>
                  <?php
                    $records1 = [];
                    if($results1 = $db->query("SELECT program_code, program_name FROM programs WHERE COALESCE(is_active,1) = 1 ORDER BY program_name")) {
                        while($row = $results1->fetch_object()){
                            $records1[] = $row;
                        }
                        $results1->free();
                    }
                    foreach($records1 as $r) {
                    ?>
                    <option value="<?php echo htmlspecialchars((string)$r->program_code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$r->program_name, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php
                    }
                    ?>
                </select>

                </div>
                <div class="form-group">
                  <lable for="year">Year of study:</lable><br>
                      <select class="form-control" name="year" id="year">
                        <option>1</option>
                        <option>2</option>
                        <option>3</option>
                        <option>4</option>
                      </select>
                </div>
                <div class="form-group">
                  <lable for="semester">Term / Semester:</lable><br>
                      <select class="form-control" name="semester" id="semester">
                        <option>1</option>
                        <option>2</option>
                        <option>3</option>
                      </select>
                </div>
              <br><br>
                <div class="form-group">
                  <!--input type="submit" value="Submit"-->
                  <button class="btn btn-sm btn-success btn-block" type="submit">SUBMIT</button>
                </div>  
              </div>

        </form><!--registration form ends-->
    </div>
  </div>
</body>
<script src="assets/vendor/sweetalert2/dist/sweetalert2.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>