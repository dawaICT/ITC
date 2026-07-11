<?php
$page_title = 'Department Budgets';
require "includes/nav.php";
error_reporting(0);
?>

<div class="container-fluid px-4 portal-dashboard">
  <!-- Dashboard Header -->
  <div class="dashboard-header admin-section mb-4">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Department Budgets</h1>
        <p class="text-muted">View allocated amounts for your department cost centers</p>
      </div>
      <div class="col-auto">
        <div class="header-actions d-flex gap-2">
          <button class="btn btn-outline-secondary" onclick="window.print()">
            <i class="fas fa-print me-2"></i>Print
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Budgets Table Card -->
  <div class="data-table-card">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
          <i class="fas fa-coins me-2"></i>Budget Tracking
        </h5>
      </div>
    </div>
    <div class="card-body">
      <div id="budgetsEmpty" class="alert alert-info d-none">
        <i class="fas fa-info-circle me-2"></i>No budget records found.
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle" id="budgets">
          <thead class="table-light">
            <tr>
              <th>Year</th>
              <th>Term</th>
              <th>Cost Center</th>
              <th class="text-end">Amount (ZMW)</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  fetch('../admin/ajax/finance_get_budgets.php')
    .then(r => r.json())
    .then(j => {
      const tbody = document.querySelector('#budgets tbody');
      const empty = document.getElementById('budgetsEmpty');
      if (!j.success || !Array.isArray(j.data) || j.data.length === 0) {
        empty.classList.remove('d-none');
        return;
      }
      j.data.forEach(b => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${b.period_year ?? ''}</td>
          <td>${b.period_term ?? ''}</td>
          <td>${b.center_name ?? ''}</td>
          <td class="text-end">${Number(b.allocated_amount ?? 0).toFixed(2)}</td>
          <td>${b.updated_at ?? ''}</td>
        `;
        tbody.appendChild(tr);
      });
    })
    .catch(() => {
      const empty = document.getElementById('budgetsEmpty');
      empty.classList.remove('d-none');
    });
});
</script>
