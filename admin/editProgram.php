<?php
require "includes/admin.php";
error_reporting(0);
if(isset($_POST['update'])){

    $id = trim($_POST["id"]);
    $program_code = trim($_POST["program_code"]);
    $program_name = trim($_POST["program_name"]);

   $sql = "update programs set program_code = '$program_code', program_name = '$program_name' WHERE id ='$id'";

    $result = mysqli_query($db, $sql);
    if (!empty($result)) {
      echo "<script>alert('Program updated successfully')</script>";
          echo"<script>window.open('programs.php','_self')</script>";
    } else {
      echo "<script>alert('Program could not update')</script>";
      echo"<script>window.open('programs.php','_self')</script>";
    }

}
?>
<!DOCTYPE html>
<html>
<title>Edit Program - ITC</title>
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
					<h3>Edit program</h3>
					<hr>

				</div>
					<br>
				<div class="">
				<div class="container">
					<?php
						if (isset($_GET['update'])) {
							$update = ($_GET['update']);
								if($results = $db->query("SELECT * FROM programs 
									WHERE id = '$update'")) {
										if($count = $results->num_rows) {
										while($row = $results->fetch_object()){
										$records_1[] = $row;
										}
										$results->free();
									}
								}
							}

                    ?>

                    <?php
						foreach($records_1 as $r) {
					?>
					<h3 class="w3-center"><strong>Edit Program</strong></h3>
                    <form action="editProgram.php" method="post" class="#" role="form">
					<table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light">
                        <tr>

	                        <td><input type="hidden" class="form-control" name="id" autofocus id="id" 
                                    value="<?php echo $r->id?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>Program Code</th>
	                        <td><input type="text" class="form-control" name="program_code" autofocus id="program_code" 
                                    value="<?php echo $r->program_code?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>Program Name</th>
	                        <td>
                                <input type="text" class="form-control" name="program_name" autofocus id="program_name" 
                                    value="<?php echo $r->program_name?>" autocomplete="off">
                                </td>
	                    </tr>
                        </thead>
                      </table>
                      <br>
                      <a class="w3-btn w3-round w3-blue" href="programs.php">Cancel</a>
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
