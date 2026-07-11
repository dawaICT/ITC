<?php
require "includes/nav.php";
include_once "upload_ca.php";

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

?>

<div class="container-fluid px-4 py-4 portal-dashboard">
	<div class="row">
		<div class="col-12">
			<div class="card admin-card p-3">
				<div class="row">
					<div class="col-12">
						<h3>Assessments</h3>
						<div class="d-flex gap-2 mb-3">
							<button class="btn btn-warning" onclick="document.getElementById('Semester').style.display='block'">Post assessment</button>
							<button class="btn btn-success ms-auto" onclick="document.getElementById('ca').style.display='block'">UPLOAD CA RESULTS</button>
						</div>
						<hr>
					</div>
				</div>

				<div class="data-table-card">
					<div class="card-header">
						<div class="d-flex justify-content-between align-items-center">
							<h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>View Submitted CA's Results</h5>
						</div>
					</div>
					<div class="card-body">
						<form role="form" method="GET" action="student_ca.php">
							<div class="row g-3">
								<div class="col-md-6">
									<label for="Sid" class="form-label">Student ID</label>
									<input type="text" class="form-control" id="Sid" name="Sid" placeholder="Enter student ID" required>
								</div>
								<div class="col-md-4">
									<label for="years" class="form-label">Year</label>
									<select id="years" name="Year" class="form-select">
										<option disabled selected>Select exam year</option>
									</select>
								</div>
								<div class="col-12">
									<button class="btn btn-success" type="submit" name="submit">Submit</button>
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<?php require "includes/footer.php"; ?>

