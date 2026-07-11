<?php
$page_title = 'Balance Statement – Search';
require "includes/nav.php";
error_reporting(0);

?>
	<div class="container-fluid px-4 portal-dashboard accounts-page search-statement-page">
		<div class="dashboard-header finance-section mb-4">
			<div class="row align-items-center">
				<div class="col d-flex justify-content-between align-items-center">
					<div>
						<h1 class="dashboard-title">Balance Statement</h1>
						<p class="text-muted mb-0">Search student by ID</p>
					</div>
					<a href="unpaidBalance.php" class="btn btn-outline-secondary btn-sm">
						<i class="fas fa-arrow-left me-1"></i> Back
					</a>
				</div>
			</div>
		</div>

		<div class="row g-4">
			<div class="col-md-9 mx-auto">
				<div class="data-table-card mb-4">
					<div class="card-header">
						<div class="d-flex justify-content-between align-items-center">
							<h5 class="mb-0">
								<i class="fas fa-file-text me-2"></i>Balance Statement – Search
							</h5>
						</div>
					</div>
					<div class="card-body">
						<form role="form" method="POST" action="balanceStatement.php">
							<div class="row g-3">
								<div class="col-md-8">
									<label for="Sid" class="form-label">
										<i class="fas fa-user-tag me-1"></i> Student ID
									</label>
									<input type="text" class="form-control" name="Sid" id="Sid" placeholder="Enter Student ID" autocomplete="off"/>
								</div>
							</div>
							<div class="mt-3 text-end">
								<button type="submit" class="btn btn-primary px-4" name="search">
									<i class="fas fa-search me-1"></i> Search
								</button>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

