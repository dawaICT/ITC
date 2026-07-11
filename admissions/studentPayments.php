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
			<div class="dashboard-header admin-section mb-3">
				<h3 class="dashboard-title mb-0"><i class="fas fa-money-bill me-2"></i>Payments</h3>
			</div>

			<ul class="nav nav-pills gap-2 mb-3">
				<li class="nav-item"><a class="nav-link disabled" href="#"><i class="fas fa-user me-1"></i> Students</a></li>
				<li class="nav-item"><a class="nav-link active" href="payments.php"><i class="fas fa-dollar-sign me-1"></i> Payments</a></li>
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">
						<i class="fas fa-credit-card me-1"></i> Create Invoice
					</a>
					<ul class="dropdown-menu">
						<li><a class="dropdown-item" href="InvoiceStudent.php">Returning student</a></li>
						<li><a class="dropdown-item" href="InvoiceNewStudent.php">New student</a></li>
					</ul>
				</li>
			</ul>
			<hr>

			<div class="data-table-card">
				<div class="card-header">
					<div class="d-flex justify-content-between align-items-center">
						<h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Payment Actions</h5>
					</div>
				</div>
				<div class="card-body">
					<!-- Payments content goes here -->
					<p class="text-muted mb-0">Select an action above to manage payments or create invoices.</p>
				</div>
			</div>
		</div>
<?php require "includes/footer.php"; ?>

