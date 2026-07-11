<?php
require_once "../includes/admin.php";
require_once "../includes/header.php";
require_once "../../includes/finance_helpers.php";

$page_title = "Finance: Reporting";
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Financial Reporting</h1>
        <p class="text-muted">Program profitability, fee analytics, and exports</p>
      </div>
      <div class="col-auto d-flex gap-2">
        <a href="../finance.php" class="btn btn-outline-secondary">Back to Finance</a>
      </div>
    </div>
  </div>

  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Reports</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="d-flex gap-2">
        <a href="../ajax/finance_report.php?type=program_profitability" class="btn btn-outline-primary">
          <i class="fas fa-chart-line"></i> Program Profitability (PDF/CSV)
        </a>
        <a href="../ajax/finance_report.php?type=fee_analytics" class="btn btn-outline-secondary">
          <i class="fas fa-chart-pie"></i> Fee Analytics (CSV)
        </a>
        <a href="../ajax/finance_export.php?type=budgets" class="btn btn-outline-success">
          <i class="fas fa-file-excel"></i> Export Budgets (CSV)
        </a>
        <a href="../ajax/finance_export.php?type=ar" class="btn btn-outline-success">
          <i class="fas fa-file-excel"></i> Export AR (CSV)
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once "../includes/footer.php"; ?>



