<?php
require_once "includes/admin.php";
require_once "includes/header.php";
require_once "../includes/finance_helpers.php";

$page_title = "Finance & Accounting";

// Gracefully handle first-time install (missing tables)
function table_exists(mysqli $db, string $table): bool {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

$requiredTables = [
  'finance_cost_centers', 'finance_budgets', 'finance_discounts', 'finance_student_installments', 'finance_vendors', 'finance_expenses'
];
$needsInstall = false;
foreach ($requiredTables as $t) {
  if (!table_exists($db, $t)) { $needsInstall = true; break; }
}

// Fetch quick stats or show installer prompt
$stats = [
    'total_budgets' => 0,
    'active_discounts' => 0,
    'pending_expenses' => 0,
    'overdue_installments' => 0
];

if (!$needsInstall) {
    if ($res = $db->query("SELECT COUNT(*) AS c FROM finance_budgets")) {
        $stats['total_budgets'] = (int)$res->fetch_object()->c;
    }
    if ($res = $db->query("SELECT COUNT(*) AS c FROM finance_discounts WHERE status='active'")) {
        $stats['active_discounts'] = (int)$res->fetch_object()->c;
    }
    if ($res = $db->query("SELECT COUNT(*) AS c FROM finance_expenses WHERE status='pending'")) {
        $stats['pending_expenses'] = (int)$res->fetch_object()->c;
    }
    if ($res = $db->query("SELECT COUNT(*) AS c FROM finance_student_installments WHERE status='overdue'")) {
        $stats['overdue_installments'] = (int)$res->fetch_object()->c;
    }
}
?>
<style>
    /* stat-card, stat-icon → assets/css/dashboard.css */
    .action-tile {
        background: white; border-radius: 12px; padding: 1.5rem; text-align: center;
        transition: all 0.3s ease; border: 1px solid #f0f0f0; height: 100%; display: block; text-decoration: none;
    }
    .action-tile:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); border-color: var(--primary, #1B2A4A); }
    .action-tile i { font-size: 2rem; color: var(--primary, #1B2A4A); margin-bottom: 1rem; }
    .action-tile h6 { color: #333; font-weight: 700; margin-bottom: 0.5rem; }
    .action-tile p { color: #667; font-size: 0.85rem; margin-bottom: 0; }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Finance & Accounting Hub</h5>
                <p class="page-subtitle mb-0">Core financial management: budgets, student fees, and institutional accounting</p>
            </div>
            <div class="header-actions d-flex gap-2">
                <a href="financial_overview.php" class="btn btn-outline-info shadow-sm">
                    <i class="fas fa-chart-line me-1"></i>Overview
                </a>
                <a href="index.php" class="btn btn-outline-primary shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100 p-3 border-0 bg-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-wallet"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats['total_budgets']) ?></h4>
                        <p class="text-muted small mb-0">Active Budgets</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100 p-3 border-0 bg-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-percent"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats['active_discounts']) ?></h4>
                        <p class="text-muted small mb-0">Active Discounts</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100 p-3 border-0 bg-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3 text-white"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats['pending_expenses']) ?></h4>
                        <p class="text-muted small mb-0">Pending Claims</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100 p-3 border-0 bg-white">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger me-3 text-white"><i class="fas fa-exclamation-triangle"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats['overdue_installments']) ?></h4>
                        <p class="text-muted small mb-0">Overdue Fees</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

  <?php if ($needsInstall): ?>
  <div class="alert alert-warning">
    <strong>Finance module not installed.</strong> Click to run the installer:
    <a class="btn btn-sm btn-primary" href="scripts/install_finance_module.php">Install Finance Tables</a>
  </div>
  <?php else: ?>
    <!-- Navigation Tiles -->
    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <a class="action-tile" href="finance/budgeting.php">
                <i class="fas fa-project-diagram"></i>
                <h6>Budgeting & Allocation</h6>
                <p>Manage cost centers and departmental budgets</p>
            </a>
        </div>
        <div class="col-md-4">
            <a class="action-tile" href="finance/fees.php">
                <i class="fas fa-user-graduate"></i>
                <h6>Student Fees</h6>
                <p>Installments, plans and invoicing</p>
            </a>
        </div>
        <div class="col-md-4">
            <a class="action-tile" href="finance/ar.php">
                <i class="fas fa-exclamation-circle"></i>
                <h6>Accounts Receivable</h6>
                <p>Aging, reminders, late fees</p>
            </a>
        </div>
        <div class="col-md-4">
            <a class="action-tile" href="finance/ap.php">
                <i class="fas fa-user-tie"></i>
                <h6>Accounts Payable</h6>
                <p>Vendors, expenses, approvals</p>
            </a>
        </div>
        <div class="col-md-4">
            <a class="action-tile" href="finance/reporting.php">
                <i class="fas fa-file-alt"></i>
                <h6>Reporting Hub</h6>
                <p>Profitability, analytics, and exports</p>
            </a>
        </div>
        <div class="col-md-4">
            <a class="action-tile" href="ajax/finance_export.php?type=budgets">
                <i class="fas fa-download"></i>
                <h6>Data Export</h6>
                <p>Export budgets and AR data to Excel</p>
            </a>
        </div>
    </div>
  <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Load cost centers and budgets
  fetch('ajax/finance_get_cost_centers.php').then(r=>r.json()).then(j=>{
    if(j.success){
      const sel = document.getElementById('cost_center');
      j.data.forEach(cc=>{
        const opt = document.createElement('option');
        opt.value = cc.id;
        opt.textContent = `${cc.center_type.toUpperCase()} - ${cc.name}`;
        sel.appendChild(opt);
      });
    }
  });

  function loadBudgets(){
    fetch('ajax/finance_get_budgets.php').then(r=>r.json()).then(j=>{
      const tbody = document.querySelector('#budgets_table tbody');
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

  document.getElementById('save_budget').addEventListener('click', ()=>{
    const payload = new FormData();
    payload.set('period_year', document.getElementById('period_year').value);
    payload.set('period_term', document.getElementById('period_term').value);
    payload.set('cost_center_id', document.getElementById('cost_center').value);
    payload.set('allocated_amount', document.getElementById('allocated_amount').value);
    fetch('ajax/finance_save_budget.php', { method: 'POST', body: payload })
      .then(r=>r.json()).then(j=>{
        if(j.success){ loadBudgets(); alert('Saved'); } else { alert(j.message||'Error'); }
      });
  });

  // Program select for plans
  fetch('ajax/finance_get_programs.php').then(r=>r.json()).then(j=>{
    const sel = document.getElementById('plan_program');
    if(j.success){
      j.data.forEach(p=>{
        const opt = document.createElement('option');
        opt.value = p.program_code; opt.textContent = `${p.program_code} - ${p.program_name}`;
        sel.appendChild(opt);
      });
    }
  });

  document.getElementById('save_plan').addEventListener('click', ()=>{
    const fd = new FormData();
    fd.set('program_code', document.getElementById('plan_program').value);
    fd.set('plan_name', document.getElementById('plan_name').value);
    fd.set('num_installments', document.getElementById('num_installments').value);
    fd.set('schedule_json', document.getElementById('schedule_json').value);
    fetch('ajax/finance_save_plan.php', { method:'POST', body: fd })
      .then(r=>r.json()).then(j=>{ if(j.success){ alert('Plan saved'); } else { alert(j.message||'Error'); } });
  });

  document.getElementById('trigger_reminders').addEventListener('click',()=>{
    fetch('ajax/finance_trigger_reminders.php', { method: 'POST' })
      .then(r=>r.json()).then(j=>{ alert(j.message || (j.success?'Done':'Error')); });
  });

  document.getElementById('apply_late_fees').addEventListener('click',()=>{
    fetch('ajax/finance_apply_late_fees.php', { method: 'POST' })
      .then(r=>r.json()).then(j=>{ alert(j.message || (j.success?'Applied':'Error')); });
  });

  document.getElementById('sync_invoices').addEventListener('click',()=>{
    fetch('ajax/finance_autoinvoice.php', { method:'POST' })
      .then(r=>r.json()).then(j=>{ alert(j.message || (j.success?'Done':'Error')); });
  });

  // Load AR aging
  function loadARAging(){
    fetch('ajax/finance_get_ar_aging.php').then(r=>r.json()).then(j=>{
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

  // Vendors
  function loadVendors(){
    fetch('ajax/finance_get_vendors.php').then(r=>r.json()).then(j=>{
      const tbody = document.querySelector('#vendors_table tbody');
      const vendorSel = document.getElementById('expense_vendor');
      tbody.innerHTML=''; vendorSel.innerHTML='';
      if(j.success){
        j.data.forEach(v=>{
          const tr=document.createElement('tr'); tr.innerHTML=`<td>${v.name}</td><td>${v.tin||''}</td><td>${v.bank_account||''}</td><td>${v.contact_email||''}</td><td>${v.contact_phone||''}</td><td>${v.status}</td>`; tbody.appendChild(tr);
          const opt=document.createElement('option'); opt.value=v.id; opt.textContent=v.name; vendorSel.appendChild(opt);
        });
      }
    });
  }
  loadVendors();

  document.getElementById('save_vendor').addEventListener('click',()=>{
    const fd=new FormData();
    fd.set('name', document.getElementById('vendor_name').value);
    fd.set('tin', document.getElementById('vendor_tin').value);
    fd.set('bank_account', document.getElementById('vendor_bank').value);
    fd.set('contact_email', document.getElementById('vendor_email').value);
    fd.set('contact_phone', document.getElementById('vendor_phone').value);
    fd.set('status','active');
    fetch('ajax/finance_save_vendor.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.success){ loadVendors(); alert('Saved'); } else { alert(j.message||'Error'); }});
  });

  function loadExpenses(){
    fetch('ajax/finance_get_expenses.php').then(r=>r.json()).then(j=>{
      const tbody = document.querySelector('#expenses_table tbody');
      tbody.innerHTML = '';
      if (j.success) {
        j.data.forEach(e => {
          const tr = document.createElement('tr');
          const actionButtons = (e.status === 'pending')
            ? '<button class="btn btn-sm btn-success approve">Approve</button> <button class="btn btn-sm btn-outline-danger reject">Reject</button>'
            : '';
          tr.innerHTML = `<td>${e.vendor_name}</td>
                          <td>${e.category}</td>
                          <td>${e.description}</td>
                          <td class="text-end">${Number(e.amount).toFixed(2)}</td>
                          <td>${e.expense_date}</td>
                          <td>${e.status}</td>
                          <td>${actionButtons}</td>`;
          tbody.appendChild(tr);
          if (e.status === 'pending') {
            tr.querySelector('.approve').addEventListener('click', () => approveExpense(e.id, 'approved'));
            tr.querySelector('.reject').addEventListener('click', () => approveExpense(e.id, 'rejected'));
          }
        });
      }
    });
  }
  loadExpenses();

  document.getElementById('save_expense').addEventListener('click',()=>{
    const fd=new FormData();
    fd.set('vendor_id', document.getElementById('expense_vendor').value);
    fd.set('category', document.getElementById('expense_category').value);
    fd.set('description', document.getElementById('expense_description').value);
    fd.set('amount', document.getElementById('expense_amount').value);
    fd.set('currency_code','ZMW');
    fd.set('expense_date', document.getElementById('expense_date').value);
    fetch('ajax/finance_save_expense.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.success){ loadExpenses(); alert('Submitted for approval'); } else { alert(j.message||'Error'); }});
  });

  function approveExpense(id, action){
    const fd=new FormData(); fd.set('expense_id', id); fd.set('action', action);
    fetch('ajax/finance_approve_expense.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.success){ loadExpenses(); } else { alert(j.message||'Error'); }});
  }
});
</script>

<?php require_once "includes/footer.php"; ?>

