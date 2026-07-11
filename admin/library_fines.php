<?php
$page_title = 'Library Fines';
require_once __DIR__ . '/includes/library_chrome.php';
wuc_library_chrome_boot();
enforcePermission($_SESSION['staff_id'], 'library_fines');
?>

<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Fines Management</h3>
    <a href="<?= htmlspecialchars($GLOBALS['wuc_library_back_href']) ?>" class="btn btn-link">Back</a>
  </div>

  <div class="card">
    <div class="card-header">Unsettled Fines</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle" id="finesTable">
          <thead class="table-light">
            <tr><th>Borrower</th><th>Amount</th><th>Reason</th><th>Actions</th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
$(function(){
  function loadFines(){
    $.get('portal-js/library_fines_api.php', {action:'list'}, function(d){
      const tb = $('#finesTable tbody').empty();
      (d.results||[]).forEach(r => tb.append(`<tr>
        <td>${r.borrower_type}:${r.borrower_id}</td>
        <td>${Number(r.amount).toFixed(2)}</td>
        <td>${r.reason||''}</td>
        <td><button class="btn btn-sm btn-success" data-id="${r.id}">Settle</button></td>
      </tr>`));
    }, 'json');
  }
  loadFines();

  $(document).on('click', 'button[data-id]', function(){
    const id = $(this).data('id');
    $.post('portal-js/library_fines_api.php', {action:'settle', id}, function(d){
      loadFines();
    }, 'json');
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


