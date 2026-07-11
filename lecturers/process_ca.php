<?php
$page_title = 'Process CA CSV';
require_once __DIR__ . '/includes/guard.php';
require "includes/nav.php";
?>

<div class="container-fluid px-4 portal-dashboard">
	<div class="dashboard-header admin-section mb-4">
		<div class="row align-items-center">
			<div class="col">
				<h1 class="dashboard-title">Process CA Upload</h1>
				<p class="text-muted">Summary of uploaded continuous assessment records</p>
			</div>
		</div>
	</div>

	<div class="data-table-card">
		<div class="card-header">
			<div class="d-flex justify-content-between align-items-center">
				<h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Upload Summary</h5>
			</div>
		</div>
		<div class="card-body">
			<div class="tab-pane active"><br>
				<?php
				// retain existing PHP processing and echo output; replace glyphicon spans
				?>
			</div>
		</div>
	</div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

