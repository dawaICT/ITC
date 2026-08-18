<?php
$page_title = 'Update Program Fees';
require "includes/nav.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
error_reporting(0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if(isset($_POST['update'])){
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        echo "<script>alert('Invalid or expired form token. Please try again.')</script>";
        echo "<script>window.open('programFees.php','_self')</script>";
        exit;
    }

    $id = trim($_POST["id"]);
    $program_code = trim($_POST["program_code"]);
    $YR_1_S1 = trim($_POST["YR_1_S1"]);
    $YR_1_S2 = trim($_POST["YR_1_S2"]);
    $YR_2_S1 = trim($_POST["YR_2_S1"]);
    $YR_2_S2 = trim($_POST["YR_2_S2"]);
    $YR_3_S1 = trim($_POST["YR_3_S1"]);
    $YR_3_S2 = trim($_POST["YR_3_S2"]);
    $YR_4_S1 = trim($_POST["YR_4_S1"]);
    $YR_4_S2 = trim($_POST["YR_4_S2"]);

    $stmt = $db->prepare(
        'UPDATE program_fees SET program_code = ?, YR_1_S1 = ?, YR_1_S2 = ?, YR_2_S1 = ?, YR_2_S2 = ?, YR_3_S1 = ?, YR_3_S2 = ?, YR_4_S1 = ?, YR_4_S2 = ? WHERE id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('ssssssssss', $program_code, $YR_1_S1, $YR_1_S2, $YR_2_S1, $YR_2_S2, $YR_3_S1, $YR_3_S2, $YR_4_S1, $YR_4_S2, $id);
        $result = $stmt->execute();
        $stmt->close();
    } else {
        $result = false;
    }

    if (!empty($result)) {
      echo "<script>alert('Program fees updated successfully')</script>";
          echo"<script>window.open('programFees.php','_self')</script>";
    } else {
      echo "<script>alert('Program fees could not update')</script>";
      echo"<script>window.open('programFees.php','_self')</script>";
    }

}
?>
			<div class="container-fluid px-4 portal-dashboard accounts-page update-fees-page">
				<div class="dashboard-header finance-section">
					<div class="row align-items-center">
						<div class="col">
							<h1 class="dashboard-title">Update Program Fees</h1>
							<p class="text-muted">Edit per semester amounts</p>
						</div>
					</div>
				</div>
			<div class="row">
				<div class="col-sm-9 mx-auto">
					<div class="data-table-card">
						<div class="card-header">
							<div class="d-flex justify-content-between align-items-center">
								<h5 class="mb-0"><i class="fas fa-dollar-sign me-2"></i>Update Program Fees</h5>
							</div>
						</div>
						<div class="card-body">
							<?php
								if (isset($_GET['update'])) {
									$update = trim($_GET['update']);
                                    $records_1 = [];
                                    if ($stmt = $db->prepare('SELECT * FROM program_fees WHERE id = ?')) {
                                        $stmt->bind_param('s', $update);
                                        $stmt->execute();
                                        $results = $stmt->get_result();
                                        if ($results && $results->num_rows) {
                                            while ($row = $results->fetch_object()) {
                                                $records_1[] = $row;
                                            }
                                        }
                                        $stmt->close();
                                    }
								}

                    ?>

                    <?php
						foreach($records_1 as $r) {
					?>
                    <form action="updateFees.php" method="post" class="row g-3" role="form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <table id="myTable" class="table table-hover align-middle">
                        <thead class="table-light">
                        <tr>
	                        <td><input type="hidden" class="form-control" name="id" autofocus id="id" 
                                    value="<?php echo $r->id?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>Program code</th>
	                        <td><input type="text" class="form-control" name="program_code" autofocus id="program_code" 
                                    value="<?php echo $r->program_code?>" autocomplete="off"></td>
	                    </tr>
                        <tr>
                            <th>Year_1/ Semester_1</th>
	                            <td>
                                <input type="text" class="form-control" name="YR_1_S1" id="YR_1_S1" 
                                    value="<?php echo $r->YR_1_S1?>" autocomplete="off">
                                </td>
	                    </tr>
                        <tr>
                            <th>Year_1/ Semester_2</th>
	                            <td>
                                <input type="text" class="form-control" name="YR_1_S2" autofocus id="YR_1_S2" 
                                    value="<?php echo $r->YR_1_S2?>" autocomplete="off">
                                </td>
	                    </tr>
                        <tr>
                            <th>Year_2/ Semester_1</th>
	                            <td>
                                <input type="text" class="form-control" name="YR_2_S1" autofocus id="YR_2_S1" 
                                    value="<?php echo $r->YR_2_S1?>" autocomplete="off">
                                </td>
	                    </tr>
                        <tr>
                            <th>Year_2/ Semester_2</th>
	                            <td>
                                <input type="text" class="form-control" name="YR_2_S2" autofocus id="YR_2_S2" 
                                    value="<?php echo $r->YR_2_S2?>" autocomplete="off">
                                </td>
	                    </tr>
                        <tr>
                            <th>Year_3/ Semester_1</th>
	                            <td>
                                <input type="text" class="form-control" name="YR_3_S1" autofocus id="YR_3_S1" 
                                    value="<?php echo $r->YR_3_S1?>" autocomplete="off">
                                </td>
	                    </tr>
                        <tr>
                            <th>Year_3/ Semester_2</th>
	                            <td>
                                <input type="text" class="form-control" name="YR_3_S2" autofocus id="YR_3_S2" 
                                    value="<?php echo $r->YR_3_S2?>" autocomplete="off">
                                </td>
	                    </tr>
                        <tr>
                            <th>Year_4/ Semester_1</th>
	                            <td>
                                <input type="text" class="form-control" name="YR_4_S1" autofocus id="YR_4_S1" 
                                    value="<?php echo $r->YR_4_S1?>" autocomplete="off">
                                </td>
	                    </tr>
                        <tr>
                            <th>Year_4/ Semester_2</th>
	                            <td>
                                <input type="text" class="form-control" name="YR_4_S2" autofocus id="YR_4_S2" 
                                    value="<?php echo $r->YR_4_S2?>" autocomplete="off">
                                </td>
	                    </tr>
                        </thead>
                      </table>
                      <div class="d-flex justify-content-between">
                        <a class="btn btn-outline-primary" href="programFees.php">Cancel</a>
                        <button class="btn btn-success" type="submit" name="update">Update</button>
                      </div>
                      </form>
					<?php	
						}
					?>
					</div>
					</div>
				</div>
			</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

