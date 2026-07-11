<?php
require_once "../includes/admin.php";
require_once "../includes/header.php";
require_once "../../includes/finance_helpers.php";

$page_title = "Finance: Budgeting & Allocation";

function table_exists(mysqli $db, string $table): bool {
  $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
  if (!$stmt) return false;
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $res = $stmt->get_result();
  return $res && $res->num_rows > 0;
}

$needsInstall = !table_exists($db, 'finance_budgets');
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Budgeting & Allocation</h1>
        <p class="text-muted">Configure and track academic budgets by cost center</p>
      </div>
      <div class="col-auto d-flex gap-2">
        <a href="../finance.php" class="btn btn-outline-secondary">Back to Finance</a>
      </div>
    </div>
  </div>

  <?php if ($needsInstall): ?>
    <div class="alert alert-warning">
      <strong>Finance module not installed.</strong>
      <a class="btn btn-sm btn-primary" href="../scripts/install_finance_module.php">Install Finance Tables</a>
    </div>
  <?php else: ?>
  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-project-diagram me-2"></i>Budgeting & Allocation</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Period Year</label>
          <input type="text" class="form-control" id="period_year" placeholder="2024/2025">
        </div>
        <div class="col-md-3">
          <label class="form-label">Period Term</label>
          <input type="text" class="form-control" id="period_term" placeholder="Semester 1">
        </div>
        <div class="col-md-3">
          <label class="form-label">Cost Center</label>
          <select class="form-select" id="cost_center"></select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Amount</label>
          <input type="number" step="0.01" class="form-control" id="allocated_amount" placeholder="0.00">
        </div>
        <div class="col-md-1">
          <button id="save_budget" class="btn btn-primary w-100">Save</button>
        </div>
      </div>
      <hr/>
      <div class="table-responsive">
        <table class="table table-hover align-middle" id="budgets_table">
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
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Load cost centers and budgets
  fetch('../ajax/finance_get_cost_centers.php').then(r=>r.json()).then(j=>{
    if(j.success){
      const sel = document.getElementById('cost_center'); if(!sel) return;
      j.data.forEach(cc=>{
        const opt = document.createElement('option');
        opt.value = cc.id;
        opt.textContent = `${cc.center_type.toUpperCase()} - ${cc.name}`;
        sel.appendChild(opt);
      });
    }
  });

  function loadBudgets(){
    fetch('../ajax/finance_get_budgets.php').then(r=>r.json()).then(j=>{
      const tbody = document.querySelector('#budgets_table tbody'); if(!tbody) return;
      tbody.innerHTML = '';
      if(j.success){
        j.data.forEach(row=>{
          const tr = document.createElement('tr');
          tr.innerHTML = `<td>${row.period_year}</td>
                          <td>${row.period_term}</td>
                          <td>${row.center_name}</td>
                          <td class="text-end">${Number(row.allocated_amount).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
                          <td>${row.updated_at}</td>`;
          tbody.appendChild(tr);
        });
      }
    });
  }
  loadBudgets();

  const saveBtn = document.getElementById('save_budget');
  if (saveBtn) {
    saveBtn.addEventListener('click', ()=>{
      const payload = new FormData();
      payload.set('period_year', document.getElementById('period_year').value);
      payload.set('period_term', document.getElementById('period_term').value);
      payload.set('cost_center_id', document.getElementById('cost_center').value);
      payload.set('allocated_amount', document.getElementById('allocated_amount').value);
      fetch('../ajax/finance_save_budget.php', { method: 'POST', body: payload })
        .then(r=>r.json()).then(j=>{
          if(j.success){ loadBudgets(); alert('Saved'); } else { alert(j.message||'Error'); }
        });
    });
  }
});
</script>

<?php require_once "../includes/footer.php"; ?>



