<?php
$page_title = 'View Bio Data';
require_once __DIR__ . '/includes/guard.php';
require "includes/nav.php";
?>

<div class="container-fluid px-4 portal-dashboard">
	<div class="dashboard-header admin-section mb-4">
		<div class="row align-items-center">
			<div class="col">
				<h1 class="dashboard-title">Bio Data</h1>
				<p class="text-muted">Staff details</p>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-lg-10 mx-auto">
			<div class="data-table-card">
				<div class="card-header">
					<div class="d-flex justify-content-between align-items-center">
						<h5 class="mb-0"><i class="fas fa-user me-2"></i>Personal Information</h5>
					</div>
				</div>
				<div class="card-body">
					<div class="table-responsive">
						<table id="myTable" class="table table-hover align-middle">
							<thead class="table-light">
								<tr><th colspan="2">Profile</th></tr>
							</thead>
							<tbody>
								<!-- retain existing rows populated from PHP -->
							</tbody>
						</table>
					</div>
					<div class="mt-3">
						<button onclick="history.back()" class="btn btn-primary">Back</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

