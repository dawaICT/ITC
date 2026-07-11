<?php
require "../db/connect.php";
if(!empty($_POST)){
      if(isset($_POST["Sid"], $_POST["program_code"], $_POST["intake"], $_POST["mode"], $_POST["startYear"], $_POST["endYear"])) {

        $Sid = trim($_POST["Sid"]);
        $program_code = trim($_POST["program_code"]);
        $intake = trim($_POST["intake"]);
        $mode = trim($_POST["mode"]);
        $startYear = trim($_POST["startYear"]);
        $endYear = trim($_POST["endYear"]);

        //To check if student already registered for a program 
        $check="SELECT * FROM student_program WHERE Sid='$Sid'";
        if ($check_query = mysqli_query($db, $check)) {
            $check_rs=mysqli_fetch_assoc($check_query);
            $index=$check_rs['Sid'];
            }

            if (isset($index)) {
              echo"<script>alert('Student ID ".$Sid." already admitted for a program in the system.')</script>";
              echo"<script>window.open('admitEnrolled_student.php','_self')</script>";
                die();

                }
        //======Check ends here===========

        else if (!empty($Sid) && !empty($program_code) && !empty($intake) && !empty($mode) && !empty($startYear) && !empty($endYear)) {
            $insert = $db->prepare("INSERT INTO student_program (Sid, program_code, intake, mode, startYear, endYear) VALUE(?,?,?,?,?,?)");
            $insert ->bind_param("ssssss", $Sid, $program_code, $intake, $mode, $startYear, $endYear);

            if ($insert->execute()) {
              echo "<script>alert('Student admission successful')</script>";
              echo"<script>window.open('students_by_admin.php','_self')</script>";
              }
            }
            else {
              echo "<script>alert('Process failed!')</script>";
              echo"<script>window.open('students_by_admin.php','_self')</script>";

            }

        }
    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admit Student - ITC</title>
<link rel="stylesheet" type="text/css" href="css/bootstrap.min.css">
<script src="js/jquery-3.5.1.min.js"></script>
<script src="js/bootstrap.min.js"></script>

<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<div id="myModal" class="modal fade">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Student admission form</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <?php
                    $records_1 = array();
                    if($results_1 = $db->query("SELECT SID FROM students ORDER BY SID DESC LIMIT 1")) {
                        if($count = $results_1->num_rows) {
                        while($row = $results_1->fetch_object()){
                        $records_1[] = $row;
                        }
                        $results_1->free();
                        }
                    }
                ?>

                <?php foreach($records_1 as $r) { ?>
                <form action="admitStudent.php" method="post" role="form">
                    <div class="form-group">
                        <label for="Sid">SID:</label><br>
                        <input type="text" class="form-control" id="Sid" name="Sid" value="<?php echo($r->SID)?>">
                    </div>
                <?php } ?>
                    <div class="form-group">
                        <label for="program_code">Program of study:</label><br>
                        <select class="form-control" name="program_code" id="program_code">
                            <option disabled selected>Select program</option>
                            <?php
                            if($results = $db->query("SELECT * FROM programs")) {
                                if($count = $results->num_rows) {
                                    while($row = $results->fetch_object()){
                                        $records[] = $row;
                                    }
                                    $results->free();
                                }
                            }
                            foreach($records as $r) { ?>
                                <option value="<?php echo ($r->program_code); ?>"><?php echo ($r->program_name); ?></option>
                            <?php } ?>
                        </select> 
                    </div>
                    <div class="form-group">
                        <label for="intake">Intake:</label><br>
                        <select class="form-control" name="intake" id="intake">
                            <option disabled selected>Select intake</option>
                            <option>January</option>
                            <option>May</option>
                            <option>September</option>
                            <option>Other</option>
                        </select> 
                    </div>
                    <div class="form-group">
                        <label for="mode">Mode of study:</label><br>
                        <select class="form-control" name="mode" id="mode">
                            <option disabled selected>Select mode</option>
                            <option>Full-Time</option>
                            <option>Part-Time(Evening)</option>
                            <option>Distance</option>
                            <option>Short Course</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="startYear">Start Year:</label><br>
                        <input type="date" class="form-control" id="startYear" name="startYear" placeholder="">
                    </div>
                    <div class="form-group">
                        <label for="endYear">Year of completion:</label><br>
                        <input type="date" class="form-control" id="endYear" name="endYear" placeholder="">
                    </div>
                    <button type="submit" class="btn btn-primary">Admit student</button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>

