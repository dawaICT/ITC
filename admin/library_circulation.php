<?php
$page_title = 'Library Circulation';
require_once __DIR__ . '/includes/library_chrome.php';
wuc_library_chrome_boot();
enforcePermission($_SESSION['staff_id'], 'library_circulation');
?>

<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Circulation</h3>
    <a href="<?= htmlspecialchars($GLOBALS['wuc_library_back_href']) ?>" class="btn btn-link">Back</a>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header">Checkout</div>
        <div class="card-body">
          <form id="checkoutForm" class="row g-2">
            <div class="col-12"><input class="form-control" name="barcode" placeholder="Scan barcode / enter RFID"></div>
            <div class="col-6">
              <select class="form-select" name="borrower_type">
                <option value="student">Student</option>
                <option value="staff">Staff</option>
              </select>
            </div>
            <div class="col-6"><input class="form-control" name="borrower_id" placeholder="Student No. / Staff ID"></div>
            <div class="col-6"><label class="form-label">Due Date</label><input type="date" class="form-control" name="due_date"></div>
            <div class="col-12"><button class="btn btn-primary">Checkout</button></div>
          </form>
          <div id="checkoutMsg" class="mt-2"></div>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header">Return</div>
        <div class="card-body">
          <form id="returnForm" class="row g-2">
            <div class="col-12"><input class="form-control" name="barcode" placeholder="Scan barcode / enter RFID"></div>
            <div class="col-12"><button class="btn btn-success">Return</button></div>
          </form>
          <div id="returnMsg" class="mt-2"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-header">Active Loans</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle" id="loansTable"><thead class="table-light">
          <tr><th>Barcode</th><th>Title</th><th>Borrower</th><th>Due</th><th>Fine</th></tr>
        </thead><tbody></tbody></table>
      </div>
    </div>
  </div>
</div>

<script>
function fmt(d){return new Date(d).toLocaleDateString();}
$(function(){
  function loadLoans(){
    $.get('portal-js/library_circulation_api.php', {action:'active_loans'}, function(d){
      const tb = $('#loansTable tbody').empty();
      (d.results||[]).forEach(r => tb.append(`<tr><td>${r.barcode||''}</td><td>${r.title}</td><td>${r.borrower_type}:${r.borrower_id}</td><td>${r.due_date?fmt(r.due_date):''}</td><td>${r.fine_amount||'0.00'}</td></tr>`));
    }, 'json');
  }
  loadLoans();

  $('#checkoutForm').on('submit', function(e){
    e.preventDefault();
    const data = $(this).serializeArray().reduce((a,x)=>{a[x.name]=x.value;return a;},{});
    data.action='checkout';
    $.post('portal-js/library_circulation_api.php', data, function(d){
      $('#checkoutMsg').text(d.message||'ok').toggleClass('text-danger', !!d.error).toggleClass('text-success', !d.error);
      loadLoans();
    }, 'json');
  });

  $('#returnForm').on('submit', function(e){
    e.preventDefault();
    const data = $(this).serializeArray().reduce((a,x)=>{a[x.name]=x.value;return a;},{});
    data.action='return';
    $.post('portal-js/library_circulation_api.php', data, function(d){
      $('#returnMsg').text(d.message||'ok').toggleClass('text-danger', !!d.error).toggleClass('text-success', !d.error);
      loadLoans();
    }, 'json');
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


