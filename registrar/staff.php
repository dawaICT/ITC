<?php
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/role_helpers.php';
wuc_require_systems_admin('/wucportal/portal_selection.php');
require_once "../includes/schema_helpers.php";
include 'add_staff.php';
error_reporting(0);

?>
<!DOCTYPE html>
<html>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" href="w3/w3.css">
	<meta charset="UTF-8">   
	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
	<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
	<script type="text/javascript" src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
	<script type="text/javascript" src="dist/js/bootstrap.min.js"></script>
	<link rel="stylesheet" type="text/css" href="w3/w3.css">
	<link rel="stylesheet" type="text/css" href="css_main/admin.css">
	<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">
</head>

<body>
<div class="w3-container">
		<div class="row">
			<div class="col-sm-1"></div>
			<div class="col-sm-10 w3-card-4 w3-animate-right">
				<div class="w3-conatiner">
					<h3>Staff</h3>
					<a class="w3-btn w3-round w3-green w3-left" onclick="document.getElementById
					('staffAccount').style.display='block'"><span class="glyphicon glyphicon-check"></span> Create staff account</a>
					<button class="w3-btn w3-round w3-green w3-right" onclick="document.getElementById
					('staff').style.display='block'"><span class="glyphicon glyphicon-plus"></span> Add New staff</button><br>

					<hr><br>
					<!--Staff table starts here -->
						<?php
                      	$number = 1;
                        $records = [];
                        $staffDeptCol = wuc_detect_column($db, 'staff', ['deptId', 'DeptID', 'department_id']);
                        $deptIdCol = wuc_detect_column($db, 'departments', ['department_id', 'DeptID', 'id', 'deptId']);
                        $deptNameCol = wuc_detect_column($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
                        $deptJoin = '';
                        $deptNameExpr = 'NULL AS deptName';
                        if ($staffDeptCol && $deptIdCol && $deptNameCol) {
                            $deptJoin = "LEFT JOIN departments d ON CAST(s.`{$staffDeptCol}` AS CHAR) = CAST(d.`{$deptIdCol}` AS CHAR)";
                            $deptNameExpr = "d.`{$deptNameCol}` AS deptName";
                        }

                       	if($results = $db->query("SELECT s.*, {$deptNameExpr} FROM staff s {$deptJoin} ORDER BY s.staff_id")) {
                              if($count = $results->num_rows) {

                              while($row = $results->fetch_object()){

	                                $records[] = $row;
	                            }

	                            $results->free();
	                          }
	                          else {
	                            echo "<script>alert('No staff records found.')</script>";
	                          }
	                        }

	                   	?>
							<h2 class="center text-danger"><?php echo $_SESSION['successMssg']; ?></h2> 	
							<h3 class="w3-center"><strong>Active Staff</strong></h3>
							<div class="table-responsive">
			            		<table id="myTable" class="table table-hover align-middle">
			                        <thead class="table-light">
			                          <tr>
			                            <th>No.</th>
			                            <th>Staff ID</th>
			                              <th>Full name</th>
			                              <th>Gender</th>
			                              <th>Department</th>
			                              <th class="w3-center">Action</th>
			                          </tr>
			                        </thead>
			                        <tbody>
			                          <?php
			                          foreach($records as $r) {
			                            ?>
			                              <tr>
			                                <td><?php echo $number++; ?>.</td>
			                                <td><?php echo htmlspecialchars($r->staff_id ?? ''); ?></td>
			                                <td><?php echo htmlspecialchars(trim(($r->title ?? '') . ' ' . ($r->Fname ?? '') . ' ' . ($r->Lname ?? ''))); ?></td>
			                                <td><?php echo htmlspecialchars($r->sex ?? ''); ?></td>
			                                <td><?php echo htmlspecialchars($r->deptName ?? 'N/A'); ?></td>
			                                <td class="w3-center">
			                                  <a class='btn w3-green' href="view_staff.php?view=<?php echo urlencode($r->staff_id ?? '') ?>"><span class="glyphicon glyphicon-eye-open"></span>
			                                    </a>
			                                    <a class='btn w3-light-blue' href="editStaff.php?update=<?php echo urlencode($r->staff_id ?? '') ?>"><span class="glyphicon glyphicon-edit"></span>
			                                    </a>
			                                    <a class='btn w3-red' href="deleteStaff.php?del=<?php echo urlencode($r->staff_id ?? '') ?>">
												<span class="glyphicon glyphicon-remove"></span>
			                                    </a>

			                                </td>

			                               </tr>
			                          <?php 
			                          }  
			                          ?>
			                        </tbody>
			                      </table>
								</div>
								<!--Staff table ends here-->
							</div>
					<br>
			</div>
			<div class="col-sm-1"></div>
		</div>
	</div>
	<script>
$(document).ready(function(){
    $('#myTable').DataTable({
        scrollX: true,
        autoWidth: false
    });
});
</script>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
<?php
include 'createAccount.php';
?>
