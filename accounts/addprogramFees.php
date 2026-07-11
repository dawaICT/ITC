<?php
require "../db/connect.php";
$erros = array();

if(!empty($_POST)){
			if(isset($_POST["program_code"], $_POST["YR_1_S1"], $_POST["YR_1_S2"], $_POST["YR_2_S1"], $_POST["YR_2_S2"],
			$_POST["YR_3_S1"], $_POST["YR_3_S2"], $_POST["YR_4_S1"], $_POST["YR_4_S2"])) {

              	$program_code = trim($_POST["program_code"]);
				  $YR_1_S1 = trim($_POST["YR_1_S1"]);
				  $YR_1_S2 = trim($_POST["YR_1_S2"]);
				  $YR_2_S1 = trim($_POST["YR_2_S1"]);
				  $YR_2_S2 = trim($_POST["YR_2_S2"]);
				  $YR_3_S1 = trim($_POST["YR_3_S1"]);
				  $YR_3_S2 = trim($_POST["YR_3_S2"]);
				  $YR_4_S1 = trim($_POST["YR_4_S1"]);
				  $YR_4_S2 = trim($_POST["YR_4_S2"]);

				if (!empty($program_code)) {
						$insert = $db->prepare("INSERT INTO program_fees (program_code, YR_1_S1, YR_1_S2, YR_2_S1,
						YR_2_S2, YR_3_S1, YR_3_S2, YR_4_S1, YR_4_S2) VALUES (?,?,?,?,?,?,?,?,?)");
						$insert ->bind_param("sssssssss", $program_code, $YR_1_S1, $YR_1_S2, $YR_2_S1,
						$YR_2_S2, $YR_3_S1, $YR_3_S2, $YR_4_S1, $YR_4_S2);

						if ($insert->execute()) {
                            echo "<script>alert('New program fees added successfully!')</script>";
							echo"<script>window.open('programFees.php','_self')</script>";

							}
						}
						else {
							echo "<script>alert('Failed!')</script>";
							echo"<script>window.open('programFees.php','_self')</script>";

						}

				}
		      }
?>

<!-- Add Program Fees Modal (partial include) -->
<div id="progrmaFees" class="modal fade" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header bg-primary text-white">
				<h5 class="modal-title">Add New Program Fees</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<form action="addprogramFees.php" method="post" role="form">
					<div class="mb-3">
						<label for="program_code" class="form-label">Program</label>
						<select class="form-select" name="program_code" id="program_code">
							<option disabled selected>Select</option>
							<?php
								if($Results = $db->query("SELECT * FROM programs")) {
									if($count = $Results->num_rows) {
										while($rows = $Results->fetch_object()){
											$recordz[] = $rows;
										}
										$Results->free();
									}
								}
							?>
							<?php if (!empty($recordz)) { foreach($recordz as $r) { ?>
								<option value="<?php echo ($r->program_code); ?>"><?php echo ($r->program_name); ?></option>
							<?php } } ?>
						</select>
					</div>
					<div class="mb-3">
						<label for="YR_1_S1" class="form-label">Year_1/ Semester_1 (ZMW)</label>
						<input type="text" class="form-control" name="YR_1_S1" id="YR_1_S1" placeholder="Enter fees" autocomplete="off">
					</div>
					<div class="mb-3">
						<label for="YR_1_S2" class="form-label">Year_1/ Semester_2 (ZMW)</label>
						<input type="text" class="form-control" name="YR_1_S2" autofocus id="YR_1_S2" placeholder="Enter fees" autocomplete="off">
					</div>
					<div class="mb-3">
						<label for="YR_2_S1" class="form-label">Year_2/ Semester_1 (ZMW)</label>
						<input type="text" class="form-control" name="YR_2_S1" autofocus id="YR_2_S1" placeholder="Enter fees" autocomplete="off">
					</div>
					<div class="mb-3">
						<label for="YR_2_S2" class="form-label">Year_2/ Semester_2 (ZMW)</label>
						<input type="text" class="form-control" name="YR_2_S2" autofocus id="YR_2_S2" placeholder="Enter fees" autocomplete="off">
					</div>
					<div class="mb-3">
						<label for="YR_3_S1" class="form-label">Year_3/ Semester_1 (ZMW)</label>
						<input type="text" class="form-control" name="YR_3_S1" autofocus id="YR_3_S1" placeholder="Enter fees" autocomplete="off">
					</div>
					<div class="mb-3">
						<label for="YR_3_S2" class="form-label">Year_3/ Semester_2 (ZMW)</label>
						<input type="text" class="form-control" name="YR_3_S2" autofocus id="YR_3_S2" placeholder="Enter fees" autocomplete="off">
					</div>
					<div class="mb-3">
						<label for="YR_4_S1" class="form-label">Year_4/ Semester_1 (ZMW)</label>
						<input type="text" class="form-control" name="YR_4_S1" autofocus id="YR_4_S1" placeholder="Enter fees" autocomplete="off">
					</div>
					<div class="mb-3">
						<label for="YR_4_S2" class="form-label">Year_4/ Semester_2 (ZMW)</label>
						<input type="text" class="form-control" name="YR_4_S2" autofocus id="YR_4_S2" placeholder="Enter fees" autocomplete="off">
					</div>
					<div class="text-end">
						<button class="btn btn-warning" type="submit">Add Fees</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
