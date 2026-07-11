<?php
$page_title = 'Update CA';
require_once __DIR__ . '/includes/guard.php';
require "includes/nav.php";
?>

<div class="container-fluid px-4 portal-dashboard">
	<div class="dashboard-header admin-section mb-4">
		<div class="row align-items-center">
			<div class="col">
				<h1 class="dashboard-title">Update Student CA</h1>
				<p class="text-muted">Edit and resubmit assessment scores</p>
			</div>
			<div class="col-auto">
				<a href="assessments.php" class="btn btn-primary"><i class="fas fa-tasks me-2"></i>Assessments</a>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-lg-10 mx-auto">
			<div class="data-table-card">
				<div class="card-header">
					<div class="d-flex justify-content-between align-items-center">
						<h5 class="mb-0"><i class="fas fa-edit me-2"></i>Update Student CA</h5>
					</div>
				</div>
				<div class="card-body">
					<div class="row g-3">
						<!-- keep original PHP form fields; replace controls with Bootstrap classes where applicable -->
						<div class="col-md-4">
							<label for="year" class="form-label">Year</label>
							<select class="form-select" id="year" name="Year">
								<option disabled selected>select</option>
							</select>
						</div>
						<div class="col-md-4">
							<label for="semester" class="form-label">Semester</label>
							<select class="form-select" id="semester" name="semester">
								<option disabled selected>select</option>
							</select>
						</div>
					</div>
					<div class="mt-4">
						<button class="btn btn-warning" type="submit" name="submit">Upload</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

