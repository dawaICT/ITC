<?php
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_POST['update'])) {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request.');
    }
    // Mapping rows live in program_courses (course_levels never existed)
    $id = (int)($_POST["course_level_id"] ?? 0);
    $program_code = trim((string)($_POST["program_code"] ?? ''));
    $semester = (int)($_POST["semester"] ?? 0);
    $year = (int)($_POST["year"] ?? 0);

    $ok = false;
    if ($id > 0 && $program_code !== '' && $semester > 0 && $year > 0) {
        $stmt = $db->prepare("UPDATE program_courses SET program_code = ?, semester = ?, year = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('siii', $program_code, $semester, $year, $id);
            $ok = $stmt->execute();
            $stmt->close();
        }
    }
    if ($ok) {
        echo "<script>alert('Course level updated successfully')</script>";
        echo "<script>window.open('courses.php','_self')</script>";
    } else {
        echo "<script>alert('Course level could not update')</script>";
        echo "<script>window.open('courses.php','_self')</script>";
    }
}
?>
<!DOCTYPE html>
<html>
<title>Edit Course Program - ITC</title>
<meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="UTF-8">
<link rel="stylesheet" type="text/css" href="w3/w3.css">
<link rel="stylesheet" type="text/css" href="css_main/admin.css">
<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">

<body>
	<div class="w3-container">
		<div class="row">
			<div class="col-sm-2"></div>
			<div class="col-sm-9 w3-card-4 w3-animate-right">
				<div class="w3-container">
					<p>Courses | edit course level</p>
					<hr>

				</div>
					<br>
				<div class="">
				<div class="container">
					<?php
						$records_2 = [];
						if (isset($_GET['update'])) {
							$update = (int)$_GET['update'];
							$stmt = $db->prepare("SELECT pc.id AS course_level_id, pc.course_code, pc.program_code, pc.semester, pc.year
									FROM program_courses pc
									INNER JOIN programs p ON pc.program_code = p.program_code
									WHERE pc.id = ?");
							if ($stmt) {
								$stmt->bind_param('i', $update);
								$stmt->execute();
								$Results = $stmt->get_result();
								while ($row = $Results->fetch_object()) {
									$records_2[] = $row;
								}
								$stmt->close();
							}
						}
                    ?>

                    <?php
						foreach($records_2 as $r) {
					?>
					<h3 class="w3-center"><strong>Edit Course Program Levels</strong></h3>
                    <form action="edit_course_program.php" method="post" class="#" role="form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
					<table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light"><tr>

	                        <td><input type="hidden" class="form-control" name="course_level_id" autofocus id="course_level_id"
                                    value="<?php echo (int)$r->course_level_id?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>Course code</th>
	                        <td><input type="text" class="form-control" name="course_code" autofocus id="course_code" 
                                    value="<?php echo $r->course_code?>" readonly></td>
	                    </tr>
                        <tr>
                            <th>Program code</th>
	                        <td>
                            <input type="text" class="form-control" name="program_code" autofocus id="program_code" 
                                    value="<?php echo $r->program_code?>">
                            </td>
	                    </tr>
                        <tr>
                            <th>Semester</th>
	                        <td>
                                <select class="form-control" name="semester" id="semester">
                                    <option selected><?php echo ($r->semester); ?></option>
                                    <option>1</option>
                                    <option>2</option>
                                </select>
                            </td>
	                    </tr>
                        <tr>
                            <th>Year</th>
	                        <td>
                                <select class="form-control" name="year" id="year">
                                    <option selected><?php echo ($r->year); ?></option>
                                    <option>1</option>
                                    <option>2</option>
                                    <option>3</option>
                                    <option>4</option>
                                </select>
                            </td>
	                    </tr>
                        </thead>
                      </table>
                      <br>
                      <a class="w3-btn w3-round w3-blue" href="courses.php">Cancel</a>
                      <button class="w3-btn w3-round w3-large w3-green" type="submit" name="update">Update</button>
                      </form>
					<?php	
						}  
					?>
				</div>
				</div>
			</div>
		</div>
	</div>
	<!-- placed at the end of the document so that the pages can load faster 
	============================================================================-->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
	</script>
	<script src="dist/js/bootstrap.min.js"></script>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
