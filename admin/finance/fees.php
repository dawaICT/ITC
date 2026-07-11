<?php
require_once "../includes/admin.php";
require_once "../../includes/finance_helpers.php";
require_once "../../includes/payment_helpers.php";

if (!function_exists('canonicalize_role')) {
    header('Location: ../finance.php');
    exit();
}

$role = canonicalize_role($_SESSION['role'] ?? '');
if (!in_array($role, ['accountant', 'systems_admin'], true)) {
    header('Location: ../finance.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$dpoConfig = payment_get_dpo_config($db);
$dpoReady = payment_dpo_is_ready($dpoConfig);
$pendingBankReviews = payment_count_pending_bank_transactions($db);

$page_title = "Finance: Student Fee Management";
require_once "../includes/header.php";
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title">Student Fee Management</h1>
        <p class="text-muted">Create installment plans and auto-invoice</p>
      </div>
      <div class="col-auto d-flex gap-2">
        <a href="/wucportal/accounts/pendingPayments.php" class="btn btn-outline-primary">
          Pending Proof Reviews
          <?php if ($pendingBankReviews > 0): ?>
            <span class="badge bg-danger ms-1"><?= (int)$pendingBankReviews ?></span>
          <?php endif; ?>
        </a>
        <a href="../finance.php" class="btn btn-outline-secondary">Back to Finance</a>
      </div>
    </div>
  </div>

  <div class="alert alert-<?= $dpoReady ? 'success' : 'warning' ?> d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <strong>Online payment integration:</strong>
      <?= $dpoReady
        ? 'DPO Pay checkout is ready for students, and bank transfer proofs are routed to the pending review queue.'
        : 'Bank transfer proof review is available, but DPO Pay still needs merchant configuration in portal settings before students can use hosted checkout.' ?>
    </div>
    <a href="/wucportal/students/fees.php" class="btn btn-sm btn-outline-dark">View Student Fees Page</a>
  </div>

  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Installment Plans</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <h6 class="mb-0">Existing Plans</h6>
          <small class="text-muted">Select a program to view saved plans.</small>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Plan Name</th>
                <th>Installments</th>
                <th>Status</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody id="plans_table_body">
              <tr>
                <td colspan="4" class="text-muted text-center py-3">Select a program to view existing plans.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Program</label>
          <select class="form-select" id="plan_program">
            <option value="">Loading programs...</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Plan Name</label>
          <input class="form-control" id="plan_name" value="Default Plan" placeholder="Default Plan">
        </div>
        <div class="col-md-2">
          <label class="form-label">Installments</label>
          <input type="number" min="1" max="12" class="form-control" id="num_installments" value="2">
        </div>
        <div class="col-md-3">
          <label class="form-label">Schedule JSON</label>
          <textarea class="form-control font-monospace" id="schedule_json" rows="3" placeholder='[{"due_date":"2026-05-10","percent":50},{"due_date":"2026-07-10","percent":50}]'></textarea>
          <small class="text-muted">Leave blank to auto-generate equal installments.</small>
        </div>
        <div class="col-md-1">
          <button id="save_plan" class="btn btn-secondary w-100">Save</button>
        </div>
      </div>
      <div id="fee_alert" class="alert d-none mt-3 mb-0" role="alert"></div>
      <div class="mt-3 d-flex gap-2 flex-wrap">
        <button id="generate_schedule" class="btn btn-outline-secondary btn-sm">
          <i class="fas fa-magic me-1"></i>Generate Schedule
        </button>
        <button id="sync_invoices" class="btn btn-outline-success">
          <i class="fas fa-sync me-1"></i>Auto-invoice current term
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const csrfToken = <?= json_encode($csrfToken) ?>;
  const planProgram = document.getElementById('plan_program');
  const planName = document.getElementById('plan_name');
  const installments = document.getElementById('num_installments');
  const scheduleInput = document.getElementById('schedule_json');
  const saveBtn = document.getElementById('save_plan');
  const syncBtn = document.getElementById('sync_invoices');
  const genBtn = document.getElementById('generate_schedule');
  const alertBox = document.getElementById('fee_alert');
  const plansTableBody = document.getElementById('plans_table_body');

  function showAlert(type, message) {
    alertBox.className = 'alert alert-' + type;
    alertBox.textContent = message;
    alertBox.classList.remove('d-none');
  }

  function hideAlert() {
    alertBox.classList.add('d-none');
  }

  function setPlansPlaceholder(message) {
    plansTableBody.innerHTML = '';
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 4;
    td.className = 'text-muted text-center py-3';
    td.textContent = message;
    tr.appendChild(td);
    plansTableBody.appendChild(tr);
  }

  function renderPlans(rows) {
    plansTableBody.innerHTML = '';
    if (!Array.isArray(rows) || rows.length === 0) {
      setPlansPlaceholder('No plans found for this program.');
      return;
    }
    rows.forEach((plan) => {
      const tr = document.createElement('tr');

      const nameTd = document.createElement('td');
      nameTd.textContent = plan.plan_name || 'Unnamed Plan';
      tr.appendChild(nameTd);

      const countTd = document.createElement('td');
      countTd.textContent = String(plan.num_installments || 0);
      tr.appendChild(countTd);

      const statusTd = document.createElement('td');
      const badge = document.createElement('span');
      badge.className = 'badge ' + ((plan.status || 'active') === 'active' ? 'bg-success' : 'bg-secondary');
      badge.textContent = plan.status || 'active';
      statusTd.appendChild(badge);
      tr.appendChild(statusTd);

      const actionTd = document.createElement('td');
      actionTd.className = 'text-end';
      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn btn-outline-danger btn-sm';
      delBtn.dataset.planId = plan.id;
      delBtn.textContent = 'Delete';
      actionTd.appendChild(delBtn);
      tr.appendChild(actionTd);

      plansTableBody.appendChild(tr);
    });
  }

  async function parseJsonResponse(response) {
    const text = await response.text();
    let payload = null;
    try {
      payload = JSON.parse(text);
    } catch (e) {
      throw new Error('Invalid server response.');
    }
    if (!response.ok && (!payload || payload.success !== false)) {
      throw new Error('Request failed with status ' + response.status + '.');
    }
    return payload;
  }

  function buildSchedule(n) {
    const count = Math.max(1, Math.min(12, parseInt(n, 10) || 1));
    const arr = [];
    const now = new Date();
    const per = Math.round((100 / count) * 100) / 100; // 2dp split
    let total = 0;
    for (let i = 0; i < count; i++) {
      const d = new Date(now.getFullYear(), now.getMonth() + i + 1, 10);
      const pct = (i === count - 1) ? Math.round((100 - total) * 100) / 100 : per;
      total = Math.round((total + pct) * 100) / 100;
      arr.push({
        due_date: d.toISOString().slice(0, 10),
        percent: pct
      });
    }
    return arr;
  }

  function parseScheduleInput() {
    const txt = scheduleInput.value.trim();
    if (!txt) {
      return buildSchedule(installments.value);
    }
    let parsed;
    try {
      parsed = JSON.parse(txt);
    } catch (e) {
      throw new Error('Schedule JSON is not valid.');
    }
    if (!Array.isArray(parsed) || parsed.length === 0) {
      throw new Error('Schedule must be a non-empty JSON array.');
    }
    let sum = 0;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    parsed.forEach((item, idx) => {
      if (!item || typeof item !== 'object') {
        throw new Error('Schedule item #' + (idx + 1) + ' is invalid.');
      }
      if (!item.due_date || !/^\d{4}-\d{2}-\d{2}$/.test(item.due_date)) {
        throw new Error('Schedule item #' + (idx + 1) + ' has invalid due_date.');
      }
      const dueDate = new Date(item.due_date + 'T00:00:00');
      if (Number.isNaN(dueDate.getTime()) || dueDate < today) {
        throw new Error('Schedule item #' + (idx + 1) + ' has a due date in the past.');
      }
      const p = Number(item.percent);
      if (!Number.isFinite(p) || p <= 0) {
        throw new Error('Schedule item #' + (idx + 1) + ' has invalid percent.');
      }
      sum += p;
    });
    if (Math.abs(sum - 100) > 0.01) {
      throw new Error('Schedule percent must total 100.');
    }
    return parsed;
  }

  async function loadPrograms() {
    hideAlert();
    try {
      const r = await fetch('../ajax/finance_get_programs.php', { credentials: 'same-origin' });
      const j = await parseJsonResponse(r);
      planProgram.innerHTML = '';
      if (!j.success) {
        planProgram.innerHTML = '<option value="">No programs available</option>';
        showAlert('warning', j.message || 'Unable to load programs.');
        return;
      }
      const rows = Array.isArray(j.data) ? j.data : [];
      if (rows.length === 0) {
        planProgram.innerHTML = '<option value="">No active programs</option>';
        showAlert('warning', 'No active programs found.');
        return;
      }
      const first = document.createElement('option');
      first.value = '';
      first.textContent = 'Select program';
      planProgram.appendChild(first);
      rows.forEach((p) => {
        const opt = document.createElement('option');
        opt.value = p.program_code;
        opt.textContent = `${p.program_code} - ${p.program_name}`;
        planProgram.appendChild(opt);
      });
      await loadPlans();
    } catch (e) {
      planProgram.innerHTML = '<option value="">Error loading programs</option>';
      showAlert('danger', e.message || 'Failed to load programs.');
    }
  }

  async function loadPlans() {
    const program = planProgram.value.trim();
    if (!program) {
      setPlansPlaceholder('Select a program to view existing plans.');
      return;
    }
    setPlansPlaceholder('Loading plans...');
    try {
      const url = '../ajax/finance_get_plans.php?program_code=' + encodeURIComponent(program);
      const r = await fetch(url, { credentials: 'same-origin' });
      const j = await parseJsonResponse(r);
      if (j.success) {
        renderPlans(j.data || []);
      } else {
        setPlansPlaceholder(j.message || 'Unable to load plans.');
      }
    } catch (e) {
      setPlansPlaceholder(e.message || 'Failed to load plans.');
    }
  }

  genBtn.addEventListener('click', () => {
    if (scheduleInput.value.trim() && !confirm('This will replace your existing schedule. Continue?')) {
      return;
    }
    const schedule = buildSchedule(installments.value);
    scheduleInput.value = JSON.stringify(schedule, null, 2);
    showAlert('info', 'Schedule generated. Review and save.');
  });

  saveBtn.addEventListener('click', async () => {
    hideAlert();
    const program = planProgram.value.trim();
    const name = planName.value.trim();
    const num = parseInt(installments.value, 10);
    if (!program) {
      showAlert('warning', 'Please select a program.');
      return;
    }
    if (!name) {
      showAlert('warning', 'Please enter a plan name.');
      return;
    }
    if (!Number.isFinite(num) || num < 1 || num > 12) {
      showAlert('warning', 'Installments must be between 1 and 12.');
      return;
    }

    let schedule;
    try {
      schedule = parseScheduleInput();
      installments.value = String(schedule.length);
    } catch (e) {
      showAlert('warning', e.message || 'Invalid schedule.');
      return;
    }

    const fd = new FormData();
    fd.set('csrf_token', csrfToken);
    fd.set('program_code', program);
    fd.set('plan_name', name);
    fd.set('num_installments', String(schedule.length));
    fd.set('schedule_json', JSON.stringify(schedule));

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
      const r = await fetch('../ajax/finance_save_plan.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await parseJsonResponse(r);
      if (j.success) {
        showAlert('success', (j.data && j.data.message) ? j.data.message : 'Installment plan saved.');
        await loadPlans();
      } else {
        showAlert('danger', j.message || 'Unable to save installment plan.');
      }
    } catch (e) {
      showAlert('danger', e.message || 'Network error while saving installment plan.');
    } finally {
      saveBtn.disabled = false;
      saveBtn.innerHTML = 'Save';
    }
  });

  plansTableBody.addEventListener('click', async (event) => {
    const btn = event.target.closest('button[data-plan-id]');
    if (!btn) {
      return;
    }
    if (!confirm('Delete this installment plan?')) {
      return;
    }
    const fd = new FormData();
    fd.set('csrf_token', csrfToken);
    fd.set('plan_id', btn.dataset.planId || '');
    btn.disabled = true;
    btn.textContent = 'Deleting...';
    try {
      const r = await fetch('../ajax/finance_delete_plan.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await parseJsonResponse(r);
      if (j.success) {
        showAlert('success', (j.data && j.data.message) ? j.data.message : 'Plan deleted.');
        await loadPlans();
      } else {
        showAlert('danger', j.message || 'Unable to delete plan.');
      }
    } catch (e) {
      showAlert('danger', e.message || 'Network error while deleting plan.');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Delete';
    }
  });

  function showPreview(data) {
    alertBox.className = 'alert alert-info';
    alertBox.innerHTML = '';
    const text = document.createElement('span');
    const created = data && Number.isFinite(Number(data.created)) ? Number(data.created) : 0;
    const existing = data && Number.isFinite(Number(data.skipped_existing)) ? Number(data.skipped_existing) : 0;
    const noFee = data && Number.isFinite(Number(data.skipped_no_fee)) ? Number(data.skipped_no_fee) : 0;
    text.textContent = `Preview: ${created} invoices would be created. Existing: ${existing}, No Fee Setup: ${noFee}. `;
    alertBox.appendChild(text);

    const confirmBtn = document.createElement('button');
    confirmBtn.type = 'button';
    confirmBtn.className = 'btn btn-sm btn-primary ms-2';
    confirmBtn.textContent = 'Confirm and Run';
    confirmBtn.addEventListener('click', () => runAutoInvoice(false));
    alertBox.appendChild(confirmBtn);
    alertBox.classList.remove('d-none');
  }

  async function runAutoInvoice(previewOnly) {
    const fd = new FormData();
    fd.set('csrf_token', csrfToken);
    if (previewOnly) {
      fd.set('dry_run', '1');
    }

    syncBtn.disabled = true;
    syncBtn.innerHTML = previewOnly
      ? '<i class="fas fa-spinner fa-spin me-1"></i>Previewing...'
      : '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';
    try {
      const r = await fetch('../ajax/finance_autoinvoice.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await parseJsonResponse(r);
      if (j.success) {
        if (previewOnly) {
          showPreview(j.data || {});
          return;
        }
        let msg = 'Auto-invoice completed.';
        if (j.data && j.data.message) {
          msg = j.data.message;
        } else if (j.data) {
          msg = `Auto-invoice completed. Created: ${j.data.created || 0}, Existing: ${j.data.skipped_existing || 0}, No Fee Setup: ${j.data.skipped_no_fee || 0}, Failed: ${j.data.failed || 0}.`;
        }
        showAlert('success', msg);
      } else {
        showAlert('danger', j.message || 'Auto-invoice failed.');
      }
    } catch (e) {
      showAlert('danger', e.message || 'Network error while auto-invoicing.');
    } finally {
      syncBtn.disabled = false;
      syncBtn.innerHTML = '<i class="fas fa-sync me-1"></i>Auto-invoice current term';
    }
  }

  syncBtn.addEventListener('click', async () => {
    hideAlert();
    await runAutoInvoice(true);
  });

  planProgram.addEventListener('change', loadPlans);
  loadPrograms();
});
</script>

<?php require_once "../includes/footer.php"; ?>

