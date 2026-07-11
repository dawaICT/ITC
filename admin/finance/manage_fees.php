<?php
require_once "../includes/admin.php";
require_once "../includes/header.php";
require_once "../../includes/finance_helpers.php";

$page_title = "Finance: Fee Structure Management";
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Fee Structure Management</h1>
        <p class="text-muted">Configure tuition and mandatory fees per program</p>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#feeModal" onclick="resetForm()">
          <i class="fas fa-plus me-1"></i> Add Fee Item
        </button>
        <a href="index.php" class="btn btn-outline-secondary">Back to Hub</a>
      </div>
    </div>
  </div>

  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-tags me-2"></i>Fee Management</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label">Filter by Program</label>
          <select id="filter_program" class="form-select" onchange="loadFees()">
            <option value="">All Programs</option>
          </select>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle" id="fees_table">
          <thead class="table-light">
            <tr>
              <th>Program</th>
              <th>Year</th>
              <th>Sem</th>
              <th>Description</th>
              <th class="text-end">Amount</th>
              <th>Status</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr><td colspan="7" class="text-center">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Fee Modal -->
<div class="modal fade" id="feeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Add Fee Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="feeForm">
          <input type="hidden" id="fee_id" name="id">
          <div class="mb-3">
            <label class="form-label">Program</label>
            <select id="program_code" name="program_code" class="form-select" required></select>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Year of Study</label>
              <input type="number" id="year_of_study" name="year_of_study" class="form-control" value="1" required min="1">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Semester</label>
              <input type="number" id="semester" name="semester" class="form-control" value="1" required min="1">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <input type="text" id="fee_description" name="fee_description" class="form-control" placeholder="e.g. Tuition Fee" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Amount</label>
            <input type="number" step="0.01" id="amount" name="amount" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select id="status" name="status" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveFee()">Save Changes</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  loadPrograms();
  loadFees();
});

function loadPrograms() {
  fetch('../ajax/finance_get_programs.php').then(r=>r.json()).then(j=>{
    if(j.success){
      const filters = document.getElementById('filter_program');
      const modalSel = document.getElementById('program_code');
      j.data.forEach(p=>{
        const opt = `<option value="${p.program_code}">${p.program_code} - ${p.program_name}</option>`;
        filters.innerHTML += opt;
        modalSel.innerHTML += opt;
      });
    }
  });
}

function loadFees() {
  const prog = document.getElementById('filter_program').value;
  const tbody = document.querySelector('#fees_table tbody');
  tbody.innerHTML = '<tr><td colspan="7" class="text-center">Loading...</td></tr>';
  
  fetch(`../ajax/finance_get_fees.php?program_code=${prog}`)
    .then(r=>r.json()).then(j=>{
      tbody.innerHTML = '';
      if(j.success && j.data.length > 0){
        j.data.forEach(row=>{
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td><strong>${row.program_code}</strong></td>
            <td>Yr ${row.year_of_study}</td>
            <td>S${row.semester}</td>
            <td>${row.fee_description}</td>
            <td class="text-end font-monospace">${Number(row.amount).toLocaleString('en-US', {minimumFractionDigits:2})}</td>
            <td><span class="badge ${row.status==='active'?'bg-success':'bg-secondary'}">${row.status}</span></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary me-1" onclick='editFee(${JSON.stringify(row)})'><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger" onclick="deleteFee(${row.id})"><i class="fas fa-trash"></i></button>
            </td>
          `;
          tbody.appendChild(tr);
        });
      } else {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No fee records found.</td></tr>';
      }
    });
}

function resetForm() {
  document.getElementById('feeForm').reset();
  document.getElementById('fee_id').value = '';
  document.getElementById('modalTitle').textContent = 'Add Fee Item';
}

function editFee(row) {
  document.getElementById('fee_id').value = row.id;
  document.getElementById('program_code').value = row.program_code;
  document.getElementById('year_of_study').value = row.year_of_study;
  document.getElementById('semester').value = row.semester;
  document.getElementById('fee_description').value = row.fee_description;
  document.getElementById('amount').value = row.amount;
  document.getElementById('status').value = row.status;
  document.getElementById('modalTitle').textContent = 'Edit Fee Item';
  new bootstrap.Modal(document.getElementById('feeModal')).show();
}

function saveFee() {
  const fd = new FormData(document.getElementById('feeForm'));
  fetch('../ajax/finance_save_fee.php', { method: 'POST', body: fd })
    .then(r=>r.json()).then(j=>{
      if(j.success){
        bootstrap.Modal.getInstance(document.getElementById('feeModal')).hide();
        loadFees();
      } else {
        alert(j.message || 'Error saving fee');
      }
    });
}

function deleteFee(id) {
  if(!confirm('Are you sure you want to delete this fee item?')) return;
  const fd = new FormData();
  fd.set('id', id);
  fetch('../ajax/finance_delete_fee.php', { method: 'POST', body: fd })
    .then(r=>r.json()).then(j=>{
      if(j.success){ loadFees(); }
      else { alert(j.message || 'Error deleting'); }
    });
}

/**
 * Amount validation for fee inputs
 * Ensures proper currency format (no leading zeros, max 2 decimal places)
 */
function isNumberKey(evt) {
    var charCode = (evt.which) ? evt.which : evt.keyCode;
    if (charCode === 46 || charCode === 8 || charCode === 9 || charCode === 27 || charCode === 13) {
        return true;
    }
    if ((charCode === 65 || charCode === 67 || charCode === 86 || charCode === 88) && (evt.ctrlKey === true || evt.metaKey === true)) {
        return true;
    }
    if (charCode >= 35 && charCode <= 40) {
        return true;
    }
    if ((charCode < 48 || charCode > 57) && charCode !== 46) {
        evt.preventDefault();
        return false;
    }
    return true;
}

// Attach amount validation on page load
document.addEventListener('DOMContentLoaded', function() {
    const amountField = document.getElementById('amount');
    if (amountField) {
        amountField.addEventListener('keypress', isNumberKey);
        amountField.addEventListener('blur', function() {
            var value = this.value.trim();
            if (value !== "" && value !== "0") {
                var pattern = /^[1-9]\d*(?:\.\d{0,2})?$/;
                if (!pattern.test(value)) {
                    alert('Amount should be in proper format!\n\nValid: 150.50, 500, 1000.00\nInvalid: 0.50, 100.555, $100');
                    this.focus();
                    this.select();
                }
            }
        });
    }
});
</script>

<?php require_once "../includes/footer.php"; ?>

