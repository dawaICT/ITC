<?php
require_once "../includes/admin.php";
require_once "../includes/header.php";
require_once "../../includes/finance_helpers.php";

$page_title = "Finance Hub";
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Finance Hub</h1>
        <p class="text-muted">Choose a module to manage</p>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-md-4">
      <a class="card shadow-sm text-decoration-none" href="budgeting.php">
        <div class="card-body">
          <h5 class="text-primary"><i class="fas fa-project-diagram me-2"></i>Budgeting & Allocation</h5>
          <p class="text-muted mb-0">Manage cost centers and departmental budgets</p>
        </div>
      </a>
    </div>
    <div class="col-md-4">
      <a class="card shadow-sm text-decoration-none" href="fees.php">
        <div class="card-body">
          <h5 class="text-primary"><i class="fas fa-user-graduate me-2"></i>Installment Plans</h5>
          <p class="text-muted mb-0">Payments, installments, and invoicing</p>
        </div>
      </a>
    </div>
    <div class="col-md-4">
      <a class="card shadow-sm text-decoration-none" href="manage_fees.php">
        <div class="card-body">
          <h5 class="text-success"><i class="fas fa-cog me-2"></i>Fee Structures</h5>
          <p class="text-muted mb-0">Configure base tuition and miscellaneous fees</p>
        </div>
      </a>
    </div>
    <div class="col-md-4">
      <a class="card shadow-sm text-decoration-none" href="ar.php">
        <div class="card-body">
          <h5 class="text-primary"><i class="fas fa-exclamation-circle me-2"></i>Accounts Receivable</h5>
          <p class="text-muted mb-0">Aging, reminders, late fees</p>
        </div>
      </a>
    </div>

    <div class="col-md-4">
      <a class="card shadow-sm text-decoration-none" href="ap.php">
        <div class="card-body">
          <h5 class="text-primary"><i class="fas fa-user-tie me-2"></i>Accounts Payable</h5>
          <p class="text-muted mb-0">Vendors, expenses, approvals</p>
        </div>
      </a>
    </div>
    <div class="col-md-4">
      <a class="card shadow-sm text-decoration-none" href="reporting.php">
        <div class="card-body">
          <h5 class="text-primary"><i class="fas fa-file-alt me-2"></i>Reporting</h5>
          <p class="text-muted mb-0">Profitability, analytics, and exports</p>
        </div>
      </a>
    </div>
  </div>
</div>

<?php require_once "../includes/footer.php"; ?>



