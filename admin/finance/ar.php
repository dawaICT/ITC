<?php
require_once "../includes/admin.php";
require_once "../includes/header.php";
require_once "../../includes/finance_helpers.php";

$page_title = "Finance: Accounts Receivable";
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Accounts Receivable</h1>
        <p class="text-muted">AR aging, reminders, and late fee application</p>
      </div>
      <div class="col-auto d-flex gap-2">
        <a href="../finance.php" class="btn btn-outline-secondary">Back to Finance</a>
      </div>
    </div>
  </div>

  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-exclamation-circle me-2"></i>AR Aging</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="d-flex gap-2 mb-3">
        <button id="trigger_reminders" class="btn btn-warning">
          <i class="fas fa-sms"></i> Send Overdue Reminders
        </button>
        <button id="apply_late_fees" class="btn btn-danger">
          <i class="fas fa-hand-holding-usd"></i> Apply Late Fees
        </button>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle" id="ar_aging_table">
          <thead class="table-light">
            <tr>
              <th>Student</th>
              <th>Program</th>
              <th class="text-end">0-30</th>
              <th class="text-end">31-60</th>
              <th class="text-end">61-90</th>
              <th class="text-end">90+</th>
              <th>Total</th>
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
  function loadARAging(){
    fetch('../ajax/finance_get_ar_aging.php').then(r=>r.json()).then(j=>{
      const tbody = document.querySelector('#ar_aging_table tbody');
      tbody.innerHTML = '';
      if(j.success){
        j.data.forEach(x=>{
          const tr = document.createElement('tr');
          tr.innerHTML = `<td>${x.student_id}</td>
                          <td>${x.program_code||''}</td>
                          <td class="text-end">${Number(x.b_0_30).toFixed(2)}</td>
                          <td class="text-end">${Number(x.b_31_60).toFixed(2)}</td>
                          <td class="text-end">${Number(x.b_61_90).toFixed(2)}</td>
                          <td class="text-end">${Number(x.b_90_plus).toFixed(2)}</td>
                          <td class="text-end">${Number(x.total).toFixed(2)}</td>`;
          tbody.appendChild(tr);
        });
      }
    });
  }
  loadARAging();

  document.getElementById('trigger_reminders').addEventListener('click',()=>{
    fetch('../ajax/finance_trigger_reminders.php', { method: 'POST' })
      .then(r=>r.json()).then(j=>{ alert(j.message || (j.success?'Done':'Error')); });
  });

  document.getElementById('apply_late_fees').addEventListener('click',()=>{
    fetch('../ajax/finance_apply_late_fees.php', { method: 'POST' })
      .then(r=>r.json()).then(j=>{ alert(j.message || (j.success?'Applied':'Error')); });
  });
});
</script>

<?php require_once "../includes/footer.php"; ?>



