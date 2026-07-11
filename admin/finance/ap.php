<?php
require_once "../includes/admin.php";
require_once "../includes/header.php";
require_once "../../includes/finance_helpers.php";

$page_title = "Finance: Accounts Payable";
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Accounts Payable</h1>
        <p class="text-muted">Vendors, expenses, and approvals</p>
      </div>
      <div class="col-auto d-flex gap-2">
        <a href="../finance.php" class="btn btn-outline-secondary">Back to Finance</a>
      </div>
    </div>
  </div>

  <div class="data-table-card mb-5">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-user-tie me-2"></i>Vendors & Accounts Payable</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-2 mb-3">
        <div class="col-md-3"><input id="vendor_name" class="form-control" placeholder="Vendor Name"></div>
        <div class="col-md-2"><input id="vendor_tin" class="form-control" placeholder="TIN"></div>
        <div class="col-md-3"><input id="vendor_bank" class="form-control" placeholder="Bank Account"></div>
        <div class="col-md-2"><input id="vendor_email" class="form-control" placeholder="Email"></div>
        <div class="col-md-2"><input id="vendor_phone" class="form-control" placeholder="Phone"></div>
        <div class="col-md-12 mt-2"><button id="save_vendor" class="btn btn-secondary">Save Vendor</button></div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle" id="vendors_table"><thead class="table-light"><tr><th>Name</th><th>TIN</th><th>Bank</th><th>Email</th><th>Phone</th><th>Status</th></tr></thead><tbody></tbody></table>
      </div>

      <hr/>
      <h6>New Expense</h6>
      <div class="row g-2 mb-3">
        <div class="col-md-3"><select id="expense_vendor" class="form-select"></select></div>
        <div class="col-md-2"><input id="expense_category" class="form-control" placeholder="Category" value="Academic"></div>
        <div class="col-md-3"><input id="expense_description" class="form-control" placeholder="Description"></div>
        <div class="col-md-2"><input id="expense_amount" type="number" step="0.01" class="form-control" placeholder="Amount"></div>
        <div class="col-md-2"><input id="expense_date" type="date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
        <div class="col-md-12 mt-2"><button id="save_expense" class="btn btn-primary">Submit for Approval</button></div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle" id="expenses_table"><thead class="table-light"><tr><th>Vendor</th><th>Category</th><th>Description</th><th class="text-end">Amount</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody></tbody></table>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  function loadVendors(){
    fetch('../ajax/finance_get_vendors.php').then(r=>r.json()).then(j=>{
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
    fetch('../ajax/finance_save_vendor.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.success){ loadVendors(); alert('Saved'); } else { alert(j.message||'Error'); }});
  });

  function loadExpenses(){
    fetch('../ajax/finance_get_expenses.php').then(r=>r.json()).then(j=>{
      const tbody=document.querySelector('#expenses_table tbody'); tbody.innerHTML='';
      if(j.success){
        j.data.forEach(e=>{
          const tr=document.createElement('tr');
          const actionButtons = (e.status === 'pending')
            ? '<button class="btn btn-sm btn-success approve">Approve</button> <button class="btn btn-sm btn-outline-danger reject">Reject</button>'
            : '';
          tr.innerHTML=`<td>${e.vendor_name}</td><td>${e.category}</td><td>${e.description}</td><td class="text-end">${Number(e.amount).toFixed(2)}</td><td>${e.expense_date}</td><td>${e.status}</td><td>${actionButtons}</td>`; tbody.appendChild(tr);
          if(e.status==='pending'){
            tr.querySelector('.approve').addEventListener('click',()=>approveExpense(e.id,'approved'));
            tr.querySelector('.reject').addEventListener('click',()=>approveExpense(e.id,'rejected'));
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
    fetch('../ajax/finance_save_expense.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.success){ loadExpenses(); alert('Submitted for approval'); } else { alert(j.message||'Error'); }});
  });

  function approveExpense(id, action){
    const fd=new FormData(); fd.set('expense_id', id); fd.set('action', action);
    fetch('../ajax/finance_approve_expense.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.success){ loadExpenses(); } else { alert(j.message||'Error'); }});
  }
});
</script>

<?php require_once "../includes/footer.php"; ?>



