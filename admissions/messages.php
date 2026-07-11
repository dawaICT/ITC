<?php
require "includes/nav.php";

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
			<div class="dashboard-header admin-section mb-3 d-flex align-items-center justify-content-between">
				<h3 class="dashboard-title mb-0"><i class="fas fa-envelope me-2"></i>Messages</h3>
				<button class="btn btn-success"><i class="fas fa-paper-plane me-1"></i> Send message</button>
			</div>
			<div class="data-table-card">
				<div class="card-header">
					<div class="d-flex justify-content-between align-items-center">
						<h5 class="mb-0"><i class="fas fa-envelope me-2"></i>Search Results</h5>
					</div>
				</div>
				<div class="card-body">
					here
				</div>
			</div>
		</div>
<?php require "includes/footer.php"; ?>

